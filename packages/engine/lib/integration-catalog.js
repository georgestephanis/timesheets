/**
 * Catalog fetchers for Harvest and ClickUp integrations.
 * JS port of src/integrations/harvest-catalog.php and clickup-catalog.php.
 */

/**
 * Returns all active Harvest project names for one connection.
 * Tries GET /v2/projects first; falls back to scanning /v2/time_entries.
 *
 * @param {Record<string, unknown>} conn  One entry from config.integrations.harvest
 * @param {number} timeoutMs
 * @returns {Promise<string[]>}
 */
export async function harvestProjectNames(conn, timeoutMs = 20_000) {
    const token = String(conn.token ?? "");
    const accountId = String(conn.account_id ?? "");
    if (!token || !accountId) return [];

    const headers = {
        Authorization: `Bearer ${token}`,
        "Harvest-Account-ID": accountId,
        "User-Agent": "activity-report",
        Accept: "application/json",
    };

    const names = new Set();
    let listed = false;

    // Primary: paginated /v2/projects
    let page = 1;
    let pages = 1;
    do {
        try {
            const res = await fetch(`https://api.harvestapp.com/v2/projects?is_active=true&page=${page}`, {
                headers,
                signal: AbortSignal.timeout(timeoutMs),
            });
            if (!res.ok) break;
            const data = /** @type {any} */ (await res.json());
            for (const p of data.projects ?? []) {
                const n = String(p.name ?? "").trim();
                if (n) {
                    names.add(n);
                    listed = true;
                }
            }
            pages = Math.max(1, data.total_pages ?? 1);
        } catch {
            break;
        }
        page++;
    } while (page <= pages);

    if (listed) return [...names];

    // Fallback: /v2/time_entries for the past year (when /projects is not authorized)
    const from = new Date(Date.now() - 365 * 86_400_000).toISOString().slice(0, 10);
    const to = new Date().toISOString().slice(0, 10);
    page = 1;
    pages = 1;
    do {
        try {
            const res = await fetch(`https://api.harvestapp.com/v2/time_entries?from=${from}&to=${to}&page=${page}`, {
                headers,
                signal: AbortSignal.timeout(timeoutMs),
            });
            if (!res.ok) break;
            const data = /** @type {any} */ (await res.json());
            for (const te of data.time_entries ?? []) {
                const n = String(te.project?.name ?? "").trim();
                if (n) names.add(n);
            }
            pages = Math.max(1, data.total_pages ?? 1);
        } catch {
            break;
        }
        page++;
    } while (page <= pages);

    return [...names];
}

/**
 * Returns all space/folder/list names reachable via one ClickUp connection.
 * Walks: team → spaces → (folders → lists) + (folderless lists per space).
 *
 * @param {Record<string, unknown>} conn  One entry from config.integrations.clickup
 * @param {number} timeoutMs
 * @returns {Promise<string[]>}
 */
export async function clickupNames(conn, timeoutMs = 20_000) {
    const token = String(conn.token ?? "");
    const rawTeams = conn.team_id ?? [];
    const teamIds = (Array.isArray(rawTeams) ? rawTeams : [rawTeams]).map(String).filter(Boolean);
    if (!token || !teamIds.length) return [];

    const headers = { Authorization: token, Accept: "application/json" };
    const names = new Set();

    const getJson = async (url) => {
        const res = await fetch(url, { headers, signal: AbortSignal.timeout(timeoutMs) });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return /** @type {any} */ (await res.json());
    };

    for (const teamId of teamIds) {
        let spaces;
        try {
            spaces = await getJson(
                `https://api.clickup.com/api/v2/team/${encodeURIComponent(teamId)}/space?archived=false`,
            );
        } catch {
            continue;
        }

        for (const space of spaces.spaces ?? []) {
            const spaceId = String(space.id ?? "");
            const spaceName = String(space.name ?? "").trim();
            if (spaceName) names.add(spaceName);
            if (!spaceId) continue;

            // Folders (and their lists)
            let foldersData = { folders: [] };
            try {
                foldersData = await getJson(
                    `https://api.clickup.com/api/v2/space/${encodeURIComponent(spaceId)}/folder?archived=false`,
                );
            } catch {
                /* skip */
            }

            for (const folder of foldersData.folders ?? []) {
                const folderId = String(folder.id ?? "");
                const folderName = String(folder.name ?? "").trim();
                if (folderName) names.add(folderName);
                if (!folderId) continue;

                let listsData = { lists: [] };
                try {
                    listsData = await getJson(
                        `https://api.clickup.com/api/v2/folder/${encodeURIComponent(folderId)}/list?archived=false`,
                    );
                } catch {
                    /* skip */
                }

                for (const list of listsData.lists ?? []) {
                    const n = String(list.name ?? "").trim();
                    if (n) names.add(n);
                }
            }

            // Folderless lists
            let spaceListsData = { lists: [] };
            try {
                spaceListsData = await getJson(
                    `https://api.clickup.com/api/v2/space/${encodeURIComponent(spaceId)}/list?archived=false`,
                );
            } catch {
                /* skip */
            }

            for (const list of spaceListsData.lists ?? []) {
                const n = String(list.name ?? "").trim();
                if (n) names.add(n);
            }
        }
    }

    return [...names];
}
