/**
 * Antigravity IDE loader: reads conversation logs from
 * ~/.gemini/antigravity-ide/conversations/*.db.
 * TypeScript port of src/loader-antigravity.php.
 */

import { existsSync, readdirSync, copyFileSync, statSync, unlinkSync } from "fs";
import { tmpdir } from "os";
import { execFileSync } from "child_process";
import path from "path";
import Database from "better-sqlite3";
import { expandPath, projectForLocalPath } from "./helpers.js";

/**
 * @param {string} src
 * @returns {string|null}
 */
function copyForRead(src) {
    const dest = path.join(tmpdir(), `timesheets-antigravity-${Date.now()}-${Math.random().toString(36).slice(2)}.db`);
    try {
        copyFileSync(src, dest);
        return dest;
    } catch {
        return null;
    }
}

/**
 * Extracts the workspace filesystem path from a conversation database's
 * trajectory_metadata_blob, by regexing the raw blob bytes for the first file:// URI.
 * @param {string} dbFile
 * @returns {string | null}
 */
function antigravityWorkspacePath(dbFile) {
    const copy = copyForRead(dbFile);
    if (!copy) return null;

    let blob = null;
    let db;
    try {
        db = new Database(copy, { readonly: true, fileMustExist: true });
        const row = /** @type {any} */ (db.prepare("SELECT data FROM trajectory_metadata_blob LIMIT 1").get());
        blob = row?.data ?? null;
    } catch {
        blob = null;
    } finally {
        db?.close();
        try {
            unlinkSync(copy);
        } catch {
            /* ignore */
        }
    }

    if (blob === null) return null;
    const text = Buffer.isBuffer(blob) ? blob.toString("latin1") : String(blob);
    const m = text.match(/file:\/\/\/[^"\x00-\x1f]+/);
    if (!m) return null;
    return m[0].slice("file://".length);
}

/**
 * Returns a file's creation time (birthtime) on macOS, or null if unavailable.
 *
 * Node's fs.statSync().birthtime is unreliable on some filesystems/platforms, so this
 * shells out to `stat -f %B` (macOS/BSD), mirroring the PHP implementation's approach.
 * @param {string} filePath
 * @returns {number | null}
 */
function antigravityFileBirthtime(filePath) {
    try {
        const out = execFileSync("stat", ["-f", "%B", filePath], { encoding: "utf8" }).trim();
        return /^\d+$/.test(out) ? parseInt(out, 10) : null;
    } catch {
        return null;
    }
}

/**
 * Loads Antigravity IDE sessions attributable to a configured project, from every
 * *.db conversation database under paths.antigravity_logs.
 *
 * The workspace path isn't stored as a plain column — it's recovered from the
 * trajectory_metadata_blob protobuf blob by regexing for the first file:// URI it
 * contains. There is no reliable per-event timestamp in these databases either, so
 * timing is approximated from the file's birthtime (session start) and mtime (last
 * update); rows are marked approximate_timing so callers can caveat the display.
 *
 * @param {import('@timesheets/contracts').Config} config
 * @param {Date} from
 * @param {Date} to
 * @returns {Array<{ start: Date; end: Date; project: string; source: string; label: string; detail: string; approximate_timing: boolean }>}
 */
export function loadAntigravitySessions(config, from, to) {
    const dir = expandPath(config.paths?.antigravity_logs ?? "~/.gemini/antigravity-ide/conversations");
    if (!existsSync(dir)) return [];

    const rows = [];
    let files;
    try {
        files = readdirSync(dir).filter((f) => f.endsWith(".db"));
    } catch {
        return [];
    }

    for (const name of files) {
        const file = path.join(dir, name);
        let mtimeMs;
        try {
            mtimeMs = statSync(file).mtimeMs;
        } catch {
            continue;
        }
        const end = new Date(mtimeMs);
        if (end < from) continue; // cheap pre-filter before opening SQLite

        const birthSec = antigravityFileBirthtime(file);
        const start = birthSec !== null ? new Date(birthSec * 1000) : new Date(mtimeMs);
        if (start > to) continue;

        const workspace = antigravityWorkspacePath(file);
        if (workspace === null) continue;
        const project = projectForLocalPath(config, workspace);
        if (project === null) continue;

        const label = path.basename(workspace);
        rows.push({
            start,
            end,
            project,
            source: "antigravity",
            label,
            // Antigravity's payload is an opaque protobuf blob — no message text is
            // recoverable, so detail just echoes the label for a uniform row shape.
            detail: label,
            approximate_timing: true,
        });
    }

    rows.sort((a, b) => a.start.getTime() - b.start.getTime());
    return rows;
}
