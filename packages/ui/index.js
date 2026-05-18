// React Native UI components for timesheets application
// Based on the existing web UI structure

import React, { useState, useEffect } from "react";
import { View, Text, StyleSheet, ScrollView, FlatList } from "react-native";

// Mock data for demonstration
const mockReportData = {
    from: "2026-05-08T00:00:00-04:00",
    to: "2026-05-08T23:59:59-04:00",
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
                commits: [{ time: "2026-05-08T09:14:00-04:00", sha: "a1b2c3d4...", subj: "...", repo: "~/..." }],
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
        // Simulate data fetching
        const fetchData = async () => {
            try {
                // In a real implementation, this would call the engine
                // const data = await engine.generateReport(dateRange);
                setTimeout(() => {
                    setReportData(mockReportData);
                    setLoading(false);
                }, 500);
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
 * Main report content component
 */
const ReportContent = ({ data }) => {
    const [selectedProject, setSelectedProject] = useState(null);

    const projects = Object.entries(data.days[Object.keys(data.days)[0]] || {});

    return (
        <View style={styles.content}>
            <FlatList
                data={projects}
                keyExtractor={([key]) => key}
                renderItem={({ item }) => {
                    const [projectName, projectData] = item;
                    return (
                        <ProjectCard
                            projectName={projectName}
                            projectData={projectData}
                            onPress={() => setSelectedProject(projectData)}
                        />
                    );
                }}
            />
        </View>
    );
};

/**
 * Project card component
 */
const ProjectCard = ({ projectName, projectData, onPress }) => (
    <View style={styles.projectCard} onPress={onPress}>
        <Text style={styles.projectTitle}>{projectName}</Text>
        <Text style={styles.projectDetails}>
            Duration: {Math.floor(projectData.seconds / 3600)}h {Math.floor((projectData.seconds % 3600) / 60)}m
        </Text>
        <Text style={styles.projectDetails}>Activity Ratio: {(projectData.activity_ratio * 100).toFixed(1)}%</Text>
    </View>
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
