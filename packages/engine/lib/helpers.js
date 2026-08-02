/**
 * General-purpose utility functions — TypeScript port of src/helpers.php.
 */

import os from "os";

/**
 * Expands a leading ~/ to the user's home directory.
 * @param {string} p
 * @returns {string}
 */
export function expandPath(p) {
    if (p.startsWith("~/")) {
        return os.homedir() + p.slice(1);
    }
    return p;
}

/**
 * Resolves a filesystem path to the configured project whose repo path is an
 * exact match or a path-prefix of it (longest match wins). Mirrors PHP
 * projectForLocalPath(). Used to attribute AI coding session cwds/workspaces
 * to a project, since those paths may be a subdirectory of the repo root.
 * @param {import('@timesheets/contracts').Config} config
 * @param {string} filePath
 * @returns {string | null}
 */
export function projectForLocalPath(config, filePath) {
    const target = expandPath(filePath).replace(/\/+$/, "");
    let best = null;
    let bestLen = -1;
    for (const [proj, p] of Object.entries(config.projects ?? {})) {
        for (const r of p.repos ?? []) {
            const repo = expandPath(r).replace(/\/+$/, "");
            if (!repo) continue;
            if (target === repo || target.startsWith(repo + "/")) {
                if (repo.length > bestLen) {
                    bestLen = repo.length;
                    best = proj;
                }
            }
        }
    }
    return best;
}

/**
 * Case-insensitive glob match. Supports * (any chars) and ? (one char).
 * Mirrors PHP fnmatch with FNM_CASEFOLD.
 * @param {string} needle
 * @param {string} pattern
 * @returns {boolean}
 */
export function fnmatchGlob(needle, pattern) {
    // Escape regex metacharacters except * and ?, then map them to regex.
    const re = pattern
        .replace(/[.+^${}()|[\]\\]/g, "\\$&")
        .replace(/\*/g, ".*")
        .replace(/\?/g, ".");
    return new RegExp("^" + re + "$", "i").test(needle);
}

/**
 * Returns true if needle matches any pattern in the list (case-insensitive glob).
 * @param {string} needle
 * @param {string[]} patterns
 * @returns {boolean}
 */
export function fnmatchAny(needle, patterns) {
    return patterns.some((p) => fnmatchGlob(needle, p));
}

/**
 * Returns true when host matches a configured domain rule.
 *
 * Rules with glob characters use fnmatch semantics. A bare domain (e.g. example.com)
 * matches both the apex and any subdomain — mirrors PHP hostMatchesDomain().
 * @param {string} host
 * @param {string} pattern
 * @returns {boolean}
 */
export function hostMatchesDomain(host, pattern) {
    host = host.toLowerCase().replace(/\.+$/, "").trim();
    pattern = pattern.toLowerCase().replace(/\.+$/, "").trim();
    if (!host || !pattern) return false;

    if (fnmatchGlob(host, pattern)) return true;

    // Apex+subdomain only for non-glob patterns.
    if (/[*?[\]]/.test(pattern)) return false;

    return host === pattern || host.endsWith("." + pattern);
}

/**
 * Returns true if host matches any configured domain rule.
 * @param {string} host
 * @param {string[]} patterns
 * @returns {boolean}
 */
export function hostMatchesAnyDomain(host, patterns) {
    return patterns.some((p) => hostMatchesDomain(host, p));
}

/**
 * Formats a duration in seconds as a compact human-readable string.
 * Examples: 3661 → "1h 01m", 90 → "1m", 18 → "18s".
 * @param {number} sec
 * @returns {string}
 */
export function fmtDur(sec) {
    const m = Math.floor(sec / 60);
    if (m >= 60) {
        const h = Math.floor(m / 60);
        const rem = m % 60;
        return `${h}h ${String(rem).padStart(2, "0")}m`;
    }
    if (m >= 1) return `${m}m`;
    return `${Math.floor(sec)}s`;
}

/**
 * Converts a Chrome visit_time (microseconds since 1601-01-01) to a JS Date.
 * @param {number} ct Chrome microsecond timestamp from the visits table.
 * @returns {Date}
 */
export function chromeTime(ct) {
    const unixMs = ct / 1000 - 11_644_473_600_000;
    return new Date(unixMs);
}

/** @type {string[]} */
let _warnings = [];

/**
 * Records a warning message. Mirrors PHP warning() without stderr output
 * (the desktop engine does not have a TTY; callers surface via Report.warnings).
 * @param {string} _source  Short subsystem tag (unused in collection, kept for API symmetry).
 * @param {string} message
 */
export function warning(_source, message) {
    _warnings.push(message);
}

/**
 * Returns all warnings collected since the last clearWarnings() call.
 * @returns {string[]}
 */
export function getWarnings() {
    return _warnings.slice();
}

/**
 * Clears the warning collector. Call at the start of each report generation.
 */
export function clearWarnings() {
    _warnings = [];
}
