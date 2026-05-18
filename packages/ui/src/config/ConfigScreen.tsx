import React, { useEffect, useState } from "react";
import { View, Text, Pressable, ActivityIndicator, Alert, StyleSheet } from "react-native";
import { configSchema } from "./configSchema";
import { useConfigDraft } from "./useConfigDraft";
import { NativeEngine } from "./NativeEngine";
import { GeneralTab } from "./tabs/GeneralTab";
import { ProjectsTab } from "./tabs/ProjectsTab";
import { GroupingsTab } from "./tabs/GroupingsTab";
import { IntegrationsTab } from "./tabs/IntegrationsTab";
import { SignalsTab } from "./tabs/SignalsTab";

const TABS = ["General", "Projects", "Groupings", "Integrations", "Signals"] as const;
type TabName = (typeof TABS)[number];

type Phase = "loading" | "no-config" | "ready" | "error";

export function ConfigScreen() {
    const [phase, setPhase] = useState<Phase>("loading");
    const [errorMsg, setErrorMsg] = useState("");
    const [configPath, setConfigPath] = useState("");
    const [activeTab, setActiveTab] = useState<TabName>("General");
    const [saving, setSaving] = useState(false);

    const { draft, isDirty, reset, setField, discard, markSaved } = useConfigDraft();

    useEffect(() => {
        let cancelled = false;
        NativeEngine.getConfigPath()
            .then((p) => {
                if (!cancelled) setConfigPath(p);
            })
            .catch(() => {});

        NativeEngine.getConfig()
            .then((raw) => {
                if (cancelled) return;
                if (raw === null) {
                    setPhase("no-config");
                    return;
                }
                const result = configSchema.safeParse(raw);
                if (!result.success) {
                    setErrorMsg(`Config validation failed: ${result.error.issues[0]?.message ?? "unknown"}`);
                    setPhase("error");
                    return;
                }
                reset(result.data);
                setPhase("ready");
            })
            .catch((e: Error) => {
                if (!cancelled) {
                    setErrorMsg(e.message);
                    setPhase("error");
                }
            });

        return () => {
            cancelled = true;
        };
    }, [reset]);

    const handleSave = async () => {
        if (!draft) return;
        const result = configSchema.safeParse(draft);
        if (!result.success) {
            Alert.alert("Validation Error", result.error.issues[0]?.message ?? "Invalid config");
            return;
        }
        setSaving(true);
        try {
            await NativeEngine.saveConfig(result.data);
            markSaved(result.data);
        } catch (e: unknown) {
            Alert.alert("Save Failed", e instanceof Error ? e.message : String(e));
        } finally {
            setSaving(false);
        }
    };

    const handleDiscard = () => {
        if (!isDirty) return;
        Alert.alert("Discard Changes", "Revert all unsaved changes?", [
            { text: "Keep Editing", style: "cancel" },
            { text: "Discard", style: "destructive", onPress: discard },
        ]);
    };

    const handlePickDir = async () => {
        try {
            const path = await NativeEngine.pickConfigDir();
            if (!path) return;
            setConfigPath(path);
            const raw = await NativeEngine.getConfig();
            if (raw === null) {
                setPhase("no-config");
                return;
            }
            const result = configSchema.safeParse(raw);
            if (!result.success) {
                setErrorMsg(`Config validation failed: ${result.error.issues[0]?.message ?? "unknown"}`);
                setPhase("error");
                return;
            }
            reset(result.data);
            setPhase("ready");
        } catch (e: unknown) {
            Alert.alert("Error", e instanceof Error ? e.message : String(e));
        }
    };

    if (phase === "loading") {
        return (
            <View style={styles.center}>
                <ActivityIndicator size="large" />
                <Text style={styles.loadingText}>Loading config…</Text>
            </View>
        );
    }

    if (phase === "error") {
        return (
            <View style={styles.center}>
                <Text style={styles.errorTitle}>Could not load config</Text>
                <Text style={styles.errorMsg}>{errorMsg}</Text>
                <Pressable onPress={handlePickDir} style={styles.primaryBtn}>
                    <Text style={styles.primaryBtnText}>Choose Config Directory…</Text>
                </Pressable>
            </View>
        );
    }

    if (phase === "no-config") {
        return (
            <View style={styles.center}>
                <Text style={styles.errorTitle}>No config.json found</Text>
                <Text style={styles.errorMsg}>
                    Default location: {configPath || "~/.config/timesheets/config.json"}
                </Text>
                <Pressable onPress={handlePickDir} style={styles.primaryBtn}>
                    <Text style={styles.primaryBtnText}>Choose Config Directory…</Text>
                </Pressable>
            </View>
        );
    }

    return (
        <View style={styles.root}>
            {/* Header bar */}
            <View style={styles.header}>
                <Pressable
                    onPress={handleDiscard}
                    disabled={!isDirty}
                    style={[styles.headerBtn, !isDirty && styles.headerBtnDisabled]}
                >
                    <Text style={[styles.headerBtnText, !isDirty && styles.headerBtnTextDisabled]}>Discard</Text>
                </Pressable>
                <View style={styles.headerCenter}>
                    <Text style={styles.headerTitle}>Config{isDirty ? " •" : ""}</Text>
                    <Text style={styles.headerPath} numberOfLines={1}>
                        {configPath}
                    </Text>
                </View>
                <Pressable
                    onPress={handleSave}
                    disabled={!isDirty || saving}
                    style={[styles.headerBtn, styles.headerSaveBtn, (!isDirty || saving) && styles.headerBtnDisabled]}
                >
                    <Text
                        style={[
                            styles.headerBtnText,
                            styles.headerSaveBtnText,
                            (!isDirty || saving) && styles.headerBtnTextDisabled,
                        ]}
                    >
                        {saving ? "Saving…" : "Save"}
                    </Text>
                </Pressable>
            </View>

            {/* Tab bar */}
            <View style={styles.tabBar}>
                {TABS.map((tab) => (
                    <Pressable
                        key={tab}
                        onPress={() => setActiveTab(tab)}
                        style={[styles.tab, activeTab === tab && styles.tabActive]}
                    >
                        <Text style={[styles.tabText, activeTab === tab && styles.tabTextActive]}>{tab}</Text>
                    </Pressable>
                ))}
            </View>

            {/* Tab content */}
            <View style={styles.tabContent}>
                {draft && activeTab === "General" && <GeneralTab draft={draft} setField={setField} />}
                {draft && activeTab === "Projects" && <ProjectsTab draft={draft} setField={setField} />}
                {draft && activeTab === "Groupings" && <GroupingsTab draft={draft} setField={setField} />}
                {draft && activeTab === "Integrations" && <IntegrationsTab draft={draft} setField={setField} />}
                {activeTab === "Signals" && <SignalsTab />}
            </View>
        </View>
    );
}

const styles = StyleSheet.create({
    root: {
        flex: 1,
        backgroundColor: "#fff",
    },
    center: {
        flex: 1,
        alignItems: "center",
        justifyContent: "center",
        padding: 32,
        gap: 12,
    },
    loadingText: {
        marginTop: 8,
        color: "#666",
        fontSize: 13,
    },
    errorTitle: {
        fontSize: 16,
        fontWeight: "600",
        color: "#333",
    },
    errorMsg: {
        fontSize: 13,
        color: "#888",
        textAlign: "center",
        maxWidth: 400,
    },
    primaryBtn: {
        marginTop: 8,
        paddingVertical: 9,
        paddingHorizontal: 16,
        backgroundColor: "#007AFF",
        borderRadius: 6,
    },
    primaryBtnText: {
        fontSize: 13,
        color: "#fff",
        fontWeight: "600",
    },
    header: {
        flexDirection: "row",
        alignItems: "center",
        paddingHorizontal: 12,
        paddingVertical: 8,
        borderBottomWidth: 1,
        borderBottomColor: "#d0d0d0",
        backgroundColor: "#f5f5f5",
    },
    headerCenter: {
        flex: 1,
        alignItems: "center",
    },
    headerTitle: {
        fontSize: 13,
        fontWeight: "600",
        color: "#333",
    },
    headerPath: {
        fontSize: 10,
        color: "#999",
        maxWidth: 400,
    },
    headerBtn: {
        paddingHorizontal: 12,
        paddingVertical: 5,
        borderRadius: 4,
        borderWidth: 1,
        borderColor: "#aaa",
        minWidth: 70,
        alignItems: "center",
    },
    headerSaveBtn: {
        backgroundColor: "#007AFF",
        borderColor: "#007AFF",
    },
    headerBtnDisabled: {
        opacity: 0.35,
    },
    headerBtnText: {
        fontSize: 12,
        color: "#333",
    },
    headerSaveBtnText: {
        color: "#fff",
        fontWeight: "600",
    },
    headerBtnTextDisabled: {},
    tabBar: {
        flexDirection: "row",
        backgroundColor: "#f8f8f8",
        borderBottomWidth: 1,
        borderBottomColor: "#d0d0d0",
    },
    tab: {
        flex: 1,
        paddingVertical: 8,
        alignItems: "center",
        borderBottomWidth: 2,
        borderBottomColor: "transparent",
    },
    tabActive: {
        borderBottomColor: "#007AFF",
    },
    tabText: {
        fontSize: 12,
        color: "#555",
    },
    tabTextActive: {
        color: "#007AFF",
        fontWeight: "600",
    },
    tabContent: {
        flex: 1,
    },
});
