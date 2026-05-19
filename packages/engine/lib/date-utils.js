/**
 * Date-range resolution and iteration utilities — TypeScript port of
 * relevant sections of src/cli.php and src/cache.php.
 */

/**
 * Returns each calendar day (as midnight Date in the given timezone) touched by [from, to].
 *
 * Mirrors PHP rangeDays(). Uses Intl to determine the local date at each step so
 * DST transitions are handled correctly.
 *
 * @param {Date} from
 * @param {Date} to
 * @param {string} timezone IANA timezone identifier.
 * @returns {Date[]}  Each element is a Date at the UTC instant that corresponds to midnight
 *                    in `timezone` for that calendar day.
 */
export function rangeDays(from, to, timezone) {
    const fmt = new Intl.DateTimeFormat("en-CA", {
        timeZone: timezone,
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
    });

    /** @param {Date} d */
    const localDateStr = (d) => fmt.format(d); // "YYYY-MM-DD"

    /** @param {string} ymd */
    const midnightUtc = (ymd) => {
        // "YYYY-MM-DDT00:00:00" interpreted in the given timezone → UTC instant.
        return new Date(
            new Date(`${ymd}T00:00:00`).toLocaleString("en-US", { timeZone: timezone }) ===
                new Date(`${ymd}T00:00:00`).toLocaleString("en-US", { timeZone: timezone })
                ? // Use a reliable approach: format and reparse
                  `${ymd}T00:00:00`
                : `${ymd}T00:00:00`,
        );
    };

    // Walk day by day using a 26-hour step to safely cross DST boundaries
    // (never skip a day, never double-count one).
    const days = [];
    let cursor = new Date(from);
    const end = new Date(to);

    let prevDateStr = "";
    while (cursor <= end) {
        const ds = localDateStr(cursor);
        if (ds !== prevDateStr) {
            days.push(localMidnight(ds, timezone));
            prevDateStr = ds;
        }
        cursor = new Date(cursor.getTime() + 60 * 60 * 1000); // +1 hour steps
    }
    return days;
}

/**
 * Returns the UTC Date corresponding to midnight in `timezone` for the given YYYY-MM-DD string.
 * @param {string} ymd
 * @param {string} timezone
 * @returns {Date}
 */
export function localMidnight(ymd, timezone) {
    // Build a UTC timestamp that represents midnight in the target timezone.
    // Strategy: use Intl to find the UTC offset at approximate midnight, then adjust.
    const approx = new Date(`${ymd}T12:00:00Z`); // noon UTC, definitely in the right day
    const localStr = approx.toLocaleString("en-CA", {
        timeZone: timezone,
        hour12: false,
        year: "numeric",
        month: "2-digit",
        day: "2-digit",
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
    });
    // localStr = "YYYY-MM-DD, HH:mm:ss"
    const [datePart, timePart] = localStr.split(", ");
    const [h, min, s] = timePart.split(":").map(Number);
    // How far from midnight are we in local time?
    const localSecondsFromMidnight = h * 3600 + min * 60 + s;
    // Subtract that offset to get to midnight UTC
    const noonLocal = approx.getTime() - localSecondsFromMidnight * 1000;
    // That's local midnight (approximately — may be off by an hour for DST edge cases)
    // Verify the result maps to the correct local date:
    const result = new Date(noonLocal);
    const verify = new Intl.DateTimeFormat("en-CA", { timeZone: timezone }).format(result);
    if (verify !== ymd) {
        // Adjust by one hour in either direction for DST transitions.
        for (const delta of [-3600000, 3600000]) {
            const candidate = new Date(noonLocal + delta);
            if (new Intl.DateTimeFormat("en-CA", { timeZone: timezone }).format(candidate) === ymd) {
                return candidate;
            }
        }
    }
    return result;
}

/**
 * Returns true if `to` is strictly before today in the given timezone — meaning
 * all data in the range is historical and will not change.
 * Mirrors PHP rangeIsHistorical().
 * @param {Date} to
 * @param {string} timezone
 * @returns {boolean}
 */
export function rangeIsHistorical(to, timezone) {
    const fmt = new Intl.DateTimeFormat("en-CA", { timeZone: timezone });
    const today = fmt.format(new Date());
    const toDate = fmt.format(to);
    return toDate < today;
}

/**
 * Returns "YYYY-MM-DD" for a single day or "YYYY-MM-DD--YYYY-MM-DD" for a range.
 * Mirrors PHP reportsCacheKey().
 * @param {Date} from
 * @param {Date} to
 * @param {string} timezone
 * @returns {string}
 */
export function reportsCacheKey(from, to, timezone) {
    const fmt = new Intl.DateTimeFormat("en-CA", { timeZone: timezone });
    const f = fmt.format(from);
    const t = fmt.format(to);
    return f === t ? f : `${f}--${t}`;
}

/**
 * Returns the cache key string for a full calendar day (YYYY-MM-DD).
 * Mirrors PHP dailyCacheKey().
 * @param {Date} day  A Date at (or near) midnight in the relevant timezone.
 * @param {string} timezone
 * @returns {string}
 */
export function dailyCacheKey(day, timezone) {
    return new Intl.DateTimeFormat("en-CA", { timeZone: timezone }).format(day);
}

/**
 * Resolves a from/to date pair from options, mirroring PHP resolveDateRange().
 *
 * @param {string} timezone IANA timezone identifier.
 * @param {{ from?: string|null, to?: string|null, days?: number|null }} opts
 * @returns {{ from: Date, to: Date }}
 */
export function resolveDateRange(timezone, opts = {}) {
    const now = new Date();

    if (opts.from) {
        const fromStr = opts.from;
        const from = /^\d{4}-\d{2}-\d{2}$/.test(fromStr) ? localMidnight(fromStr, timezone) : new Date(fromStr);

        let to;
        if (opts.to) {
            const toStr = opts.to;
            if (/^\d{4}-\d{2}-\d{2}$/.test(toStr)) {
                // End of day: YYYY-MM-DD 23:59:59 local
                const nextDay = localMidnight(toStr, timezone);
                to = new Date(nextDay.getTime() + 24 * 3600 * 1000 - 1000);
            } else {
                to = new Date(toStr);
            }
        } else {
            to = now;
        }

        return { from, to };
    }

    const days = opts.days ?? 7;
    const to = now;
    const today = new Intl.DateTimeFormat("en-CA", { timeZone: timezone }).format(now);
    const todayMidnight = localMidnight(today, timezone);
    const from = new Date(todayMidnight.getTime() - days * 24 * 3600 * 1000);
    return { from, to };
}
