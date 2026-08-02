/**
 * Claude Code loader: reads session logs from ~/.claude/projects/*​/*.jsonl.
 * TypeScript port of src/loader-claude-code.php.
 */

import { createReadStream, existsSync, readdirSync } from "fs";
import { createInterface } from "readline";
import path from "path";
import { expandPath, projectForLocalPath } from "./helpers.js";

/**
 * Returns every *.jsonl file one level under each subdirectory of dir
 * (i.e. dir/*​/*.jsonl), without pulling in a glob dependency.
 * @param {string} dir
 * @returns {string[]}
 */
function findSessionFiles(dir) {
    /** @type {string[]} */
    const files = [];
    let subdirs;
    try {
        subdirs = readdirSync(dir, { withFileTypes: true });
    } catch {
        return files;
    }
    for (const entry of subdirs) {
        if (!entry.isDirectory()) continue;
        const sub = path.join(dir, entry.name);
        let entries;
        try {
            entries = readdirSync(sub, { withFileTypes: true });
        } catch {
            continue;
        }
        for (const f of entries) {
            if (f.isFile() && f.name.endsWith(".jsonl")) {
                files.push(path.join(sub, f.name));
            }
        }
    }
    return files;
}

/**
 * Streams a single Claude Code transcript file and extracts per-cwd line counts,
 * the earliest/latest timestamp, a label (first human user message, truncated), and a
 * detail string (that first message plus a few follow-ups, truncated) suitable as raw
 * material for an LLM-generated "what happened" summary.
 *
 * @param {string} file
 * @returns {Promise<{ cwdCounts: Record<string, number>; minTs: Date; maxTs: Date; label: string; detail: string } | null>}
 */
async function parseClaudeCodeSessionFile(file) {
    /** @type {Record<string, number>} */
    const cwdCounts = {};
    /** @type {Date | null} */
    let minTs = null;
    /** @type {Date | null} */
    let maxTs = null;
    let label = "";
    /** @type {string[]} */
    const userMessages = [];

    const rl = createInterface({ input: createReadStream(file, { encoding: "utf8" }), crlfDelay: Infinity });
    for await (const rawLine of rl) {
        const line = rawLine.trim();
        if (!line) continue;
        let entry;
        try {
            entry = JSON.parse(line);
        } catch {
            continue;
        }
        if (!entry || typeof entry !== "object") continue;

        const cwd = entry.cwd;
        const tsRaw = entry.timestamp;
        if (typeof cwd !== "string" || cwd === "" || typeof tsRaw !== "string") continue;
        const ts = new Date(tsRaw);
        if (isNaN(ts.getTime())) continue;

        cwdCounts[cwd] = (cwdCounts[cwd] ?? 0) + 1;
        if (minTs === null || ts < minTs) minTs = ts;
        if (maxTs === null || ts > maxTs) maxTs = ts;

        if (entry.type === "user" && entry.message?.role === "user") {
            const content = entry.message?.content;
            let text = null;
            if (typeof content === "string") {
                text = content;
            } else if (Array.isArray(content)) {
                for (const block of content) {
                    if (block && typeof block === "object" && block.type === "text" && typeof block.text === "string") {
                        text = block.text;
                        break;
                    }
                }
            }
            // Skip tool-result/system-injected "user" turns (no free text, or a
            // slash-command/system-reminder wrapper) — only real typed prompts are
            // useful as summary material.
            if (typeof text === "string" && text !== "" && !text.startsWith("<")) {
                if (label === "") label = text;
                if (userMessages.length < 8) userMessages.push(text);
            }
        }
    }

    if (minTs === null || maxTs === null || Object.keys(cwdCounts).length === 0) return null;

    label = label.replace(/\s+/g, " ").trim();
    if (label.length > 120) label = label.slice(0, 119) + "…";

    let detail = userMessages
        .map((m) => {
            const t = m.replace(/\s+/g, " ").trim();
            return t.length > 200 ? t.slice(0, 199) + "…" : t;
        })
        .join(" | ");
    if (detail.length > 1000) detail = detail.slice(0, 999) + "…";

    return { cwdCounts, minTs, maxTs, label, detail };
}

/**
 * Loads Claude Code sessions attributable to a configured project, from every
 * *.jsonl transcript under paths.claude_code_logs.
 *
 * Each transcript file is treated as one session. Lines are streamed and parsed as
 * JSON; only lines carrying both a "cwd" and a "timestamp" are considered. The session
 * is attributed to whichever cwd appears most often in the file, then resolved to a
 * project via projectForLocalPath(). Sessions with no matching project, or entirely
 * outside [from, to], are skipped.
 *
 * @param {import('@timesheets/contracts').Config} config
 * @param {Date} from
 * @param {Date} to
 * @returns {Promise<Array<{ start: Date; end: Date; project: string; source: string; label: string; detail: string }>>}
 */
export async function loadClaudeCodeSessions(config, from, to) {
    const dir = expandPath(config.paths?.claude_code_logs ?? "~/.claude/projects");
    if (!existsSync(dir)) return [];

    const files = findSessionFiles(dir);
    const rows = [];
    for (const file of files) {
        const session = await parseClaudeCodeSessionFile(file);
        if (session === null) continue;
        const { cwdCounts, minTs, maxTs, label, detail } = session;
        if (maxTs < from || minTs > to) continue;

        let cwd = null;
        let bestCount = -1;
        for (const [c, count] of Object.entries(cwdCounts)) {
            if (count > bestCount) {
                bestCount = count;
                cwd = c;
            }
        }
        const project = cwd === null ? null : projectForLocalPath(config, cwd);
        if (project === null) continue;

        rows.push({ start: minTs, end: maxTs, project, source: "claude", label, detail });
    }

    rows.sort((a, b) => a.start.getTime() - b.start.getTime());
    return rows;
}
