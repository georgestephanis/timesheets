#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * activity-report.php
 *
 * A PHP CLI tool that aggregates time and commit activity across:
 *   - ActivityWatch (local sqlite at ~/Library/Application Support/activitywatch/)
 *   - Chrome history (local sqlite at ~/Library/Application Support/Google/Chrome/)
 *   - Git repositories (via `git log`)
 *
 * Signals are clustered into "projects" via config.json. A project can
 * pull in any combination of:
 *   - VSCode workspace folder names (matched against window titles)
 *   - Browser URL domains (with glob support, e.g. *.example.com)
 *   - Slack workspaces / channels (with glob support)
 *   - Git repository paths
 *   - SSH hostnames (matched against Terminal window titles)
 *
 * Usage:
 *   php activity-report.php                         # prior 7 completed days, one report per day
 *   php activity-report.php --days 3                # last 3 days
 *   php activity-report.php --from 2026-04-15 --to 2026-04-29
 *   php activity-report.php --project "Acme Corp"   # filter to one project
 *   php activity-report.php --format json
 *   php activity-report.php --show-unmatched        # debug: list events the rules missed
 *   php activity-report.php --list-projects
 *   php activity-report.php --help
 */

// =====================================================================
//  BOOTSTRAP
// =====================================================================

const PROJECT_ROOT = __DIR__;

$configFile = PROJECT_ROOT . '/config.json';
if (!file_exists($configFile)) {
    fwrite(STDERR, "error: config.json not found. Copy config.example.json to config.json and edit it.\n");
    exit(1);
}
$CONFIG = json_decode(file_get_contents($configFile), true);
if (!is_array($CONFIG)) {
    fwrite(STDERR, "error: config.json is not valid JSON.\n");
    exit(1);
}

require_once PROJECT_ROOT . '/src/helpers.php';
require_once PROJECT_ROOT . '/src/config.php';
require_once PROJECT_ROOT . '/src/cache.php';
require_once PROJECT_ROOT . '/src/loader-activitywatch.php';
require_once PROJECT_ROOT . '/src/loader-chrome.php';
require_once PROJECT_ROOT . '/src/loader-git.php';
require_once PROJECT_ROOT . '/src/loader-integrations.php';
require_once PROJECT_ROOT . '/src/integrations/llm.php';
require_once PROJECT_ROOT . '/src/classifiers.php';
require_once PROJECT_ROOT . '/src/renderers.php';
require_once PROJECT_ROOT . '/src/cli.php';

main($CONFIG);
