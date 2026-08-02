<?php
// Ndizi catalog functions for timesheets

/**
 * Fetches all project names from a Ndizi site
 *
 * @param array $conn Ndizi connection config
 * @param int $timeout HTTP timeout in seconds
 * @return array List of project names
 */
function ndiziFetchProjectNames(array $conn, int $timeout = 20): array
{
    $siteUrl = rtrim((string)($conn['site_url'] ?? ''), '/');
    $username = (string)($conn['username'] ?? '');
    $appPassword = (string)($conn['app_password'] ?? '');

    if ($siteUrl === '' || $username === '' || $appPassword === '') {
        return [];
    }

    $headers = [
        'Authorization: Basic ' . base64_encode("$username:$appPassword"),
        'Accept: application/json',
    ];

    $projectNames = [];
    $page = 1;
    $pageSize = 50;
    $maxPages = 100; // Prevent infinite loops

    do {
        $url = $siteUrl . '/wp-json/ndizi/v1/projects?' .
               "page={$page}&per_page={$pageSize}";

        $json = httpGetJson($url, $headers, $timeout);

        // No need to check as httpGetJson always returns an array

        foreach ($json as $project) {
            $projectNames[] = (string)($project['title'] ?? $project['name'] ?? '');
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
