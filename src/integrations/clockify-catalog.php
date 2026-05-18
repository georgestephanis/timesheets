<?php
// Clockify catalog functions for timesheets

/**
 * Fetches all project names from a Clockify workspace
 *
 * @param array $conn Clockify connection config
 * @param int $timeout HTTP timeout in seconds
 * @return array List of project names
 */
function clockifyFetchProjectNames(array $conn, int $timeout = 20): array
{
    $apiKey = (string)($conn['api_key'] ?? '');
    $workspaceId = (string)($conn['workspace_id'] ?? '');

    if ($apiKey === '' || $workspaceId === '') {
        return [];
    }

    $projectNames = [];
    $page = 1;
    $pageSize = 50;
    $maxPages = 100; // Prevent infinite loops

    do {
        $url = "https://api.clockify.me/api/v1/workspaces/{$workspaceId}/projects?" .
               "page={$page}&pageSize={$pageSize}";

        $headers = [
            'X-Api-Key: ' . $apiKey,
            'Accept: application/json',
        ];

        $json = httpGetJson($url, $headers, $timeout);

    // No need to check as httpGetJson always returns an array

        foreach ($json as $project) {
            $projectNames[] = (string)($project['name'] ?? '');
        }

        $hasMorePages = count($json) === $pageSize;
        $page++;

        // Safety check to prevent infinite loops
        if ($page > $maxPages) {
            break;
        }
    } while ($hasMorePages);

    return $projectNames;
}
