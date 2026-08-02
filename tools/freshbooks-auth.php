<?php

declare(strict_types=1);

/**
 * One-time FreshBooks OAuth2 bootstrap.
 *
 * Prerequisite: config.json has an integrations.freshbooks[0] entry with at least
 * `name`, `client_id`, `client_secret`, and `redirect_uri` (register the app at
 * https://my.freshbooks.com/#/developer to obtain the first three; the redirect_uri
 * must be an HTTPS URL you control and must match the app's configured redirect).
 *
 * Run:  php tools/freshbooks-auth.php
 *
 * The script prints an authorize URL, waits for you to paste back the `code` from the
 * browser redirect, exchanges it for tokens, resolves the account_id, and saves
 * access_token / refresh_token / token_expires_at / account_id into config.json.
 */

const TOOL_ROOT = __DIR__ . '/..';

require_once TOOL_ROOT . '/src/integrations/shared.php';
require_once TOOL_ROOT . '/src/integrations/freshbooks.php';
require_once TOOL_ROOT . '/src/config.php';

$configPath = TOOL_ROOT . '/config.json';
$configRaw = file_get_contents($configPath);
$config = json_decode((string)$configRaw, true);
if (!is_array($config)) {
    fwrite(STDERR, "error: config.json is invalid JSON\n");
    exit(1);
}

$connections = $config['integrations']['freshbooks'] ?? [];
if (!is_array($connections) || $connections === [] || !is_array($connections[0])) {
    fwrite(STDERR, "error: no integrations.freshbooks[0] entry in config.json.\n");
    fwrite(STDERR, "  Add one with name, client_id, client_secret, redirect_uri first.\n");
    exit(1);
}

$conn = $connections[0];
$timeout = (int)($config['integration_http_timeout_seconds'] ?? 20);

foreach (['client_id', 'client_secret', 'redirect_uri'] as $required) {
    if (trim((string)($conn[$required] ?? '')) === '') {
        fwrite(STDERR, "error: integrations.freshbooks[0].$required is missing or empty.\n");
        exit(1);
    }
}

echo "Open this URL in your browser and approve access:\n\n";
echo '  ' . freshbooksAuthorizeUrl($conn) . "\n\n";
echo "After approving you'll be redirected to your redirect_uri with a `?code=...`\n";
echo "query parameter. Paste that code value here and press Enter:\n\n";
echo 'code> ';

$code = trim((string)fgets(STDIN));
// Tolerate a pasted full redirect URL: extract the code param if present.
if (str_contains($code, 'code=')) {
    parse_str((string)parse_url($code, PHP_URL_QUERY), $q);
    $code = (string)($q['code'] ?? $code);
}
if ($code === '') {
    fwrite(STDERR, "error: no code provided.\n");
    exit(1);
}

try {
    $tokens = freshbooksExchangeCode($conn, $code, $timeout);
} catch (RuntimeException $e) {
    fwrite(STDERR, 'error: ' . $e->getMessage() . "\n");
    exit(1);
}

$conn['access_token']     = $tokens['access_token'];
$conn['refresh_token']    = $tokens['refresh_token'];
$conn['token_expires_at'] = $tokens['expires_at'];

$accountId = freshbooksResolveAccountId($conn, $timeout);
if ($accountId === null) {
    fwrite(STDERR, "error: obtained tokens but could not resolve account_id from /users/me.\n");
    // Persist the tokens anyway so they aren't lost (refresh tokens are one-time-use).
} else {
    $conn['account_id'] = $accountId;
}

$config['integrations']['freshbooks'][0] = $conn;

try {
    $backup = saveConfigWithBackup($config, $configPath, 'freshbooks-auth');
} catch (RuntimeException $e) {
    fwrite(STDERR, 'error saving config: ' . $e->getMessage() . "\n");
    exit(1);
}

echo "\nSaved FreshBooks credentials to config.json\n";
echo "  account_id: " . ($accountId ?? '(unresolved — set manually)') . "\n";
echo "  token expires: " . date('c', $tokens['expires_at']) . "\n";
echo "  config backup: $backup\n";
echo "\nNext: php tools/freshbooks-to-ndizi.php --dry-run\n";

exit($accountId === null ? 1 : 0);
