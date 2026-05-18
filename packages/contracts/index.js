// Shared type contracts for the timesheets application.
// These JSDoc typedefs mirror the PHP renderJson() output and config.schema.json shapes.

/**
 * @typedef {Object} ProjectReport
 * @property {string} grouping - The grouping label for this project
 * @property {number} seconds - Total seconds for this project
 * @property {number} active_seconds - Active seconds for this project
 * @property {number} activity_ratio - Ratio of active to total time
 * @property {Object<string, number>} detail - Seconds by signal type (vscode, browser, slack, apps, etc.)
 * @property {Object<string, *>} external - External integration data (harvest, clickup, clockify, github)
 * @property {Array<Object>} commits - Git commit objects
 * @property {string} [summary] - LLM-generated day summary for this project
 */

/**
 * @typedef {Object} Report
 * @property {string} from - Start datetime (ISO 8601)
 * @property {string} to - End datetime (ISO 8601)
 * @property {string} tz - Timezone identifier
 * @property {Object<string, Object<string, ProjectReport>>} days - Daily project reports, keyed by date then project name
 * @property {Object<string, Array<*>>} unmatched - Unmatched signals by type
 * @property {Array<string>} warnings - Integration warning messages
 * @property {Object<string, Array<Object>>} timelines - Timeline segments keyed by project name
 */

/**
 * @typedef {Object} SlackRule
 * @property {string} workspace - Slack workspace display name (case-insensitive)
 * @property {string} [channel_glob] - Glob pattern for channel names; omit to match any channel
 */

/**
 * @typedef {Object} ProjectConfig
 * @property {string} [grouping] - Grouping label this project belongs to
 * @property {Array<string>} [repos] - Git repository paths
 * @property {Array<string>} [vscode_dirs] - VSCode workspace folder names
 * @property {Array<string>} [domains] - Browser hostnames (bare domain or glob)
 * @property {Array<SlackRule>} [slack] - Slack matching rules
 * @property {Array<string>} [ssh_hosts] - SSH hostname patterns
 * @property {Array<string>} [apps] - Application name patterns
 * @property {Array<string>} [harvest_projects] - Harvest project name globs
 * @property {string} [harvest_client] - Harvest client name (case-insensitive) for retainer-style entries
 * @property {Array<string>} [clickup_tasks] - ClickUp task name globs
 * @property {Array<string>} [clockify_projects] - Clockify project name globs
 * @property {Object<string, Object<string, string>>} [repo_remotes] - Remote name/URL snapshot keyed by repo path
 */

/**
 * @typedef {Object} GroupingConfig
 * @property {string} [color] - CSS color value for accent bars
 * @property {Array<string>} [aliases] - Alternate names that resolve to this grouping
 * @property {string} [logo] - Logo URL or data URI
 * @property {'clickup'|'harvest'|'none'} [time_tracking] - Time-tracking method for this grouping
 * @property {string} [harvest_connection] - Name of the Harvest connection to use (required when time_tracking is 'harvest')
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
 * @property {string|Array<string>} team_id
 * @property {string} token
 * @property {string} [assignee]
 */

/**
 * @typedef {Object} GitHubConnection
 * @property {string} name
 * @property {string} [token]
 * @property {Array<string>} [usernames]
 * @property {Array<string>} [authors]
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
 * @property {Array<HarvestConnection>} [harvest]
 * @property {Array<ClickUpConnection>} [clickup]
 * @property {Array<GitHubConnection>} [github]
 * @property {Array<LlmConnection>} [llm]
 * @property {Array<ClockifyConnection>} [clockify]
 */

/**
 * @typedef {Object} Config
 * @property {string} timezone - Default timezone
 * @property {Object} paths - Path configurations
 * @property {Array<string>} git_authors - Git author emails
 * @property {string} [discover_repos] - Repo discovery setting
 * @property {number} [chrome_correlation_window_seconds]
 * @property {number} [min_event_seconds_to_show]
 * @property {Object<string, ProjectConfig>} projects - Project configurations
 * @property {Array<string>} [personal_hosts]
 * @property {Array<string>} [personal_apps]
 * @property {Array<string>} [ignored_projects]
 * @property {Object<string, GroupingConfig>} [groupings]
 * @property {Array<string>} [correlated_apps]
 * @property {number} [app_correlation_window_seconds]
 * @property {number} [project_gap_window_seconds]
 * @property {number} [timeline_merge_gap_seconds]
 * @property {number} [timeline_min_seconds]
 * @property {number} [integration_http_timeout_seconds]
 * @property {number} [github_command_timeout_seconds]
 * @property {string} [github_cache_ttl]
 * @property {IntegrationsConfig} [integrations]
 */

/**
 * @typedef {Object} DateRange
 * @property {string} from - Start date (YYYY-MM-DD)
 * @property {string} to - End date (YYYY-MM-DD)
 */

/**
 * @typedef {Object} ReassignSignalPayload
 * @property {string} type - Signal type (vscode, browser, slack, apps, etc.)
 * @property {string} key - Signal identifier
 * @property {string} project - Target project name
 */

/**
 * @typedef {Object} SetGroupingPayload
 * @property {string} project - Project name
 * @property {string} grouping - Grouping label to assign
 */

/**
 * @typedef {Object} FlagIgnoredPayload
 * @property {Array<string>} projects - Project names to mark ignored
 * @property {boolean} ignored - Whether to set or clear the ignored flag
 */

export {};
