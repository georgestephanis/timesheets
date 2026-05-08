<?php

declare(strict_types=1);

/**
 * Harvest project catalog: fetches active project names for one connection.
 *
 * Used by tools/sync-integration-projects.php and tools/set-integration-groupings.php
 * as the single source of truth for Harvest project discovery.
 */

/**
 * Returns all active Harvest project names for a single connection.
 *
 * Tries GET /v2/projects first (returns active projects by page). Falls back to
 * scanning GET /v2/time_entries for the past year when the project endpoint is not
 * authorized. Errors from the API are swallowed; the returned set may be empty or
 * partial if the connection is misconfigured.
 *
 * @param  array<string, mixed> $conn  One entry from config.integrations.harvest.
 * @return array<string, true>         Map of project name => true.
 */
function harvestFetchProjectNames(array $conn): array
{
    $token     = (string)($conn['token']      ?? '');
    $accountId = (string)($conn['account_id'] ?? '');
    if ($token === '' || $accountId === '') {
        return [];
    }

    $headers = [
        'Authorization: Bearer ' . $token,
        'Harvest-Account-ID: ' . $accountId,
        'User-Agent: activity-report',
        'Accept: application/json',
    ];

    $names  = [];
    $page   = 1;
    $pages  = 1;
    $listed = false;

    do {
        try {
            $json = httpGetJson(
                'https://api.harvestapp.com/v2/projects?' . http_build_query([
                    'is_active' => 'true',
                    'page'      => (string)$page,
                ]),
                $headers
            );
        } catch (RuntimeException $e) {
            break;
        }

        foreach (($json['projects'] ?? []) as $p) {
            if (!is_array($p)) {
                continue;
            }
            $n = trim((string)($p['name'] ?? ''));
            if ($n !== '') {
                $names[$n] = true;
                $listed    = true;
            }
        }

        $pages = max(1, (int)($json['total_pages'] ?? 1));
        $page++;
    } while ($page <= $pages);

    if ($listed) {
        return $names;
    }

    // Fallback: scan time entries when /projects is not authorized.
    $page  = 1;
    $pages = 1;
    do {
        try {
            $json = httpGetJson(
                'https://api.harvestapp.com/v2/time_entries?' . http_build_query([
                    'from' => (new DateTimeImmutable('now -365 days', new DateTimeZone('UTC')))->format('Y-m-d'),
                    'to'   => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d'),
                    'page' => (string)$page,
                ]),
                $headers
            );
        } catch (RuntimeException $e) {
            break;
        }

        foreach (($json['time_entries'] ?? []) as $te) {
            if (!is_array($te)) {
                continue;
            }
            $n = trim((string)($te['project']['name'] ?? ''));
            if ($n !== '') {
                $names[$n] = true;
            }
        }

        $pages = max(1, (int)($json['total_pages'] ?? 1));
        $page++;
    } while ($page <= $pages);

    return $names;
}
