import React, { useCallback, useEffect, useState } from "react";
import { ActivityIndicator, Pressable, ScrollView, StyleSheet, Text, TextInput, View } from "react-native";
import type { Config } from "../configSchema";
import { useSidecar } from "../../SidecarContext";
import { Brand } from "../../brand";

type Props = {
    draft: Config;
    setField: (path: string | string[], value: unknown) => void;
};

type Suggestion = { kind: string; value: string; project: string; reason: string };

const SIGNAL_KINDS = ["vscode", "browser", "slack", "apps"] as const;

const KIND_LABELS: Record<string, string> = {
    vscode: "VS Code",
    browser: "Browser",
    slack: "Slack",
    apps: "Apps / SSH",
};

function todayIso(): string {
    return new Intl.DateTimeFormat("en-CA").format(new Date());
}

function addUnique(arr: string[], value: string): string[] {
    return arr.includes(value) ? arr : [...arr, value];
}

function applySignalToDraft(
    draft: Config,
    setField: (path: string | string[], value: unknown) => void,
    kind: string,
    value: string,
    project: string,
) {
    if (project === "__personal__") {
        if (kind === "browser" && value && value !== "(no url)") {
            setField("personal_hosts", addUnique((draft.personal_hosts as string[]) ?? [], value));
        } else if (kind === "apps") {
            const appVal = value.startsWith("ssh:") ? value.slice(4) : value;
            setField("personal_apps", addUnique((draft.personal_apps as string[]) ?? [], appVal));
        }
        return;
    }
    if (project === "__correlated__") {
        if (kind === "apps" && !value.startsWith("ssh:")) {
            setField("correlated_apps", addUnique((draft.correlated_apps as string[]) ?? [], value));
        }
        return;
    }

    const proj = (draft.projects ?? {})[project];
    const p = (proj ?? {}) as Record<string, unknown>;

    switch (kind) {
        case "vscode":
            setField(["projects", project, "vscode_dirs"], addUnique((p.vscode_dirs as string[]) ?? [], value));
            break;
        case "browser":
            if (!value || value === "(no url)") break;
            setField(["projects", project, "domains"], addUnique((p.domains as string[]) ?? [], value));
            break;
        case "slack": {
            const idx = value.indexOf(" / ");
            if (idx === -1) break;
            const workspace = value.slice(0, idx).trim();
            const channel = value.slice(idx + 3).trim();
            if (!workspace || !channel) break;
            const PSEUDO = new Set(["__threads__", "__activity__", "__huddle__"]);
            const rule: Record<string, string> = { workspace };
            if (!PSEUDO.has(channel)) rule.channel_glob = channel;
            const existing = (p.slack as Array<Record<string, string>>) ?? [];
            const dup = existing.some(
                (ex) =>
                    ex.workspace === rule.workspace &&
                    (ex.channel_glob ?? undefined) === (rule.channel_glob ?? undefined),
            );
            if (!dup) setField(["projects", project, "slack"], [...existing, rule]);
            break;
        }
        case "apps":
            if (value.startsWith("ssh:")) {
                setField(
                    ["projects", project, "ssh_hosts"],
                    addUnique((p.ssh_hosts as string[]) ?? [], value.slice(4)),
                );
            } else {
                setField(["projects", project, "apps"], addUnique((p.apps as string[]) ?? [], value));
            }
            break;
    }
}

export function SignalsTab({ draft, setField }: Props) {
    const { state: sidecarState, client } = useSidecar();
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [unmatched, setUnmatched] = useState<Record<string, Record<string, number>>>({});
    const [dismissed, setDismissed] = useState<Set<string>>(new Set());
    const [pickerOpen, setPickerOpen] = useState<string | null>(null); // "kind:value"
    const [selections, setSelections] = useState<Record<string, string>>({}); // "kind:value" → project
    const [assigning, setAssigning] = useState<Set<string>>(new Set());
    const [suggesting, setSuggesting] = useState(false);
    const [suggestions, setSuggestions] = useState<Suggestion[]>([]);
    const [suggestionError, setSuggestionError] = useState<string | null>(null);
    const [newProjectInputs, setNewProjectInputs] = useState<Record<string, string>>({}); // "kind:value" → draft name

    const today = todayIso();

    const load = useCallback(async () => {
        if (!client) return;
        setLoading(true);
        setError(null);
        try {
            const report = await client.getReport(today, today);
            setUnmatched(report.unmatched ?? {});
        } catch (e) {
            setError(e instanceof Error ? e.message : String(e));
        } finally {
            setLoading(false);
        }
    }, [client, today]);

    useEffect(() => {
        if (sidecarState === "running") load();
    }, [sidecarState, load]);

    const projectNames = Object.keys(draft.projects ?? {}).sort();

    const signalKey = (kind: string, value: string) => `${kind}:${value}`;

    const handleAssign = async (kind: string, value: string) => {
        const key = signalKey(kind, value);
        const isNew = selections[key] === "__new__";
        const newName = isNew ? (newProjectInputs[key] ?? "").trim() : "";
        const project = isNew ? newName : selections[key];
        if (!project || !client) return;
        setAssigning((s) => new Set(s).add(key));
        try {
            if (isNew) {
                await client.reassignSignal(kind, value, newName, true);
                setField(["projects", newName], {});
            } else {
                await client.reassignSignal(kind, value, project);
            }
            applySignalToDraft(draft, setField, kind, value, project);
            setDismissed((s) => new Set(s).add(key));
            if (isNew)
                setNewProjectInputs((prev) => {
                    const next = { ...prev };
                    delete next[key];
                    return next;
                });
        } catch (e) {
            // leave signal in place; user can retry
        } finally {
            setAssigning((prev) => {
                const next = new Set(prev);
                next.delete(key);
                return next;
            });
        }
    };

    const handleSuggest = async () => {
        if (!client) return;
        setSuggesting(true);
        setSuggestions([]);
        setSuggestionError(null);
        try {
            const result = await client.suggestAssignments(today);
            setSuggestions(result.suggestions);
            if (result.suggestions.length === 0) setSuggestionError("No confident suggestions found.");
        } catch (e) {
            setSuggestionError(e instanceof Error ? e.message : String(e));
        } finally {
            setSuggesting(false);
        }
    };

    const applySuggestion = async (s: Suggestion) => {
        const key = signalKey(s.kind, s.value);
        setSelections((prev) => ({ ...prev, [key]: s.project }));
        await handleAssign(s.kind, s.value);
        setSuggestions((prev) => prev.filter((x) => x.kind !== s.kind || x.value !== s.value));
    };

    if (sidecarState === "idle" || sidecarState === "starting" || (sidecarState === "running" && loading)) {
        return (
            <View style={styles.center}>
                <ActivityIndicator size="large" color={Brand.terracotta} />
                <Text style={styles.loadingText}>
                    {sidecarState !== "running" ? "Starting engine…" : "Loading today's signals…"}
                </Text>
            </View>
        );
    }

    if (error) {
        return (
            <View style={styles.center}>
                <Text style={styles.errorTitle}>Could not load signals</Text>
                <Text style={styles.errorMsg} selectable>
                    {error}
                </Text>
                <Pressable onPress={load} style={styles.primaryBtn}>
                    <Text style={styles.primaryBtnText}>Retry</Text>
                </Pressable>
            </View>
        );
    }

    const visibleKinds = SIGNAL_KINDS.filter((k) => {
        const bucket = unmatched[k] ?? {};
        return Object.keys(bucket).some((v) => !dismissed.has(signalKey(k, v)));
    });

    const hasLlm = (draft.integrations?.llm?.length ?? 0) > 0;

    return (
        <ScrollView style={styles.scroll} contentContainerStyle={styles.content}>
            {/* Header row */}
            <View style={styles.headerRow}>
                <Text style={styles.headerTitle}>Unmatched Signals — {today}</Text>
                <View style={styles.headerActions}>
                    <Pressable onPress={load} style={styles.actionBtn}>
                        <Text style={styles.actionBtnText}>Refresh</Text>
                    </Pressable>
                    {hasLlm && (
                        <Pressable
                            onPress={handleSuggest}
                            disabled={suggesting}
                            style={[styles.actionBtn, styles.actionBtnPrimary, suggesting && styles.actionBtnDisabled]}
                        >
                            <Text style={[styles.actionBtnText, styles.actionBtnPrimaryText]}>
                                {suggesting ? "Thinking…" : "✦ Suggest"}
                            </Text>
                        </Pressable>
                    )}
                </View>
            </View>

            {/* LLM suggestions */}
            {suggestions.length > 0 && (
                <View style={styles.suggestionBox}>
                    <Text style={styles.suggestionHeader}>AI Suggestions</Text>
                    {suggestions.map((s) => {
                        const key = signalKey(s.kind, s.value);
                        const busy = assigning.has(key);
                        return (
                            <View key={key} style={styles.suggestionRow}>
                                <View style={styles.suggestionLeft}>
                                    <Text style={styles.suggestionKind}>{KIND_LABELS[s.kind] ?? s.kind}</Text>
                                    <Text style={styles.suggestionValue} numberOfLines={1}>
                                        {s.value}
                                    </Text>
                                    <Text style={styles.suggestionArrow}>→ {s.project}</Text>
                                    <Text style={styles.suggestionReason} numberOfLines={2}>
                                        {s.reason}
                                    </Text>
                                </View>
                                <Pressable
                                    onPress={() => {
                                        setSelections((prev) => ({ ...prev, [key]: s.project }));
                                        applySuggestion(s);
                                    }}
                                    disabled={busy}
                                    style={[styles.assignBtn, busy && styles.assignBtnDisabled]}
                                >
                                    <Text style={styles.assignBtnText}>{busy ? "…" : "Apply"}</Text>
                                </Pressable>
                            </View>
                        );
                    })}
                </View>
            )}
            {suggestionError && <Text style={styles.suggestionError}>{suggestionError}</Text>}

            {visibleKinds.length === 0 ? (
                <View style={styles.emptyBox}>
                    <Text style={styles.emptyText}>No unmatched signals for today.</Text>
                    <Text style={styles.emptySubtext}>All detected activity has been classified to a project.</Text>
                </View>
            ) : (
                visibleKinds.map((kind) => {
                    const bucket = unmatched[kind] ?? {};
                    const entries = Object.entries(bucket)
                        .filter(([v]) => !dismissed.has(signalKey(kind, v)))
                        .sort(([, a], [, b]) => b - a);
                    if (entries.length === 0) return null;

                    return (
                        <View key={kind} style={styles.kindSection}>
                            <Text style={styles.kindLabel}>{KIND_LABELS[kind] ?? kind}</Text>
                            {entries.map(([value, count]) => {
                                const key = signalKey(kind, value);
                                const selected = selections[key];
                                const busy = assigning.has(key);
                                const isOpen = pickerOpen === key;
                                return (
                                    <View key={key} style={styles.signalRow}>
                                        <View style={styles.signalInfo}>
                                            <Text style={styles.signalValue} numberOfLines={1} selectable>
                                                {value}
                                            </Text>
                                            <Text style={styles.signalCount}>{count}×</Text>
                                        </View>
                                        <View style={styles.signalActions}>
                                            <Pressable
                                                onPress={() => setPickerOpen(isOpen ? null : key)}
                                                style={styles.pickerBtn}
                                            >
                                                <Text style={styles.pickerBtnText} numberOfLines={1}>
                                                    {selected ?? "Select project…"}
                                                </Text>
                                                <Text style={styles.pickerChevron}>{isOpen ? "▲" : "▼"}</Text>
                                            </Pressable>
                                            <Pressable
                                                onPress={() => handleAssign(kind, value)}
                                                disabled={
                                                    !selected ||
                                                    busy ||
                                                    (selected === "__new__" && !(newProjectInputs[key] ?? "").trim())
                                                }
                                                style={[
                                                    styles.assignBtn,
                                                    (!selected ||
                                                        busy ||
                                                        (selected === "__new__" &&
                                                            !(newProjectInputs[key] ?? "").trim())) &&
                                                        styles.assignBtnDisabled,
                                                ]}
                                            >
                                                <Text style={styles.assignBtnText}>{busy ? "…" : "Assign"}</Text>
                                            </Pressable>
                                        </View>
                                        {isOpen && (
                                            <View style={styles.dropdown}>
                                                {(kind === "browser" || kind === "apps") && (
                                                    <Pressable
                                                        onPress={() => {
                                                            setSelections((prev) => ({
                                                                ...prev,
                                                                [key]: "__personal__",
                                                            }));
                                                            setPickerOpen(null);
                                                        }}
                                                        style={[
                                                            styles.dropdownItem,
                                                            styles.dropdownItemSpecial,
                                                            selected === "__personal__" && styles.dropdownItemSelected,
                                                        ]}
                                                    >
                                                        <Text
                                                            style={[
                                                                styles.dropdownItemText,
                                                                styles.dropdownItemSpecialText,
                                                                selected === "__personal__" &&
                                                                    styles.dropdownItemTextSelected,
                                                            ]}
                                                        >
                                                            Personal (ignore)
                                                        </Text>
                                                    </Pressable>
                                                )}
                                                {kind === "apps" && !value.startsWith("ssh:") && (
                                                    <Pressable
                                                        onPress={() => {
                                                            setSelections((prev) => ({
                                                                ...prev,
                                                                [key]: "__correlated__",
                                                            }));
                                                            setPickerOpen(null);
                                                        }}
                                                        style={[
                                                            styles.dropdownItem,
                                                            styles.dropdownItemSpecial,
                                                            selected === "__correlated__" &&
                                                                styles.dropdownItemSelected,
                                                        ]}
                                                    >
                                                        <Text
                                                            style={[
                                                                styles.dropdownItemText,
                                                                styles.dropdownItemSpecialText,
                                                                selected === "__correlated__" &&
                                                                    styles.dropdownItemTextSelected,
                                                            ]}
                                                        >
                                                            Correlated (attribute to active project)
                                                        </Text>
                                                    </Pressable>
                                                )}
                                                {projectNames.map((p) => (
                                                    <Pressable
                                                        key={p}
                                                        onPress={() => {
                                                            setSelections((prev) => ({ ...prev, [key]: p }));
                                                            setPickerOpen(null);
                                                        }}
                                                        style={[
                                                            styles.dropdownItem,
                                                            selected === p && styles.dropdownItemSelected,
                                                        ]}
                                                    >
                                                        <Text
                                                            style={[
                                                                styles.dropdownItemText,
                                                                selected === p && styles.dropdownItemTextSelected,
                                                            ]}
                                                        >
                                                            {p}
                                                        </Text>
                                                    </Pressable>
                                                ))}
                                                <Pressable
                                                    onPress={() => {
                                                        setSelections((prev) => ({ ...prev, [key]: "__new__" }));
                                                        setPickerOpen(null);
                                                    }}
                                                    style={[styles.dropdownItem, styles.dropdownItemSpecial]}
                                                >
                                                    <Text
                                                        style={[
                                                            styles.dropdownItemText,
                                                            styles.dropdownItemSpecialText,
                                                        ]}
                                                    >
                                                        + New project…
                                                    </Text>
                                                </Pressable>
                                            </View>
                                        )}
                                        {selected === "__new__" && (
                                            <TextInput
                                                value={newProjectInputs[key] ?? ""}
                                                onChangeText={(t) =>
                                                    setNewProjectInputs((prev) => ({ ...prev, [key]: t }))
                                                }
                                                placeholder="New project name…"
                                                style={styles.newProjectInput}
                                                autoFocus
                                            />
                                        )}
                                    </View>
                                );
                            })}
                        </View>
                    );
                })
            )}
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    scroll: { flex: 1 },
    content: { padding: 16, gap: 12 },
    center: {
        flex: 1,
        alignItems: "center",
        justifyContent: "center",
        padding: 32,
        gap: 12,
    },
    loadingText: { color: "#666", fontSize: 13, marginTop: 8 },
    errorTitle: { fontSize: 15, fontWeight: "600", color: "#333" },
    errorMsg: { fontSize: 12, color: "#888", textAlign: "center", maxWidth: 360 },
    primaryBtn: {
        marginTop: 4,
        paddingVertical: 7,
        paddingHorizontal: 16,
        backgroundColor: Brand.terracotta,
        borderRadius: 5,
    },
    primaryBtnText: { fontSize: 13, color: "#fff", fontWeight: "600" },
    headerRow: {
        flexDirection: "row",
        alignItems: "center",
        justifyContent: "space-between",
        marginBottom: 4,
    },
    headerTitle: { fontSize: 13, fontWeight: "600", color: "#333" },
    headerActions: { flexDirection: "row", gap: 8 },
    actionBtn: {
        paddingVertical: 5,
        paddingHorizontal: 10,
        borderWidth: 1,
        borderColor: "#ccc",
        borderRadius: 4,
    },
    actionBtnPrimary: {
        backgroundColor: Brand.terracotta,
        borderColor: Brand.terracotta,
    },
    actionBtnDisabled: { opacity: 0.4 },
    actionBtnText: { fontSize: 12, color: "#444" },
    actionBtnPrimaryText: { color: "#fff", fontWeight: "600" },
    suggestionBox: {
        borderWidth: 1,
        borderColor: Brand.amber,
        borderRadius: 6,
        padding: 10,
        gap: 8,
        backgroundColor: "#FFFBF3",
    },
    suggestionHeader: {
        fontSize: 11,
        fontWeight: "700",
        color: Brand.terracotta,
        textTransform: "uppercase",
        letterSpacing: 0.5,
    },
    suggestionRow: {
        flexDirection: "row",
        alignItems: "flex-start",
        gap: 8,
    },
    suggestionLeft: { flex: 1, gap: 2 },
    suggestionKind: { fontSize: 10, color: "#888", textTransform: "uppercase" },
    suggestionValue: { fontSize: 12, color: "#111", fontWeight: "500" },
    suggestionArrow: { fontSize: 12, color: Brand.terracotta, fontWeight: "600" },
    suggestionReason: { fontSize: 11, color: "#777" },
    suggestionError: { fontSize: 12, color: "#888", textAlign: "center", marginTop: 4 },
    emptyBox: { alignItems: "center", paddingVertical: 32, gap: 6 },
    emptyText: { fontSize: 14, color: "#555", fontWeight: "500" },
    emptySubtext: { fontSize: 12, color: "#999", textAlign: "center", maxWidth: 300 },
    kindSection: { gap: 4 },
    kindLabel: {
        fontSize: 10,
        fontWeight: "700",
        color: "#888",
        textTransform: "uppercase",
        letterSpacing: 0.5,
        marginBottom: 2,
    },
    signalRow: {
        borderWidth: 1,
        borderColor: "#e4e4e4",
        borderRadius: 6,
        padding: 8,
        backgroundColor: "#fff",
        gap: 6,
    },
    signalInfo: {
        flexDirection: "row",
        alignItems: "center",
        justifyContent: "space-between",
    },
    signalValue: { flex: 1, fontSize: 12, color: "#222", fontFamily: "Menlo" },
    signalCount: { fontSize: 11, color: "#999", marginLeft: 8, flexShrink: 0 },
    signalActions: { flexDirection: "row", gap: 6, alignItems: "center" },
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
    assignBtn: {
        paddingVertical: 4,
        paddingHorizontal: 10,
        backgroundColor: Brand.terracotta,
        borderRadius: 4,
        flexShrink: 0,
    },
    assignBtnDisabled: { opacity: 0.35 },
    assignBtnText: { fontSize: 12, color: "#fff", fontWeight: "600" },
    dropdown: {
        borderWidth: 1,
        borderColor: "#ddd",
        borderRadius: 4,
        backgroundColor: "#fff",
        maxHeight: 180,
        overflow: "hidden",
    },
    dropdownItem: {
        paddingHorizontal: 10,
        paddingVertical: 6,
        borderBottomWidth: StyleSheet.hairlineWidth,
        borderBottomColor: "#eee",
    },
    dropdownItemSelected: { backgroundColor: "#FFF4EE" },
    dropdownItemSpecial: { backgroundColor: "#F5F5F5" },
    dropdownItemText: { fontSize: 12, color: "#333" },
    dropdownItemTextSelected: { color: Brand.terracotta, fontWeight: "600" },
    dropdownItemSpecialText: { color: "#666", fontStyle: "italic" },
    newProjectInput: {
        borderWidth: 1,
        borderColor: Brand.terracotta,
        borderRadius: 4,
        paddingHorizontal: 8,
        paddingVertical: 4,
        fontSize: 12,
        color: "#222",
        backgroundColor: "#fff",
        marginTop: 4,
    },
});
