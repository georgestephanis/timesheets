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
 * Performs an HTTP request with an optional body and decodes the JSON response.
 *
 * Unlike httpGetJson(), this does not throw on non-2xx responses — it returns the
 * decoded body alongside the status so callers can inspect API-specific error shapes
 * (e.g. OAuth token errors, WordPress REST {code, message}) and decide how to react.
 *
 * @param  string              $method  HTTP verb, e.g. 'GET', 'POST', 'DELETE'.
 * @param  string              $url
 * @param  array<int, string>  $headers Full header lines, e.g. 'Content-Type: application/json'.
 * @param  ?string             $body    Raw request body, or null for none.
 * @return array{status:int, body:array<mixed>}
 * @throws RuntimeException  On transport failure or an undecodable (non-JSON) body.
 */
function httpRequestJson(string $method, string $url, array $headers, ?string $body = null, int $timeout = 20): array
{
    $http = [
        'method'        => $method,
        'header'        => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout'       => $timeout,
    ];
    if ($body !== null) {
        $http['content'] = $body;
    }

    $ctx = stream_context_create(['http' => $http]);

    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        throw new RuntimeException("HTTP request failed: $method $url");
    }

    $status = 0;
    $line = $http_response_header[0] ?? '';
    if (preg_match('/\s(\d{3})\s/', $line, $m)) {
        $status = (int)$m[1];
    }

    // Treat an empty 2xx body (e.g. 204 No Content) as an empty JSON object.
    $decoded = ($raw === '') ? [] : json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("Invalid JSON from $method $url — body: " . substr($raw, 0, 300));
    }

    return ['status' => $status, 'body' => $decoded];
}

/**
 * Performs an HTTP GET request and decodes JSON, throwing on any non-2xx status.
 *
 * @param  string              $url
 * @param  array<int, string>  $headers
 * @return array<string, mixed>
 */
function httpGetJson(string $url, array $headers, int $timeout = 20): array
{
    $result = httpRequestJson('GET', $url, $headers, null, $timeout);
    $status = $result['status'];
    $decoded = $result['body'];

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
