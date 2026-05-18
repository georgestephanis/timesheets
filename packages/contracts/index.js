// Types for the timesheets application
// Based on the existing PHP implementation and JSON output

/**
 * @typedef {Object} ProjectReport
 * @property {string} grouping - The grouping label for this project
 * @property {number} seconds - Total seconds for this project
 * @property {number} active_seconds - Active seconds for this project
 * @property {number} activity_ratio - Ratio of active time to total time
 * @property {Object} detail - Breakdown by signal type
 * @property {Object} external - External integration counts
 * @property {Array<Object>} commits - Git commit history
 */

/**
 * @typedef {Object} DayReport
 * @property {string} projectName - Name of the project
 * @property {ProjectReport} [project] - Project report data
 */

/**
 * @typedef {Object} Report
 * @property {string} from - Start datetime
 * @property {string} to - End datetime
 * @property {string} tz - Timezone
 * @property {Object<string, DayReport>} days - Daily reports
 * @property {Object} unmatched - Unmatched signals
 * @property {Array<string>} warnings - Integration warnings
 * @property {Object<string, Array<Object>>} timelines - Timeline data
 */

/**
 * @typedef {Object} Config
 * @property {string} timezone - Default timezone
 * @property {Object} paths - Path configurations
 * @property {Array<string>} git_authors - Git author emails
 * @property {string} discover_repos - Repo discovery setting
 * @property {number} chrome_correlation_window_seconds - Chrome correlation window
 * @property {number} min_event_seconds_to_show - Minimum event duration
 * @property {Object<string, ProjectConfig>} projects - Project configurations
 * @property {Array<string>} personal_hosts - Personal hosts
 * @property {Array<string>} personal_apps - Personal apps
 * @property {Array<string>} ignored_projects - Ignored projects
 * @property {Object<string, GroupingConfig>} groupings - Grouping configurations
 * @property {Array<string>} correlated_apps - Correlated apps
 * @property {number} app_correlation_window_seconds - App correlation window
 * @property {number} project_gap_window_seconds - Project gap window
 * @property {number} timeline_merge_gap_seconds - Timeline merge gap
 * @property {number} timeline_min_seconds - Timeline minimum seconds
 * @property {number} integration_http_timeout_seconds - Integration timeout
 * @property {number} github_command_timeout_seconds - GitHub timeout
 * @property {string} github_cache_ttl - GitHub cache TTL
 * @property {Object} groupings_map - Grouping mapping
 * @property {Object} integrations - Integration configurations
 */

/**
 * @typedef {Object} ProjectConfig
 * @property {string} [grouping] - Grouping label
 * @property {Array<string>} [repos] - Repository paths
 * @property {Array<string>} [vscode_dirs] - VSCode directory names
 * @property {Array<string>} [domains] - Browser domains
 * @property {Array<Object>} [slack] - Slack configurations
 * @property {Array<string>} [ssh_hosts] - SSH host patterns
 * @property {Array<string>} [apps] - App names
 * @property {Array<string>} [harvest_projects] - Harvest project names
 * @property {Array<string>} [clickup_tasks] - ClickUp task names
 * @property {Object<string, Object>} [repo_remotes] - Repository remotes
 * @property {Array<string>} [clockify_projects] - Clockify project names
 */

/**
 * @typedef {Object} GroupingConfig
 * @property {string} [color] - Color for the grouping
 * @property {Array<string>} [aliases] - Aliases for the grouping
 * @property {string} [logo] - Logo URL for the grouping
 */

/**
 * @typedef {Object} MutationPayload
 * @property {string} action - The action to perform
 * @property {Object} data - The data for the action
 */

export {};
