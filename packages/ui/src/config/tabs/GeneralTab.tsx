import React from "react";
import { ScrollView } from "react-native";
import { SectionHeader } from "../components/SectionHeader";
import { FieldRow } from "../components/FieldRow";
import { useFieldPath } from "../useFieldPath";
import type { Config } from "../configSchema";

type Props = {
    draft: Config;
    setField: (path: string, value: unknown) => void;
};

export function GeneralTab({ draft, setField }: Props) {
    const aw = useFieldPath(draft, "paths.activitywatch", setField);
    const chrome = useFieldPath(draft, "paths.chrome", setField);
    const autoDetect = {
        value: draft.paths.chrome_profiles === null,
        onChange: (v: unknown) => {
            setField("paths.chrome_profiles", v ? null : "");
        },
    };
    const chromeProfiles = useFieldPath(draft, "paths.chrome_profiles", setField, {
        arrayAsTextarea: true,
    });

    const gitAuthors = useFieldPath(draft, "git_authors", setField, { arrayAsTextarea: true });
    const discoverRepos = useFieldPath(draft, "discover_repos", setField);
    const timezone = useFieldPath(draft, "timezone", setField);

    const chromeCorrWindow = useFieldPath(draft, "chrome_correlation_window_seconds", setField);
    const minEventSeconds = useFieldPath(draft, "min_event_seconds_to_show", setField);
    const appCorrWindow = useFieldPath(draft, "app_correlation_window_seconds", setField);
    const projectGapWindow = useFieldPath(draft, "project_gap_window_seconds", setField);
    const timelineMergeGap = useFieldPath(draft, "timeline_merge_gap_seconds", setField);
    const timelineMin = useFieldPath(draft, "timeline_min_seconds", setField);
    const integHttpTimeout = useFieldPath(draft, "integration_http_timeout_seconds", setField);
    const githubCmdTimeout = useFieldPath(draft, "github_command_timeout_seconds", setField);
    const githubCacheTtl = useFieldPath(draft, "github_cache_ttl", setField);

    const personalHosts = useFieldPath(draft, "personal_hosts", setField, { arrayAsTextarea: true });
    const personalApps = useFieldPath(draft, "personal_apps", setField, { arrayAsTextarea: true });
    const ignoredProjects = useFieldPath(draft, "ignored_projects", setField, {
        arrayAsTextarea: true,
    });
    const correlatedApps = useFieldPath(draft, "correlated_apps", setField, {
        arrayAsTextarea: true,
    });

    const isAutoDetect = draft.paths.chrome_profiles === null;

    return (
        <ScrollView>
            <SectionHeader title="Core Paths" />
            <FieldRow
                label="ActivityWatch data"
                value={aw.value}
                onChange={aw.onChange}
                placeholder="~/.local/share/activitywatch"
            />
            <FieldRow
                label="Chrome user data"
                value={chrome.value}
                onChange={chrome.onChange}
                placeholder="~/Library/Application Support/Google/Chrome"
            />
            <FieldRow
                label="Auto-detect profiles"
                value={autoDetect.value}
                onChange={autoDetect.onChange}
                type="checkbox"
                hint="When enabled, all Chrome profiles are scanned automatically"
            />
            {!isAutoDetect && (
                <FieldRow
                    label="Chrome profiles"
                    value={chromeProfiles.value}
                    onChange={chromeProfiles.onChange}
                    type="textarea"
                    placeholder="Default&#10;Profile 1"
                    hint="One profile directory name per line"
                />
            )}

            <SectionHeader title="Identity" />
            <FieldRow
                label="Timezone"
                value={timezone.value}
                onChange={timezone.onChange}
                placeholder="America/New_York"
            />
            <FieldRow
                label="Git authors"
                value={gitAuthors.value}
                onChange={gitAuthors.onChange}
                type="textarea"
                placeholder="you@example.com&#10;alias@example.com"
                hint="One email address per line"
            />
            <FieldRow
                label="Repo discovery"
                value={discoverRepos.value}
                onChange={discoverRepos.onChange}
                placeholder="github_desktop or path to repos"
            />

            <SectionHeader title="Timing (seconds)" />
            <FieldRow
                label="Chrome correlation window"
                value={chromeCorrWindow.value}
                onChange={chromeCorrWindow.onChange}
                type="number"
                placeholder="300"
            />
            <FieldRow
                label="Min event seconds to show"
                value={minEventSeconds.value}
                onChange={minEventSeconds.onChange}
                type="number"
                placeholder="60"
            />
            <FieldRow
                label="App correlation window"
                value={appCorrWindow.value}
                onChange={appCorrWindow.onChange}
                type="number"
                placeholder="300"
            />
            <FieldRow
                label="Project gap window"
                value={projectGapWindow.value}
                onChange={projectGapWindow.onChange}
                type="number"
                placeholder="600"
            />
            <FieldRow
                label="Timeline merge gap"
                value={timelineMergeGap.value}
                onChange={timelineMergeGap.onChange}
                type="number"
                placeholder="120"
            />
            <FieldRow
                label="Timeline min seconds"
                value={timelineMin.value}
                onChange={timelineMin.onChange}
                type="number"
                placeholder="60"
            />
            <FieldRow
                label="Integration HTTP timeout"
                value={integHttpTimeout.value}
                onChange={integHttpTimeout.onChange}
                type="number"
                placeholder="30"
            />
            <FieldRow
                label="GitHub command timeout"
                value={githubCmdTimeout.value}
                onChange={githubCmdTimeout.onChange}
                type="number"
                placeholder="10"
            />
            <FieldRow
                label="GitHub cache TTL"
                value={githubCacheTtl.value}
                onChange={githubCacheTtl.onChange}
                placeholder="1 hour"
            />

            <SectionHeader title="Personal & Ignored" />
            <FieldRow
                label="Personal hosts"
                value={personalHosts.value}
                onChange={personalHosts.onChange}
                type="textarea"
                placeholder="gmail.com&#10;twitter.com"
                hint="Domains to treat as personal browsing (one per line)"
            />
            <FieldRow
                label="Personal apps"
                value={personalApps.value}
                onChange={personalApps.onChange}
                type="textarea"
                placeholder="Spotify&#10;Messages"
                hint="App names to treat as personal (one per line)"
            />
            <FieldRow
                label="Ignored projects"
                value={ignoredProjects.value}
                onChange={ignoredProjects.onChange}
                type="textarea"
                hint="Project names to exclude from reports (one per line)"
            />
            <FieldRow
                label="Correlated apps"
                value={correlatedApps.value}
                onChange={correlatedApps.onChange}
                type="textarea"
                hint="App names whose activity correlates with VSCode (one per line)"
            />
        </ScrollView>
    );
}
