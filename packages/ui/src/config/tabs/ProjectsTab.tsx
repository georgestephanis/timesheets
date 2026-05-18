import React, { useState } from "react";
import { View, Text, FlatList, Pressable, TextInput, StyleSheet, Alert } from "react-native";
import { ProjectDrawer } from "./ProjectDrawer";
import type { Config } from "../configSchema";

type Props = {
    draft: Config;
    setField: (path: string, value: unknown) => void;
};

export function ProjectsTab({ draft, setField }: Props) {
    const [selectedProject, setSelectedProject] = useState<string | null>(null);
    const [addingName, setAddingName] = useState("");
    const [showAdd, setShowAdd] = useState(false);

    const projects = Object.entries(draft.projects ?? {});

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
                    <View style={styles.addSection}>
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
                            <Pressable onPress={() => setShowAdd(true)} style={styles.addBtn}>
                                <Text style={styles.addBtnText}>+ Add Project</Text>
                            </Pressable>
                        )}
                    </View>
                }
            />
        </View>
    );
}

const styles = StyleSheet.create({
    container: {
        flex: 1,
    },
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
    projectName: {
        fontSize: 13,
        color: "#111",
    },
    groupingBadge: {
        fontSize: 11,
        color: "#555",
        backgroundColor: "#eee",
        paddingHorizontal: 6,
        paddingVertical: 2,
        borderRadius: 3,
    },
    chevron: {
        fontSize: 18,
        color: "#bbb",
    },
    empty: {
        padding: 20,
        color: "#888",
        textAlign: "center",
    },
    addSection: {
        padding: 16,
    },
    addBtn: {
        paddingVertical: 8,
        paddingHorizontal: 12,
        borderWidth: 1,
        borderColor: "#007AFF",
        borderRadius: 4,
        alignSelf: "flex-start",
    },
    addBtnText: {
        fontSize: 13,
        color: "#007AFF",
    },
    addRow: {
        flexDirection: "row",
        alignItems: "center",
        gap: 8,
    },
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
        backgroundColor: "#007AFF",
        borderRadius: 4,
    },
    addConfirmBtnText: {
        fontSize: 12,
        color: "#fff",
        fontWeight: "600",
    },
    cancelBtn: {
        paddingHorizontal: 8,
        paddingVertical: 5,
    },
    cancelBtnText: {
        fontSize: 12,
        color: "#888",
    },
});
