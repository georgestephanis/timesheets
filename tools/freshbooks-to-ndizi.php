<?php

declare(strict_types=1);

/**
 * Migrates legacy FreshBooks invoices (and their clients) into the Ndizi Project
 * Management system running on a WordPress site.
 *
 * Invoices attach directly to a client via _ndizi_client_id (Ndizi 1.1.1+), so no
 * intermediary project is created. Writes go through WordPress core REST
 * (/wp/v2/ndizi_client, /ndizi_invoice) authenticated with the Ndizi connection's
 * application password — the ndizi/v1 namespace has no create endpoints. Reads come
 * from the FreshBooks accounting API or CSV exports via src/integrations/freshbooks.php.
 *
 * Two data sources are supported:
 *   - CSV exports (no OAuth needed): pass --invoices-csv=<path> and optionally --clients-csv=<path>.
 *   - The FreshBooks API: the default when no --invoices-csv is given (requires OAuth setup
 *     via tools/freshbooks-auth.php).
 *
 * Usage:
 *   php tools/freshbooks-to-ndizi.php --invoices-csv=tmp/invoices.csv --clients-csv=tmp/clients.csv
 *   php tools/freshbooks-to-ndizi.php --invoices-csv=tmp/invoices.csv --apply
 *   php tools/freshbooks-to-ndizi.php --apply --limit=1        # API source
 *
 * --dry-run (default) writes nothing; --apply performs the migration; --limit=N caps invoices.
 *
 * Idempotency: each Ndizi invoice is titled "FreshBooks #<invoice_number>"; invoices
 * whose marker already exists are skipped, so re-runs are safe.
 */

const TOOL_ROOT = __DIR__ . '/..';

require_once TOOL_ROOT . '/src/integrations/shared.php';
require_once TOOL_ROOT . '/src/integrations/freshbooks.php';
require_once TOOL_ROOT . '/src/config.php';

// ---- Parse flags -----------------------------------------------------------

$apply       = false;
$limit       = 0; // 0 = no limit
$invoicesCsv = '';
$clientsCsv  = '';
$backfill    = false; // --backfill-payments: update existing migrated invoices' payments in place
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif ($arg === '--dry-run') {
        $apply = false;
    } elseif ($arg === '--backfill-payments') {
        $backfill = true;
    } elseif (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $limit = (int)$m[1];
    } elseif (preg_match('/^--invoices-csv=(.+)$/', $arg, $m)) {
        $invoicesCsv = $m[1];
    } elseif (preg_match('/^--clients-csv=(.+)$/', $arg, $m)) {
        $clientsCsv = $m[1];
    } else {
        fwrite(STDERR, "error: unknown argument: $arg\n");
        exit(1);
    }
}

/**
 * Builds the Ndizi structured payments array for a normalized FreshBooks invoice.
 * A paid invoice with a known paid date becomes a single payment for the full amount.
 *
 * @param array<string,mixed> $inv
 * @return list<array{date:string, amount:float, method:string, note:string}>
 */
function fbPayments(array $inv): array
{
    $datePaid = trim((string)($inv['date_paid'] ?? ''));
    $status   = freshbooksMapStatus((string)($inv['v3_status'] ?? ''));
    if ($datePaid === '' || $status !== 'paid') {
        return [];
    }
    return [[
        'date'   => $datePaid,
        'amount' => (float)($inv['amount'] ?? 0),
        'method' => 'FreshBooks',
        'note'   => 'Imported from FreshBooks',
    ]];
}

$csvMode = $invoicesCsv !== '';
if ($clientsCsv !== '' && !$csvMode) {
    fwrite(STDERR, "error: --clients-csv requires --invoices-csv\n");
    exit(1);
}

$mode = $apply ? 'APPLY' : 'DRY-RUN';
$source = $csvMode ? 'CSV' : 'API';
$op = $backfill ? 'backfill-payments' : 'migration';
echo "FreshBooks -> Ndizi $op [$mode, source=$source]" . ($limit > 0 ? " (limit $limit)" : '') . "\n\n";

// ---- Load config -----------------------------------------------------------

$configPath = TOOL_ROOT . '/config.json';
$config = json_decode((string)file_get_contents($configPath), true);
if (!is_array($config)) {
    fwrite(STDERR, "error: config.json is invalid JSON\n");
    exit(1);
}

$timeout = (int)($config['integration_http_timeout_seconds'] ?? 20);

$fb = $config['integrations']['freshbooks'][0] ?? null;
if (!$csvMode && !is_array($fb)) {
    fwrite(STDERR, "error: no integrations.freshbooks[0]; run tools/freshbooks-auth.php first,\n");
    fwrite(STDERR, "  or pass --invoices-csv=<path> to import from a CSV export instead.\n");
    exit(1);
}

$ndizi = $config['integrations']['ndizi'][0] ?? null;
if (!is_array($ndizi)) {
    fwrite(STDERR, "error: no integrations.ndizi[0] in config.json\n");
    exit(1);
}

$siteUrl = rtrim((string)($ndizi['site_url'] ?? ''), '/');
$ndiziAuth = 'Basic ' . base64_encode(((string)($ndizi['username'] ?? '')) . ':' . ((string)($ndizi['app_password'] ?? '')));
if ($siteUrl === '') {
    fwrite(STDERR, "error: Ndizi connection has no site_url\n");
    exit(1);
}

// ---- Ensure a fresh FreshBooks access token (API mode only) ----------------
// Refresh tokens are one-time-use and rotate on every exchange, so whenever we
// refresh we MUST persist the rotated token immediately — even in dry-run.

if (!$csvMode) {
    $now = time();
    $expiresAt = (int)($fb['token_expires_at'] ?? 0);
    if (($fb['access_token'] ?? '') === '' || $expiresAt <= $now + 60) {
        try {
            $tokens = freshbooksRefreshAccessToken($fb, $timeout);
        } catch (RuntimeException $e) {
            fwrite(STDERR, 'error refreshing FreshBooks token: ' . $e->getMessage() . "\n");
            exit(1);
        }
        $fb['access_token']     = $tokens['access_token'];
        $fb['refresh_token']    = $tokens['refresh_token'];
        $fb['token_expires_at'] = $tokens['expires_at'];
        $config['integrations']['freshbooks'][0] = $fb;
        try {
            saveConfigWithBackup($config, $configPath, 'freshbooks-migrate');
        } catch (RuntimeException $e) {
            fwrite(STDERR, 'error persisting rotated FreshBooks token: ' . $e->getMessage() . "\n");
            exit(1);
        }
        echo "Refreshed FreshBooks access token (rotated token saved to config).\n";
    }

    if (($fb['account_id'] ?? '') === '') {
        fwrite(STDERR, "error: FreshBooks account_id missing; run tools/freshbooks-auth.php\n");
        exit(1);
    }
}

// ---- Ndizi REST helpers ----------------------------------------------------

/**
 * Fetches every published item from a Ndizi core-REST collection (paginated).
 *
 * @return list<array<string,mixed>>
 */
$ndiziGetAll = static function (string $restBase) use ($siteUrl, $ndiziAuth, $timeout): array {
    $items = [];
    $page = 1;
    $perPage = 100;
    do {
        $url = $siteUrl . "/wp-json/wp/v2/$restBase?" . http_build_query([
            'per_page' => $perPage,
            'page'     => $page,
            'status'   => 'publish,draft',
            'context'  => 'edit', // needed to read `meta` on protected keys
        ]);
        $json = httpGetJson($url, ['Authorization: ' . $ndiziAuth, 'Accept: application/json'], $timeout);
        foreach ($json as $item) {
            if (is_array($item)) {
                $items[] = $item;
            }
        }
        $count = count($json);
        $page++;
    } while ($count === $perPage && $page <= 200);
    return $items;
};

/**
 * Creates a Ndizi post via core REST. Returns the new post ID.
 *
 * @param array<string,mixed> $payload
 * @throws RuntimeException On a non-2xx response.
 */
$ndiziCreate = static function (string $restBase, array $payload) use ($siteUrl, $ndiziAuth, $timeout): int {
    $result = httpRequestJson(
        'POST',
        $siteUrl . "/wp-json/wp/v2/$restBase",
        [
            'Authorization: ' . $ndiziAuth,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        (string)json_encode($payload),
        $timeout
    );
    if ($result['status'] < 200 || $result['status'] >= 300) {
        $msg = $result['body']['message'] ?? json_encode($result['body']);
        throw new RuntimeException("Ndizi create $restBase failed (HTTP {$result['status']}): $msg");
    }
    return (int)($result['body']['id'] ?? 0);
};

/**
 * Updates an existing Ndizi post via core REST (POST to /wp/v2/<base>/<id>).
 *
 * @param array<string,mixed> $payload
 * @throws RuntimeException On a non-2xx response.
 */
$ndiziUpdate = static function (string $restBase, int $id, array $payload) use ($siteUrl, $ndiziAuth, $timeout): void {
    $result = httpRequestJson(
        'POST',
        $siteUrl . "/wp-json/wp/v2/$restBase/$id",
        [
            'Authorization: ' . $ndiziAuth,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        (string)json_encode($payload),
        $timeout
    );
    if ($result['status'] < 200 || $result['status'] >= 300) {
        $msg = $result['body']['message'] ?? json_encode($result['body']);
        throw new RuntimeException("Ndizi update $restBase/$id failed (HTTP {$result['status']}): $msg");
    }
};

$nameKey = static fn(string $s): string => strtolower(trim(html_entity_decode($s, ENT_QUOTES)));
$titleOf = static fn(array $item): string => (string)($item['title']['rendered'] ?? $item['title']['raw'] ?? '');

// ---- Load existing Ndizi state --------------------------------------------

echo "Loading existing Ndizi clients and invoices...\n";
$existingClients  = $ndiziGetAll('ndizi_client');
$existingInvoices = $ndiziGetAll('ndizi_invoice');

$clientIdByName = [];
foreach ($existingClients as $c) {
    $clientIdByName[$nameKey($titleOf($c))] = (int)($c['id'] ?? 0);
}

// Idempotency: dedupe on the provenance meta (_ndizi_external_source/_id), which the
// plugin now exposes over REST. A FreshBooks invoice already migrated carries
// source=freshbooks and external_id=<invoice number>.
$migratedInvoiceNumbers = [];
$existingInvoiceByExtId = []; // external_id => ['id' => int, 'has_payments' => bool]
foreach ($existingInvoices as $inv) {
    $meta = $inv['meta'] ?? [];
    if (($meta['_ndizi_external_source'] ?? '') === 'freshbooks') {
        $extId = (string)($meta['_ndizi_external_id'] ?? '');
        if ($extId !== '') {
            $migratedInvoiceNumbers[$extId] = true;
            $existingInvoiceByExtId[$extId] = [
                'id'           => (int)($inv['id'] ?? 0),
                'has_payments' => is_array($meta['_ndizi_invoice_payments'] ?? null)
                    && count($meta['_ndizi_invoice_payments']) > 0,
            ];
        }
    }
}

echo '  ' . count($existingClients) . " clients, " . count($existingInvoices) . " invoices ("
    . count($migratedInvoiceNumbers) . " already migrated from FreshBooks)\n\n";

// ---- Load FreshBooks data (CSV or API) -------------------------------------
// Both sources are normalized to: clients carry a canonical `name`; invoices carry
// a resolved `client_name`. Downstream logic keys purely on those.

$fbClients = [];
$fbInvoices = [];

if ($csvMode) {
    echo "Reading FreshBooks CSV export(s)...\n";
    try {
        if ($clientsCsv !== '') {
            $fbClients = freshbooksParseClientsCsv($clientsCsv);
        }
        $fbInvoices = freshbooksParseInvoicesCsv($invoicesCsv);
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
        exit(1);
    }
} else {
    echo "Fetching FreshBooks clients and invoices via API...\n";
    try {
        $apiClients  = freshbooksFetchClients($fb, $timeout);
        $apiInvoices = freshbooksFetchInvoices($fb, $timeout);
    } catch (RuntimeException $e) {
        fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
        exit(1);
    }
    // Normalize: attach a canonical name to clients, resolve client_name on invoices.
    $byId = [];
    foreach ($apiClients as $c) {
        $c['name'] = freshbooksClientDisplayName($c);
        $byId[$c['id']] = $c;
        $fbClients[] = $c;
    }
    foreach ($apiInvoices as $inv) {
        $client = $byId[$inv['customerid']] ?? null;
        $inv['client_name'] = $client ? $client['name'] : ('FreshBooks client ' . $inv['customerid']);
        $fbInvoices[] = $inv;
    }
}

echo '  ' . count($fbClients) . " clients, " . count($fbInvoices) . " invoices\n\n";

// Build a client-details map keyed by canonical name (for address/website meta).
$fbClientByName = [];
foreach ($fbClients as $c) {
    $fbClientByName[$nameKey($c['name'])] = $c;
}

// ---- Backfill payments onto already-migrated invoices ----------------------
// Matches each source invoice to the existing Ndizi invoice by external_id and
// adds its payment record. Does not create anything; leaves unpaid invoices and
// invoices that already have payments untouched.

if ($backfill) {
    $bf = ['updated' => 0, 'skipped_existing' => 0, 'skipped_unpaid' => 0, 'missing' => 0];
    $prefix = $apply ? '' : 'WOULD ';
    $processed = 0;

    foreach ($fbInvoices as $inv) {
        if ($limit > 0 && $processed >= $limit) {
            break;
        }
        $number   = (string)$inv['invoice_number'];
        $payments = fbPayments($inv);
        if ($payments === []) {
            $bf['skipped_unpaid']++;
            continue;
        }
        $existing = $existingInvoiceByExtId[$number] ?? null;
        if ($existing === null) {
            $bf['missing']++;
            fwrite(STDERR, "  warning: FreshBooks #$number has a payment but no migrated invoice in Ndizi\n");
            continue;
        }
        if ($existing['has_payments']) {
            $bf['skipped_existing']++;
            continue;
        }

        $processed++;
        if ($apply) {
            try {
                $ndiziUpdate('ndizi_invoice', $existing['id'], [
                    'meta' => ['_ndizi_invoice_payments' => $payments],
                ]);
            } catch (RuntimeException $e) {
                fwrite(STDERR, "  error updating invoice #{$existing['id']}: " . $e->getMessage() . "\n");
                continue;
            }
        }
        $bf['updated']++;
        printf(
            "  %sadd payment: FreshBooks #%s -> invoice #%d  (%.2f paid %s)\n",
            $prefix,
            $number,
            $existing['id'],
            $payments[0]['amount'],
            $payments[0]['date']
        );
    }

    echo "\nSummary [$mode] backfill-payments:\n";
    printf("  payments added   : %d\n", $bf['updated']);
    printf("  already had one  : %d\n", $bf['skipped_existing']);
    printf("  unpaid (skipped) : %d\n", $bf['skipped_unpaid']);
    if ($bf['missing'] > 0) {
        printf("  no matching invoice: %d (see warnings)\n", $bf['missing']);
    }
    if (!$apply) {
        echo "\nThis was a dry-run. Re-run with --apply to write to Ndizi.\n";
    }
    return;
}

// ---- Migrate ---------------------------------------------------------------

$stats = [
    'clients_created'  => 0,
    'clients_matched'  => 0,
    'invoices_created' => 0,
    'invoices_skipped' => 0,
];
$plannedClients = [];  // nameKey => true (dry-run bookkeeping)
$matchedClients = [];  // nameKey => true (dedupe "matched" counts to distinct)

$prefix = $apply ? '' : 'WOULD ';
$processed = 0;

foreach ($fbInvoices as $inv) {
    if ($limit > 0 && $processed >= $limit) {
        break;
    }

    $number = $inv['invoice_number'];
    if ($number !== '' && isset($migratedInvoiceNumbers[$number])) {
        $stats['invoices_skipped']++;
        continue;
    }

    $processed++;

    $clientName = (string)($inv['client_name'] ?? '');
    if ($clientName === '') {
        $clientName = 'Unknown FreshBooks client';
    }
    $ck = $nameKey($clientName);
    $fbClient = $fbClientByName[$ck] ?? null;

    // --- find-or-create client (matched by name) ---
    $clientId = $clientIdByName[$ck] ?? 0;
    if ($clientId > 0) {
        if (!isset($matchedClients[$ck])) {
            $matchedClients[$ck] = true;
            $stats['clients_matched']++;
        }
    } elseif (isset($plannedClients[$ck])) {
        // already planned/created this run
    } else {
        if ($apply) {
            $clientExtId = (string)($fbClient['id'] ?? '');
            $meta = [
                '_ndizi_client_status'   => 'active',
                '_ndizi_external_source' => 'freshbooks',
                '_ndizi_external_id'     => $clientExtId !== '' ? $clientExtId : $clientName,
            ];
            if ($fbClient && $fbClient['address'] !== '') {
                $meta['_ndizi_client_address'] = $fbClient['address'];
            }
            if ($fbClient && $fbClient['website'] !== '') {
                $meta['_ndizi_client_website'] = $fbClient['website'];
            }
            $clientId = $ndiziCreate('ndizi_client', [
                'title'  => $clientName,
                'status' => 'publish',
                'meta'   => $meta,
            ]);
            $clientIdByName[$ck] = $clientId;
        }
        $plannedClients[$ck] = true;
        $stats['clients_created']++;
        echo "  {$prefix}create client: $clientName\n";
    }

    // --- create invoice (attached directly to the client) ---
    $title = 'FreshBooks #' . ($number !== '' ? $number : ('x' . substr(md5($clientName . $inv['date']), 0, 8)));
    $status = freshbooksMapStatus($inv['v3_status']);
    $invoiceMeta = [
        '_ndizi_client_id'         => $clientId,
        '_ndizi_invoice_number'    => $number,
        '_ndizi_invoice_currency'  => $inv['currency'] !== '' ? $inv['currency'] : 'USD',
        '_ndizi_invoice_date'      => $inv['date'],
        '_ndizi_invoice_due_date'  => $inv['due_date'],
        '_ndizi_invoice_amount'    => $inv['amount'],
        '_ndizi_invoice_status'    => $status,
        '_ndizi_invoice_line_items' => fbLineItems($inv),
        '_ndizi_invoice_payments'  => fbPayments($inv),
        '_ndizi_external_source'   => 'freshbooks',
        '_ndizi_external_id'       => $number !== '' ? $number : $title,
    ];

    if ($apply) {
        try {
            $ndiziCreate('ndizi_invoice', [
                'title'  => $title,
                'status' => 'publish',
                'meta'   => $invoiceMeta,
            ]);
        } catch (RuntimeException $e) {
            fwrite(STDERR, "  error creating invoice $title: " . $e->getMessage() . "\n");
            continue;
        }
    }
    if ($number !== '') {
        $migratedInvoiceNumbers[$number] = true;
    }
    $stats['invoices_created']++;
    printf(
        "  %screate invoice: %s  (%s %.2f, %s, %d line item%s) -> client %s\n",
        $prefix,
        $title,
        $invoiceMeta['_ndizi_invoice_currency'],
        $inv['amount'],
        $status,
        count($invoiceMeta['_ndizi_invoice_line_items']),
        count($invoiceMeta['_ndizi_invoice_line_items']) === 1 ? '' : 's',
        $apply ? '#' . $clientId : $clientName
    );
}

// ---- Summary ---------------------------------------------------------------

echo "\nSummary [$mode]:\n";
printf("  clients : %d created, %d matched\n", $stats['clients_created'], $stats['clients_matched']);
printf("  invoices: %d created, %d skipped (already migrated)\n", $stats['invoices_created'], $stats['invoices_skipped']);
if (!$apply) {
    echo "\nThis was a dry-run. Re-run with --apply to write to Ndizi.\n";
}

/**
 * Maps a FreshBooks invoice's normalized lines to Ndizi's structured
 * _ndizi_invoice_line_items shape: {description, quantity, unit_price, amount}.
 * FreshBooks separates item name and description; Ndizi has a single description
 * field, so the two are combined.
 *
 * @param array<string,mixed> $inv
 * @return list<array{description:string, quantity:float, unit_price:float, amount:float}>
 */
function fbLineItems(array $inv): array
{
    $items = [];
    foreach ($inv['lines'] as $line) {
        $name = trim((string)($line['name'] ?? ''));
        $desc = trim((string)($line['description'] ?? ''));
        $description = $name;
        if ($desc !== '') {
            $description .= ($description !== '' ? ' — ' : '') . $desc;
        }
        $items[] = [
            'description' => $description,
            'quantity'    => (float)($line['qty'] ?? 0),
            'unit_price'  => (float)($line['unit_cost'] ?? 0),
            'amount'      => (float)($line['amount'] ?? 0),
        ];
    }
    return $items;
}
