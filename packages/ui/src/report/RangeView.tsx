import React from "react";
import { ScrollView, View, Text, StyleSheet } from "react-native";
import type { Report } from "./EngineClient";
import { Brand } from "../brand";

type Props = {
    report: Report;
    projectFilter?: string;
};

function fmtDur(seconds: number): string {
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    return `${h}h ${m}m`;
}

function fmtDayHeader(dateStr: string): string {
    const d = new Date(dateStr + "T12:00:00");
    return d.toLocaleDateString("en-US", { weekday: "short", month: "short", day: "numeric" });
}

export function RangeView({ report, projectFilter }: Props) {
    const days = Object.keys(report.days).sort();

    // Aggregate seconds per project across all days in the range.
    const totals: Record<string, number> = {};
    for (const day of days) {
        for (const [proj, data] of Object.entries(report.days[day] ?? {})) {
            if (projectFilter && proj !== projectFilter) continue;
            totals[proj] = (totals[proj] ?? 0) + data.seconds;
        }
    }

    const sortedProjects = Object.entries(totals).sort(([, a], [, b]) => b - a);
    const grandTotal = sortedProjects.reduce((s, [, v]) => s + v, 0);

    if (sortedProjects.length === 0) {
        return (
            <View style={styles.empty}>
                <Text style={styles.emptyText}>No tracked time for this period</Text>
            </View>
        );
    }

    return (
        <ScrollView style={styles.container}>
            {/* ── Weekly totals ── */}
            <View style={styles.totalsSection}>
                <View style={styles.totalsHeader}>
                    <Text style={styles.totalLabel}>
                        {sortedProjects.length} project{sortedProjects.length !== 1 ? "s" : ""} · {fmtDur(grandTotal)}{" "}
                        total
                    </Text>
                </View>
                {sortedProjects.map(([name, secs]) => (
                    <View key={name} style={styles.totalRow}>
                        <Text style={styles.totalProject} numberOfLines={1}>
                            {name}
                        </Text>
                        <Text style={styles.totalHours}>{fmtDur(secs)}</Text>
                    </View>
                ))}
            </View>

            {/* ── Day-by-day breakdown ── */}
            {days.map((day) => {
                const dayProjects = Object.entries(report.days[day] ?? {})
                    .filter(([p]) => !projectFilter || p === projectFilter)
                    .sort(([, a], [, b]) => b.seconds - a.seconds);
                const dayTotal = dayProjects.reduce((s, [, p]) => s + p.seconds, 0);
                if (dayProjects.length === 0) return null;

                return (
                    <View key={day} style={styles.daySection}>
                        <View style={styles.dayHeader}>
                            <Text style={styles.dayDate}>{fmtDayHeader(day)}</Text>
                            <Text style={styles.dayTotal}>{fmtDur(dayTotal)}</Text>
                        </View>
                        {dayProjects.map(([name, data]) => (
                            <View key={name} style={styles.dayRow}>
                                <Text style={styles.dayProject} numberOfLines={1}>
                                    {name}
                                </Text>
                                <Text style={styles.dayHours}>{fmtDur(data.seconds)}</Text>
                            </View>
                        ))}
                    </View>
                );
            })}
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    container: { flex: 1, backgroundColor: Brand.paper },
    empty: { flex: 1, alignItems: "center", justifyContent: "center", padding: 32 },
    emptyText: { fontSize: 14, color: "#888" },

    totalsSection: {
        margin: 12,
        borderWidth: 1,
        borderColor: "#e0d8ce",
        borderRadius: 8,
        backgroundColor: "#fff",
        overflow: "hidden",
    },
    totalsHeader: {
        paddingHorizontal: 12,
        paddingVertical: 8,
        borderBottomWidth: StyleSheet.hairlineWidth,
        borderBottomColor: "#e8e0d6",
        backgroundColor: "#FAF7F3",
    },
    totalLabel: { fontSize: 11, color: "#888", fontWeight: "600", textTransform: "uppercase", letterSpacing: 0.4 },
    totalRow: {
        flexDirection: "row",
        alignItems: "center",
        paddingHorizontal: 12,
        paddingVertical: 7,
        borderBottomWidth: StyleSheet.hairlineWidth,
        borderBottomColor: "#f0eae2",
    },
    totalProject: { flex: 1, fontSize: 13, color: "#222", fontWeight: "500" },
    totalHours: { fontSize: 13, color: Brand.terracotta, fontWeight: "600", marginLeft: 8 },

    daySection: {
        marginHorizontal: 12,
        marginBottom: 8,
        borderWidth: 1,
        borderColor: "#e4dcd4",
        borderRadius: 6,
        backgroundColor: "#fff",
        overflow: "hidden",
    },
    dayHeader: {
        flexDirection: "row",
        alignItems: "center",
        paddingHorizontal: 12,
        paddingVertical: 6,
        backgroundColor: Brand.ink,
    },
    dayDate: { flex: 1, fontSize: 12, color: Brand.paper, fontWeight: "600" },
    dayTotal: { fontSize: 12, color: Brand.amber, fontWeight: "500" },
    dayRow: {
        flexDirection: "row",
        alignItems: "center",
        paddingHorizontal: 12,
        paddingVertical: 5,
        borderBottomWidth: StyleSheet.hairlineWidth,
        borderBottomColor: "#f0eae2",
    },
    dayProject: { flex: 1, fontSize: 12, color: "#333" },
    dayHours: { fontSize: 12, color: "#666", marginLeft: 8 },
});
