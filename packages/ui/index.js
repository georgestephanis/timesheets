// React Native UI components for timesheets application
// Based on the existing web UI structure

import React, { useState, useEffect } from "react";
import { View, Text, StyleSheet, FlatList, Pressable } from "react-native";

// Mock data for demonstration
const mockReportData = {
    from: "2026-05-08T00:00:00-04:00",
    to: "2026-05-09T23:59:59-04:00",
    tz: "America/New_York",
    days: {
        "2026-05-08": {
            "Project Name": {
                grouping: "Group Label",
                seconds: 3600,
                active_seconds: 2700,
                activity_ratio: 0.75,
                detail: {
                    vscode: { "folder-name": 3600 },
                    browser: { "example.com": 900 },
                },
                external: {
                    harvest: { entries: 1, activity: 1, discussion: 0 },
                    clickup: { entries: 0, activity: 0, discussion: 0 },
                    clockify: { entries: 1, activity: 1, discussion: 1 },
                },
                commits: [
                    {
                        time: "2026-05-08T09:14:00-04:00",
                        sha: "a1b2c3d4...",
                        subj: "...",
                        repo: "~/...",
                    },
                ],
            },
        },
        "2026-05-09": {
            "Another Project": {
                grouping: "Group Label",
                seconds: 1800,
                active_seconds: 1500,
                activity_ratio: 0.83,
                detail: { vscode: { "another-dir": 1800 } },
                external: {},
                commits: [],
            },
        },
    },
    unmatched: {
        vscode: { "unknown-dir": 5 },
        browser: { "example.com": 3 },
    },
    warnings: ["[Harvest Main] HTTP 401 from api.harvestapp.com: Invalid token"],
    timelines: {
        "2026-05-08": [{ s: 32400, e: 34200, p: "Project Name", g: "Group Label" }],
    },
};

/**
 * Main application component
 */
const TimesheetsApp = () => {
    const [reportData, setReportData] = useState(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState(null);

    useEffect(() => {
        const fetchData = async () => {
            try {
                // In a real implementation, this would call the engine:
                // const data = await engine.generateReport(dateRange);
                setReportData(mockReportData);
                setLoading(false);
            } catch (err) {
                setError(err.message);
                setLoading(false);
            }
        };

        fetchData();
    }, []);

    if (loading) {
        return (
            <View style={styles.container}>
                <Text>Loading...</Text>
            </View>
        );
    }

    if (error) {
        return (
            <View style={styles.container}>
                <Text>Error: {error}</Text>
            </View>
        );
    }

    return (
        <ReportContent
            data={reportData}
            header={<ReportHeader from={reportData.from} to={reportData.to} timezone={reportData.tz} />}
        />
    );
};

/**
 * Report header component
 */
const ReportHeader = ({ from, to, timezone }) => (
    <View style={styles.header}>
        <Text style={styles.title}>Timesheet Report</Text>
        <Text style={styles.dateRange}>
            {from} to {to} ({timezone})
        </Text>
    </View>
);

/**
 * Main report content component — shows one day at a time with prev/next navigation
 */
const ReportContent = ({ data, header }) => {
    const dates = Object.keys(data.days).sort();
    const lastDayIndex = Math.max(0, dates.length - 1);
    const [dayIndex, setDayIndex] = useState(lastDayIndex);

    useEffect(() => {
        setDayIndex(lastDayIndex);
    }, [lastDayIndex, dates.join("|")]);

    const hasDates = dates.length > 0;
    const currentDate = hasDates ? dates[dayIndex] : null;
    const projects = currentDate ? Object.entries(data.days[currentDate] || {}) : [];

    return (
        <FlatList
            style={styles.container}
            contentContainerStyle={styles.content}
            data={projects}
            keyExtractor={([key]) => key}
            renderItem={({ item }) => {
                const [projectName, projectData] = item;
                return <ProjectCard projectName={projectName} projectData={projectData} />;
            }}
            ListHeaderComponent={
                <View>
                    {header}
                    <View style={styles.dayNav}>
                        <Pressable
                            onPress={() => setDayIndex((i) => Math.max(0, i - 1))}
                            disabled={!hasDates || dayIndex === 0}
                            style={[styles.navButton, (!hasDates || dayIndex === 0) && styles.navButtonDisabled]}
                        >
                            <Text style={styles.navButtonText}>← Prev</Text>
                        </Pressable>
                        <Text style={styles.dayLabel}>{currentDate || "No report data"}</Text>
                        <Pressable
                            onPress={() => setDayIndex((i) => Math.min(lastDayIndex, i + 1))}
                            disabled={!hasDates || dayIndex === lastDayIndex}
                            style={[
                                styles.navButton,
                                (!hasDates || dayIndex === lastDayIndex) && styles.navButtonDisabled,
                            ]}
                        >
                            <Text style={styles.navButtonText}>Next →</Text>
                        </Pressable>
                    </View>
                </View>
            }
            ListEmptyComponent={
                <Text style={styles.emptyState}>
                    {currentDate ? "No projects for this date." : "No report data available."}
                </Text>
            }
        />
    );
};

/**
 * Project card component
 */
const ProjectCard = ({ projectName, projectData }) => (
    <Pressable style={styles.projectCard}>
        <Text style={styles.projectTitle}>{projectName}</Text>
        <Text style={styles.projectDetails}>
            Duration: {Math.floor(projectData.seconds / 3600)}h {Math.floor((projectData.seconds % 3600) / 60)}m
        </Text>
        <Text style={styles.projectDetails}>Activity Ratio: {(projectData.activity_ratio * 100).toFixed(1)}%</Text>
    </Pressable>
);

const styles = StyleSheet.create({
    container: {
        flex: 1,
        backgroundColor: "#fff",
    },
    header: {
        padding: 20,
        borderBottomWidth: 1,
        borderBottomColor: "#eee",
    },
    title: {
        fontSize: 24,
        fontWeight: "bold",
    },
    dateRange: {
        fontSize: 16,
        color: "#666",
        marginTop: 5,
    },
    content: {
        paddingBottom: 20,
    },
    dayNav: {
        flexDirection: "row",
        justifyContent: "space-between",
        alignItems: "center",
        paddingHorizontal: 20,
        paddingTop: 20,
        marginBottom: 12,
    },
    dayLabel: {
        fontSize: 16,
        fontWeight: "600",
    },
    navButton: {
        padding: 8,
    },
    navButtonDisabled: {
        opacity: 0.3,
    },
    navButtonText: {
        fontSize: 14,
        color: "#007AFF",
    },
    emptyState: {
        paddingHorizontal: 20,
        color: "#666",
    },
    projectCard: {
        padding: 15,
        marginHorizontal: 20,
        marginVertical: 5,
        borderWidth: 1,
        borderColor: "#ddd",
        borderRadius: 8,
    },
    projectTitle: {
        fontSize: 18,
        fontWeight: "bold",
    },
    projectDetails: {
        fontSize: 14,
        color: "#666",
        marginTop: 5,
    },
});

export { TimesheetsApp };
