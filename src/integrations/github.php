<?php

declare(strict_types=1);

/**
 * Loads GitHub activity (commits, PRs, issues, comments) for project repos.
 *
 * Coordinates five per-resource fetchers. Returns early if a web-request budget
 * deadline is reached (CLI has no deadline).
 *
 * @param  array             $conn   GitHub integration config.
 * @param  array             $config Full app config.
 * @param  DateTimeImmutable $from
 * @param  DateTimeImmutable $to
 * @return list<array<string, mixed>>
 */
function loadGitHubActivity(array $conn, array $config, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $deadline = PHP_SAPI === 'cli' ? null : microtime(true) + 8.0;
    $rows = [];
    $seen = [];

    $reposByProject = githubReposByProject($config);
    if ($reposByProject === []) {
        return [];
    }

    $authors = $conn['authors'] ?? ($config['git_authors'] ?? []);
    if (!is_array($authors) || $authors === []) {
        return [];
    }
    $authors = array_values(array_filter(array_map('strval', $authors), fn($a) => $a !== ''));
    if ($authors === []) {
        return [];
    }

    $ghCacheTtl  = (string)($config['github_cache_ttl'] ?? '1h');
    $ghCmdTimeout = (int)($config['github_command_timeout_seconds'] ?? 8);

    $actorLogins = githubActorLogins($conn, $authors);
    $actorLookup = array_fill_keys($actorLogins, true);
    $connection = (string)($conn['name'] ?? 'github');
    $fromIso = $from->setTimezone(new DateTimeZone('UTC'))->format('c');
    $toIso   = $to->setTimezone(new DateTimeZone('UTC'))->format('c');

    foreach ($reposByProject as $project => $repos) {
        foreach (array_keys($repos) as $repoFullName) {
            if (githubBudgetExceeded($deadline)) {
                return $rows;
            }
            githubFetchCommits(
                $repoFullName,
                $authors,
                $conn,
                $from,
                $to,
                $fromIso,
                $toIso,
                $connection,
                $project,
                $seen,
                $rows,
                $deadline,
                $ghCacheTtl,
                $ghCmdTimeout
            );
            if (githubBudgetExceeded($deadline)) {
                return $rows;
            }
            githubFetchPullRequests($repoFullName, $conn, $from, $to, $fromIso, $connection, $project, $actorLookup, $seen, $rows, $ghCacheTtl, $ghCmdTimeout);
            if (githubBudgetExceeded($deadline)) {
                return $rows;
            }
            githubFetchIssues($repoFullName, $conn, $from, $to, $fromIso, $connection, $project, $actorLookup, $seen, $rows, $ghCacheTtl, $ghCmdTimeout);
            if (githubBudgetExceeded($deadline)) {
                return $rows;
            }
            githubFetchIssueComments($repoFullName, $conn, $from, $to, $fromIso, $connection, $project, $actorLookup, $seen, $rows, $ghCacheTtl, $ghCmdTimeout);
            if (githubBudgetExceeded($deadline)) {
                return $rows;
            }
            githubFetchReviewComments(
                $repoFullName,
                $conn,
                $from,
                $to,
                $fromIso,
                $connection,
                $project,
                $actorLookup,
                $seen,
                $rows,
                $ghCacheTtl,
                $ghCmdTimeout
            );
        }
    }

    return $rows;
}

/**
 * Fetches commits for a single repo authored by any of the given email addresses.
 *
 * @param list<string>         $authors
 * @param array<string, bool>  $seen    Dedup set, modified in place.
 * @param list<array>          $rows    Result accumulator, modified in place.
 */
function githubFetchCommits(
    string $repoFullName,
    array $authors,
    array $conn,
    DateTimeImmutable $from,
    DateTimeImmutable $to,
    string $fromIso,
    string $toIso,
    string $connection,
    string $project,
    array &$seen,
    array &$rows,
    ?float $deadline,
    string $cacheTtl = '1h',
    int $cmdTimeout = 8
): void {
    foreach ($authors as $author) {
        if (githubBudgetExceeded($deadline)) {
            return;
        }
        $path = '/repos/' . $repoFullName . '/commits?' . http_build_query([
            'since'  => $fromIso,
            'until'  => $toIso,
            'author' => $author,
        ]);
        foreach (githubPaginatedGet($path, $conn, 1, $cacheTtl, $cmdTimeout) as $commit) {
            if (!is_array($commit)) {
                continue;
            }
            $sha = (string)($commit['sha'] ?? '');
            $key = 'commit:' . $repoFullName . '#' . $sha;
            if ($sha === '' || isset($seen[$key])) {
                continue;
            }
            $start = githubDateInRange((string)($commit['commit']['author']['date'] ?? ''), $from, $to);
            if ($start === null) {
                continue;
            }
            $seen[$key] = true;
            $message = trim((string)($commit['commit']['message'] ?? 'GitHub commit'));
            $rows[] = [
                'source' => 'github', 'connection' => $connection, 'project' => $project,
                'start' => $start, 'end' => $start, 'seconds' => 0,
                'project_hint' => $repoFullName,
                'label' => $repoFullName . ': ' . strtok($message, "\n"),
                'entry_count' => 1, 'activity_count' => 1, 'discussion_count' => 0,
            ];
        }
    }
}

/**
 * Fetches pull requests opened by the actor(s) in the given date range.
 *
 * @param array<string, bool>  $actorLookup Login set for attribution filtering.
 * @param array<string, bool>  $seen        Dedup set, modified in place.
 * @param list<array>          $rows        Result accumulator, modified in place.
 */
function githubFetchPullRequests(
    string $repoFullName,
    array $conn,
    DateTimeImmutable $from,
    DateTimeImmutable $to,
    string $fromIso,
    string $connection,
    string $project,
    array $actorLookup,
    array &$seen,
    array &$rows,
    string $cacheTtl = '1h',
    int $cmdTimeout = 8
): void {
    $path = '/repos/' . $repoFullName . '/pulls?' . http_build_query([
        'state' => 'all', 'sort' => 'updated', 'direction' => 'desc',
    ]);
    foreach (githubPaginatedGet($path, $conn, 1, $cacheTtl, $cmdTimeout) as $pr) {
        if (!is_array($pr)) {
            continue;
        }
        $login = strtolower((string)($pr['user']['login'] ?? ''));
        if ($login === '' || !isset($actorLookup[$login])) {
            continue;
        }
        $start = githubDateInRange((string)($pr['created_at'] ?? ''), $from, $to);
        if ($start === null) {
            continue;
        }
        $num = (string)($pr['number'] ?? '');
        $key = 'pr:' . $repoFullName . '#' . $num;
        if ($num === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $title = trim((string)($pr['title'] ?? 'Pull request'));
        $discussion = (int)($pr['comments'] ?? 0) + (int)($pr['review_comments'] ?? 0);
        $rows[] = [
            'source' => 'github', 'connection' => $connection, 'project' => $project,
            'start' => $start, 'end' => $start, 'seconds' => 0,
            'project_hint' => $repoFullName,
            'label' => $repoFullName . ' PR #' . $num . ': ' . $title,
            'entry_count' => 1, 'activity_count' => 1, 'discussion_count' => max(0, $discussion),
        ];
    }
}

/**
 * Fetches issues (excluding PRs) opened by the actor(s) in the given date range.
 *
 * @param array<string, bool>  $actorLookup Login set for attribution filtering.
 * @param array<string, bool>  $seen        Dedup set, modified in place.
 * @param list<array>          $rows        Result accumulator, modified in place.
 */
function githubFetchIssues(
    string $repoFullName,
    array $conn,
    DateTimeImmutable $from,
    DateTimeImmutable $to,
    string $fromIso,
    string $connection,
    string $project,
    array $actorLookup,
    array &$seen,
    array &$rows,
    string $cacheTtl = '1h',
    int $cmdTimeout = 8
): void {
    $path = '/repos/' . $repoFullName . '/issues?' . http_build_query([
        'state' => 'all', 'since' => $fromIso, 'sort' => 'updated', 'direction' => 'desc',
    ]);
    foreach (githubPaginatedGet($path, $conn, 1, $cacheTtl, $cmdTimeout) as $issue) {
        if (!is_array($issue) || isset($issue['pull_request'])) {
            continue;
        }
        $login = strtolower((string)($issue['user']['login'] ?? ''));
        if ($login === '' || !isset($actorLookup[$login])) {
            continue;
        }
        $start = githubDateInRange((string)($issue['created_at'] ?? ''), $from, $to);
        if ($start === null) {
            continue;
        }
        $num = (string)($issue['number'] ?? '');
        $key = 'issue:' . $repoFullName . '#' . $num;
        if ($num === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $title = trim((string)($issue['title'] ?? 'Issue'));
        $rows[] = [
            'source' => 'github', 'connection' => $connection, 'project' => $project,
            'start' => $start, 'end' => $start, 'seconds' => 0,
            'project_hint' => $repoFullName,
            'label' => $repoFullName . ' Issue #' . $num . ': ' . $title,
            'entry_count' => 1, 'activity_count' => 1, 'discussion_count' => (int)($issue['comments'] ?? 0),
        ];
    }
}

/**
 * Fetches issue comments left by the actor(s) since $fromIso.
 *
 * @param array<string, bool>  $actorLookup Login set for attribution filtering.
 * @param array<string, bool>  $seen        Dedup set, modified in place.
 * @param list<array>          $rows        Result accumulator, modified in place.
 */
function githubFetchIssueComments(
    string $repoFullName,
    array $conn,
    DateTimeImmutable $from,
    DateTimeImmutable $to,
    string $fromIso,
    string $connection,
    string $project,
    array $actorLookup,
    array &$seen,
    array &$rows,
    string $cacheTtl = '1h',
    int $cmdTimeout = 8
): void {
    $path = '/repos/' . $repoFullName . '/issues/comments?' . http_build_query(['since' => $fromIso]);
    foreach (githubPaginatedGet($path, $conn, 1, $cacheTtl, $cmdTimeout) as $comment) {
        if (!is_array($comment)) {
            continue;
        }
        $login = strtolower((string)($comment['user']['login'] ?? ''));
        if ($login === '' || !isset($actorLookup[$login])) {
            continue;
        }
        $start = githubDateInRange((string)($comment['created_at'] ?? ''), $from, $to);
        if ($start === null) {
            continue;
        }
        $id = (string)($comment['id'] ?? '');
        $key = 'issue_comment:' . $repoFullName . '#' . $id;
        if ($id === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $rows[] = [
            'source' => 'github', 'connection' => $connection, 'project' => $project,
            'start' => $start, 'end' => $start, 'seconds' => 0,
            'project_hint' => $repoFullName,
            'label' => $repoFullName . ' issue comment',
            'entry_count' => 1, 'activity_count' => 1, 'discussion_count' => 1,
        ];
    }
}

/**
 * Fetches PR review comments left by the actor(s) since $fromIso.
 *
 * @param array<string, bool>  $actorLookup Login set for attribution filtering.
 * @param array<string, bool>  $seen        Dedup set, modified in place.
 * @param list<array>          $rows        Result accumulator, modified in place.
 */
function githubFetchReviewComments(
    string $repoFullName,
    array $conn,
    DateTimeImmutable $from,
    DateTimeImmutable $to,
    string $fromIso,
    string $connection,
    string $project,
    array $actorLookup,
    array &$seen,
    array &$rows,
    string $cacheTtl = '1h',
    int $cmdTimeout = 8
): void {
    $path = '/repos/' . $repoFullName . '/pulls/comments?' . http_build_query(['since' => $fromIso]);
    foreach (githubPaginatedGet($path, $conn, 1, $cacheTtl, $cmdTimeout) as $comment) {
        if (!is_array($comment)) {
            continue;
        }
        $login = strtolower((string)($comment['user']['login'] ?? ''));
        if ($login === '' || !isset($actorLookup[$login])) {
            continue;
        }
        $start = githubDateInRange((string)($comment['created_at'] ?? ''), $from, $to);
        if ($start === null) {
            continue;
        }
        $id = (string)($comment['id'] ?? '');
        $key = 'review_comment:' . $repoFullName . '#' . $id;
        if ($id === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $rows[] = [
            'source' => 'github', 'connection' => $connection, 'project' => $project,
            'start' => $start, 'end' => $start, 'seconds' => 0,
            'project_hint' => $repoFullName,
            'label' => $repoFullName . ' PR review comment',
            'entry_count' => 1, 'activity_count' => 1, 'discussion_count' => 1,
        ];
    }
}

/**
 * Returns true when the optional GitHub fetch deadline has been reached.
 *
 * @phpstan-impure
 */
function githubBudgetExceeded(?float $deadline): bool
{
    return $deadline !== null && microtime(true) >= $deadline;
}

/**
 * Produces a set of GitHub actor logins used for PR/issue/comment attribution.
 *
 * @param  array          $conn
 * @param  list<string>   $authors
 * @return list<string>
 */
function githubActorLogins(array $conn, array $authors): array
{
    $logins = [];

    foreach (($conn['usernames'] ?? []) as $u) {
        if (is_string($u) && $u !== '') {
            $logins[] = strtolower($u);
        }
    }

    foreach ($authors as $a) {
        // Email-like entries are useful for commit filters but not login matching.
        if ($a !== '' && !str_contains($a, '@')) {
            $logins[] = strtolower($a);
        }
    }

    // Fallback to the authenticated gh/token account identity.
    try {
        $me = githubGetJson('/user', $conn);
        $login = strtolower((string)($me['login'] ?? ''));
        if ($login !== '') {
            $logins[] = $login;
        }
    } catch (RuntimeException) {
        // No-op: keep best-effort behavior.
    }

    return array_values(array_unique(array_filter($logins, fn($v) => $v !== '')));
}

/**
 * Paginates GitHub array responses using ?per_page=100&page=N.
 *
 * @return list<array<string, mixed>>
 */
function githubPaginatedGet(string $pathWithQuery, array $conn, int $maxPages = 10, string $cacheTtl = '1h', int $cmdTimeout = 8): array
{
    $rows = [];
    $glue = str_contains($pathWithQuery, '?') ? '&' : '?';

    for ($page = 1; $page <= $maxPages; $page++) {
        $path = $pathWithQuery . $glue . http_build_query(['per_page' => 100, 'page' => $page]);
        $json = githubGetJson($path, $conn, $cacheTtl, $cmdTimeout);
        if (!is_array($json) || $json === []) {
            break;
        }

        // /user returns an object. Only list endpoints should be paginated.
        if (!array_is_list($json)) {
            break;
        }

        foreach ($json as $row) {
            if (is_array($row)) {
                $rows[] = $row;
            }
        }

        if (count($json) < 100) {
            break;
        }
    }

    return $rows;
}

/**
 * Parses ISO timestamp and returns it only when inside [from, to].
 */
function githubDateInRange(string $iso, DateTimeImmutable $from, DateTimeImmutable $to): ?DateTimeImmutable
{
    if ($iso === '') {
        return null;
    }

    try {
        $dt = new DateTimeImmutable($iso);
    } catch (Throwable) {
        return null;
    }

    if ($dt < $from || $dt > $to) {
        return null;
    }

    return $dt;
}

/**
 * Calls GitHub REST API using gh CLI auth when available, with token fallback.
 *
 * @return array<mixed>
 */
function githubGetJson(string $pathWithQuery, array $conn, string $cacheTtl = '1h', int $cmdTimeout = 8): array
{
    static $ghPath = null;
    static $ghAuthReady = null;

    $token = (string)($conn['token'] ?? '');

    // Prefer explicit token when configured.
    if ($token !== '') {
        $url = 'https://api.github.com' . $pathWithQuery;
        return httpGetJson($url, [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'User-Agent: activity-report',
        ]);
    }

    if ($ghPath === null) {
        $which = githubRunCommandWithTimeout('command -v gh 2>/dev/null', 2);
        $ghPath = trim((string)$which);
    }
    if ($ghPath === '') {
        throw new RuntimeException('GitHub integration requires gh CLI auth or integrations.github[*].token');
    }

    if ($ghAuthReady === null) {
        $authCheck = githubRunCommandWithTimeout('GH_PROMPT_DISABLED=1 gh auth status >/dev/null 2>&1; echo $?', 4);
        $ghAuthReady = trim((string)$authCheck) === '0';
    }
    if ($ghAuthReady !== true) {
        throw new RuntimeException('gh CLI is not authenticated; run gh auth login or configure integrations.github[*].token');
    }

    $cmd = 'GH_PROMPT_DISABLED=1 gh api --cache ' . escapeshellarg($cacheTtl) . ' ' . escapeshellarg($pathWithQuery) . ' 2>/dev/null';
    $out = githubRunCommandWithTimeout($cmd, $cmdTimeout);
    if (!is_string($out) || trim($out) === '') {
        throw new RuntimeException('gh api request failed for ' . $pathWithQuery);
    }

    $json = json_decode($out, true);
    if (!is_array($json)) {
        throw new RuntimeException('Invalid JSON from gh api for ' . $pathWithQuery);
    }

    return $json;
}

/**
 * Executes a shell command with a hard timeout and returns stdout, or null on timeout/failure.
 */
function githubRunCommandWithTimeout(string $command, int $timeoutSeconds): ?string
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes);
    if (!is_resource($process)) {
        return null;
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + max(1, $timeoutSeconds);

    while (true) {
        $status = proc_get_status($process);
        $running = (bool)($status['running'] ?? false);

        $stdout .= stream_get_contents($pipes[1]);
        $stderr .= stream_get_contents($pipes[2]);

        if (!$running) {
            break;
        }

        if (microtime(true) >= $deadline) {
            proc_terminate($process);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
            return null;
        }

        usleep(50_000);
    }

    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        return null;
    }

    return $stdout;
}

/**
 * Builds project => github_repo_full_name set from repo_remotes and local git remotes.
 *
 * @param  array $config Full app config.
 * @return array<string, array<string, bool>>
 */
function githubReposByProject(array $config): array
{
    $result = [];
    foreach (($config['projects'] ?? []) as $project => $p) {
        if (!is_array($p)) {
            continue;
        }

        foreach (($p['repo_remotes'] ?? []) as $repoPath => $remotes) {
            if (!is_array($remotes)) {
                continue;
            }
            foreach ($remotes as $url) {
                if (!is_string($url) || $url === '') {
                    continue;
                }
                $full = githubRepoFromRemoteUrl($url);
                if ($full !== null) {
                    $result[$project][$full] = true;
                }
            }
        }

        // Fallback for projects without repo_remotes snapshots.
        foreach (($p['repos'] ?? []) as $repoPath) {
            if (!is_string($repoPath) || $repoPath === '') {
                continue;
            }
            $abs = expandPath($repoPath);
            if (!is_dir($abs . '/.git')) {
                continue;
            }
            $url = trim((string)shell_exec(
                'git -C ' . escapeshellarg($abs) . ' remote get-url origin 2>/dev/null'
            ));
            if ($url === '') {
                continue;
            }
            $full = githubRepoFromRemoteUrl($url);
            if ($full !== null) {
                $result[$project][$full] = true;
            }
        }
    }

    return $result;
}

/**
 * Parses owner/repo from common GitHub remote URL formats.
 */
function githubRepoFromRemoteUrl(string $url): ?string
{
    if (preg_match('#github\.com[:/]([^/]+)/([^/]+?)(?:\.git)?$#i', $url, $m)) {
        return $m[1] . '/' . $m[2];
    }
    return null;
}
