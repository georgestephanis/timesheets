import { z } from "zod";

const slackRuleSchema = z.object({
    workspace: z.string(),
    channel_glob: z.string().optional(),
});

export const projectSchema = z.object({
    grouping: z.string().optional(),
    repos: z.array(z.string()).optional(),
    vscode_dirs: z.array(z.string()).optional(),
    domains: z.array(z.string()).optional(),
    slack: z.array(slackRuleSchema).optional(),
    ssh_hosts: z.array(z.string()).optional(),
    apps: z.array(z.string()).optional(),
    harvest_projects: z.array(z.string()).optional(),
    harvest_client: z.string().optional(),
    clickup_tasks: z.array(z.string()).optional(),
    clockify_projects: z.array(z.string()).optional(),
    ndizi_projects: z.array(z.string()).optional(),
    repo_remotes: z.record(z.record(z.string())).optional(),
});

export const groupingSchema = z.object({
    color: z.string().optional(),
    aliases: z.array(z.string()).optional(),
    logo: z.string().optional(),
    time_tracking: z.enum(["clickup", "harvest", "clockify", "ndizi", "none"]).optional(),
    harvest_connection: z.string().optional(),
});

const harvestConnectionSchema = z.object({
    name: z.string(),
    account_id: z.string(),
    token: z.string(),
    user_id: z.union([z.string(), z.number()]).optional(),
});

const clickupConnectionSchema = z.object({
    name: z.string(),
    team_id: z.union([z.string(), z.array(z.string())]),
    token: z.string(),
    assignee: z.string().optional(),
});

const githubConnectionSchema = z.object({
    name: z.string(),
    token: z.string().optional(),
    usernames: z.array(z.string()).optional(),
    authors: z.array(z.string()).optional(),
});

const llmConnectionSchema = z.object({
    name: z.string(),
    base_url: z.string(),
    api_key: z.string().optional(),
    model: z.string().optional(),
    timeout: z.number().optional(),
});

const clockifyConnectionSchema = z.object({
    name: z.string(),
    api_key: z.string(),
    workspace_id: z.string().optional(),
    user_id: z.string().optional(),
});

const ndiziConnectionSchema = z.object({
    name: z.string(),
    site_url: z.string(),
    username: z.string(),
    app_password: z.string(),
    user_id: z.string().optional(),
});

const integrationsSchema = z.object({
    harvest: z.array(harvestConnectionSchema).optional(),
    clickup: z.array(clickupConnectionSchema).optional(),
    github: z.array(githubConnectionSchema).optional(),
    llm: z.array(llmConnectionSchema).optional(),
    clockify: z.array(clockifyConnectionSchema).optional(),
    ndizi: z.array(ndiziConnectionSchema).optional(),
});

const pathsSchema = z.object({
    activitywatch: z.string(),
    chrome: z.string(),
    chrome_profiles: z.union([z.string(), z.array(z.string()), z.null()]).optional(),
    claude_code_logs: z.string().optional(),
    antigravity_logs: z.string().optional(),
});

export const configSchema = z.object({
    timezone: z.string(),
    paths: pathsSchema,
    git_authors: z.array(z.string()),
    discover_repos: z.string().optional(),
    chrome_correlation_window_seconds: z.number().optional(),
    min_event_seconds_to_show: z.number().optional(),
    projects: z.record(projectSchema),
    personal_hosts: z.array(z.string()).optional(),
    personal_apps: z.array(z.string()).optional(),
    ignored_projects: z.array(z.string()).optional(),
    groupings: z.record(groupingSchema).optional(),
    correlated_apps: z.array(z.string()).optional(),
    app_correlation_window_seconds: z.number().optional(),
    project_gap_window_seconds: z.number().optional(),
    timeline_merge_gap_seconds: z.number().optional(),
    timeline_min_seconds: z.number().optional(),
    integration_http_timeout_seconds: z.number().optional(),
    github_command_timeout_seconds: z.number().optional(),
    github_cache_ttl: z.string().optional(),
    integrations: integrationsSchema.optional(),
});

export type Config = z.infer<typeof configSchema>;
export type ProjectConfig = z.infer<typeof projectSchema>;
export type GroupingConfig = z.infer<typeof groupingSchema>;
export type HarvestConnection = z.infer<typeof harvestConnectionSchema>;
export type ClickUpConnection = z.infer<typeof clickupConnectionSchema>;
export type GitHubConnection = z.infer<typeof githubConnectionSchema>;
export type LlmConnection = z.infer<typeof llmConnectionSchema>;
export type ClockifyConnection = z.infer<typeof clockifyConnectionSchema>;
export type NdiziConnection = z.infer<typeof ndiziConnectionSchema>;
