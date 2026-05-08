<?php

declare(strict_types=1);

// Syncs project repo_remotes metadata in config.json by reading git remotes
// from each configured local repository path.

const TOOL_ROOT = __DIR__ . '/..';

require_once TOOL_ROOT . '/src/config.php';

/**
 * Expands a ~-relative path to an absolute home-directory path.
 */
function expandRepoPath(string $path): string
{
    if (str_starts_with($path, '~/')) {
        $home = getenv('HOME') ?: '';
        return ($home !== '' ? $home : rtrim((string)exec('printf %s "$HOME"'), "\n")) . substr($path, 1);
    }
    return $path;
}

/**
 * Returns remote name => URL for a local git repository.
 *
 * @return array<string, string>
 */
function gitRemotesForRepo(string $absoluteRepoPath): array
{
    if (!is_dir($absoluteRepoPath . '/.git')) {
        return [];
    }

    $namesOut = shell_exec('git -C ' . escapeshellarg($absoluteRepoPath) . ' remote 2>/dev/null');
    if (!is_string($namesOut) || trim($namesOut) === '') {
        return [];
    }

    $remotes = [];
    foreach (preg_split('/\r?\n/', trim($namesOut)) as $name) {
        $name = trim((string)$name);
        if ($name === '') {
            continue;
        }

        $url = shell_exec(
            'git -C ' . escapeshellarg($absoluteRepoPath)
            . ' remote get-url ' . escapeshellarg($name) . ' 2>/dev/null'
        );
        $url = trim((string)$url);
        if ($url !== '') {
            $remotes[$name] = $url;
        }
    }

    return $remotes;
}

$configPath = TOOL_ROOT . '/config.json';
$config = json_decode((string)file_get_contents($configPath), true);
if (!is_array($config)) {
    fwrite(STDERR, "error: config.json is invalid JSON\n");
    exit(1);
}
if (!isset($config['projects']) || !is_array($config['projects'])) {
    fwrite(STDERR, "error: config.json has no projects object\n");
    exit(1);
}

$changedProjects = 0;
$reposScanned = 0;
$reposWithRemotes = 0;

foreach ($config['projects'] as &$project) {
    if (!is_array($project)) {
        continue;
    }

    $repoPaths = $project['repos'] ?? [];
    if (!is_array($repoPaths) || $repoPaths === []) {
        continue;
    }

    $snapshot = [];
    foreach ($repoPaths as $repoPath) {
        if (!is_string($repoPath) || $repoPath === '') {
            continue;
        }
        $reposScanned++;
        $abs = expandRepoPath($repoPath);
        $remotes = gitRemotesForRepo($abs);
        if ($remotes !== []) {
            $snapshot[$repoPath] = $remotes;
            $reposWithRemotes++;
        }
    }

    $existing = $project['repo_remotes'] ?? null;
    if ($snapshot === []) {
        if (is_array($existing) && $existing !== []) {
            unset($project['repo_remotes']);
            $changedProjects++;
        }
        continue;
    }

    if (!is_array($existing) || $existing !== $snapshot) {
        $project['repo_remotes'] = $snapshot;
        $changedProjects++;
    }
}
unset($project);

if ($changedProjects === 0) {
    echo 'No repo_remotes changes needed.' . "\n";
    echo 'Repos scanned: ' . $reposScanned . '; with remotes: ' . $reposWithRemotes . "\n";
    exit(0);
}

try {
    $backupPath = saveConfigWithBackup($config, $configPath, 'remotes');
} catch (RuntimeException $e) {
    fwrite(STDERR, "error: " . $e->getMessage() . "\n");
    exit(1);
}

echo 'Updated projects: ' . $changedProjects . "\n";
echo 'Repos scanned: ' . $reposScanned . '; with remotes: ' . $reposWithRemotes . "\n";
echo 'Backup: ' . $backupPath . "\n";
