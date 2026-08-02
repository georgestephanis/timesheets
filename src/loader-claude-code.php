<?php

declare(strict_types=1);

/**
 * Claude Code loader: reads session logs from ~/.claude/projects/*​/*.jsonl.
 */

/**
 * Loads Claude Code sessions attributable to a configured project, from every
 * *.jsonl transcript under paths.claude_code_logs.
 *
 * Each transcript file is treated as one session. Lines are streamed and parsed as
 * JSON; only lines carrying both a "cwd" and a "timestamp" are considered. The session
 * is attributed to whichever cwd appears most often in the file, then resolved to a
 * project via projectForLocalPath(). Sessions with no matching project, or entirely
 * outside [$from, $to], are skipped.
 *
 * @param  array             $config Loaded config array.
 * @param  DateTimeImmutable $from   Start of the query window.
 * @param  DateTimeImmutable $to     End of the query window.
 * @return list<array{start: DateTimeImmutable, end: DateTimeImmutable, project: string, source: string, label: string, detail: string}>
 */
function loadClaudeCodeSessions(array $config, DateTimeImmutable $from, DateTimeImmutable $to): array
{
    $dir = expandPath($config['paths']['claude_code_logs'] ?? '~/.claude/projects');
    if (!is_dir($dir)) {
        return [];
    }

    $rows = [];
    foreach (glob("$dir/*/*.jsonl") ?: [] as $file) {
        $session = parseClaudeCodeSessionFile($file);
        if ($session === null) {
            continue;
        }
        [$cwdCounts, $minTs, $maxTs, $label, $detail] = $session;
        if ($maxTs < $from || $minTs > $to) {
            continue;
        }

        arsort($cwdCounts);
        $cwd = array_key_first($cwdCounts);
        $project = projectForLocalPath($config, $cwd);
        if ($project === null) {
            continue;
        }

        $rows[] = [
            'start'   => $minTs,
            'end'     => $maxTs,
            'project' => $project,
            'source'  => 'claude',
            'label'   => $label,
            'detail'  => $detail,
        ];
    }

    usort($rows, fn($a, $b) => $a['start'] <=> $b['start']);
    return $rows;
}

/**
 * Streams a single Claude Code transcript file and extracts per-cwd line counts,
 * the earliest/latest timestamp, a label (first human user message, truncated), and a
 * detail string (that first message plus a few follow-ups, truncated) suitable as raw
 * material for an LLM-generated "what happened" summary.
 *
 * @return array{0: array<string, int>, 1: DateTimeImmutable, 2: DateTimeImmutable, 3: string, 4: string}|null
 */
function parseClaudeCodeSessionFile(string $file): ?array
{
    $fh = @fopen($file, 'r');
    if ($fh === false) {
        return null;
    }

    $cwdCounts = [];
    $minTs = null;
    $maxTs = null;
    $label = '';
    $userMessages = [];

    while (($line = fgets($fh)) !== false) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $entry = json_decode($line, true);
        if (!is_array($entry)) {
            continue;
        }

        $cwd = $entry['cwd'] ?? null;
        $tsRaw = $entry['timestamp'] ?? null;
        if (!is_string($cwd) || $cwd === '' || !is_string($tsRaw)) {
            continue;
        }
        try {
            $ts = new DateTimeImmutable($tsRaw);
        } catch (Throwable) {
            continue;
        }

        $cwdCounts[$cwd] = ($cwdCounts[$cwd] ?? 0) + 1;
        $minTs = $minTs === null ? $ts : min($minTs, $ts);
        $maxTs = $maxTs === null ? $ts : max($maxTs, $ts);

        if (($entry['type'] ?? '') === 'user' && ($entry['message']['role'] ?? '') === 'user') {
            $content = $entry['message']['content'] ?? null;
            $text = null;
            if (is_string($content)) {
                $text = $content;
            } elseif (is_array($content)) {
                foreach ($content as $block) {
                    if (is_array($block) && ($block['type'] ?? '') === 'text' && is_string($block['text'] ?? null)) {
                        $text = $block['text'];
                        break;
                    }
                }
            }
            // Skip tool-result/system-injected "user" turns (no free text, or a
            // slash-command/system-reminder wrapper) — only real typed prompts are
            // useful as summary material.
            if (is_string($text) && $text !== '' && !str_starts_with($text, '<')) {
                if ($label === '') {
                    $label = $text;
                }
                if (count($userMessages) < 8) {
                    $userMessages[] = $text;
                }
            }
        }
    }
    fclose($fh);

    if ($minTs === null || $maxTs === null || $cwdCounts === []) {
        return null;
    }

    $label = trim(preg_replace('/\s+/', ' ', $label) ?? '');
    if (strlen($label) > 120) {
        $label = substr($label, 0, 119) . '…';
    }

    $detail = implode(' | ', array_map(
        static function (string $m): string {
            $m = trim(preg_replace('/\s+/', ' ', $m) ?? '');
            return strlen($m) > 200 ? substr($m, 0, 199) . '…' : $m;
        },
        $userMessages
    ));
    if (strlen($detail) > 1000) {
        $detail = substr($detail, 0, 999) . '…';
    }

    return [$cwdCounts, $minTs, $maxTs, $label, $detail];
}
