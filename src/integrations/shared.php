<?php

declare(strict_types=1);

/**
 * True when an ID looks like a numeric account/user identifier.
 */
function idLooksStandard(mixed $value): bool
{
    if (is_int($value)) {
        return $value > 0;
    }

    if (is_string($value)) {
        return $value !== '' && preg_match('/^[0-9]+$/', $value) === 1;
    }

    return false;
}

/**
 * Performs an HTTP GET request and decodes JSON.
 *
 * @param  string              $url
 * @param  array<int, string>  $headers
 * @return array<string, mixed>
 */
function httpGetJson(string $url, array $headers, int $timeout = 20): array
{
    $ctx = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'ignore_errors' => true,
            'timeout' => $timeout,
        ],
    ]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        throw new RuntimeException("HTTP request failed: $url");
    }

    $status = 0;
    $line = $http_response_header[0] ?? '';
    if (preg_match('/\s(\d{3})\s/', $line, $m)) {
        $status = (int)$m[1];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Invalid JSON from $url — body: " . substr($raw, 0, 300));
    }
    if ($status < 200 || $status >= 300) {
        // ClickUp wraps errors as {"ECODE":"...","err":"..."}, Harvest uses {"error":"..."}
        $msg = $decoded['error'] ?? $decoded['err'] ?? null;
        if (!is_string($msg) || $msg === '') {
            // Fallback: dump the whole decoded body for context
            $msg = json_encode($decoded);
        }
        throw new RuntimeException("HTTP $status from $url: $msg");
    }

    return $decoded;
}
