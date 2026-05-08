<?php

declare(strict_types=1);

const TOOL_ROOT = __DIR__ . '/..';

require_once TOOL_ROOT . '/src/config.php';

$configPath = TOOL_ROOT . '/config.json';
$config = json_decode((string)file_get_contents($configPath), true);
if (!is_array($config)) {
    fwrite(STDERR, "error: config.json is invalid JSON\n");
    exit(1);
}

if (!isset($config['integrations']) || !is_array($config['integrations'])) {
    $config['integrations'] = [];
}
if (!isset($config['integrations']['github']) || !is_array($config['integrations']['github'])) {
    $config['integrations']['github'] = [];
}

foreach ($config['integrations']['github'] as $conn) {
    if (is_array($conn) && ((string)($conn['name'] ?? '') === 'GitHub via gh')) {
        echo "GitHub integration already present; no changes.\n";
        exit(0);
    }
}

$authors = $config['git_authors'] ?? [];
if (!is_array($authors) || $authors === []) {
    $authors = ['you@example.com'];
}
$authors = array_values(array_filter(array_map('strval', $authors), static fn($a) => $a !== ''));

$config['integrations']['github'][] = [
    'name' => 'GitHub via gh',
    'authors' => $authors,
];

try {
    $backupPath = saveConfigWithBackup($config, $configPath, 'github');
} catch (RuntimeException $e) {
    fwrite(STDERR, "error: " . $e->getMessage() . "\n");
    exit(1);
}
echo "Added GitHub integration.\n";
echo "Backup: $backupPath\n";
