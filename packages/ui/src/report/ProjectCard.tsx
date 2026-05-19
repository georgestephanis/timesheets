import React, { useState } from "react";
import { View, Text, Pressable, StyleSheet } from "react-native";
import type { ProjectReport } from "./EngineClient";
import { Brand } from "../brand";

type Props = {
    name: string;
    data: ProjectReport;
};

function fmtDur(seconds: number): string {
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    if (h === 0) return `${m}m`;
    if (m === 0) return `${h}h`;
    return `${h}h ${m}m`;
}

type ExternalIntg = { entries?: number; activity?: number; discussion?: number };

function badgeLabel(key: string, val: ExternalIntg): string {
    switch (key) {
        case "github": {
            const commits = val.entries ?? 0;
            const prs = val.discussion ?? 0;
            const parts = [];
            if (commits) parts.push(`${commits} commit${commits !== 1 ? "s" : ""}`);
            if (prs) parts.push(`${prs} PR${prs !== 1 ? "s" : ""}`);
            return `GitHub: ${parts.join(", ") || "0"}`;
        }
        case "harvest":
            return `Harvest: ${val.entries ?? 0} entr${(val.entries ?? 0) !== 1 ? "ies" : "y"}`;
        case "clickup":
            return `ClickUp: ${val.entries ?? 0} task${(val.entries ?? 0) !== 1 ? "s" : ""}`;
        case "clockify":
            return `Clockify: ${val.entries ?? 0} entr${(val.entries ?? 0) !== 1 ? "ies" : "y"}`;
        default:
            return `${key}: ${val.entries ?? 0}`;
    }
}

export function ProjectCard({ name, data }: Props) {
    const [expanded, setExpanded] = useState(false);

    const external = Array.isArray(data.external) ? {} : (data.external as Record<string, ExternalIntg>);
    const activityPct = Math.round(data.activity_ratio * 100);

    return (
        <Pressable style={styles.card} onPress={() => setExpanded((v) => !v)}>
            <View style={styles.header}>
                <View style={styles.nameRow}>
                    <Text style={styles.name}>{name}</Text>
                    {data.grouping ? <Text style={styles.grouping}>{data.grouping}</Text> : null}
                </View>
                <Text style={styles.duration}>{fmtDur(data.seconds)}</Text>
            </View>

            {/* Activity bar */}
            <View style={styles.barBg}>
                <View style={[styles.barFg, { width: `${activityPct}%` }]} />
            </View>
            <Text style={styles.activityLabel}>{activityPct}% active</Text>

            {/* Integration badges */}
            {Object.keys(external).length > 0 && (
                <View style={styles.badges}>
                    {Object.entries(external).map(([key, val]) => (
                        <Text key={key} style={styles.badge}>
                            {badgeLabel(key, val)}
                        </Text>
                    ))}
                </View>
            )}

            {/* Expanded detail */}
            {expanded && (
                <View style={styles.detail}>
                    {data.commits.length > 0 && (
                        <View style={styles.commits}>
                            <Text style={styles.detailLabel}>Commits</Text>
                            {data.commits.map((c) => (
                                <View key={c.sha} style={styles.commit}>
                                    <Text style={styles.commitSha}>{c.sha.slice(0, 7)}</Text>
                                    <Text style={styles.commitSubj} numberOfLines={2}>
                                        {c.subj}
                                    </Text>
                                </View>
                            ))}
                        </View>
                    )}

                    {Object.entries(data.detail).map(([sigType, items]) => (
                        <View key={sigType} style={styles.sigGroup}>
                            <Text style={styles.detailLabel}>{sigType}</Text>
                            {Object.entries(items)
                                .sort(([, a], [, b]) => b - a)
                                .slice(0, 8)
                                .map(([id, secs]) => (
                                    <View key={id} style={styles.sigRow}>
                                        <Text style={styles.sigId} numberOfLines={1}>
                                            {id}
                                        </Text>
                                        <Text style={styles.sigDur}>{fmtDur(secs)}</Text>
                                    </View>
                                ))}
                        </View>
                    ))}
                </View>
            )}
        </Pressable>
    );
}

const styles = StyleSheet.create({
    card: {
        marginHorizontal: 12,
        marginVertical: 5,
        padding: 12,
        borderWidth: 1,
        borderColor: "#e0e0e0",
        borderRadius: 8,
        backgroundColor: "#fff",
    },
    header: {
        flexDirection: "row",
        alignItems: "flex-start",
        justifyContent: "space-between",
        marginBottom: 6,
    },
    nameRow: {
        flex: 1,
        flexDirection: "row",
        alignItems: "center",
        gap: 6,
        flexWrap: "wrap",
    },
    name: {
        fontSize: 14,
        fontWeight: "600",
        color: "#111",
    },
    grouping: {
        fontSize: 11,
        color: "#555",
        backgroundColor: "#eee",
        paddingHorizontal: 5,
        paddingVertical: 1,
        borderRadius: 3,
    },
    duration: {
        fontSize: 14,
        fontWeight: "600",
        color: Brand.terracotta,
        marginLeft: 8,
        flexShrink: 0,
    },
    barBg: {
        height: 4,
        backgroundColor: "#f0f0f0",
        borderRadius: 2,
        marginBottom: 2,
    },
    barFg: {
        height: 4,
        backgroundColor: "#4CAF50",
        borderRadius: 2,
    },
    activityLabel: {
        fontSize: 10,
        color: "#888",
        marginBottom: 4,
    },
    badges: {
        flexDirection: "row",
        flexWrap: "wrap",
        gap: 4,
        marginTop: 4,
    },
    badge: {
        fontSize: 10,
        color: "#555",
        backgroundColor: "#f0f0f0",
        paddingHorizontal: 5,
        paddingVertical: 2,
        borderRadius: 3,
    },
    detail: {
        marginTop: 8,
        borderTopWidth: StyleSheet.hairlineWidth,
        borderTopColor: "#e0e0e0",
        paddingTop: 8,
        gap: 8,
    },
    commits: { gap: 3 },
    commit: {
        flexDirection: "row",
        gap: 6,
        alignItems: "flex-start",
    },
    commitSha: {
        fontSize: 11,
        fontFamily: "Menlo",
        color: "#888",
        width: 52,
        flexShrink: 0,
    },
    commitSubj: {
        flex: 1,
        fontSize: 11,
        color: "#333",
    },
    sigGroup: { gap: 2 },
    sigRow: {
        flexDirection: "row",
        justifyContent: "space-between",
        alignItems: "center",
    },
    sigId: {
        flex: 1,
        fontSize: 11,
        color: "#555",
    },
    sigDur: {
        fontSize: 11,
        color: "#888",
        marginLeft: 8,
    },
    detailLabel: {
        fontSize: 10,
        fontWeight: "600",
        color: "#888",
        textTransform: "uppercase",
        letterSpacing: 0.5,
        marginBottom: 2,
    },
});
