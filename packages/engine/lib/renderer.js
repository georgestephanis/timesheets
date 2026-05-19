/**
 * Report object builder — TypeScript port of the renderJson() section of
 * src/renderers.php.
 *
 * Unlike the PHP version this module produces a plain JS object, not a JSON string.
 * Serialization happens at the IPC layer.
 */

// ─── Types (local, matching the PHP renderJson output shape) ─────────────────

/**
 * @typedef {import('./classifiers.js').CommitRow} CommitRow
 */

// ─── Main builder ─────────────────────────────────────────────────────────────

/**
 * Converts the aggregated bucket + classifier outputs into the Report contract shape.
 *
 * Mirrors PHP renderJson() but returns a structured object instead of a JSON string.
 *
 * @param {Record<string, Record<string, any>>} bucket       From classifyAndAggregate().
 * @param {Record<string, Record<string, number>>} unmatched From classifyAndAggregate().
 * @param {Date}   from
 * @param {Date}   to
 * @param {string} timezone  IANA timezone identifier.
 * @param {Record<string, Array<{s:number;e:number;p:string;g:string|null}>>} [timeline]
 * @param {string[]} [warnings]
 * @param {Record<string, string>} [summaries]
 * @returns {import('@timesheets/contracts').Report}
 */
export function buildReport(bucket, unmatched, from, to, timezone, timeline = {}, warnings = [], summaries = {}) {
    const tzFmt = new Intl.DateTimeFormat("en-CA", { timeZone: timezone });

    /** @type {import('@timesheets/contracts').Report['days']} */
    const days = {};

    for (const [date, projs] of Object.entries(bucket)) {
        days[date] = {};
        for (const [name, rec] of Object.entries(projs)) {
            days[date][name] = {
                grouping: rec.grouping ?? null,
                seconds: rec.seconds ?? 0,
                active_seconds: rec.active_seconds ?? 0,
                activity_ratio: rec.activity_ratio ?? 0,
                external: rec.external ?? {},
                detail: rec.detail ?? {},
                commits: (rec.commits ?? []).map((/** @type {CommitRow} */ c) => ({
                    time: new Intl.DateTimeFormat("en-CA", {
                        timeZone: timezone,
                        year: "numeric",
                        month: "2-digit",
                        day: "2-digit",
                        hour: "2-digit",
                        minute: "2-digit",
                        second: "2-digit",
                        hour12: false,
                    }).format(c.dt),
                    sha: c.sha,
                    subj: c.subj,
                    repo: c.repo,
                })),
            };
        }
    }

    /** @type {import('@timesheets/contracts').Report} */
    const report = {
        from: from.toISOString(),
        to: to.toISOString(),
        tz: timezone,
        days,
        unmatched,
    };

    if (warnings.length > 0) report.warnings = warnings;
    if (Object.keys(timeline).length > 0) report.timelines = timeline;
    if (Object.keys(summaries).length > 0) report.summaries = summaries;

    return report;
}
