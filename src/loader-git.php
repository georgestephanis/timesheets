<?php

declare(strict_types=1);

/**
 * Git loader: reads commits from all project repositories via `git log`.
 */

/**
 * Loads git commits from every repository listed across all configured projects.
 *
 * Commits are pre-attributed to their project at load time by walking projects[*].repos,
 * so the classifier does not need to re-examine repository paths. Rows are sorted
 * ascending by author date.
 *
 * @param  array             $config Loaded config array.
 * @param  DateTimeImmutable $from   Start of the query window.
 * @param  DateTimeImmutable $to     End of the query window.
 * @return list<array{dt: DateTimeImmutable, project: string, repo: string, sha: string, subj: string}>
 */
function loadGitCommits(array $config, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $authors = $config['git_authors'] ?? [];
    if (!$authors) {
        return [];
    }

    // Explicitly configured repos per project.
    $repos = [];
    foreach ($config['projects'] as $proj => $p) {
        foreach ($p['repos'] ?? [] as $r) {
            $repos[expandPath($r)] = $proj;
        }
    }

    // Auto-discover repos from GitHub Desktop when requested.
    if (($config['discover_repos'] ?? null) === 'github_desktop') {
        require_once __DIR__ . '/loader-github-desktop.php';
        foreach (discoverGitHubDesktopRepos() as $path => $info) {
            if (isset($repos[$path])) {
                continue; // already mapped by explicit config — don't override
            }
            // Match to an existing project by repo basename (case-insensitive), else
            // use the repo name itself so commits still appear under a named project.
            $matched = null;
            foreach (array_keys($config['projects']) as $projName) {
                if (strcasecmp($projName, $info['name']) === 0) {
                    $matched = $projName;
                    break;
                }
            }
            $repos[$path] = $matched ?? $info['name'];
        }
    }

    if (!$repos) {
        return [];
    }

    $sinceArg = '--since=' . escapeshellarg($from->format('c'));
    $untilArg = '--until=' . escapeshellarg($to->format('c'));
    $authorPat = implode('|', array_map('preg_quote', $authors));
    $authorArg = '--author=' . escapeshellarg($authorPat);

    $rows = [];
    foreach ($repos as $repo => $project) {
        if (!is_dir("$repo/.git")) {
            continue;
        }
        $cmd = "git -C " . escapeshellarg($repo)
             . " log --all $sinceArg $untilArg $authorArg"
             . ' --pretty=tformat:"%aI%x09%H%x09%s" 2>/dev/null';
        $out = shell_exec($cmd);
        if (!$out) {
            continue;
        }
        foreach (preg_split("/\r?\n/", trim($out)) as $line) {
            if (!$line) {
                continue;
            }
            $parts = explode("\t", $line, 3);
            if (count($parts) < 3) {
                continue;
            }
            [$iso, $sha, $subj] = $parts;
            try {
                $dt = new DateTimeImmutable($iso);
            } catch (Throwable) {
                continue;
            }
            $rows[] = ['dt' => $dt, 'project' => $project, 'repo' => $repo, 'sha' => $sha, 'subj' => $subj];
        }
    }
    usort($rows, fn($a, $b) => $a['dt'] <=> $b['dt']);
    return $rows;
}
