<?php

declare(strict_types=1);

/**
 * Loads GitHub activity (commits, PRs, issues, comments) for project repos.
 *
 * @param  array             $conn   GitHub integration config.
 * @param  array             $config Full app config.
 * @param  DateTimeImmutable $from
 * @param  DateTimeImmutable $to
 * @return list<array<string, mixed>>
 */
function loadGitHubActivity(array $conn, array $config, DateTimeImmutable $from, DateTimeImmutable $to): array
{
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

    $actorLogins = githubActorLogins($conn, $authors);
    $actorLookup = array_fill_keys($actorLogins, true);

    $connection = (string)($conn['name'] ?? 'github');
    $fromIso = $from->setTimezone(new DateTimeZone('UTC'))->format('c');
    $toIso = $to->setTimezone(new DateTimeZone('UTC'))->format('c');

    foreach ($reposByProject as $project => $repos) {
        foreach (array_keys($repos) as $repoFullName) {
            // Commits
            foreach ($authors as $author) {
                $commitPath = '/repos/' . $repoFullName . '/commits?' . http_build_query([
                    'since' => $fromIso,
                    'until' => $toIso,
                    'author' => $author,
                ]);
                $commits = githubPaginatedGet($commitPath, $conn, 10);
                foreach ($commits as $commit) {
                    if (!is_array($commit)) {
                        continue;
                    }
                    $sha = (string)($commit['sha'] ?? '');
                    if ($sha === '' || isset($seen['commit:' . $repoFullName . '#' . $sha])) {
                        continue;
                    }

                    $start = githubDateInRange((string)($commit['commit']['author']['date'] ?? ''), $from, $to);
                    if ($start === null) {
                        continue;
                    }
                    $seen['commit:' . $repoFullName . '#' . $sha] = true;
                    $message = trim((string)($commit['commit']['message'] ?? 'GitHub commit'));

                    $rows[] = [
                        'source' => 'github',
                        'connection' => $connection,
                        'project' => $project,
                        'start' => $start,
                        'end' => $start,
                        'seconds' => 0,
                        'project_hint' => $repoFullName,
                        'label' => $repoFullName . ': ' . strtok($message, "\n"),
                        'entry_count' => 1,
                        'activity_count' => 1,
                        'discussion_count' => 0,
                    ];
                }
            }

            // Pull requests
            $pulls = githubPaginatedGet(
                '/repos/' . $repoFullName . '/pulls?' . http_build_query([
                    'state' => 'all',
                    'sort' => 'updated',
                    'direction' => 'desc',
                ]),
                $conn,
                10
            );
            foreach ($pulls as $pr) {
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
                if ($num === '' || isset($seen['pr:' . $repoFullName . '#' . $num])) {
                    continue;
                }
                $seen['pr:' . $repoFullName . '#' . $num] = true;

                $title = trim((string)($pr['title'] ?? 'Pull request'));
                $discussion = (int)($pr['comments'] ?? 0) + (int)($pr['review_comments'] ?? 0);
                $rows[] = [
                    'source' => 'github',
                    'connection' => $connection,
                    'project' => $project,
                    'start' => $start,
                    'end' => $start,
                    'seconds' => 0,
                    'project_hint' => $repoFullName,
                    'label' => $repoFullName . ' PR #' . $num . ': ' . $title,
                    'entry_count' => 1,
                    'activity_count' => 1,
                    'discussion_count' => max(0, $discussion),
                ];
            }

            // Issues (excluding pull requests)
            $issues = githubPaginatedGet(
                '/repos/' . $repoFullName . '/issues?' . http_build_query([
                    'state' => 'all',
                    'since' => $fromIso,
                    'sort' => 'updated',
                    'direction' => 'desc',
                ]),
                $conn,
                10
            );
            foreach ($issues as $issue) {
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
                if ($num === '' || isset($seen['issue:' . $repoFullName . '#' . $num])) {
                    continue;
                }
                $seen['issue:' . $repoFullName . '#' . $num] = true;

                $title = trim((string)($issue['title'] ?? 'Issue'));
                $rows[] = [
                    'source' => 'github',
                    'connection' => $connection,
                    'project' => $project,
                    'start' => $start,
                    'end' => $start,
                    'seconds' => 0,
                    'project_hint' => $repoFullName,
                    'label' => $repoFullName . ' Issue #' . $num . ': ' . $title,
                    'entry_count' => 1,
                    'activity_count' => 1,
                    'discussion_count' => (int)($issue['comments'] ?? 0),
                ];
            }

            // Issue comments authored by the actor(s)
            $issueComments = githubPaginatedGet(
                '/repos/' . $repoFullName . '/issues/comments?' . http_build_query(['since' => $fromIso]),
                $conn,
                10
            );
            foreach ($issueComments as $comment) {
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
                if ($id === '' || isset($seen['issue_comment:' . $repoFullName . '#' . $id])) {
                    continue;
                }
                $seen['issue_comment:' . $repoFullName . '#' . $id] = true;

                $rows[] = [
                    'source' => 'github',
                    'connection' => $connection,
                    'project' => $project,
                    'start' => $start,
                    'end' => $start,
                    'seconds' => 0,
                    'project_hint' => $repoFullName,
                    'label' => $repoFullName . ' issue comment',
                    'entry_count' => 1,
                    'activity_count' => 1,
                    'discussion_count' => 1,
                ];
            }

            // PR review comments authored by the actor(s)
            $reviewComments = githubPaginatedGet(
                '/repos/' . $repoFullName . '/pulls/comments?' . http_build_query(['since' => $fromIso]),
                $conn,
                10
            );
            foreach ($reviewComments as $comment) {
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
                if ($id === '' || isset($seen['review_comment:' . $repoFullName . '#' . $id])) {
                    continue;
                }
                $seen['review_comment:' . $repoFullName . '#' . $id] = true;

                $rows[] = [
                    'source' => 'github',
                    'connection' => $connection,
                    'project' => $project,
                    'start' => $start,
                    'end' => $start,
                    'seconds' => 0,
                    'project_hint' => $repoFullName,
                    'label' => $repoFullName . ' PR review comment',
                    'entry_count' => 1,
                    'activity_count' => 1,
                    'discussion_count' => 1,
                ];
            }
        }
    }

    return $rows;
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
    } catch (RuntimeException $e) {
        // No-op: keep best-effort behavior.
    }

    return array_values(array_unique(array_filter($logins, fn($v) => $v !== '')));
}

/**
 * Paginates GitHub array responses using ?per_page=100&page=N.
 *
 * @return list<array<string, mixed>>
 */
function githubPaginatedGet(string $pathWithQuery, array $conn, int $maxPages = 10): array
{
    $rows = [];
    $glue = str_contains($pathWithQuery, '?') ? '&' : '?';

    for ($page = 1; $page <= $maxPages; $page++) {
        $path = $pathWithQuery . $glue . http_build_query(['per_page' => 100, 'page' => $page]);
        $json = githubGetJson($path, $conn);
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
function githubGetJson(string $pathWithQuery, array $conn): array
{
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

    $gh = trim((string)shell_exec('command -v gh 2>/dev/null'));
    if ($gh === '') {
        throw new RuntimeException('GitHub integration requires gh CLI auth or integrations.github[*].token');
    }

    $cmd = 'gh api ' . escapeshellarg($pathWithQuery) . ' 2>/dev/null';
    $out = shell_exec($cmd);
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
