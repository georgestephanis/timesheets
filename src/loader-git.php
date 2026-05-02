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

    $repos = [];
    foreach ($config['projects'] as $proj => $p) {
        foreach ($p['repos'] ?? [] as $r) {
            $repos[expandPath($r)] = $proj;
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
