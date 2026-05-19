/**
 * Chrome history loader: reads browser visits from local Chrome SQLite databases.
 * TypeScript port of src/loader-chrome.php.
 */

import { existsSync, readdirSync, copyFileSync } from "fs";
import { tmpdir } from "os";
import path from "path";
import Database from "better-sqlite3";
import { expandPath, chromeTime, warning } from "./helpers.js";

/**
 * @param {string} src
 * @returns {string|null}
 */
function copyForRead(src) {
    const dest = path.join(tmpdir(), `timesheets-chrome-${Date.now()}-${Math.random().toString(36).slice(2)}.db`);
    try {
        copyFileSync(src, dest);
        return dest;
    } catch {
        return null;
    }
}

/**
 * Upper-bound binary search: returns the smallest index i such that sorted[i] > key.
 * Mirrors PHP bsearchRight().
 * @param {number[]} sorted
 * @param {number} key
 * @returns {number}
 */
function bsearchRight(sorted, key) {
    let lo = 0;
    let hi = sorted.length;
    while (lo < hi) {
        const mid = (lo + hi) >> 1;
        if (sorted[mid] <= key) lo = mid + 1;
        else hi = mid;
    }
    return lo;
}

/**
 * Loads all Chrome history visits across configured (or auto-discovered) profiles.
 * Mirrors PHP loadChromeHistory().
 *
 * @param {import('@timesheets/contracts').Config} config
 * @param {Date} from
 * @param {Date} to
 * @returns {Array<{ time: Date; host: string; url: string; title: string }>}
 */
export function loadChromeHistory(config, from, to) {
    const base = expandPath(config.paths.chrome);
    let profiles = config.paths.chrome_profiles;
    if (profiles === null || profiles === undefined) {
        profiles = [];
        try {
            for (const entry of readdirSync(base, { withFileTypes: true })) {
                if (entry.isDirectory() && existsSync(path.join(base, entry.name, "History"))) {
                    profiles.push(entry.name);
                }
            }
        } catch {
            /* base dir may not exist */
        }
    }
    if (typeof profiles === "string") profiles = [profiles];

    const rows = [];
    for (const prof of profiles) {
        const src = path.join(base, prof, "History");
        if (!existsSync(src)) continue;
        const copy = copyForRead(src);
        if (!copy) {
            warning("chrome", `could not copy ${src}`);
            continue;
        }
        let db;
        try {
            db = new Database(copy, { readonly: true, fileMustExist: true });
            // Chrome stores visit_time as µs since 1601-01-01 (Windows FILETIME epoch).
            const offsetSec = 11_644_473_600;
            const fromCt = Math.floor((from.getTime() / 1000 + offsetSec) * 1e6);
            const toCt = Math.floor((to.getTime() / 1000 + offsetSec) * 1e6);
            const results = db
                .prepare(
                    "SELECT v.visit_time, u.url, u.title FROM visits v JOIN urls u ON v.url = u.id WHERE v.visit_time >= ? AND v.visit_time <= ?",
                )
                .all(fromCt, toCt);
            for (const r of /** @type {any[]} */ (results)) {
                const dt = chromeTime(Number(r.visit_time));
                if (dt < from || dt > to) continue;
                let host = "";
                try {
                    host = new URL(String(r.url)).hostname;
                } catch {
                    /* ignore malformed URLs */
                }
                rows.push({ time: dt, host, url: String(r.url), title: String(r.title ?? "") });
            }
        } catch (err) {
            warning("chrome", `could not read ${src}: ${err instanceof Error ? err.message : String(err)}`);
        } finally {
            db?.close();
        }
    }

    rows.sort((a, b) => a.time.getTime() - b.time.getTime());
    return rows;
}

/**
 * Back-fills missing URLs on Chrome ActivityWatch events using Chrome history.
 * Mirrors PHP backfillChromeUrls().
 *
 * @param {{ window: Array<{ app: string; url: string; start: Date; [key: string]: unknown }> }} events  Mutated in place.
 * @param {Array<{ time: Date; url: string }>} chrome  Sorted Chrome history rows.
 * @param {number} windowSec  Maximum seconds of tolerance.
 */
export function backfillChromeUrls(events, chrome, windowSec) {
    if (!chrome.length) return;
    const times = chrome.map((r) => r.time.getTime() / 1000);
    for (const ev of events.window) {
        if (ev.app !== "Google Chrome" || ev.url !== "") continue;
        const start = ev.start.getTime() / 1000;
        const i = bsearchRight(times, start) - 1;
        if (i >= 0 && start - times[i] <= windowSec) {
            ev.url = chrome[i].url;
        }
    }
}
