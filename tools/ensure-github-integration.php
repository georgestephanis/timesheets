<?php

declare(strict_types=1);

const TOOL_ROOT = __DIR__ . '/..';

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

$backupDir = TOOL_ROOT . '/reports/config';
if (!is_dir($backupDir) && !mkdir($backupDir, 0755, true)) {
    fwrite(STDERR, "error: failed to create reports/config backup directory\n");
    exit(1);
}
$backupPath = $backupDir . '/config.github.' . date('Ymd\\THis_u') . '.json';
if (!copy($configPath, $backupPath)) {
    fwrite(STDERR, "error: failed to create backup\n");
    exit(1);
}

file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo "Added GitHub integration.\n";
echo "Backup: $backupPath\n";
