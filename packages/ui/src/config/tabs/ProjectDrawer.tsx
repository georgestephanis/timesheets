import React from "react";
import { View, Text, ScrollView, Pressable, StyleSheet, Alert } from "react-native";
import { SectionHeader } from "../components/SectionHeader";
import { FieldRow } from "../components/FieldRow";
import { useFieldPath } from "../useFieldPath";
import type { Config } from "../configSchema";

type Props = {
    name: string;
    draft: Config;
    setField: (path: string, value: unknown) => void;
    onClose: () => void;
    onDelete: (name: string) => void;
    onRename: (oldName: string, newName: string) => void;
};

const base = (name: string) => `projects.${name}`;

export function ProjectDrawer({ name, draft, setField, onClose, onDelete, onRename }: Props) {
    const b = base(name);
    const groupings = Object.keys(draft.groupings ?? {});

    const grouping = useFieldPath(draft, `${b}.grouping`, setField);
    const repos = useFieldPath(draft, `${b}.repos`, setField, { arrayAsTextarea: true });
    const vscodeDirs = useFieldPath(draft, `${b}.vscode_dirs`, setField, { arrayAsTextarea: true });
    const domains = useFieldPath(draft, `${b}.domains`, setField, { arrayAsTextarea: true });
    const sshHosts = useFieldPath(draft, `${b}.ssh_hosts`, setField, { arrayAsTextarea: true });
    const apps = useFieldPath(draft, `${b}.apps`, setField, { arrayAsTextarea: true });
    const harvestProjects = useFieldPath(draft, `${b}.harvest_projects`, setField, {
        arrayAsTextarea: true,
    });
    const harvestClient = useFieldPath(draft, `${b}.harvest_client`, setField);
    const clickupTasks = useFieldPath(draft, `${b}.clickup_tasks`, setField, {
        arrayAsTextarea: true,
    });
    const clockifyProjects = useFieldPath(draft, `${b}.clockify_projects`, setField, {
        arrayAsTextarea: true,
    });

    const handleDelete = () => {
        Alert.alert("Delete Project", `Remove "${name}" from the config? This cannot be undone until you discard.`, [
            { text: "Cancel", style: "cancel" },
            { text: "Delete", style: "destructive", onPress: () => onDelete(name) },
        ]);
    };

    return (
        <View style={styles.container}>
            <View style={styles.header}>
                <Pressable onPress={onClose} style={styles.backBtn}>
                    <Text style={styles.backBtnText}>← Back</Text>
                </Pressable>
                <Text style={styles.title}>{name}</Text>
                <Pressable onPress={handleDelete} style={styles.deleteBtn}>
                    <Text style={styles.deleteBtnText}>Delete</Text>
                </Pressable>
            </View>

            <ScrollView>
                <SectionHeader title="Classification" />
                {groupings.length > 0 ? (
                    <FieldRow
                        label="Grouping"
                        value={grouping.value}
                        onChange={grouping.onChange}
                        type="segment"
                        options={["", ...groupings]}
                    />
                ) : (
                    <FieldRow label="Grouping" value={grouping.value} onChange={grouping.onChange} />
                )}

                <SectionHeader title="VSCode" />
                <FieldRow
                    label="Repos"
                    value={repos.value}
                    onChange={repos.onChange}
                    type="textarea"
                    hint="One repo path per line (may use ~)"
                />
                <FieldRow
                    label="VSCode dirs"
                    value={vscodeDirs.value}
                    onChange={vscodeDirs.onChange}
                    type="textarea"
                    hint="Workspace folder names (one per line)"
                />

                <SectionHeader title="Browser" />
                <FieldRow
                    label="Domains"
                    value={domains.value}
                    onChange={domains.onChange}
                    type="textarea"
                    placeholder="example.com&#10;*.example.org"
                    hint="Hostnames or globs, one per line"
                />

                <SectionHeader title="Other Signals" />
                <FieldRow
                    label="SSH hosts"
                    value={sshHosts.value}
                    onChange={sshHosts.onChange}
                    type="textarea"
                    hint="SSH hostname patterns (one per line)"
                />
                <FieldRow
                    label="Apps"
                    value={apps.value}
                    onChange={apps.onChange}
                    type="textarea"
                    hint="Application name patterns (one per line)"
                />

                <SectionHeader title="Integrations" />
                <FieldRow
                    label="Harvest projects"
                    value={harvestProjects.value}
                    onChange={harvestProjects.onChange}
                    type="textarea"
                    hint="Project name globs (one per line)"
                />
                <FieldRow
                    label="Harvest client"
                    value={harvestClient.value}
                    onChange={harvestClient.onChange}
                    placeholder="Client name"
                />
                <FieldRow
                    label="ClickUp tasks"
                    value={clickupTasks.value}
                    onChange={clickupTasks.onChange}
                    type="textarea"
                    hint="Task name globs (one per line)"
                />
                <FieldRow
                    label="Clockify projects"
                    value={clockifyProjects.value}
                    onChange={clockifyProjects.onChange}
                    type="textarea"
                    hint="Project name globs (one per line)"
                />
            </ScrollView>
        </View>
    );
}

const styles = StyleSheet.create({
    container: {
        flex: 1,
        backgroundColor: "#fff",
    },
    header: {
        flexDirection: "row",
        alignItems: "center",
        paddingHorizontal: 12,
        paddingVertical: 10,
        borderBottomWidth: 1,
        borderBottomColor: "#e0e0e0",
        backgroundColor: "#f8f8f8",
    },
    backBtn: {
        paddingHorizontal: 8,
        paddingVertical: 4,
    },
    backBtnText: {
        fontSize: 13,
        color: "#007AFF",
    },
    title: {
        flex: 1,
        textAlign: "center",
        fontSize: 14,
        fontWeight: "600",
        color: "#333",
    },
    deleteBtn: {
        paddingHorizontal: 8,
        paddingVertical: 4,
        borderWidth: 1,
        borderColor: "#c00",
        borderRadius: 3,
    },
    deleteBtnText: {
        fontSize: 12,
        color: "#c00",
    },
});
