import React, { useState } from "react";
import { ScrollView, View, Text, Pressable, Alert, StyleSheet } from "react-native";
import { useSidecar } from "../../SidecarContext";
import { ConnectionCard } from "../components/ConnectionCard";
import { FieldRow } from "../components/FieldRow";
import { useFieldPath } from "../useFieldPath";
import type {
    Config,
    HarvestConnection,
    ClickUpConnection,
    GitHubConnection,
    LlmConnection,
    ClockifyConnection,
} from "../configSchema";

type Props = {
    draft: Config;
    setField: (path: string, value: unknown) => void;
};

function HarvestCard({
    idx,
    conn,
    draft,
    setField,
    onRemove,
}: {
    idx: number;
    conn: HarvestConnection;
    draft: Config;
    setField: (path: string, value: unknown) => void;
    onRemove: () => void;
}) {
    const b = `integrations.harvest.${idx}`;
    const name = useFieldPath(draft, `${b}.name`, setField);
    const accountId = useFieldPath(draft, `${b}.account_id`, setField);
    const token = useFieldPath(draft, `${b}.token`, setField);
    const userId = useFieldPath(draft, `${b}.user_id`, setField);

    return (
        <ConnectionCard title={conn.name || `Harvest #${idx + 1}`} onRemove={onRemove}>
            <FieldRow label="Name" value={name.value} onChange={name.onChange} />
            <FieldRow label="Account ID" value={accountId.value} onChange={accountId.onChange} />
            <FieldRow label="Token" value={token.value} onChange={token.onChange} type="password" />
            <FieldRow label="User ID" value={userId.value} onChange={userId.onChange} />
        </ConnectionCard>
    );
}

function ClickUpCard({
    idx,
    conn,
    draft,
    setField,
    onRemove,
}: {
    idx: number;
    conn: ClickUpConnection;
    draft: Config;
    setField: (path: string, value: unknown) => void;
    onRemove: () => void;
}) {
    const b = `integrations.clickup.${idx}`;
    const name = useFieldPath(draft, `${b}.name`, setField);
    const teamId = useFieldPath(draft, `${b}.team_id`, setField);
    const token = useFieldPath(draft, `${b}.token`, setField);
    const assignee = useFieldPath(draft, `${b}.assignee`, setField);

    return (
        <ConnectionCard title={conn.name || `ClickUp #${idx + 1}`} onRemove={onRemove}>
            <FieldRow label="Name" value={name.value} onChange={name.onChange} />
            <FieldRow label="Team ID" value={teamId.value} onChange={teamId.onChange} />
            <FieldRow label="Token" value={token.value} onChange={token.onChange} type="password" />
            <FieldRow label="Assignee" value={assignee.value} onChange={assignee.onChange} />
        </ConnectionCard>
    );
}

function GitHubCard({
    idx,
    conn,
    draft,
    setField,
    onRemove,
}: {
    idx: number;
    conn: GitHubConnection;
    draft: Config;
    setField: (path: string, value: unknown) => void;
    onRemove: () => void;
}) {
    const b = `integrations.github.${idx}`;
    const name = useFieldPath(draft, `${b}.name`, setField);
    const token = useFieldPath(draft, `${b}.token`, setField);
    const usernames = useFieldPath(draft, `${b}.usernames`, setField, { arrayAsTextarea: true });
    const authors = useFieldPath(draft, `${b}.authors`, setField, { arrayAsTextarea: true });

    return (
        <ConnectionCard title={conn.name || `GitHub #${idx + 1}`} onRemove={onRemove}>
            <FieldRow label="Name" value={name.value} onChange={name.onChange} />
            <FieldRow label="Token" value={token.value} onChange={token.onChange} type="password" />
            <FieldRow
                label="Usernames"
                value={usernames.value}
                onChange={usernames.onChange}
                type="textarea"
                hint="GitHub usernames to track (one per line)"
            />
            <FieldRow
                label="Authors"
                value={authors.value}
                onChange={authors.onChange}
                type="textarea"
                hint="Git author patterns (one per line)"
            />
        </ConnectionCard>
    );
}

function LlmCard({
    idx,
    conn,
    draft,
    setField,
    onRemove,
}: {
    idx: number;
    conn: LlmConnection;
    draft: Config;
    setField: (path: string, value: unknown) => void;
    onRemove: () => void;
}) {
    const b = `integrations.llm.${idx}`;
    const name = useFieldPath(draft, `${b}.name`, setField);
    const baseUrl = useFieldPath(draft, `${b}.base_url`, setField);
    const apiKey = useFieldPath(draft, `${b}.api_key`, setField);
    const model = useFieldPath(draft, `${b}.model`, setField);
    const timeout = useFieldPath(draft, `${b}.timeout`, setField);

    return (
        <ConnectionCard title={conn.name || `LLM #${idx + 1}`} onRemove={onRemove}>
            <FieldRow label="Name" value={name.value} onChange={name.onChange} />
            <FieldRow label="Base URL" value={baseUrl.value} onChange={baseUrl.onChange} type="url" />
            <FieldRow label="API Key" value={apiKey.value} onChange={apiKey.onChange} type="password" />
            <FieldRow label="Model" value={model.value} onChange={model.onChange} />
            <FieldRow label="Timeout (s)" value={timeout.value} onChange={timeout.onChange} type="number" />
        </ConnectionCard>
    );
}

function ClockifyCard({
    idx,
    conn,
    draft,
    setField,
    onRemove,
}: {
    idx: number;
    conn: ClockifyConnection;
    draft: Config;
    setField: (path: string, value: unknown) => void;
    onRemove: () => void;
}) {
    const b = `integrations.clockify.${idx}`;
    const name = useFieldPath(draft, `${b}.name`, setField);
    const apiKey = useFieldPath(draft, `${b}.api_key`, setField);
    const workspaceId = useFieldPath(draft, `${b}.workspace_id`, setField);
    const userId = useFieldPath(draft, `${b}.user_id`, setField);

    return (
        <ConnectionCard title={conn.name || `Clockify #${idx + 1}`} onRemove={onRemove}>
            <FieldRow label="Name" value={name.value} onChange={name.onChange} />
            <FieldRow label="API Key" value={apiKey.value} onChange={apiKey.onChange} type="password" />
            <FieldRow label="Workspace ID" value={workspaceId.value} onChange={workspaceId.onChange} />
            <FieldRow label="User ID" value={userId.value} onChange={userId.onChange} />
        </ConnectionCard>
    );
}

function AddButton({ label, onPress }: { label: string; onPress: () => void }) {
    return (
        <Pressable onPress={onPress} style={styles.addBtn}>
            <Text style={styles.addBtnText}>+ {label}</Text>
        </Pressable>
    );
}

export function IntegrationsTab({ draft, setField }: Props) {
    const integrations = draft.integrations ?? {};
    const { activeLlmIndex, setActiveLlmIndex, client } = useSidecar();
    const llmList = integrations.llm ?? [];
    const [syncing, setSyncing] = useState(false);

    const hasHarvestOrClickUp =
        (integrations.harvest ?? []).some((c) => c.token && c.account_id) ||
        (integrations.clickup ?? []).some((c) => c.token && c.team_id);

    const handleSync = async () => {
        if (!client) return;
        setSyncing(true);
        try {
            const catalog = await client.getIntegrationCatalog();
            const projects: Record<string, Record<string, unknown>> = { ...(draft.projects ?? {}) };
            let added = 0;
            let mappingsUpdated = 0;

            for (const name of catalog.harvest) {
                if (!projects[name]) {
                    projects[name] = {};
                    added++;
                }
                const proj = { ...projects[name] };
                const existing = (proj.harvest_projects as string[] | undefined) ?? [];
                if (!existing.includes(name)) {
                    proj.harvest_projects = [...existing, name];
                    mappingsUpdated++;
                }
                projects[name] = proj;
            }

            for (const name of catalog.clickup) {
                if (name.length < 3) continue;
                const glob = `*${name}*`;
                if (!projects[name]) {
                    projects[name] = {};
                    added++;
                }
                const proj = { ...projects[name] };
                const existing = (proj.clickup_tasks as string[] | undefined) ?? [];
                if (!existing.includes(glob)) {
                    proj.clickup_tasks = [...existing, glob];
                    mappingsUpdated++;
                }
                projects[name] = proj;
            }

            setField("projects", projects);
            Alert.alert(
                "Sync Complete",
                `Added ${added} project${added !== 1 ? "s" : ""}, updated ${mappingsUpdated} mapping${mappingsUpdated !== 1 ? "s" : ""}.`,
            );
        } catch (e: unknown) {
            Alert.alert("Sync Failed", e instanceof Error ? e.message : String(e));
        } finally {
            setSyncing(false);
        }
    };

    const addHarvest = () => {
        const existing = integrations.harvest ?? [];
        setField("integrations.harvest", [...existing, { name: "", account_id: "", token: "" }]);
    };
    const removeHarvest = (idx: number) => {
        const next = (integrations.harvest ?? []).filter((_, i) => i !== idx);
        setField("integrations.harvest", next);
    };

    const addClickUp = () => {
        const existing = integrations.clickup ?? [];
        setField("integrations.clickup", [...existing, { name: "", team_id: "", token: "" }]);
    };
    const removeClickUp = (idx: number) => {
        const next = (integrations.clickup ?? []).filter((_, i) => i !== idx);
        setField("integrations.clickup", next);
    };

    const addGitHub = () => {
        const existing = integrations.github ?? [];
        setField("integrations.github", [...existing, { name: "" }]);
    };
    const removeGitHub = (idx: number) => {
        const next = (integrations.github ?? []).filter((_, i) => i !== idx);
        setField("integrations.github", next);
    };

    const addLlm = () => {
        const existing = integrations.llm ?? [];
        setField("integrations.llm", [...existing, { name: "", base_url: "" }]);
    };
    const removeLlm = (idx: number) => {
        const next = (integrations.llm ?? []).filter((_, i) => i !== idx);
        setField("integrations.llm", next);
    };

    const addClockify = () => {
        const existing = integrations.clockify ?? [];
        setField("integrations.clockify", [...existing, { name: "", api_key: "" }]);
    };
    const removeClockify = (idx: number) => {
        const next = (integrations.clockify ?? []).filter((_, i) => i !== idx);
        setField("integrations.clockify", next);
    };

    return (
        <ScrollView contentContainerStyle={styles.container}>
            {hasHarvestOrClickUp && (
                <View style={styles.syncRow}>
                    <Pressable
                        onPress={handleSync}
                        disabled={syncing || !client}
                        style={[styles.syncBtn, (syncing || !client) && styles.syncBtnDisabled]}
                    >
                        <Text style={styles.syncBtnText}>
                            {syncing ? "Syncing…" : "⟳ Sync projects from Harvest / ClickUp"}
                        </Text>
                    </Pressable>
                    <Text style={styles.syncHint}>
                        Adds project entries and harvest_projects / clickup_tasks globs to your config.
                    </Text>
                </View>
            )}

            <View style={styles.section}>
                <Text style={styles.sectionLabel}>HARVEST</Text>
                {(integrations.harvest ?? []).map((conn, i) => (
                    <HarvestCard
                        key={i}
                        idx={i}
                        conn={conn}
                        draft={draft}
                        setField={setField}
                        onRemove={() => removeHarvest(i)}
                    />
                ))}
                <AddButton label="Add Harvest" onPress={addHarvest} />
            </View>

            <View style={styles.section}>
                <Text style={styles.sectionLabel}>CLICKUP</Text>
                {(integrations.clickup ?? []).map((conn, i) => (
                    <ClickUpCard
                        key={i}
                        idx={i}
                        conn={conn}
                        draft={draft}
                        setField={setField}
                        onRemove={() => removeClickUp(i)}
                    />
                ))}
                <AddButton label="Add ClickUp" onPress={addClickUp} />
            </View>

            <View style={styles.section}>
                <Text style={styles.sectionLabel}>GITHUB</Text>
                {(integrations.github ?? []).map((conn, i) => (
                    <GitHubCard
                        key={i}
                        idx={i}
                        conn={conn}
                        draft={draft}
                        setField={setField}
                        onRemove={() => removeGitHub(i)}
                    />
                ))}
                <AddButton label="Add GitHub" onPress={addGitHub} />
            </View>

            <View style={styles.section}>
                <Text style={styles.sectionLabel}>LLM</Text>
                {llmList.length > 1 && (
                    <View style={styles.llmPicker}>
                        <Text style={styles.llmPickerLabel}>Active provider (this session):</Text>
                        <View style={styles.llmPickerRow}>
                            {llmList.map((conn, i) => (
                                <Pressable
                                    key={i}
                                    style={[styles.llmPickerChip, activeLlmIndex === i && styles.llmPickerChipActive]}
                                    onPress={() => setActiveLlmIndex(i)}
                                >
                                    <Text
                                        style={[
                                            styles.llmPickerChipText,
                                            activeLlmIndex === i && styles.llmPickerChipTextActive,
                                        ]}
                                    >
                                        {conn.name || `LLM #${i + 1}`}
                                    </Text>
                                </Pressable>
                            ))}
                        </View>
                    </View>
                )}
                {llmList.map((conn, i) => (
                    <LlmCard
                        key={i}
                        idx={i}
                        conn={conn}
                        draft={draft}
                        setField={setField}
                        onRemove={() => removeLlm(i)}
                    />
                ))}
                <AddButton label="Add LLM" onPress={addLlm} />
            </View>

            <View style={styles.section}>
                <Text style={styles.sectionLabel}>CLOCKIFY</Text>
                {(integrations.clockify ?? []).map((conn, i) => (
                    <ClockifyCard
                        key={i}
                        idx={i}
                        conn={conn}
                        draft={draft}
                        setField={setField}
                        onRemove={() => removeClockify(i)}
                    />
                ))}
                <AddButton label="Add Clockify" onPress={addClockify} />
            </View>
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    container: {
        paddingBottom: 32,
    },
    section: {
        marginTop: 16,
    },
    sectionLabel: {
        fontSize: 11,
        fontWeight: "600",
        color: "#888",
        letterSpacing: 0.5,
        textTransform: "uppercase",
        paddingHorizontal: 16,
        paddingBottom: 8,
        borderBottomWidth: 1,
        borderBottomColor: "#e0e0e0",
        marginBottom: 4,
    },
    addBtn: {
        marginHorizontal: 16,
        marginTop: 8,
        paddingVertical: 7,
        paddingHorizontal: 12,
        borderWidth: 1,
        borderColor: "#007AFF",
        borderRadius: 4,
        alignSelf: "flex-start",
    },
    addBtnText: {
        fontSize: 12,
        color: "#007AFF",
    },
    llmPicker: {
        marginHorizontal: 16,
        marginTop: 8,
        marginBottom: 4,
        padding: 10,
        backgroundColor: "#f0f4ff",
        borderRadius: 6,
        borderWidth: 1,
        borderColor: "#d0d8f0",
    },
    llmPickerLabel: {
        fontSize: 11,
        color: "#555",
        marginBottom: 6,
    },
    llmPickerRow: {
        flexDirection: "row",
        flexWrap: "wrap",
        gap: 6,
    },
    llmPickerChip: {
        paddingVertical: 4,
        paddingHorizontal: 10,
        borderRadius: 12,
        borderWidth: 1,
        borderColor: "#007AFF",
        backgroundColor: "#fff",
    },
    llmPickerChipActive: {
        backgroundColor: "#007AFF",
    },
    llmPickerChipText: {
        fontSize: 12,
        color: "#007AFF",
    },
    llmPickerChipTextActive: {
        color: "#fff",
    },
    syncRow: {
        marginHorizontal: 16,
        marginTop: 16,
        marginBottom: 4,
        gap: 6,
    },
    syncBtn: {
        paddingVertical: 8,
        paddingHorizontal: 14,
        borderWidth: 1,
        borderColor: "#007AFF",
        borderRadius: 5,
        alignSelf: "flex-start",
    },
    syncBtnDisabled: { opacity: 0.4 },
    syncBtnText: {
        fontSize: 13,
        color: "#007AFF",
        fontWeight: "500",
    },
    syncHint: {
        fontSize: 11,
        color: "#888",
        lineHeight: 16,
    },
});
