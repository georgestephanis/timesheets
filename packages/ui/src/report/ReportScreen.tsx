import React, { useCallback, useEffect, useState } from "react";
import { View, Text, Pressable, ActivityIndicator, Alert, StyleSheet, TextInput } from "react-native";
import { useSidecar } from "../SidecarContext";
import type { Report } from "./EngineClient";
import { DayView } from "./DayView";
import { RangeView } from "./RangeView";
import { WarningBanner } from "./WarningBanner";
import { Brand } from "../brand";

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
    const { state: sidecarState, error: sidecarError, client, locateAndStart } = useSidecar();

    const [date, setDate] = useState(todayString);
    const [report, setReport] = useState<Report | null>(null);
    const [loadState, setLoadState] = useState<"idle" | "loading" | "error">("idle");
    const [loadError, setLoadError] = useState("");
    const [rebuilding, setRebuilding] = useState(false);
    const [generating, setGenerating] = useState(false);
    const [dateEditing, setDateEditing] = useState(false);
    const [dateInput, setDateInput] = useState("");
    const [backfilling, setBackfilling] = useState(false);
    const [backfillProgress, setBackfillProgress] = useState(0);
    const [projectFilter, setProjectFilter] = useState("");
    const [filterOpen, setFilterOpen] = useState(false);
    const [rangeMode, setRangeMode] = useState<"day" | "week">("day");
    const [lastRebuildTime, setLastRebuildTime] = useState<number>(0);

    // ── Load report ───────────────────────────────────────────────────────────

    const rangeFrom = rangeMode === "week" ? offsetDate(date, -6) : date;
    const rangeTo = date;

    const loadReport = useCallback(
        async (rebuild = false) => {
            if (!client) return;
            setLoadState("loading");
            setLoadError("");
            if (rebuild) setRebuilding(true);
            try {
                const r = await client.getReport(rangeFrom, rangeTo, rebuild);
                setReport(r);
                setLoadState("idle");
            } catch (e: unknown) {
                setLoadError(e instanceof Error ? e.message : String(e));
                setLoadState("error");
            } finally {
                setRebuilding(false);
            }
        },
        [client, rangeFrom, rangeTo],
    );

    useEffect(() => {
        if (sidecarState === "running") loadReport();
    }, [sidecarState, loadReport]);

    useEffect(() => {
        if (sidecarState === "running" && client) {
            client
                .getLastRebuildTime()
                .then(({ mtime }) => setLastRebuildTime(mtime))
                .catch(() => {});
        }
    }, [sidecarState, client]);

    // ── Date navigation ───────────────────────────────────────────────────────

    const goTo = useCallback(
        (delta: number) => {
            setDate((d) => offsetDate(d, rangeMode === "week" ? delta * 7 : delta));
            setReport(null);
        },
        [rangeMode],
    );

    const isToday = date === todayString();

    // Clear filter on date or range mode change.
    useEffect(() => {
        setProjectFilter("");
        setFilterOpen(false);
    }, [date, rangeMode]);

    // Clear filter if the selected project disappears from the report.
    useEffect(() => {
        if (!projectFilter || !report) return;
        const allProjects = new Set(Object.values(report.days).flatMap((d) => Object.keys(d)));
        if (!allProjects.has(projectFilter)) setProjectFilter("");
    }, [report, projectFilter]);

    // ── Date jump (tap-to-edit) ───────────────────────────────────────────────

    const commitDateEdit = useCallback(() => {
        const trimmed = dateInput.trim();
        if (/^\d{4}-\d{2}-\d{2}$/.test(trimmed)) {
            const d = new Date(trimmed + "T12:00:00");
            if (!isNaN(d.getTime()) && trimmed <= todayString()) {
                setDate(trimmed);
                setReport(null);
            }
        }
        setDateEditing(false);
        setDateInput("");
    }, [dateInput]);

    // ── Backfill last 7 days ──────────────────────────────────────────────────

    const handleBackfill = useCallback(async () => {
        if (!client) return;
        setBackfilling(true);
        const today = todayString();
        for (let i = 1; i <= 7; i++) {
            setBackfillProgress(i);
            try {
                await client.getReport(offsetDate(today, -i), offsetDate(today, -i), true);
            } catch {
                // continue with next day
            }
        }
        setBackfilling(false);
        setBackfillProgress(0);
    }, [client]);

    // ── Generate LLM summary ──────────────────────────────────────────────────

    const handleGenerateSummary = useCallback(async () => {
        if (!client) return;
        setGenerating(true);
        try {
            await client.generateSummary(date);
            await loadReport();
        } catch (e: unknown) {
            Alert.alert("Summary Error", e instanceof Error ? e.message : String(e));
        } finally {
            setGenerating(false);
        }
    }, [client, date, loadReport]);

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
                <Pressable onPress={locateAndStart} style={styles.primaryBtn}>
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
                <Pressable onPress={locateAndStart} style={styles.primaryBtn}>
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
                    {dateEditing ? (
                        <TextInput
                            value={dateInput}
                            onChangeText={setDateInput}
                            onSubmitEditing={commitDateEdit}
                            onBlur={commitDateEdit}
                            placeholder={date}
                            autoFocus
                            style={styles.dateInput}
                        />
                    ) : (
                        <Pressable
                            onPress={() => {
                                if (rangeMode === "day") {
                                    setDateEditing(true);
                                    setDateInput(date);
                                }
                            }}
                        >
                            <Text style={styles.dateLabel}>
                                {rangeMode === "week"
                                    ? `${fmtDisplay(rangeFrom)} – ${fmtDisplay(date)}`
                                    : fmtDisplay(date)}
                            </Text>
                        </Pressable>
                    )}
                    {!isToday && !dateEditing && (
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
                    style={[styles.toolbarBtn, (rebuilding || loadState === "loading") && styles.btnDisabled]}
                >
                    <Text style={styles.toolbarBtnText}>{rebuilding ? "Rebuilding…" : "↺ Rebuild"}</Text>
                </Pressable>
                <Pressable
                    onPress={handleBackfill}
                    disabled={backfilling}
                    style={[styles.toolbarBtn, backfilling && styles.btnDisabled]}
                >
                    <Text style={styles.toolbarBtnText}>
                        {backfilling ? `Backfilling ${backfillProgress}/7…` : "⟳ Backfill 7 days"}
                    </Text>
                </Pressable>
                {lastRebuildTime > 0 && (
                    <Text style={styles.cacheBadge}>
                        {(() => {
                            const ageMs = Date.now() - lastRebuildTime;
                            const h = Math.floor(ageMs / 3_600_000);
                            const m = Math.floor((ageMs % 3_600_000) / 60_000);
                            const d = Math.floor(ageMs / 86_400_000);
                            if (d >= 1) return `rebuilt ${d}d ago`;
                            return `rebuilt ${h > 0 ? `${h}h ` : ""}${m}m ago`;
                        })()}
                    </Text>
                )}
                <View style={styles.modeToggle}>
                    {(["day", "week"] as const).map((m) => (
                        <Pressable
                            key={m}
                            onPress={() => {
                                setRangeMode(m);
                                setReport(null);
                            }}
                            style={[styles.modeBtn, rangeMode === m && styles.modeBtnActive]}
                        >
                            <Text style={[styles.modeBtnText, rangeMode === m && styles.modeBtnActiveText]}>
                                {m === "day" ? "Day" : "Week"}
                            </Text>
                        </Pressable>
                    ))}
                </View>
                {report && Object.keys(Object.assign({}, ...Object.values(report.days))).length > 1 && (
                    <Pressable
                        onPress={() => setFilterOpen((o) => !o)}
                        style={[styles.toolbarBtn, styles.filterBtn, projectFilter ? styles.filterBtnActive : null]}
                    >
                        <Text
                            style={[styles.toolbarBtnText, projectFilter ? styles.filterBtnActiveText : null]}
                            numberOfLines={1}
                        >
                            {projectFilter || "All projects"}
                        </Text>
                        <Text style={styles.filterChevron}>{filterOpen ? "▲" : "▼"}</Text>
                    </Pressable>
                )}
            </View>
            {filterOpen && report && (
                <View style={styles.filterDropdown}>
                    <Pressable
                        onPress={() => {
                            setProjectFilter("");
                            setFilterOpen(false);
                        }}
                        style={[styles.filterItem, !projectFilter && styles.filterItemSelected]}
                    >
                        <Text style={[styles.filterItemText, !projectFilter && styles.filterItemTextSelected]}>
                            All projects
                        </Text>
                    </Pressable>
                    {Object.keys(report.days[date] ?? {})
                        .sort()
                        .map((p) => (
                            <Pressable
                                key={p}
                                onPress={() => {
                                    setProjectFilter(p);
                                    setFilterOpen(false);
                                }}
                                style={[styles.filterItem, projectFilter === p && styles.filterItemSelected]}
                            >
                                <Text
                                    style={[
                                        styles.filterItemText,
                                        projectFilter === p && styles.filterItemTextSelected,
                                    ]}
                                    numberOfLines={1}
                                >
                                    {p}
                                </Text>
                            </Pressable>
                        ))}
                </View>
            )}

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
                rangeMode === "week" ? (
                    <RangeView report={report} projectFilter={projectFilter} />
                ) : (
                    <DayView
                        date={date}
                        report={report}
                        projectFilter={projectFilter}
                        onGenerateSummary={handleGenerateSummary}
                        generatingSummary={generating}
                    />
                )
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
        flexWrap: "wrap",
        alignItems: "center",
        gap: 6,
        paddingHorizontal: 12,
        paddingVertical: 6,
        backgroundColor: Brand.paper,
        borderBottomWidth: StyleSheet.hairlineWidth,
        borderBottomColor: "#d8d0c4",
    },
    toolbarBtn: {
        flexDirection: "row",
        alignItems: "center",
        paddingHorizontal: 10,
        paddingVertical: 4,
        borderWidth: 1,
        borderColor: Brand.terracotta,
        borderRadius: 4,
        gap: 4,
    },
    toolbarBtnText: { fontSize: 12, color: Brand.terracotta },
    filterBtn: { borderColor: "#bbb", maxWidth: 180 },
    filterBtnActive: { borderColor: Brand.terracotta, backgroundColor: "#FFF4EE" },
    filterBtnActiveText: { color: Brand.terracotta },
    filterChevron: { fontSize: 9, color: "#999" },
    filterDropdown: {
        backgroundColor: "#fff",
        borderWidth: 1,
        borderColor: "#ddd",
        borderTopWidth: 0,
        maxHeight: 220,
        overflow: "hidden",
    },
    filterItem: {
        paddingHorizontal: 14,
        paddingVertical: 7,
        borderBottomWidth: StyleSheet.hairlineWidth,
        borderBottomColor: "#eee",
    },
    filterItemSelected: { backgroundColor: "#FFF4EE" },
    filterItemText: { fontSize: 12, color: "#333" },
    filterItemTextSelected: { color: Brand.terracotta, fontWeight: "600" },
    cacheBadge: { fontSize: 11, color: "#999", marginLeft: "auto" },
    btnDisabled: { opacity: 0.4 },
    modeToggle: {
        flexDirection: "row",
        borderWidth: 1,
        borderColor: "#bbb",
        borderRadius: 4,
        overflow: "hidden",
    },
    modeBtn: {
        paddingHorizontal: 10,
        paddingVertical: 4,
        backgroundColor: "transparent",
    },
    modeBtnActive: { backgroundColor: Brand.ink },
    modeBtnText: { fontSize: 12, color: "#555" },
    modeBtnActiveText: { color: Brand.paper, fontWeight: "600" },
    dateInput: {
        fontSize: 14,
        fontWeight: "600",
        color: Brand.paper,
        borderBottomWidth: 1,
        borderBottomColor: Brand.amber,
        minWidth: 100,
        textAlign: "center",
    },
});
