import React, { useState } from "react";
import { View, Text, Pressable, StyleSheet } from "react-native";
import type { ProjectReport } from "./EngineClient";
import { Brand } from "../brand";

type Props = {
    projects: Array<[string, ProjectReport]>;
    totalSeconds: number;
};

function fmtDur(seconds: number): string {
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    if (h === 0) return `${m}m`;
    if (m === 0) return `${h}h`;
    return `${h}h ${m}m`;
}

export function HarvestPanel({ projects, totalSeconds }: Props) {
    const [expanded, setExpanded] = useState(false);

    // Aggregate harvest-logged seconds by label across all projects.
    const entryMap: Record<string, number> = {};
    for (const [, data] of projects) {
        for (const [label, sec] of Object.entries(data.detail.harvest ?? {})) {
            entryMap[label] = (entryMap[label] ?? 0) + sec;
        }
    }

    const loggedSec = Object.values(entryMap).reduce((s, v) => s + v, 0);
    if (loggedSec === 0 && Object.keys(entryMap).length === 0) return null;

    const gapSec = totalSeconds - loggedSec;
    const hasGap = gapSec >= 900;
    const entries = Object.entries(entryMap).sort(([, a], [, b]) => b - a);

    return (
        <View style={styles.panel}>
            <Pressable style={styles.header} onPress={() => setExpanded((v) => !v)}>
                <View style={styles.headerLeft}>
                    <Text style={styles.title}>Harvest</Text>
                    <Text style={styles.logged}>{fmtDur(loggedSec)} logged</Text>
                    {hasGap && (
                        <View style={styles.gapBadge}>
                            <Text style={styles.gapText}>{fmtDur(gapSec)} gap</Text>
                        </View>
                    )}
                </View>
                <Text style={styles.chevron}>{expanded ? "▲" : "▼"}</Text>
            </Pressable>

            {expanded && (
                <View style={styles.entries}>
                    {entries.map(([label, sec]) => (
                        <View key={label} style={styles.entryRow}>
                            <Text style={styles.entryLabel} numberOfLines={1}>
                                {label}
                            </Text>
                            <Text style={styles.entrySec}>{fmtDur(sec)}</Text>
                        </View>
                    ))}
                    {entries.length === 0 && <Text style={styles.noEntries}>No Harvest entries logged</Text>}
                    <View style={styles.totalsRow}>
                        <Text style={styles.totalsLabel}>Tracked (timesheets)</Text>
                        <Text style={styles.totalsVal}>{fmtDur(totalSeconds)}</Text>
                    </View>
                    {hasGap && (
                        <View style={[styles.totalsRow, styles.gapRow]}>
                            <Text style={styles.gapRowLabel}>Unlogged gap</Text>
                            <Text style={styles.gapRowVal}>{fmtDur(gapSec)}</Text>
                        </View>
                    )}
                </View>
            )}
        </View>
    );
}

const styles = StyleSheet.create({
    panel: {
        marginHorizontal: 12,
        marginBottom: 8,
        borderWidth: 1,
        borderColor: "#e0d8ce",
        borderRadius: 8,
        backgroundColor: "#fff",
        overflow: "hidden",
    },
    header: {
        flexDirection: "row",
        alignItems: "center",
        paddingHorizontal: 12,
        paddingVertical: 8,
        backgroundColor: "#FAF7F3",
    },
    headerLeft: {
        flex: 1,
        flexDirection: "row",
        alignItems: "center",
        gap: 8,
        flexWrap: "wrap",
    },
    title: {
        fontSize: 12,
        fontWeight: "700",
        color: "#555",
        textTransform: "uppercase",
        letterSpacing: 0.4,
    },
    logged: {
        fontSize: 12,
        color: "#444",
        fontWeight: "500",
    },
    gapBadge: {
        backgroundColor: "#FFF0E0",
        borderWidth: 1,
        borderColor: Brand.terracotta,
        borderRadius: 4,
        paddingHorizontal: 5,
        paddingVertical: 1,
    },
    gapText: {
        fontSize: 11,
        color: Brand.terracotta,
        fontWeight: "600",
    },
    chevron: {
        fontSize: 9,
        color: "#999",
        marginLeft: 6,
    },
    entries: {
        borderTopWidth: StyleSheet.hairlineWidth,
        borderTopColor: "#e8e0d6",
    },
    entryRow: {
        flexDirection: "row",
        alignItems: "center",
        paddingHorizontal: 12,
        paddingVertical: 5,
        borderBottomWidth: StyleSheet.hairlineWidth,
        borderBottomColor: "#f0eae2",
    },
    entryLabel: {
        flex: 1,
        fontSize: 12,
        color: "#333",
    },
    entrySec: {
        fontSize: 12,
        color: "#555",
        marginLeft: 8,
    },
    noEntries: {
        padding: 10,
        fontSize: 12,
        color: "#888",
        textAlign: "center",
    },
    totalsRow: {
        flexDirection: "row",
        alignItems: "center",
        paddingHorizontal: 12,
        paddingVertical: 5,
        borderTopWidth: StyleSheet.hairlineWidth,
        borderTopColor: "#e8e0d6",
        backgroundColor: "#FAF7F3",
    },
    totalsLabel: {
        flex: 1,
        fontSize: 11,
        color: "#888",
    },
    totalsVal: {
        fontSize: 11,
        color: "#666",
        marginLeft: 8,
    },
    gapRow: {
        backgroundColor: "#FFF6EE",
    },
    gapRowLabel: {
        flex: 1,
        fontSize: 11,
        color: Brand.terracotta,
        fontWeight: "600",
    },
    gapRowVal: {
        fontSize: 11,
        color: Brand.terracotta,
        fontWeight: "600",
        marginLeft: 8,
    },
});
