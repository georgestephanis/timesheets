/**
 * ActivityWatch data loader: reads window-focus, AFK, and input events from
 * the local ActivityWatch SQLite database.
 * TypeScript port of src/loader-activitywatch.php.
 */

import { existsSync } from "fs";
import { tmpdir } from "os";
import { copyFileSync } from "fs";
import path from "path";
import Database from "better-sqlite3";
import { expandPath, warning } from "./helpers.js";

/**
 * Converts an epoch value (seconds, ms, µs, or ns integer) to a Date.
 * Mirrors PHP awEpochToDateTime().
 * @param {unknown} value
 * @returns {Date|null}
 */
function awEpochToDateTime(value) {
    const raw = Number(value);
    if (!raw || raw <= 0) return null;
    const digits = Math.floor(Math.abs(raw)).toString().length;
    let seconds = raw;
    if (digits >= 19) seconds = raw / 1e9;
    else if (digits >= 16) seconds = raw / 1e6;
    else if (digits >= 13) seconds = raw / 1e3;
    const ms = Math.round(seconds * 1000);
    return isFinite(ms) ? new Date(ms) : null;
}

/**
 * @param {{ presses?: number; clicks?: number; deltaX?: number; deltaY?: number; scrollX?: number; scrollY?: number }} data
 * @returns {{ presses: number; clicks: number; deltaX: number; deltaY: number; scrollX: number; scrollY: number; active: boolean }}
 */
function normalizeInputData(data) {
    const presses = Number(data.presses ?? 0);
    const clicks = Number(data.clicks ?? 0);
    const deltaX = Number(data.deltaX ?? 0);
    const deltaY = Number(data.deltaY ?? 0);
    const scrollX = Number(data.scrollX ?? 0);
    const scrollY = Number(data.scrollY ?? 0);
    const active =
        presses + clicks > 0 ||
        Math.abs(deltaX) > 0 ||
        Math.abs(deltaY) > 0 ||
        Math.abs(scrollX) > 0 ||
        Math.abs(scrollY) > 0;
    return { presses, clicks, deltaX, deltaY, scrollX, scrollY, active };
}

/**
 * Makes a read-only copy of a SQLite file to avoid locking the live database.
 * @param {string} src
 * @returns {string|null}
 */
function copyForRead(src) {
    const dest = path.join(tmpdir(), `timesheets-aw-${Date.now()}-${Math.random().toString(36).slice(2)}.db`);
    try {
        copyFileSync(src, dest);
        return dest;
    } catch {
        return null;
    }
}

/**
 * @param {import('better-sqlite3').Database} db
 * @param {Date} from
 * @param {Date} to
 */
function loadAwSqliteRust(db, from, to) {
    const buckets = db.prepare("SELECT id, name FROM buckets").all();
    const winIds = [];
    const afkIds = [];
    const inputIds = [];
    for (const b of /** @type {any[]} */ (buckets)) {
        const name = String(b.name ?? "");
        if (name.startsWith("aw-watcher-window")) winIds.push(b.id);
        else if (name.startsWith("aw-watcher-afk")) afkIds.push(b.id);
        else if (name.startsWith("aw-watcher-input")) inputIds.push(b.id);
    }

    const fromNs = BigInt(from.getTime()) * 1_000_000n;
    const toNs = BigInt(to.getTime()) * 1_000_000n;

    /**
     * @param {number[]} ids
     * @returns {any[]}
     */
    function fetch(ids) {
        if (!ids.length) return [];
        const placeholders = ids.map(() => "?").join(",");
        return db
            .prepare(
                `SELECT starttime, endtime, data FROM events WHERE bucketrow IN (${placeholders}) AND starttime < ? AND endtime > ? ORDER BY starttime`,
            )
            .all(...ids, toNs, fromNs);
    }

    const window = [];
    const afk = [];
    const input = [];

    for (const r of fetch(winIds)) {
        const start = awEpochToDateTime(r.starttime);
        const end = awEpochToDateTime(r.endtime);
        if (!start || !end || end <= from || start >= to) continue;
        const data = JSON.parse(r.data ?? "{}") ?? {};
        window.push({ start, end, app: data.app ?? "", title: data.title ?? "", url: data.url ?? "" });
    }
    for (const r of fetch(afkIds)) {
        const start = awEpochToDateTime(r.starttime);
        const end = awEpochToDateTime(r.endtime);
        if (!start || !end || end <= from || start >= to) continue;
        const data = JSON.parse(r.data ?? "{}") ?? {};
        afk.push({ start, end, status: data.status ?? "unknown" });
    }
    for (const r of fetch(inputIds)) {
        const start = awEpochToDateTime(r.starttime);
        const end = awEpochToDateTime(r.endtime);
        if (!start || !end || end <= from || start >= to) continue;
        const data = JSON.parse(r.data ?? "{}") ?? {};
        input.push({ start, end, ...normalizeInputData(data) });
    }

    window.sort((a, b) => a.start.getTime() - b.start.getTime());
    afk.sort((a, b) => a.start.getTime() - b.start.getTime());
    input.sort((a, b) => a.start.getTime() - b.start.getTime());
    return { window, afk, input };
}

/**
 * @param {import('better-sqlite3').Database} db
 * @param {Date} from
 * @param {Date} to
 */
function loadAwSqliteLegacy(db, from, to) {
    const buckets = db.prepare("SELECT key, id FROM bucketmodel").all();
    const winIds = [];
    const afkIds = [];
    const inputIds = [];
    for (const b of /** @type {any[]} */ (buckets)) {
        const id = String(b.id ?? "");
        if (id.startsWith("aw-watcher-window")) winIds.push(b.key);
        else if (id.startsWith("aw-watcher-afk")) afkIds.push(b.key);
        else if (id.startsWith("aw-watcher-input")) inputIds.push(b.key);
    }

    const fromIso = from.toISOString().replace("T", " ").replace("Z", "+00:00");
    const toIso = to.toISOString().replace("T", " ").replace("Z", "+00:00");

    /**
     * @param {number[]} ids
     * @returns {any[]}
     */
    function fetch(ids) {
        if (!ids.length) return [];
        const placeholders = ids.map(() => "?").join(",");
        return db
            .prepare(
                `SELECT timestamp, duration, datastr FROM eventmodel WHERE bucket_id IN (${placeholders}) AND timestamp BETWEEN ? AND ? ORDER BY timestamp`,
            )
            .all(...ids, fromIso, toIso);
    }

    const window = [];
    const afk = [];
    const input = [];

    for (const r of fetch(winIds)) {
        const data = JSON.parse(r.datastr ?? "{}") ?? {};
        const start = new Date(r.timestamp);
        const end = new Date(start.getTime() + Math.round(Number(r.duration) * 1000));
        window.push({ start, end, app: data.app ?? "", title: data.title ?? "", url: data.url ?? "" });
    }
    for (const r of fetch(afkIds)) {
        const data = JSON.parse(r.datastr ?? "{}") ?? {};
        const start = new Date(r.timestamp);
        const end = new Date(start.getTime() + Math.round(Number(r.duration) * 1000));
        afk.push({ start, end, status: data.status ?? "unknown" });
    }
    for (const r of fetch(inputIds)) {
        const data = JSON.parse(r.datastr ?? "{}") ?? {};
        const start = new Date(r.timestamp);
        const end = new Date(start.getTime() + Math.round(Number(r.duration) * 1000));
        input.push({ start, end, ...normalizeInputData(data) });
    }

    window.sort((a, b) => a.start.getTime() - b.start.getTime());
    afk.sort((a, b) => a.start.getTime() - b.start.getTime());
    input.sort((a, b) => a.start.getTime() - b.start.getTime());
    return { window, afk, input };
}

/**
 * @param {string} dbPath
 * @param {Date} from
 * @param {Date} to
 */
function loadAwSqlite(dbPath, from, to) {
    const db = new Database(dbPath, { readonly: true, fileMustExist: true });
    try {
        const tables = new Set(
            db
                .prepare("SELECT name FROM sqlite_master WHERE type='table'")
                .all()
                .map((/** @type {any} */ r) => r.name),
        );
        if (tables.has("buckets") && tables.has("events")) return loadAwSqliteRust(db, from, to);
        if (tables.has("bucketmodel") && tables.has("eventmodel")) return loadAwSqliteLegacy(db, from, to);
        throw new Error("unrecognized ActivityWatch sqlite schema");
    } finally {
        db.close();
    }
}

/**
 * Loads window-focus, AFK, and input events from the local ActivityWatch SQLite database.
 * Mirrors PHP loadActivityWatch().
 *
 * @param {import('@timesheets/contracts').Config} config
 * @param {Date} from
 * @param {Date} to
 * @returns {{ window: any[]; afk: any[]; input: any[] }}
 */
export function loadActivityWatch(config, from, to) {
    const base = expandPath(config.paths.activitywatch);
    /** @type {{ window: any[]; afk: any[]; input: any[] }} */
    const empty = { window: [], afk: [], input: [] };
    /** @type {{ window: any[]; afk: any[]; input: any[] }} */
    let fallback = empty;

    for (const rel of ["aw-server-rust/sqlite.db", "aw-server/peewee-sqlite.v2.db"]) {
        const full = path.join(base, rel);
        if (!existsSync(full)) continue;
        const copy = copyForRead(full);
        if (!copy) {
            warning("aw", `could not copy ${full}`);
            continue;
        }
        try {
            const loaded = loadAwSqlite(copy, from, to);
            if (loaded.window.length || loaded.afk.length || loaded.input.length) return loaded;
            fallback = loaded;
        } catch (err) {
            warning("aw", `could not parse ${full} (${err instanceof Error ? err.message : String(err)})`);
        }
    }

    if (fallback.window.length || fallback.afk.length || fallback.input.length) return fallback;
    warning("aw", `no ActivityWatch sqlite found under ${base}`);
    return empty;
}
