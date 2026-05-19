import React, { useState } from "react";
import { ActivityIndicator, Alert, FlatList, Pressable, StyleSheet, Text, TextInput, View } from "react-native";
import { ProjectDrawer } from "./ProjectDrawer";
import type { Config } from "../configSchema";
import { useSidecar } from "../../SidecarContext";
import { Brand } from "../../brand";

type Props = {
    draft: Config;
    setField: (path: string, value: unknown) => void;
};

type DiscoveredRepo = { path: string; name: string; recent: boolean; assigned: string | null };

export function ProjectsTab({ draft, setField }: Props) {
    const [selectedProject, setSelectedProject] = useState<string | null>(null);
    const [addingName, setAddingName] = useState("");
    const [showAdd, setShowAdd] = useState(false);

    // Repo discovery panel state
    const [discovering, setDiscovering] = useState(false);
    const [repos, setRepos] = useState<DiscoveredRepo[] | null>(null);
    const [discoverError, setDiscoverError] = useState<string | null>(null);
    const [repoPickerOpen, setRepoPickerOpen] = useState<string | null>(null); // repo path
    const [repoSelections, setRepoSelections] = useState<Record<string, string>>({}); // path → project
    const [addingRepo, setAddingRepo] = useState<Set<string>>(new Set());

    const { client } = useSidecar();

    const projects = Object.entries(draft.projects ?? {});
    const projectNames = Object.keys(draft.projects ?? {}).sort();

    const handleAdd = () => {
        const name = addingName.trim();
        if (!name) return;
        if (draft.projects[name] !== undefined) {
            Alert.alert("Name taken", `A project named "${name}" already exists.`);
            return;
        }
        setField("projects", { ...draft.projects, [name]: {} });
        setAddingName("");
        setShowAdd(false);
        setSelectedProject(name);
    };

    const handleDelete = (name: string) => {
        const { [name]: _removed, ...rest } = draft.projects;
        setField("projects", rest);
        setSelectedProject(null);
    };

    const handleRename = (oldName: string, newName: string) => {
        const trimmed = newName.trim();
        if (!trimmed || trimmed === oldName) return;
        if (draft.projects[trimmed] !== undefined) {
            Alert.alert("Name taken", `A project named "${trimmed}" already exists.`);
            return;
        }
        const { [oldName]: proj, ...rest } = draft.projects;
        setField("projects", { ...rest, [trimmed]: proj });
        setSelectedProject(trimmed);
    };

    const handleDiscover = async () => {
        if (!client) {
            setDiscoverError("Engine is not running yet. Please wait and try again.");
            return;
        }
        setDiscovering(true);
        setDiscoverError(null);
        setRepos(null);
        try {
            const result = await client.discoverRepos();
            setRepos(result.repos);
        } catch (e) {
            setDiscoverError(e instanceof Error ? e.message : String(e));
        } finally {
            setDiscovering(false);
        }
    };

    const handleAddRepo = async (repoPath: string) => {
        const project = repoSelections[repoPath];
        if (!project) return;
        setAddingRepo((s) => new Set(s).add(repoPath));
        try {
            const proj = (draft.projects ?? {})[project] as Record<string, unknown> | undefined;
            const existing = (proj?.repos as string[]) ?? [];
            if (!existing.includes(repoPath)) {
                setField(`projects.${project}.repos`, [...existing, repoPath]);
            }
            // Mark as assigned in local state
            setRepos((prev) => prev?.map((r) => (r.path === repoPath ? { ...r, assigned: project } : r)) ?? null);
        } finally {
            setAddingRepo((prev) => {
                const next = new Set(prev);
                next.delete(repoPath);
                return next;
            });
        }
    };

    if (selectedProject !== null) {
        return (
            <ProjectDrawer
                name={selectedProject}
                draft={draft}
                setField={setField}
                onClose={() => setSelectedProject(null)}
                onDelete={handleDelete}
                onRename={handleRename}
            />
        );
    }

    return (
        <View style={styles.container}>
            <FlatList
                data={projects}
                keyExtractor={([name]) => name}
                renderItem={({ item: [name, proj] }) => {
                    const grouping = proj.grouping;
                    return (
                        <Pressable style={styles.row} onPress={() => setSelectedProject(name)}>
                            <View style={styles.rowLeft}>
                                <Text style={styles.projectName}>{name}</Text>
                                {grouping ? <Text style={styles.groupingBadge}>{grouping}</Text> : null}
                            </View>
                            <Text style={styles.chevron}>›</Text>
                        </Pressable>
                    );
                }}
                ListEmptyComponent={<Text style={styles.empty}>No projects. Add one below.</Text>}
                ListFooterComponent={
                    <View style={styles.footer}>
                        {/* Add project inline form */}
                        {showAdd ? (
                            <View style={styles.addRow}>
                                <TextInput
                                    style={styles.addInput}
                                    value={addingName}
                                    onChangeText={setAddingName}
                                    placeholder="Project name"
                                    placeholderTextColor="#aaa"
                                    autoFocus
                                    onSubmitEditing={handleAdd}
                                />
                                <Pressable onPress={handleAdd} style={styles.addConfirmBtn}>
                                    <Text style={styles.addConfirmBtnText}>Add</Text>
                                </Pressable>
                                <Pressable
                                    onPress={() => {
                                        setShowAdd(false);
                                        setAddingName("");
                                    }}
                                    style={styles.cancelBtn}
                                >
                                    <Text style={styles.cancelBtnText}>Cancel</Text>
                                </Pressable>
                            </View>
                        ) : (
                            <View style={styles.footerBtns}>
                                <Pressable onPress={() => setShowAdd(true)} style={styles.addBtn}>
                                    <Text style={styles.addBtnText}>+ Add Project</Text>
                                </Pressable>
                                <Pressable
                                    onPress={handleDiscover}
                                    disabled={discovering}
                                    style={[styles.discoverBtn, discovering && styles.discoverBtnDisabled]}
                                >
                                    {discovering ? (
                                        <ActivityIndicator size="small" color={Brand.terracotta} />
                                    ) : (
                                        <Text style={styles.discoverBtnText}>Discover GitHub Desktop Repos</Text>
                                    )}
                                </Pressable>
                            </View>
                        )}

                        {/* Repo discovery results */}
                        {discoverError && <Text style={styles.discoverError}>{discoverError}</Text>}
                        {repos !== null && (
                            <View style={styles.reposPanel}>
                                <Text style={styles.reposPanelTitle}>GitHub Desktop Repos ({repos.length})</Text>
                                {repos.length === 0 ? (
                                    <Text style={styles.reposEmpty}>No repos found.</Text>
                                ) : (
                                    repos.map((repo) => {
                                        const isOpen = repoPickerOpen === repo.path;
                                        const selected = repoSelections[repo.path];
                                        const busy = addingRepo.has(repo.path);
                                        return (
                                            <View
                                                key={repo.path}
                                                style={[styles.repoRow, !repo.recent && styles.repoRowStale]}
                                            >
                                                <View style={styles.repoInfo}>
                                                    <Text style={styles.repoName}>{repo.name}</Text>
                                                    <Text style={styles.repoPath} numberOfLines={1}>
                                                        {repo.path}
                                                    </Text>
                                                    {repo.assigned ? (
                                                        <Text style={styles.repoAssigned}>→ {repo.assigned}</Text>
                                                    ) : null}
                                                </View>
                                                {!repo.assigned && (
                                                    <View style={styles.repoActions}>
                                                        <Pressable
                                                            onPress={() => setRepoPickerOpen(isOpen ? null : repo.path)}
                                                            style={styles.pickerBtn}
                                                        >
                                                            <Text style={styles.pickerBtnText} numberOfLines={1}>
                                                                {selected ?? "Select project…"}
                                                            </Text>
                                                            <Text style={styles.pickerChevron}>
                                                                {isOpen ? "▲" : "▼"}
                                                            </Text>
                                                        </Pressable>
                                                        <Pressable
                                                            onPress={() => handleAddRepo(repo.path)}
                                                            disabled={!selected || busy}
                                                            style={[
                                                                styles.addRepoBtn,
                                                                (!selected || busy) && styles.addRepoBtnDisabled,
                                                            ]}
                                                        >
                                                            <Text style={styles.addRepoBtnText}>
                                                                {busy ? "…" : "Add"}
                                                            </Text>
                                                        </Pressable>
                                                    </View>
                                                )}
                                                {isOpen && !repo.assigned && (
                                                    <View style={styles.dropdown}>
                                                        {projectNames.map((p) => (
                                                            <Pressable
                                                                key={p}
                                                                onPress={() => {
                                                                    setRepoSelections((prev) => ({
                                                                        ...prev,
                                                                        [repo.path]: p,
                                                                    }));
                                                                    setRepoPickerOpen(null);
                                                                }}
                                                                style={[
                                                                    styles.dropdownItem,
                                                                    selected === p && styles.dropdownItemSelected,
                                                                ]}
                                                            >
                                                                <Text
                                                                    style={[
                                                                        styles.dropdownItemText,
                                                                        selected === p &&
                                                                            styles.dropdownItemTextSelected,
                                                                    ]}
                                                                >
                                                                    {p}
                                                                </Text>
                                                            </Pressable>
                                                        ))}
                                                    </View>
                                                )}
                                            </View>
                                        );
                                    })
                                )}
                            </View>
                        )}
                    </View>
                }
            />
        </View>
    );
}

const styles = StyleSheet.create({
    container: { flex: 1 },
    row: {
        flexDirection: "row",
        alignItems: "center",
        paddingHorizontal: 16,
        paddingVertical: 10,
        borderBottomWidth: StyleSheet.hairlineWidth,
        borderBottomColor: "#e0e0e0",
    },
    rowLeft: {
        flex: 1,
        flexDirection: "row",
        alignItems: "center",
        gap: 8,
    },
    projectName: { fontSize: 13, color: "#111" },
    groupingBadge: {
        fontSize: 11,
        color: "#555",
        backgroundColor: "#eee",
        paddingHorizontal: 6,
        paddingVertical: 2,
        borderRadius: 3,
    },
    chevron: { fontSize: 18, color: "#bbb" },
    empty: { padding: 20, color: "#888", textAlign: "center" },
    footer: { padding: 16, gap: 12 },
    footerBtns: { flexDirection: "row", gap: 8, flexWrap: "wrap" },
    addBtn: {
        paddingVertical: 8,
        paddingHorizontal: 12,
        borderWidth: 1,
        borderColor: Brand.terracotta,
        borderRadius: 4,
    },
    addBtnText: { fontSize: 13, color: Brand.terracotta },
    discoverBtn: {
        paddingVertical: 8,
        paddingHorizontal: 12,
        borderWidth: 1,
        borderColor: "#bbb",
        borderRadius: 4,
        flexDirection: "row",
        alignItems: "center",
        gap: 6,
    },
    discoverBtnDisabled: { opacity: 0.5 },
    discoverBtnText: { fontSize: 13, color: "#555" },
    discoverError: { fontSize: 12, color: "#c00", paddingHorizontal: 4 },
    addRow: { flexDirection: "row", alignItems: "center", gap: 8 },
    addInput: {
        flex: 1,
        fontSize: 13,
        borderWidth: 1,
        borderColor: "#ccc",
        borderRadius: 4,
        paddingHorizontal: 8,
        paddingVertical: 4,
        backgroundColor: "#fff",
        color: "#111",
    },
    addConfirmBtn: {
        paddingHorizontal: 12,
        paddingVertical: 5,
        backgroundColor: Brand.terracotta,
        borderRadius: 4,
    },
    addConfirmBtnText: { fontSize: 12, color: "#fff", fontWeight: "600" },
    cancelBtn: { paddingHorizontal: 8, paddingVertical: 5 },
    cancelBtnText: { fontSize: 12, color: "#888" },
    // Repo discovery panel
    reposPanel: {
        borderWidth: 1,
        borderColor: "#ddd",
        borderRadius: 6,
        overflow: "hidden",
    },
    reposPanelTitle: {
        fontSize: 11,
        fontWeight: "700",
        color: "#888",
        textTransform: "uppercase",
        letterSpacing: 0.5,
        padding: 10,
        backgroundColor: "#f8f8f8",
        borderBottomWidth: StyleSheet.hairlineWidth,
        borderBottomColor: "#ddd",
    },
    reposEmpty: { padding: 12, fontSize: 13, color: "#888", textAlign: "center" },
    repoRow: {
        padding: 10,
        borderBottomWidth: StyleSheet.hairlineWidth,
        borderBottomColor: "#eee",
        gap: 6,
    },
    repoRowStale: { opacity: 0.6 },
    repoInfo: { gap: 2 },
    repoName: { fontSize: 13, fontWeight: "600", color: "#111" },
    repoPath: { fontSize: 11, color: "#888", fontFamily: "Menlo" },
    repoAssigned: { fontSize: 11, color: Brand.terracotta, fontWeight: "600" },
    repoActions: { flexDirection: "row", gap: 6, alignItems: "center" },
    pickerBtn: {
        flex: 1,
        flexDirection: "row",
        alignItems: "center",
        borderWidth: 1,
        borderColor: "#ccc",
        borderRadius: 4,
        paddingHorizontal: 8,
        paddingVertical: 4,
        backgroundColor: "#f9f9f9",
        gap: 4,
    },
    pickerBtnText: { flex: 1, fontSize: 12, color: "#555" },
    pickerChevron: { fontSize: 10, color: "#999" },
    addRepoBtn: {
        paddingVertical: 4,
        paddingHorizontal: 10,
        backgroundColor: Brand.terracotta,
        borderRadius: 4,
        flexShrink: 0,
    },
    addRepoBtnDisabled: { opacity: 0.35 },
    addRepoBtnText: { fontSize: 12, color: "#fff", fontWeight: "600" },
    dropdown: {
        borderWidth: 1,
        borderColor: "#ddd",
        borderRadius: 4,
        backgroundColor: "#fff",
        maxHeight: 160,
        overflow: "hidden",
    },
    dropdownItem: {
        paddingHorizontal: 10,
        paddingVertical: 6,
        borderBottomWidth: StyleSheet.hairlineWidth,
        borderBottomColor: "#eee",
    },
    dropdownItemSelected: { backgroundColor: "#FFF4EE" },
    dropdownItemText: { fontSize: 12, color: "#333" },
    dropdownItemTextSelected: { color: Brand.terracotta, fontWeight: "600" },
});
