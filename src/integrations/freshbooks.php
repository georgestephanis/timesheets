<?php
// FreshBooks integration for timesheets
// One-time migration source: OAuth2 client + accounting API readers.
//
// Unlike the other integrations, FreshBooks is not wired into loadIntegrationActivity();
// it exists to feed tools/freshbooks-to-ndizi.php, which migrates legacy invoices into
// the Ndizi Project Management system. See src/integrations/shared.php for httpRequestJson().

const FRESHBOOKS_AUTH_BASE = 'https://auth.freshbooks.com';
const FRESHBOOKS_API_BASE  = 'https://api.freshbooks.com';

/**
 * Builds the OAuth2 authorization URL a user opens in a browser to grant access.
 *
 * @param array $conn FreshBooks connection config (needs client_id, redirect_uri).
 * @return string Fully-formed authorize URL.
 */
function freshbooksAuthorizeUrl(array $conn): string
{
    $params = http_build_query([
        'response_type' => 'code',
        'redirect_uri'  => (string)($conn['redirect_uri'] ?? ''),
        'client_id'     => (string)($conn['client_id'] ?? ''),
    ]);
    return FRESHBOOKS_AUTH_BASE . '/oauth/authorize/?' . $params;
}

/**
 * Performs an OAuth2 token exchange against FreshBooks and normalizes the result.
 *
 * Handles both the authorization_code and refresh_token grants. Refresh tokens are
 * one-time-use and rotate on every call, so the returned refresh_token MUST be
 * persisted by the caller or the next request will fail.
 *
 * @param array               $conn    FreshBooks connection config.
 * @param array<string,mixed> $grant   Grant-specific params (grant_type + code|refresh_token).
 * @param int                 $timeout HTTP timeout in seconds.
 * @return array{access_token:string, refresh_token:string, expires_at:int}
 * @throws RuntimeException On a non-2xx response or a missing access token.
 */
function freshbooksTokenExchange(array $conn, array $grant, int $timeout = 20): array
{
    $payload = array_merge(
        [
            'client_id'     => (string)($conn['client_id'] ?? ''),
            'client_secret' => (string)($conn['client_secret'] ?? ''),
            'redirect_uri'  => (string)($conn['redirect_uri'] ?? ''),
        ],
        $grant
    );

    $result = httpRequestJson(
        'POST',
        FRESHBOOKS_API_BASE . '/auth/oauth/token',
        [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        (string)json_encode($payload),
        $timeout
    );

    $status = $result['status'];
    $body   = $result['body'];

    if ($status < 200 || $status >= 300) {
        $msg = $body['error_description'] ?? $body['error'] ?? json_encode($body);
        throw new RuntimeException("FreshBooks token exchange failed (HTTP $status): $msg");
    }

    $accessToken = (string)($body['access_token'] ?? '');
    if ($accessToken === '') {
        throw new RuntimeException('FreshBooks token exchange returned no access_token');
    }

    // FreshBooks reports expiry as either created_at + expires_in (seconds) or omits it.
    $createdAt = isset($body['created_at']) ? (int)$body['created_at'] : time();
    $expiresIn = isset($body['expires_in']) ? (int)$body['expires_in'] : 3600 * 12;

    return [
        'access_token'  => $accessToken,
        'refresh_token' => (string)($body['refresh_token'] ?? ''),
        'expires_at'    => $createdAt + $expiresIn,
    ];
}

/**
 * Exchanges an authorization code (from the browser redirect) for tokens.
 *
 * @param array  $conn    FreshBooks connection config.
 * @param string $code    The `code` query param captured after the user authorizes.
 * @param int    $timeout HTTP timeout in seconds.
 * @return array{access_token:string, refresh_token:string, expires_at:int}
 */
function freshbooksExchangeCode(array $conn, string $code, int $timeout = 20): array
{
    return freshbooksTokenExchange($conn, [
        'grant_type' => 'authorization_code',
        'code'       => $code,
    ], $timeout);
}

/**
 * Refreshes the access token using the stored (rotating) refresh token.
 *
 * @param array $conn    FreshBooks connection config (needs refresh_token).
 * @param int   $timeout HTTP timeout in seconds.
 * @return array{access_token:string, refresh_token:string, expires_at:int}
 */
function freshbooksRefreshAccessToken(array $conn, int $timeout = 20): array
{
    $refreshToken = (string)($conn['refresh_token'] ?? '');
    if ($refreshToken === '') {
        throw new RuntimeException('FreshBooks connection has no refresh_token; run tools/freshbooks-auth.php first');
    }

    return freshbooksTokenExchange($conn, [
        'grant_type'    => 'refresh_token',
        'refresh_token' => $refreshToken,
    ], $timeout);
}

/**
 * Returns the standard authenticated request headers for accounting API calls.
 *
 * @param array $conn FreshBooks connection config (needs access_token).
 * @return array<int,string>
 */
function freshbooksAuthHeaders(array $conn): array
{
    return [
        'Authorization: Bearer ' . (string)($conn['access_token'] ?? ''),
        'Api-Version: alpha',
        'Content-Type: application/json',
        'Accept: application/json',
    ];
}

/**
 * Resolves the FreshBooks account_id (used in accounting API paths) via /users/me.
 *
 * When a business has multiple memberships, the first with an accounting-system
 * account_id is returned.
 *
 * @param array $conn    FreshBooks connection config (needs access_token).
 * @param int   $timeout HTTP timeout in seconds.
 * @return ?string account_id on success, null on failure.
 */
function freshbooksResolveAccountId(array $conn, int $timeout = 20): ?string
{
    try {
        $result = httpRequestJson(
            'GET',
            FRESHBOOKS_API_BASE . '/auth/api/v1/users/me',
            freshbooksAuthHeaders($conn),
            null,
            $timeout
        );
    } catch (RuntimeException) {
        return null;
    }

    if ($result['status'] < 200 || $result['status'] >= 300) {
        return null;
    }

    $memberships = $result['body']['response']['business_memberships'] ?? [];
    if (!is_array($memberships)) {
        return null;
    }

    foreach ($memberships as $membership) {
        $accountId = $membership['business']['account_id'] ?? null;
        if (is_string($accountId) && $accountId !== '') {
            return $accountId;
        }
    }

    return null;
}

/**
 * Fetches all FreshBooks clients for the configured account, normalized.
 *
 * @param array $conn    FreshBooks connection config (needs access_token, account_id).
 * @param int   $timeout HTTP timeout in seconds.
 * @return list<array{id:int, organization:string, fname:string, lname:string, email:string, address:string, website:string}>
 * @throws RuntimeException On a non-2xx response.
 */
function freshbooksFetchClients(array $conn, int $timeout = 20): array
{
    $accountId = (string)($conn['account_id'] ?? '');
    if ($accountId === '') {
        throw new RuntimeException('FreshBooks connection has no account_id; run tools/freshbooks-auth.php first');
    }

    $headers = freshbooksAuthHeaders($conn);
    $clients = [];
    $page = 1;
    $maxPages = 200;

    do {
        $url = FRESHBOOKS_API_BASE . "/accounting/account/$accountId/users/clients?" . http_build_query([
            'page'     => $page,
            'per_page' => 100,
        ]);

        $result = httpRequestJson('GET', $url, $headers, null, $timeout);
        if ($result['status'] < 200 || $result['status'] >= 300) {
            $msg = $result['body']['message'] ?? json_encode($result['body']);
            throw new RuntimeException("FreshBooks clients fetch failed (HTTP {$result['status']}): $msg");
        }

        $clientResult = $result['body']['response']['result'] ?? [];
        $rows = $clientResult['clients'] ?? [];
        $pages = (int)($clientResult['pages'] ?? 1);

        foreach ($rows as $c) {
            if (!is_array($c)) {
                continue;
            }
            $addressParts = array_filter([
                trim((string)($c['p_street'] ?? '')),
                trim((string)($c['p_street2'] ?? '')),
                trim(implode(' ', array_filter([
                    (string)($c['p_city'] ?? ''),
                    (string)($c['p_province'] ?? ''),
                    (string)($c['p_code'] ?? ''),
                ]))),
                trim((string)($c['p_country'] ?? '')),
            ], static fn($p) => $p !== '');

            $clients[] = [
                'id'           => (int)($c['id'] ?? $c['userid'] ?? 0),
                'organization' => trim((string)($c['organization'] ?? '')),
                'fname'        => trim((string)($c['fname'] ?? '')),
                'lname'        => trim((string)($c['lname'] ?? '')),
                'email'        => trim((string)($c['email'] ?? '')),
                'address'      => implode("\n", $addressParts),
                // The FreshBooks accounting client object has no website field; kept for
                // symmetry with Ndizi's _ndizi_client_website meta (always empty here).
                'website'      => '',
            ];
        }

        $page++;
    } while ($page <= $pages && $page <= $maxPages);

    return $clients;
}

/**
 * Fetches all FreshBooks invoices for the configured account, normalized, with line items.
 *
 * @param array $conn    FreshBooks connection config (needs access_token, account_id).
 * @param int   $timeout HTTP timeout in seconds.
 * @return list<array{invoice_number:string, customerid:int, amount:float, currency:string,
 *                    date:string, due_date:string, v3_status:string, lines:list<array<string,mixed>>}>
 * @throws RuntimeException On a non-2xx response.
 */
function freshbooksFetchInvoices(array $conn, int $timeout = 20): array
{
    $accountId = (string)($conn['account_id'] ?? '');
    if ($accountId === '') {
        throw new RuntimeException('FreshBooks connection has no account_id; run tools/freshbooks-auth.php first');
    }

    $headers = freshbooksAuthHeaders($conn);
    $invoices = [];
    $page = 1;
    $maxPages = 500;

    do {
        $url = FRESHBOOKS_API_BASE . "/accounting/account/$accountId/invoices/invoices?" . http_build_query([
            'page'       => $page,
            'per_page'   => 100,
            'include[]'  => 'lines',
        ]);

        $result = httpRequestJson('GET', $url, $headers, null, $timeout);
        if ($result['status'] < 200 || $result['status'] >= 300) {
            $msg = $result['body']['message'] ?? json_encode($result['body']);
            throw new RuntimeException("FreshBooks invoices fetch failed (HTTP {$result['status']}): $msg");
        }

        $invoiceResult = $result['body']['response']['result'] ?? [];
        $rows = $invoiceResult['invoices'] ?? [];
        $pages = (int)($invoiceResult['pages'] ?? 1);

        foreach ($rows as $inv) {
            if (!is_array($inv)) {
                continue;
            }

            $lines = [];
            foreach (($inv['lines'] ?? []) as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $lines[] = [
                    'name'        => trim((string)($line['name'] ?? '')),
                    'description' => trim((string)($line['description'] ?? '')),
                    'qty'         => (float)($line['qty'] ?? 0),
                    'unit_cost'   => (float)($line['unit_cost']['amount'] ?? 0),
                    'amount'      => (float)($line['amount']['amount'] ?? 0),
                ];
            }

            $invoices[] = [
                'invoice_number' => trim((string)($inv['invoice_number'] ?? (string)($inv['id'] ?? ''))),
                'customerid'     => (int)($inv['customerid'] ?? 0),
                'amount'         => (float)($inv['amount']['amount'] ?? 0),
                'currency'       => trim((string)($inv['currency_code'] ?? '')),
                'date'           => trim((string)($inv['create_date'] ?? '')),
                'due_date'       => trim((string)($inv['due_date'] ?? '')),
                'date_paid'      => trim((string)($inv['date_paid'] ?? '')),
                'v3_status'      => trim((string)($inv['v3_status'] ?? '')),
                'lines'          => $lines,
            ];
        }

        $page++;
    } while ($page <= $pages && $page <= $maxPages);

    return $invoices;
}

/**
 * Returns the canonical display name for a FreshBooks client: the organization if
 * set, otherwise "First Last", otherwise a synthetic id-based fallback.
 *
 * @param array<string,mixed> $client Normalized client (from API or CSV).
 */
function freshbooksClientDisplayName(array $client): string
{
    $org = trim((string)($client['organization'] ?? ''));
    if ($org !== '') {
        return $org;
    }
    $person = trim(((string)($client['fname'] ?? '')) . ' ' . ((string)($client['lname'] ?? '')));
    if ($person !== '') {
        return $person;
    }
    $id = (string)($client['id'] ?? '');
    return $id !== '' ? "FreshBooks client $id" : 'FreshBooks client';
}

/**
 * Reads a CSV file into a list of header-keyed associative rows.
 *
 * Uses fgetcsv so quoted fields containing commas (e.g. "OneGuide, LLC") parse
 * correctly. A UTF-8 BOM on the first header cell, if present, is stripped.
 *
 * @return list<array<string,string>>
 * @throws RuntimeException When the file cannot be opened.
 */
function freshbooksReadCsv(string $path): array
{
    $handle = @fopen($path, 'r');
    if ($handle === false) {
        throw new RuntimeException("Cannot open CSV file: $path");
    }

    $rows = [];
    $header = null;
    while (($fields = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        if ($header === null) {
            $fields[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$fields[0]);
            $header = array_map(static fn($h) => trim((string)$h), $fields);
            continue;
        }
        // Skip fully-blank lines.
        if (count($fields) === 1 && trim((string)$fields[0]) === '') {
            continue;
        }
        $row = [];
        foreach ($header as $i => $col) {
            $row[$col] = isset($fields[$i]) ? trim((string)$fields[$i]) : '';
        }
        $rows[] = $row;
    }
    fclose($handle);

    return $rows;
}

/**
 * Parses a FreshBooks "Clients" CSV export into normalized client records.
 *
 * Expected columns: Organization, First Name, Last Name, Email, Phone,
 * Address Line 1, Address Line 2, City, Province/State, Country, Postal Code, Notes.
 *
 * @return list<array{name:string, organization:string, fname:string, lname:string, email:string, address:string, website:string}>
 */
function freshbooksParseClientsCsv(string $path): array
{
    $clients = [];
    foreach (freshbooksReadCsv($path) as $row) {
        $addressParts = array_filter([
            $row['Address Line 1'] ?? '',
            $row['Address Line 2'] ?? '',
            trim(implode(' ', array_filter([
                $row['City'] ?? '',
                $row['Province/State'] ?? '',
                $row['Postal Code'] ?? '',
            ], static fn($p) => trim((string)$p) !== ''))),
            $row['Country'] ?? '',
        ], static fn($p) => trim((string)$p) !== '');

        $client = [
            'organization' => (string)($row['Organization'] ?? ''),
            'fname'        => (string)($row['First Name'] ?? ''),
            'lname'        => (string)($row['Last Name'] ?? ''),
            'email'        => (string)($row['Email'] ?? ''),
            'address'      => implode("\n", $addressParts),
            'website'      => '',
        ];
        $client['name'] = freshbooksClientDisplayName($client);
        $clients[] = $client;
    }

    return $clients;
}

/**
 * Parses a FreshBooks "Invoices" CSV export (one row per line item) into normalized
 * invoices, grouping line rows by invoice number and summing line totals.
 *
 * Expected columns: Client Name, Invoice #, Date Issued, Date Due, Invoice Status,
 * Date Paid, Item Name, Item Description, Rate, Quantity, Discount Percentage,
 * Line Subtotal, Tax 1..., Line Total, Currency.
 *
 * @return list<array{invoice_number:string, client_name:string, amount:float, currency:string,
 *                    date:string, due_date:string, date_paid:string, v3_status:string, lines:list<array<string,mixed>>}>
 */
function freshbooksParseInvoicesCsv(string $path): array
{
    $byNumber = [];
    $order = [];
    foreach (freshbooksReadCsv($path) as $row) {
        $number = (string)($row['Invoice #'] ?? '');
        if ($number === '') {
            continue;
        }
        if (!isset($byNumber[$number])) {
            $byNumber[$number] = [
                'invoice_number' => $number,
                'client_name'    => (string)($row['Client Name'] ?? ''),
                'amount'         => 0.0,
                'currency'       => (string)($row['Currency'] ?? ''),
                'date'           => (string)($row['Date Issued'] ?? ''),
                'due_date'       => (string)($row['Date Due'] ?? ''),
                'date_paid'      => (string)($row['Date Paid'] ?? ''),
                'v3_status'      => (string)($row['Invoice Status'] ?? ''),
                'lines'          => [],
            ];
            $order[] = $number;
        }

        $lineTotal = (float)($row['Line Total'] ?? 0);
        $byNumber[$number]['amount'] += $lineTotal;
        $byNumber[$number]['lines'][] = [
            'name'        => (string)($row['Item Name'] ?? ''),
            'description' => (string)($row['Item Description'] ?? ''),
            'qty'         => (float)($row['Quantity'] ?? 0),
            'unit_cost'   => (float)($row['Rate'] ?? 0),
            'amount'      => $lineTotal,
        ];
    }

    // Preserve first-seen order.
    return array_map(static fn(string $n) => $byNumber[$n], $order);
}

/**
 * Maps a FreshBooks v3 invoice status to a Ndizi invoice status.
 *
 * Ndizi statuses: draft, sent, paid, void. FreshBooks outstanding states
 * (sent/viewed/partial/overdue) all collapse to "sent".
 *
 * @param string $v3Status FreshBooks v3_status value.
 * @return string One of: draft, sent, paid, void.
 */
function freshbooksMapStatus(string $v3Status): string
{
    return match (strtolower($v3Status)) {
        'paid', 'resolved'    => 'paid',
        'draft'               => 'draft',
        'disputed', 'declined' => 'void',
        default               => 'sent',
    };
}
