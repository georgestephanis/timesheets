/**
 * Config file I/O and signal-application helpers — TypeScript port of src/config.php.
 */

import { promises as fs } from "fs";
import path from "path";

/**
 * Loads and parses config.json. Returns the parsed object.
 * @param {string} configPath Absolute path to config.json.
 * @returns {Promise<import('@timesheets/contracts').Config>}
 */
export async function loadConfig(configPath) {
    const raw = await fs.readFile(configPath, "utf8");
    return JSON.parse(raw);
}

/**
 * Saves config atomically with a timestamped backup.
 *
 * Writes to a temp file then renames over configPath so readers never see a partial
 * write. The backup is created before any write — mirrors PHP saveConfigWithBackup().
 *
 * @param {import('@timesheets/contracts').Config} config
 * @param {string} configPath  Absolute path to config.json.
 * @param {string} source      Tag for the backup filename (e.g. 'api', 'engine', 'llm-suggest').
 * @returns {Promise<string>}  Absolute path of the backup file created.
 */
export async function saveConfigWithBackup(config, configPath, source) {
    const backupDir = path.join(path.dirname(configPath), "reports", "config");
    await fs.mkdir(backupDir, { recursive: true });

    const stamp = new Date()
        .toISOString()
        .replace(/[-:]/g, "")
        .replace("T", "T")
        .replace(/\.\d+Z$/, "");
    const backupPath = path.join(backupDir, `config.${source}.${stamp}.json`);
    await fs.copyFile(configPath, backupPath);

    const json = JSON.stringify(config, null, 2) + "\n";
    const tmp = configPath + ".tmp." + process.pid;
    await fs.writeFile(tmp, json, { flag: "wx" });
    await fs.rename(tmp, configPath);

    return backupPath;
}

/**
 * Appends value to arr[key] if not already present — mirrors PHP addUniqueValue().
 * Mutates arr in place.
 * @param {Record<string, unknown>} arr
 * @param {string} key
 * @param {string} value
 */
export function addUniqueValue(arr, key, value) {
    if (!Array.isArray(arr[key])) arr[key] = [];
    const list = /** @type {string[]} */ (arr[key]);
    if (!list.includes(value)) list.push(value);
}

/**
 * Parses a "Workspace / channel" Slack signal label into its two parts.
 * Returns null when the format is invalid — mirrors PHP parseSlackSignal().
 * @param {string} value
 * @returns {{ workspace: string; channel: string } | null}
 */
export function parseSlackSignal(value) {
    const idx = value.indexOf(" / ");
    if (idx === -1) return null;
    const workspace = value.slice(0, idx).trim();
    const channel = value.slice(idx + 3).trim();
    if (!workspace || !channel) return null;
    return { workspace, channel };
}

// Slack pseudo-channel names that should not become channel_glob rules.
const SLACK_PSEUDO_CHANNELS = new Set(["__threads__", "__activity__", "__huddle__"]);

/**
 * Applies one signal-to-project assignment to the config in place.
 *
 * Handles vscode, browser, slack, and apps (including ssh: prefix).
 * Silent no-op on unknown project, invalid input, or already-present value —
 * mirrors PHP applySignalToProject().
 *
 * @param {import('@timesheets/contracts').Config} config  Mutated in place.
 * @param {string} kind    'vscode' | 'browser' | 'slack' | 'apps'
 * @param {string} value   Signal value.
 * @param {string} project Exact project name key in config.projects.
 */
export function applySignalToProject(config, kind, value, project) {
    const p = config.projects?.[project];
    if (!p) return;

    switch (kind) {
        case "vscode":
            addUniqueValue(p, "vscode_dirs", value);
            break;

        case "browser":
            if (!value || value === "(no url)") break;
            addUniqueValue(p, "domains", value);
            break;

        case "slack": {
            const parsed = parseSlackSignal(value);
            if (!parsed) break;
            if (!Array.isArray(p.slack)) p.slack = [];
            /** @type {{ workspace: string; channel_glob?: string }} */
            const rule = { workspace: parsed.workspace };
            if (!SLACK_PSEUDO_CHANNELS.has(parsed.channel)) {
                rule.channel_glob = parsed.channel;
            }
            const alreadyPresent = p.slack.some(
                (ex) =>
                    ex.workspace === rule.workspace &&
                    (ex.channel_glob ?? undefined) === (rule.channel_glob ?? undefined),
            );
            if (!alreadyPresent) p.slack.push(rule);
            break;
        }

        case "apps":
            if (value.startsWith("ssh:")) {
                addUniqueValue(p, "ssh_hosts", value.slice(4));
            } else {
                addUniqueValue(p, "apps", value);
            }
            break;
    }
}
