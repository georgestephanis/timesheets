<?php

declare(strict_types=1);

// Conservative cleanup utility for integration project sync results.
// - Finds projects added after a baseline config backup
// - Merges only high-confidence name matches back into existing projects
// - Leaves ambiguous items untouched
//
// Usage:
//   php tools/cleanup-integration-projects.php --baseline reports/config/config.sync.YYYYMMDDTHHMMSS_UUUUUU.json --dry-run
//   php tools/cleanup-integration-projects.php --baseline reports/config/config.sync.YYYYMMDDTHHMMSS_UUUUUU.json --apply

const TOOL_ROOT = __DIR__ . '/..';

require_once TOOL_ROOT . '/src/config.php';

$options = getopt('', ['baseline:', 'dry-run', 'apply']);
$baselineArg = (string)($options['baseline'] ?? '');
$doApply = array_key_exists('apply', $options);
$dryRun = array_key_exists('dry-run', $options) || !$doApply;

if ($baselineArg === '') {
    fwrite(STDERR, "error: --baseline is required\n");
    exit(1);
}

$baselinePath = str_starts_with($baselineArg, '/')
    ? $baselineArg
    : TOOL_ROOT . '/' . ltrim($baselineArg, '/');
$currentPath = TOOL_ROOT . '/config.json';

$baseline = json_decode((string)file_get_contents($baselinePath), true);
$current = json_decode((string)file_get_contents($currentPath), true);
if (!is_array($baseline) || !is_array($current)) {
    fwrite(STDERR, "error: invalid JSON in baseline or config.json\n");
    exit(1);
}

$baseProjects = $baseline['projects'] ?? [];
$curProjects = $current['projects'] ?? [];
if (!is_array($baseProjects) || !is_array($curProjects)) {
    fwrite(STDERR, "error: projects must be objects in both files\n");
    exit(1);
}

$added = array_values(array_diff(array_keys($curProjects), array_keys($baseProjects)));

$normalize = static function (string $s): string {
    $s = strtolower($s);
    return preg_replace('/[^a-z0-9]+/', '', $s) ?? '';
};

$tokens = static function (string $s): array {
    $t = preg_split('/[^a-z0-9]+/i', strtolower($s));
    $t = array_values(array_filter($t ?: [], static fn($x) => strlen((string)$x) >= 4));
    return array_values(array_unique($t));
};

$scorePair = static function (string $candidate, string $existing) use ($normalize, $tokens): int {
    $cn = $normalize($candidate);
    $en = $normalize($existing);
    if ($cn === '' || $en === '') {
        return 0;
    }
    if ($cn === $en) {
        return 100;
    }

    // Strong containment signals.
    if (strlen($en) >= 5 && (str_contains($cn, $en) || str_contains($en, $cn))) {
        return 90;
    }

    // Token overlap signal.
    $ct = $tokens($candidate);
    $et = $tokens($existing);
    $overlap = count(array_intersect($ct, $et));
    if ($overlap >= 2) {
        return 75 + min($overlap, 10);
    }

    return 0;
};

$ignoredExact = [
    'support',
    'development',
    'operations',
    'marketing',
    'delivery',
    'sales',
    'templates',
    'list',
    'pages',
    'growth',
    'new business',
    'new project initiation',
    'project 1',
    'project 2',
    'team space',
    'sandbox',
    'internal tasks',
];
$ignoredSet = array_fill_keys($ignoredExact, true);

$mergePlan = [];
foreach ($added as $a) {
    $lower = strtolower(trim($a));
    if (isset($ignoredSet[$lower])) {
        continue;
    }

    $bestName = '';
    $bestScore = 0;
    foreach (array_keys($baseProjects) as $e) {
        $s = $scorePair($a, $e);
        if ($s > $bestScore) {
            $bestScore = $s;
            $bestName = (string)$e;
        }
    }

    // Conservative threshold.
    if ($bestScore >= 90 && $bestName !== '') {
        $mergePlan[] = ['from' => $a, 'to' => $bestName, 'score' => $bestScore];
    }
}

// Apply merges in-memory.
$applied = 0;
foreach ($mergePlan as $m) {
    $from = $m['from'];
    $to = $m['to'];
    if (!isset($curProjects[$from]) || !isset($curProjects[$to])) {
        continue;
    }

    foreach (['harvest_projects', 'clickup_tasks'] as $field) {
        $src = $curProjects[$from][$field] ?? [];
        if (!is_array($src) || $src === []) {
            continue;
        }
        if (!isset($curProjects[$to][$field]) || !is_array($curProjects[$to][$field])) {
            $curProjects[$to][$field] = [];
        }
        foreach ($src as $entry) {
            if (is_string($entry) && !in_array($entry, $curProjects[$to][$field], true)) {
                $curProjects[$to][$field][] = $entry;
            }
        }
    }

    unset($curProjects[$from]);
    $applied++;
}

echo 'Added project count: ' . count($added) . "\n";
echo 'Merge candidates: ' . count($mergePlan) . "\n";
echo 'Mode: ' . ($dryRun ? 'dry-run' : 'apply') . "\n";
foreach ($mergePlan as $m) {
    echo '- ' . $m['from'] . ' => ' . $m['to'] . ' (score ' . $m['score'] . ")\n";
}

if ($dryRun) {
    exit(0);
}

$current['projects'] = $curProjects;
try {
    $backup = saveConfigWithBackup($current, $currentPath, 'cleanup');
} catch (RuntimeException $e) {
    fwrite(STDERR, "error: " . $e->getMessage() . "\n");
    exit(1);
}
echo 'Cleanup backup: ' . $backup . "\n";
echo 'Applied merges: ' . $applied . "\n";
