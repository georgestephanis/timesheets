// React Native UI components for timesheets application
// Based on the existing web UI structure

import React, { useState, useEffect } from "react";
import { View, Text, StyleSheet, ScrollView, FlatList, Pressable } from "react-native";

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
        <ScrollView style={styles.container}>
            <ReportHeader from={reportData.from} to={reportData.to} timezone={reportData.tz} />
            <ReportContent data={reportData} />
        </ScrollView>
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
const ReportContent = ({ data }) => {
    const dates = Object.keys(data.days).sort();
    const [dayIndex, setDayIndex] = useState(dates.length - 1);

    const currentDate = dates[dayIndex];
    const projects = Object.entries(data.days[currentDate] || {});

    return (
        <View style={styles.content}>
            <View style={styles.dayNav}>
                <Pressable
                    onPress={() => setDayIndex((i) => Math.max(0, i - 1))}
                    disabled={dayIndex === 0}
                    style={[styles.navButton, dayIndex === 0 && styles.navButtonDisabled]}
                >
                    <Text style={styles.navButtonText}>← Prev</Text>
                </Pressable>
                <Text style={styles.dayLabel}>{currentDate}</Text>
                <Pressable
                    onPress={() => setDayIndex((i) => Math.min(dates.length - 1, i + 1))}
                    disabled={dayIndex === dates.length - 1}
                    style={[styles.navButton, dayIndex === dates.length - 1 && styles.navButtonDisabled]}
                >
                    <Text style={styles.navButtonText}>Next →</Text>
                </Pressable>
            </View>
            <FlatList
                data={projects}
                keyExtractor={([key]) => key}
                renderItem={({ item }) => {
                    const [projectName, projectData] = item;
                    return <ProjectCard projectName={projectName} projectData={projectData} />;
                }}
            />
        </View>
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
        padding: 20,
    },
    dayNav: {
        flexDirection: "row",
        justifyContent: "space-between",
        alignItems: "center",
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
    projectCard: {
        padding: 15,
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
