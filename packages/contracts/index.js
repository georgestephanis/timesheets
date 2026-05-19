// Shared type contracts for the timesheets application.
// These JSDoc typedefs mirror the PHP renderJson() output and config.schema.json shapes.
// Verified against live renderJson() output and config.json as of 2024-01.

/**
 * A single timeline segment within a day.
 * s/e are seconds since midnight; p is the project name; g is the grouping or null.
 *
 * @typedef {Object} TimelineSegment
 * @property {number} s - Start offset in seconds since midnight
 * @property {number} e - End offset in seconds since midnight
 * @property {string} p - Project name
 * @property {string|null} g - Grouping label, or null if ungrouped
 */

/**
 * @typedef {Object} CommitRecord
 * @property {string} time - ISO 8601 commit timestamp
 * @property {string} sha  - Full commit SHA
 * @property {string} subj - Commit subject line
 * @property {string} repo - Repo path as configured (may contain ~)
 */

/**
 * External integration data for a single project on a single day.
 * The value is an empty array [] when no integration data is present,
 * or an object with per-integration keys when data exists.
 *
 * @typedef {[]|{
 *   harvest?:  {entries: number, seconds: number, discussion: number},
 *   clickup?:  {entries: number, discussion: number},
 *   clockify?: {entries: number, seconds: number},
 *   github?:   {entries: number, activity: number, discussion: number}
 * }} ExternalData
 */

/**
 * Per-signal-type breakdown of seconds. Each key is a signal type
 * ('vscode', 'browser', 'slack', 'app') and maps to an object of
 * {identifier: seconds}.
 *
 * @typedef {Object<string, Object<string, number>>} DetailData
 */

/**
 * @typedef {Object} ProjectReport
 * @property {string|null} grouping       - Grouping label, or null if ungrouped
 * @property {number}      seconds        - Total seconds for this project on this day
 * @property {number}      active_seconds - Active (non-idle) seconds
 * @property {number}      activity_ratio - active_seconds / seconds
 * @property {ExternalData} external      - Integration data ([] when empty)
 * @property {DetailData}  detail         - Per-signal-type signal breakdown
 * @property {CommitRecord[]} commits     - Git commits attributed to this project
 */

/**
 * Top-level report envelope, as emitted by PHP renderJson().
 *
 * @typedef {Object} Report
 * @property {string} from - Report start (ISO 8601, with timezone offset)
 * @property {string} to   - Report end (ISO 8601, with timezone offset)
 * @property {string} tz   - IANA timezone identifier (e.g. "America/New_York")
 * @property {Object<string, Object<string, ProjectReport>>} days
 *   Daily project reports — outer key is "YYYY-MM-DD", inner key is project name.
 * @property {Object<string, Object<string, number>>} unmatched
 *   Unmatched signals by type ('vscode'|'browser'|'slack'|'apps'|'harvest'|'clickup'|'clockify'|'github'),
 *   each mapping signal identifiers to seconds.
 * @property {Object<string, TimelineSegment[]>} [timelines]
 *   Present only when non-empty. Outer key is "YYYY-MM-DD"; value is ordered segments.
 * @property {string[]} [warnings]
 *   Present only when non-empty. Human-readable integration warning messages.
 * @property {Object<string, string>} [summaries]
 *   Present only when non-empty. LLM-generated summaries keyed by "YYYY-MM-DD".
 * @property {number} [cachedAt]
 *   Unix epoch ms of the source-cache files, present only when all data came from cache.
 */

/**
 * @typedef {Object} SlackRule
 * @property {string}  workspace    - Slack workspace display name (case-insensitive match)
 * @property {string}  [channel_glob] - Glob pattern for channel names; omit to match any channel
 */

/**
 * @typedef {Object} ProjectConfig
 * @property {string}   [grouping]      - Grouping label this project belongs to
 * @property {string[]} [repos]         - Git repository paths (may use ~)
 * @property {string[]} [vscode_dirs]   - VSCode workspace folder names
 * @property {string[]} [domains]       - Browser hostnames (bare domain or glob)
 * @property {SlackRule[]} [slack]      - Slack matching rules
 * @property {string[]} [ssh_hosts]     - SSH hostname patterns
 * @property {string[]} [apps]          - Application name patterns
 * @property {string[]} [harvest_projects]  - Harvest project name globs
 * @property {string}   [harvest_client]   - Harvest client name (case-insensitive)
 * @property {string[]} [clickup_tasks]    - ClickUp task name globs
 * @property {string[]} [clockify_projects] - Clockify project name globs
 * @property {Object<string, Object<string, string>>} [repo_remotes]
 *   Remote name/URL snapshot keyed by repo path, then remote name.
 */

/**
 * @typedef {Object} GroupingConfig
 * @property {string} [color]        - CSS color value for accent bars
 * @property {string[]} [aliases]    - Alternate names that resolve to this grouping
 * @property {string} [logo]         - Logo URL or data URI
 * @property {'clickup'|'harvest'|'clockify'|'none'} [time_tracking]
 * @property {string} [harvest_connection] - Name of the Harvest connection (required when time_tracking is 'harvest')
 */

/**
 * @typedef {Object} HarvestConnection
 * @property {string} name
 * @property {string} account_id
 * @property {string} token
 * @property {string|number} [user_id]
 */

/**
 * @typedef {Object} ClickUpConnection
 * @property {string} name
 * @property {string|string[]} team_id
 * @property {string} token
 * @property {string} [assignee]
 */

/**
 * @typedef {Object} GitHubConnection
 * @property {string}   name
 * @property {string}   [token]
 * @property {string[]} [usernames]
 * @property {string[]} [authors]
 */

/**
 * @typedef {Object} LlmConnection
 * @property {string} name
 * @property {string} base_url
 * @property {string} [api_key]
 * @property {string} [model]
 * @property {number} [timeout]
 */

/**
 * @typedef {Object} ClockifyConnection
 * @property {string} name
 * @property {string} api_key
 * @property {string} [workspace_id]
 * @property {string} [user_id]
 */

/**
 * @typedef {Object} IntegrationsConfig
 * @property {HarvestConnection[]}  [harvest]
 * @property {ClickUpConnection[]}  [clickup]
 * @property {GitHubConnection[]}   [github]
 * @property {LlmConnection[]}      [llm]
 * @property {ClockifyConnection[]} [clockify]
 */

/**
 * Root config.json shape.
 *
 * @typedef {Object} Config
 * @property {string} timezone - IANA timezone identifier
 * @property {Object} paths    - Filesystem paths for data sources
 * @property {string} paths.activitywatch - Path to ActivityWatch data directory
 * @property {string} paths.chrome        - Path to Chrome user data directory
 * @property {string|string[]|null} paths.chrome_profiles - Profile name(s), or null to auto-detect
 * @property {string[]} git_authors       - Git author email addresses
 * @property {string}   [discover_repos]  - Repo discovery strategy ('github_desktop' or path)
 * @property {number}   [chrome_correlation_window_seconds]
 * @property {number}   [min_event_seconds_to_show]
 * @property {Object<string, ProjectConfig>} projects
 * @property {string[]} [personal_hosts]
 * @property {string[]} [personal_apps]
 * @property {string[]} [ignored_projects]
 * @property {Object<string, GroupingConfig>} [groupings]
 * @property {string[]} [correlated_apps]
 * @property {number}   [app_correlation_window_seconds]
 * @property {number}   [project_gap_window_seconds]
 * @property {number}   [timeline_merge_gap_seconds]
 * @property {number}   [timeline_min_seconds]
 * @property {number}   [integration_http_timeout_seconds]
 * @property {number}   [github_command_timeout_seconds]
 * @property {string}   [github_cache_ttl]
 * @property {IntegrationsConfig} [integrations]
 */

/**
 * @typedef {Object} DateRange
 * @property {string} from - Start date (YYYY-MM-DD)
 * @property {string} to   - End date (YYYY-MM-DD)
 */

/**
 * @typedef {Object} ReassignSignalPayload
 * @property {string}  type          - Signal type ('vscode'|'browser'|'slack'|'apps'|'harvest'|'clickup'|'clockify'|'github')
 * @property {string}  key           - Signal identifier (domain, workspace, app name, etc.)
 * @property {string}  project       - Target project name
 * @property {boolean} [createProject] - When true, create the project if it does not already exist
 */

/**
 * @typedef {Object} SetGroupingPayload
 * @property {string} project  - Project name
 * @property {string} grouping - Grouping label to assign
 */

/**
 * @typedef {Object} FlagIgnoredPayload
 * @property {string[]} projects - Project names to mark ignored
 * @property {boolean}  ignored  - Whether to set or clear the ignored flag
 */

export {};
