<?php

declare(strict_types=1);

/**
 * OpenAI-compatible LLM integration: project-assignment suggestions for unmatched signals.
 *
 * CLI-only — never required by api.php.
 * Uses stream_context (no curl) consistent with httpGetJson() in shared.php.
 */

/**
 * Returns the first configured LLM connection, or null when none are configured.
 *
 * @param  array<string, mixed> $config
 * @return array<string, mixed>|null
 */
function llmGetConnection(array $config): ?array
{
    $list = $config['integrations']['llm'] ?? [];
    return is_array($list) && !empty($list) ? $list[0] : null;
}

/**
 * Resolves the model to use for a connection.
 *
 * Returns the configured model name, or queries /models and returns the ID of
 * the first available model when no model is explicitly configured.
 *
 * @param array<string, mixed> $conn
 */
function llmResolveModel(array $conn): string
{
    if (!empty($conn['model'])) {
        return (string)$conn['model'];
    }

    $baseUrl = rtrim((string)$conn['base_url'], '/');
    $apiKey  = (string)($conn['api_key'] ?? 'local');
    $timeout = (int)($conn['timeout'] ?? 30);

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'GET',
            'header'        => 'Authorization: Bearer ' . $apiKey,
            'ignore_errors' => true,
            'timeout'       => $timeout,
        ],
    ]);
    $raw = @file_get_contents($baseUrl . '/models', false, $ctx);
    if ($raw === false) {
        throw new RuntimeException("Could not fetch model list from $baseUrl/models");
    }
    $decoded = json_decode($raw, true);
    $model   = $decoded['data'][0]['id'] ?? null;
    if (!is_string($model) || $model === '') {
        throw new RuntimeException("No models available at $baseUrl");
    }
    return $model;
}

/**
 * POSTs a JSON payload to an OpenAI-compatible endpoint and returns the decoded response.
 *
 * @param  array<string, mixed> $conn
 * @param  string               $path    Relative path, e.g. '/chat/completions'.
 * @param  array<string, mixed> $payload Request body.
 * @return array<string, mixed>
 */
function llmPostJson(array $conn, string $path, array $payload): array
{
    $baseUrl = rtrim((string)$conn['base_url'], '/');
    $apiKey  = (string)($conn['api_key'] ?? 'local');
    $timeout = (int)($conn['timeout'] ?? 30);
    $body    = (string)json_encode($payload);

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\nAuthorization: Bearer $apiKey",
            'content'       => $body,
            'ignore_errors' => true,
            'timeout'       => $timeout,
        ],
    ]);

    $url = $baseUrl . $path;
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) {
        throw new RuntimeException("LLM request failed: $url");
    }

    $status = 0;
    $line   = $http_response_header[0] ?? '';
    if (preg_match('/\s(\d{3})\s/', $line, $m)) {
        $status = (int)$m[1];
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        throw new RuntimeException("LLM returned invalid JSON from $url — body: " . substr($raw, 0, 300));
    }
    if ($status < 200 || $status >= 300) {
        $err = $decoded['error']['message'] ?? (is_string($decoded['error'] ?? null) ? $decoded['error'] : null);
        throw new RuntimeException("HTTP $status from $url: " . ($err ?? (string)json_encode($decoded)));
    }

    return $decoded;
}

/**
 * Asks the LLM to produce a concise accomplishment summary for a single work day,
 * organised by project with duration and time-of-day context.
 *
 * Pulls together tracked time, git commits, GitHub PR/issue labels, ClickUp task
 * names, and Harvest entry labels — grouped per project — then sends them to the
 * configured LLM for a project-by-project bullet summary.
 *
 * Returns null when no LLM is configured, when no actionable data exists, or when
 * the LLM call fails — so callers can safely skip the summary without aborting.
 *
 * @param  string        $date        Date being summarised (YYYY-MM-DD).
 * @param  array         $dayBucket   $fullBucket[$date]: project-keyed records including commits.
 * @param  array         $external    Raw external rows already filtered to the day's range.
 * @param  array<string, mixed> $config
 * @param  DateTimeZone  $tz
 * @param  list<array{s: int, e: int, p: string, g: string|null}> $dayTimeline
 *         Timeline segments for the day; used to compute per-project active windows.
 * @return string|null   Markdown bullet list, or null on failure / no data / no LLM.
 */
function llmDailySummary(
    string $date,
    array $dayBucket,
    array $external,
    array $config,
    DateTimeZone $tz,
    array $dayTimeline = []
): ?string {
    $conn = llmGetConnection($config);
    if ($conn === null) {
        return null;
    }

    $ignoredNames = array_fill_keys($config['ignored_projects'] ?? [], true);

    // Compute per-project active windows (first start → last end) from timeline segments.
    $projectWindows = [];
    foreach ($dayTimeline as $seg) {
        $p = (string)($seg['p'] ?? '');
        $s = (int)($seg['s'] ?? 0);
        $e = (int)($seg['e'] ?? 0);
        if ($p === '' || $s === 0) {
            continue;
        }
        if (!isset($projectWindows[$p])) {
            $projectWindows[$p] = ['first' => $s, 'last' => $e];
        } else {
            if ($s < $projectWindows[$p]['first']) {
                $projectWindows[$p]['first'] = $s;
            }
            if ($e > $projectWindows[$p]['last']) {
                $projectWindows[$p]['last'] = $e;
            }
        }
    }
    $fmtTime = fn(int $ts): string =>
        (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('g:ia');

    // Group external activity by the project already attributed in each row ('project'
    // field is populated by GitHub; ClickUp/Harvest rows use '__unattributed__').
    $extByProj = [];
    $seen = [];
    foreach ($external as $row) {
        $rawLabel = (string)($row['label'] ?? '');
        $source   = (string)($row['source'] ?? '');
        if ($rawLabel === '' || $source === '') {
            continue;
        }
        $label = mb_substr(str_replace(["\n", "\r", "\t"], ' ', $rawLabel), 0, 200);
        $key   = $source . ':' . $label;
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;

        $proj = (string)($row['project'] ?? '');
        $bucket = $proj !== '' ? $proj : '__unattributed__';

        switch ($source) {
            case 'github':
                if (str_contains($label, ' PR #') || str_contains($label, ' Issue #')) {
                    $extByProj[$bucket]['github'][] = $label;
                }
                break;
            case 'clickup':
                if ($label !== 'ClickUp time entry') {
                    $sec = (int)($row['seconds'] ?? 0);
                    $extByProj[$bucket]['clickup'][] = $sec > 0 ? "$label (" . fmtDur($sec) . ' logged)' : $label;
                }
                break;
            case 'harvest':
                $sec = (int)($row['seconds'] ?? 0);
                $extByProj[$bucket]['harvest'][] = $sec > 0 ? "$label (" . fmtDur($sec) . ' logged)' : $label;
                break;
        }
    }

    // Merge project names from bucket + attributed external rows, sort by time desc.
    $allProjects = array_unique(array_merge(
        array_keys($dayBucket),
        array_filter(array_keys($extByProj), fn($k) => $k !== '__unattributed__')
    ));
    usort($allProjects, fn($a, $b) =>
        ($dayBucket[$b]['seconds'] ?? 0) <=> ($dayBucket[$a]['seconds'] ?? 0));

    // Build one prompt section per project.
    $sections = [];
    $totalCommits = 0;
    $totalGithub  = 0;
    $totalClickup = 0;
    $totalHarvest = 0;

    foreach ($allProjects as $proj) {
        if (isset($ignoredNames[$proj])) {
            continue;
        }

        $sec     = (int)($dayBucket[$proj]['seconds'] ?? 0);
        $commits = array_slice($dayBucket[$proj]['commits'] ?? [], 0, 15);
        $ext     = $extByProj[$proj] ?? [];

        if ($sec < 60 && !$commits && !$ext) {
            continue;
        }

        // Header: project name + duration + time window.
        $meta = [];
        if ($sec >= 60) {
            $meta[] = fmtDur($sec) . ' tracked';
        }
        if (isset($projectWindows[$proj])) {
            $meta[] = $fmtTime($projectWindows[$proj]['first'])
                    . '–' . $fmtTime($projectWindows[$proj]['last']);
        }
        $header = $proj . ($meta ? ' (' . implode(', ', $meta) . ')' : '') . ':';

        $lines = [$header];
        foreach ($commits as $c) {
            $lines[] = '  commit: ' . $c['subj'];
        }
        foreach (array_slice($ext['github'] ?? [], 0, 10) as $l) {
            $lines[] = '  github: ' . $l;
        }
        foreach (array_slice($ext['clickup'] ?? [], 0, 10) as $l) {
            $lines[] = '  clickup: ' . $l;
        }
        foreach (array_slice($ext['harvest'] ?? [], 0, 10) as $l) {
            $lines[] = '  harvest: ' . $l;
        }

        $sections[] = implode("\n", $lines);

        $totalCommits += count($commits);
        $totalGithub  += count($ext['github'] ?? []);
        $totalClickup += count($ext['clickup'] ?? []);
        $totalHarvest += count($ext['harvest'] ?? []);
    }

    // Unattributed external activity (no matched project).
    $unattr = $extByProj['__unattributed__'] ?? [];
    if ($unattr) {
        $lines = ['Other activity (no project match):'];
        foreach (array_slice($unattr['github'] ?? [], 0, 10) as $l) {
            $lines[] = '  github: ' . $l;
        }
        foreach (array_slice($unattr['clickup'] ?? [], 0, 10) as $l) {
            $lines[] = '  clickup: ' . $l;
        }
        foreach (array_slice($unattr['harvest'] ?? [], 0, 10) as $l) {
            $lines[] = '  harvest: ' . $l;
        }
        $sections[] = implode("\n", $lines);
        $totalGithub  += count($unattr['github'] ?? []);
        $totalClickup += count($unattr['clickup'] ?? []);
        $totalHarvest += count($unattr['harvest'] ?? []);
    }

    appLog('INFO', 'llm', "[$date] data: $totalCommits commits, $totalGithub github, "
        . "$totalClickup clickup, $totalHarvest harvest across " . count($sections) . " project sections");

    if (!$sections) {
        appLog('INFO', 'llm', "[$date] no actionable data — summary skipped");
        return null;
    }

    $systemPrompt = 'You are a technical work-log assistant. Given daily activity data organised by '
        . 'project (with time tracked and active window), write a concise accomplishment summary '
        . 'organised by project. For each project, use one top-level bullet with the project name, '
        . 'duration, and approximate time of day, then sub-bullets for what was specifically '
        . 'accomplished. Use past tense. Group related commits into a single sub-bullet when '
        . 'appropriate. Omit boilerplate like "Worked on" or "Continued work on". '
        . 'Respond with a markdown bullet list only — no headings, no prose, no code fences.';

    $userPrompt = "Summarize the work accomplished on $date by project:\n\n"
        . implode("\n\n", $sections);

    $baseUrl = rtrim((string)($conn['base_url'] ?? 'unknown'), '/');
    appLog('INFO', 'llm', "[$date] sending request to $baseUrl — prompt length: " . mb_strlen($userPrompt) . " chars");

    try {
        $model = llmResolveModel($conn);
        appLog('INFO', 'llm', "[$date] model: $model");
        $response = llmPostJson($conn, '/chat/completions', [
            'model'    => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user',   'content' => $userPrompt],
            ],
        ]);
    } catch (RuntimeException $e) {
        appLog('ERROR', 'llm', "[$date] request failed: " . $e->getMessage());
        return null;
    }

    $content = trim((string)($response['choices'][0]['message']['content'] ?? ''));
    appLog('DEBUG', 'llm', "[$date] raw response: " . mb_substr($content, 0, 400));

    if ($content === '') {
        appLog('ERROR', 'llm', "[$date] empty content. finish_reason="
            . (string)($response['choices'][0]['finish_reason'] ?? '?')
            . " full_response=" . mb_substr((string)json_encode($response), 0, 500));
        return null;
    }

    // Strip markdown code fences some models add despite instructions.
    $content = (string)preg_replace('/^```(?:markdown)?\s*/m', '', $content);
    $content = (string)preg_replace('/\s*```\s*$/m', '', $content);
    $result  = trim($content) ?: null;

    if ($result === null) {
        appLog('WARNING', 'llm', "[$date] summary empty after stripping fences");
    } else {
        appLog('INFO', 'llm', "[$date] summary ok (" . mb_strlen($result) . " chars)");
    }
    return $result;
}

/**
 * Asks the LLM to suggest project assignments for unmatched signals.
 *
 * Sends a structured prompt describing configured projects and unmatched signals, then
 * parses the JSON response and validates each suggestion against the known project list
 * and the actual unmatched signal set. Only confident, valid suggestions are returned.
 *
 * @param  array<string, array<string, int>> $unmatched  Output of classifyAndAggregate()[1].
 * @param  array<string, mixed>              $config
 * @return list<array{kind: string, value: string, project: string, reason: string}>
 */
function llmSuggestAssignments(array $unmatched, array $config): array
{
    $conn = llmGetConnection($config);
    if ($conn === null) {
        return [];
    }

    $projects     = $config['projects'] ?? [];
    $ignoredNames = array_fill_keys($config['ignored_projects'] ?? [], true);

    // Build concise per-project descriptions for the prompt.
    $projectLines = [];
    foreach ($projects as $name => $p) {
        if (isset($ignoredNames[$name])) {
            continue;
        }
        $parts = [];
        if (!empty($p['grouping'])) {
            $parts[] = 'group: ' . $p['grouping'];
        }
        if (!empty($p['repos'])) {
            $parts[] = 'repos: ' . implode(', ', array_map('basename', (array)$p['repos']));
        }
        if (!empty($p['vscode_dirs'])) {
            $parts[] = 'vscode: ' . implode(', ', (array)$p['vscode_dirs']);
        }
        if (!empty($p['domains'])) {
            $parts[] = 'domains: ' . implode(', ', (array)$p['domains']);
        }
        $projectLines[] = '- "' . $name . '"' . ($parts ? ': ' . implode('; ', $parts) : '');
    }

    // Flatten unmatched signals into prompt lines, tracking valid values per kind.
    // Sanitize values before they reach the prompt: strip control characters and cap length
    // to prevent prompt injection via crafted window titles or hostnames. Also skip the
    // '(no url)' placeholder here so the LLM never sees it as a candidate.
    $kinds       = ['vscode', 'browser', 'slack', 'apps'];
    $signalLines = [];
    $signalSet   = [];
    foreach ($kinds as $kind) {
        foreach ((array)($unmatched[$kind] ?? []) as $value => $count) {
            $value = mb_substr(str_replace(["\n", "\r", "\t"], ' ', (string)$value), 0, 200);
            if ($value === '' || $value === '(no url)') {
                continue;
            }
            $signalLines[] = sprintf('- %s: "%s" (%d events)', $kind, $value, (int)$count);
            $signalSet[$kind][] = $value;
        }
    }

    if (!$signalLines) {
        return [];
    }

    $model = llmResolveModel($conn);

    $systemPrompt = 'You are a time-tracking assistant. Map unclassified computer-activity signals to the '
        . 'correct project based on naming patterns. Be conservative: only suggest when confident. '
        . 'Respond with a JSON array only — no prose, no markdown fences.';

    $userPrompt = "Configured projects:\n"
        . implode("\n", $projectLines) . "\n\n"
        . "Unmatched signals (activity that matched no project rule):\n"
        . implode("\n", $signalLines) . "\n\n"
        . "For each signal you are confident about, output one JSON object:\n"
        . "  {\"kind\": \"vscode|browser|slack|apps\", \"value\": \"<exact signal value>\","
        . " \"project\": \"<exact project name>\", \"reason\": \"<one sentence>\"}\n\n"
        . "Use only exact values and project names from the lists above. "
        . "Omit signals you are unsure about. Reply with the JSON array only.";

    $response = llmPostJson($conn, '/chat/completions', [
        'model'    => $model,
        'messages' => [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user',   'content' => $userPrompt],
        ],
    ]);

    $content = trim((string)($response['choices'][0]['message']['content'] ?? ''));

    // Strip markdown code fences that some models add despite instructions.
    $content = (string)preg_replace('/^```(?:json)?\s*/m', '', $content);
    $content = (string)preg_replace('/\s*```\s*$/m', '', $content);
    $content = trim($content);

    $suggestions = json_decode($content, true);
    if (!is_array($suggestions)) {
        return [];
    }

    // Validate: kind must be known, value must have been unmatched, project must exist.
    $projectNames = array_keys($projects);
    $valid        = [];
    foreach ($suggestions as $s) {
        if (!is_array($s)) {
            continue;
        }
        $kind    = (string)($s['kind']    ?? '');
        $value   = (string)($s['value']   ?? '');
        $project = (string)($s['project'] ?? '');
        $reason  = (string)($s['reason']  ?? '');

        if (!in_array($kind, $kinds, true) || $value === '' || $project === '') {
            continue;
        }
        if (!in_array($project, $projectNames, true)) {
            continue;
        }
        if (!in_array($value, $signalSet[$kind] ?? [], true)) {
            continue;
        }
        $valid[] = ['kind' => $kind, 'value' => $value, 'project' => $project, 'reason' => $reason];
    }

    return $valid;
}
