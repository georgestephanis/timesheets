import React, { useCallback, useEffect, useRef, useState } from "react";
import { View, Text, Pressable, ActivityIndicator, Alert, StyleSheet } from "react-native";
import { NativeEngine } from "../config/NativeEngine";
import { EngineClient } from "./EngineClient";
import type { Report } from "./EngineClient";
import { DayView } from "./DayView";
import { WarningBanner } from "./WarningBanner";
import { Brand } from "../brand";

type SidecarState = "idle" | "starting" | "running" | "no-script" | "error";

function todayString(): string {
    return new Intl.DateTimeFormat("en-CA").format(new Date());
}

function offsetDate(dateStr: string, days: number): string {
    const d = new Date(dateStr + "T12:00:00");
    d.setDate(d.getDate() + days);
    return new Intl.DateTimeFormat("en-CA").format(d);
}

function fmtDisplay(dateStr: string): string {
    const d = new Date(dateStr + "T12:00:00");
    return d.toLocaleDateString("en-US", { weekday: "short", month: "short", day: "numeric" });
}

export function ReportScreen() {
    const [sidecarState, setSidecarState] = useState<SidecarState>("idle");
    const [sidecarError, setSidecarError] = useState("");
    const clientRef = useRef<EngineClient | null>(null);

    const [date, setDate] = useState(todayString);
    const [report, setReport] = useState<Report | null>(null);
    const [loadState, setLoadState] = useState<"idle" | "loading" | "error">("idle");
    const [loadError, setLoadError] = useState("");
    const [rebuilding, setRebuilding] = useState(false);
    const [generating, setGenerating] = useState(false);

    // ── Start sidecar ─────────────────────────────────────────────────────────

    const startSidecar = useCallback(async () => {
        setSidecarState("starting");
        setSidecarError("");
        try {
            // Check if already running.
            const existing = await NativeEngine.getSidecarPort();
            if (existing > 0) {
                clientRef.current = new EngineClient(existing);
                setSidecarState("running");
                return;
            }
            // Check script is configured.
            const scriptPath = await NativeEngine.getEngineScriptPath();
            if (!scriptPath) {
                setSidecarState("no-script");
                return;
            }
            const port = await NativeEngine.startSidecar();
            clientRef.current = new EngineClient(port);
            setSidecarState("running");
        } catch (e: unknown) {
            setSidecarError(e instanceof Error ? e.message : String(e));
            setSidecarState("error");
        }
    }, []);

    useEffect(() => {
        startSidecar();
        return () => {
            // Leave sidecar running; the OS kills it when the app exits.
        };
    }, [startSidecar]);

    // ── Load report ───────────────────────────────────────────────────────────

    const loadReport = useCallback(
        async (rebuild = false) => {
            if (!clientRef.current) return;
            setLoadState("loading");
            setLoadError("");
            if (rebuild) setRebuilding(true);
            try {
                const r = await clientRef.current.getReport(date, date, rebuild);
                setReport(r);
                setLoadState("idle");
            } catch (e: unknown) {
                setLoadError(e instanceof Error ? e.message : String(e));
                setLoadState("error");
            } finally {
                setRebuilding(false);
            }
        },
        [date],
    );

    useEffect(() => {
        if (sidecarState === "running") loadReport();
    }, [sidecarState, loadReport]);

    // ── Date navigation ───────────────────────────────────────────────────────

    const goTo = useCallback((delta: number) => {
        setDate((d) => offsetDate(d, delta));
        setReport(null);
    }, []);

    const isToday = date === todayString();

    // ── Generate LLM summary ──────────────────────────────────────────────────

    const handleGenerateSummary = useCallback(async () => {
        if (!clientRef.current) return;
        setGenerating(true);
        try {
            await clientRef.current.generateSummary(date);
            await loadReport();
        } catch (e: unknown) {
            Alert.alert("Summary Error", e instanceof Error ? e.message : String(e));
        } finally {
            setGenerating(false);
        }
    }, [date, loadReport]);

    // ── Locate engine script ──────────────────────────────────────────────────

    const handleLocateScript = async () => {
        try {
            const path = await NativeEngine.pickEngineScript();
            if (path) await startSidecar();
        } catch (e: unknown) {
            Alert.alert("Error", e instanceof Error ? e.message : String(e));
        }
    };

    // ── Render states ─────────────────────────────────────────────────────────

    if (sidecarState === "idle" || sidecarState === "starting") {
        return (
            <View style={styles.center}>
                <ActivityIndicator size="large" />
                <Text style={styles.statusText}>Starting engine…</Text>
            </View>
        );
    }

    if (sidecarState === "no-script") {
        return (
            <View style={styles.center}>
                <Text style={styles.heading}>Engine Not Configured</Text>
                <Text style={styles.body} selectable>
                    Locate <Text style={styles.code}>engine-server.js</Text> inside your Timesheets repository at{" "}
                    <Text style={styles.code}>apps/desktop/engine-server.js</Text>
                </Text>
                <Pressable onPress={handleLocateScript} style={styles.primaryBtn}>
                    <Text style={styles.primaryBtnText}>Locate engine-server.js…</Text>
                </Pressable>
            </View>
        );
    }

    if (sidecarState === "error") {
        return (
            <View style={styles.center}>
                <Text style={styles.heading}>Engine Error</Text>
                <Text style={styles.body} selectable>
                    {sidecarError}
                </Text>
                <Pressable onPress={() => startSidecar()} style={styles.primaryBtn}>
                    <Text style={styles.primaryBtnText}>Retry</Text>
                </Pressable>
            </View>
        );
    }

    return (
        <View style={styles.root}>
            {/* Date navigation bar */}
            <View style={styles.navBar}>
                <Pressable onPress={() => goTo(-1)} style={styles.navBtn}>
                    <Text style={styles.navBtnText}>‹</Text>
                </Pressable>
                <View style={styles.navCenter}>
                    <Text style={styles.dateLabel}>{fmtDisplay(date)}</Text>
                    {!isToday && (
                        <Pressable
                            onPress={() => {
                                setDate(todayString());
                                setReport(null);
                            }}
                        >
                            <Text style={styles.todayLink}>Today</Text>
                        </Pressable>
                    )}
                </View>
                <Pressable
                    onPress={() => goTo(1)}
                    disabled={isToday}
                    style={[styles.navBtn, isToday && styles.navBtnDisabled]}
                >
                    <Text style={[styles.navBtnText, isToday && styles.navBtnTextDisabled]}>›</Text>
                </Pressable>
            </View>

            {/* Toolbar */}
            <View style={styles.toolbar}>
                <Pressable
                    onPress={() => loadReport(true)}
                    disabled={rebuilding || loadState === "loading"}
                    style={[styles.rebuildBtn, (rebuilding || loadState === "loading") && styles.btnDisabled]}
                >
                    <Text style={styles.rebuildBtnText}>{rebuilding ? "Rebuilding…" : "↺ Rebuild"}</Text>
                </Pressable>
            </View>

            {/* Warnings */}
            {report?.warnings && <WarningBanner warnings={report.warnings} />}

            {/* Content */}
            {loadState === "loading" && !report ? (
                <View style={styles.center}>
                    <ActivityIndicator />
                    <Text style={styles.statusText}>Loading report…</Text>
                </View>
            ) : loadState === "error" ? (
                <View style={styles.center}>
                    <Text style={styles.errorText} selectable>
                        {loadError}
                    </Text>
                    <Pressable onPress={() => loadReport()} style={styles.primaryBtn}>
                        <Text style={styles.primaryBtnText}>Retry</Text>
                    </Pressable>
                </View>
            ) : report ? (
                <DayView
                    date={date}
                    report={report}
                    onGenerateSummary={handleGenerateSummary}
                    generatingSummary={generating}
                />
            ) : null}
        </View>
    );
}

const styles = StyleSheet.create({
    root: { flex: 1, backgroundColor: Brand.paper },
    center: {
        flex: 1,
        alignItems: "center",
        justifyContent: "center",
        padding: 32,
        gap: 12,
    },
    statusText: { color: "#888", fontSize: 13, marginTop: 8 },
    heading: { fontSize: 16, fontWeight: "600", color: "#333" },
    body: { fontSize: 13, color: "#666", textAlign: "center", maxWidth: 380, lineHeight: 20 },
    code: { fontFamily: "Menlo", fontSize: 12, color: "#555", backgroundColor: "#f0f0f0" },
    errorText: { fontSize: 13, color: "#c00", textAlign: "center" },
    primaryBtn: {
        paddingVertical: 9,
        paddingHorizontal: 16,
        backgroundColor: Brand.terracotta,
        borderRadius: 6,
    },
    primaryBtnText: { fontSize: 13, color: Brand.paper, fontWeight: "600" },
    navBar: {
        flexDirection: "row",
        alignItems: "center",
        paddingHorizontal: 8,
        paddingVertical: 8,
        backgroundColor: Brand.ink,
        borderBottomWidth: StyleSheet.hairlineWidth,
        borderBottomColor: "#333",
    },
    navBtn: { padding: 8 },
    navBtnDisabled: { opacity: 0.3 },
    navBtnText: { fontSize: 24, color: Brand.paper, lineHeight: 28 },
    navBtnTextDisabled: { color: "#888" },
    navCenter: {
        flex: 1,
        alignItems: "center",
        gap: 2,
    },
    dateLabel: { fontSize: 15, fontWeight: "600", color: Brand.paper },
    todayLink: { fontSize: 11, color: Brand.amber },
    toolbar: {
        flexDirection: "row",
        paddingHorizontal: 12,
        paddingVertical: 6,
        backgroundColor: Brand.paper,
        borderBottomWidth: StyleSheet.hairlineWidth,
        borderBottomColor: "#d8d0c4",
    },
    rebuildBtn: {
        paddingHorizontal: 10,
        paddingVertical: 4,
        borderWidth: 1,
        borderColor: Brand.terracotta,
        borderRadius: 4,
    },
    btnDisabled: { opacity: 0.4 },
    rebuildBtnText: { fontSize: 12, color: Brand.terracotta },
});
