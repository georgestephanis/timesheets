<?php

declare(strict_types=1);

/**
 * ClickUp name catalog: fetches all space/folder/list names for one connection.
 *
 * Used by tools/sync-integration-projects.php and tools/set-integration-groupings.php
 * as the single source of truth for ClickUp name discovery.
 */

/**
 * Returns all space, folder, and list names reachable via a single ClickUp connection.
 *
 * Walks: team → spaces → (folders → lists) + (folderless lists per space).
 * Errors from individual API calls are swallowed; unreachable sub-trees yield no names.
 *
 * @param  array<string, mixed> $conn  One entry from config.integrations.clickup.
 * @return array<string, true>         Map of name => true.
 */
function clickupFetchAllNames(array $conn, int $timeout = 20): array
{
    $token    = (string)($conn['token'] ?? '');
    $rawTeams = $conn['team_id'] ?? [];
    $teamIds  = is_array($rawTeams) ? $rawTeams : [$rawTeams];
    $teamIds  = array_values(array_filter(array_map('strval', $teamIds), static fn($v) => $v !== ''));
    if ($token === '' || $teamIds === []) {
        return [];
    }

    $headers = [
        'Authorization: ' . $token,
        'Accept: application/json',
    ];

    $names = [];

    foreach ($teamIds as $teamId) {
        try {
            $spaces = httpGetJson(
                'https://api.clickup.com/api/v2/team/' . rawurlencode($teamId) . '/space?archived=false',
                $headers,
                $timeout
            );
        } catch (RuntimeException $e) {
            continue;
        }

        foreach (($spaces['spaces'] ?? []) as $space) {
            if (!is_array($space)) {
                continue;
            }
            $spaceId   = (string)($space['id'] ?? '');
            $spaceName = trim((string)($space['name'] ?? ''));
            if ($spaceName !== '') {
                $names[$spaceName] = true;
            }
            if ($spaceId === '') {
                continue;
            }

            try {
                $folders = httpGetJson(
                    'https://api.clickup.com/api/v2/space/' . rawurlencode($spaceId) . '/folder?archived=false',
                    $headers,
                    $timeout
                );
            } catch (RuntimeException $e) {
                $folders = ['folders' => []];
            }

            foreach (($folders['folders'] ?? []) as $folder) {
                if (!is_array($folder)) {
                    continue;
                }
                $folderId   = (string)($folder['id'] ?? '');
                $folderName = trim((string)($folder['name'] ?? ''));
                if ($folderName !== '') {
                    $names[$folderName] = true;
                }
                if ($folderId === '') {
                    continue;
                }

                try {
                    $lists = httpGetJson(
                        'https://api.clickup.com/api/v2/folder/' . rawurlencode($folderId) . '/list?archived=false',
                        $headers,
                        $timeout
                    );
                } catch (RuntimeException $e) {
                    $lists = ['lists' => []];
                }

                foreach (($lists['lists'] ?? []) as $list) {
                    if (!is_array($list)) {
                        continue;
                    }
                    $listName = trim((string)($list['name'] ?? ''));
                    if ($listName !== '') {
                        $names[$listName] = true;
                    }
                }
            }

            try {
                $spaceLists = httpGetJson(
                    'https://api.clickup.com/api/v2/space/' . rawurlencode($spaceId) . '/list?archived=false',
                    $headers,
                    $timeout
                );
            } catch (RuntimeException $e) {
                $spaceLists = ['lists' => []];
            }

            foreach (($spaceLists['lists'] ?? []) as $list) {
                if (!is_array($list)) {
                    continue;
                }
                $listName = trim((string)($list['name'] ?? ''));
                if ($listName !== '') {
                    $names[$listName] = true;
                }
            }
        }
    }

    return $names;
}
