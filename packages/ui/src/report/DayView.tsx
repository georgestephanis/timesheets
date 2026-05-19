import React from "react";
import { ScrollView, View, Text, StyleSheet } from "react-native";
import { ProjectCard } from "./ProjectCard";
import { TimelineView } from "./TimelineView";
import type { Report } from "./EngineClient";

type Props = {
    date: string;
    report: Report;
};

function fmtDur(seconds: number): string {
    const h = Math.floor(seconds / 3600);
    const m = Math.floor((seconds % 3600) / 60);
    return `${h}h ${m}m`;
}

export function DayView({ date, report }: Props) {
    const projects = Object.entries(report.days[date] ?? {}).sort(([, a], [, b]) => b.seconds - a.seconds);

    const segments = report.timelines?.[date] ?? [];
    const totalSeconds = projects.reduce((sum, [, p]) => sum + p.seconds, 0);

    if (projects.length === 0) {
        return (
            <View style={styles.empty}>
                <Text style={styles.emptyText}>No tracked time for {date}</Text>
            </View>
        );
    }

    return (
        <ScrollView style={styles.container}>
            {segments.length > 0 && <TimelineView segments={segments} />}

            <View style={styles.summary}>
                <Text style={styles.summaryText}>
                    {projects.length} project{projects.length !== 1 ? "s" : ""} · {fmtDur(totalSeconds)} total
                </Text>
            </View>

            {report.summaries?.[date] && (
                <View style={styles.aiSummary}>
                    <Text style={styles.aiSummaryText}>{report.summaries[date]}</Text>
                </View>
            )}

            {projects.map(([name, data]) => (
                <ProjectCard key={name} name={name} data={data} />
            ))}
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    container: { flex: 1 },
    empty: {
        flex: 1,
        alignItems: "center",
        justifyContent: "center",
        padding: 32,
    },
    emptyText: {
        fontSize: 14,
        color: "#888",
    },
    summary: {
        paddingHorizontal: 16,
        paddingTop: 8,
        paddingBottom: 4,
    },
    summaryText: {
        fontSize: 12,
        color: "#888",
    },
    aiSummary: {
        marginHorizontal: 12,
        marginBottom: 8,
        padding: 10,
        backgroundColor: "#f0f8ff",
        borderRadius: 6,
        borderLeftWidth: 3,
        borderLeftColor: "#007AFF",
    },
    aiSummaryText: {
        fontSize: 12,
        color: "#333",
        lineHeight: 18,
    },
});
