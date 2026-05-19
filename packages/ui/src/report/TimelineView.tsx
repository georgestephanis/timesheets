import React from "react";
import { View, Text, StyleSheet } from "react-native";
import type { TimelineSegment } from "./EngineClient";

type Props = {
    segments: TimelineSegment[];
    groupingColors?: Record<string, string>;
};

function segColor(seg: TimelineSegment, groupingColors?: Record<string, string>): string {
    if (seg.g && groupingColors?.[seg.g]) return groupingColors[seg.g];
    return "#007AFF";
}

export function TimelineView({ segments, groupingColors }: Props) {
    if (!segments.length) return null;

    // Determine visible range: clamp to 7AM–9PM (25200s–75600s)
    const WINDOW_START = 25200; // 7:00
    const WINDOW_END = 75600; // 21:00
    const WINDOW = WINDOW_END - WINDOW_START;

    const clampedSegs = segments
        .map((s) => ({ ...s, s: Math.max(s.s, WINDOW_START), e: Math.min(s.e, WINDOW_END) }))
        .filter((s) => s.e > s.s);

    function pct(seconds: number) {
        return ((seconds - WINDOW_START) / WINDOW) * 100;
    }

    function fmt(seconds: number) {
        const h = Math.floor(seconds / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        return `${h}:${String(m).padStart(2, "0")}`;
    }

    const ticks = [7, 9, 11, 13, 15, 17, 19, 21].map((h) => h * 3600);

    return (
        <View style={styles.container}>
            <View style={styles.bar}>
                {clampedSegs.map((seg, i) => (
                    <View
                        key={i}
                        style={[
                            styles.segment,
                            {
                                left: `${pct(seg.s)}%`,
                                width: `${pct(seg.e) - pct(seg.s)}%`,
                                backgroundColor: segColor(seg, groupingColors),
                            },
                        ]}
                    />
                ))}
            </View>
            <View style={styles.axis}>
                {ticks.map((t) => (
                    <Text key={t} style={[styles.tick, { left: `${pct(t)}%` }]}>
                        {fmt(t)}
                    </Text>
                ))}
            </View>
        </View>
    );
}

const styles = StyleSheet.create({
    container: {
        marginHorizontal: 12,
        marginTop: 8,
        marginBottom: 4,
    },
    bar: {
        height: 20,
        backgroundColor: "#f0f0f0",
        borderRadius: 3,
        position: "relative",
        overflow: "hidden",
    },
    segment: {
        position: "absolute",
        top: 0,
        bottom: 0,
        opacity: 0.85,
    },
    axis: {
        height: 16,
        position: "relative",
        marginTop: 2,
    },
    tick: {
        position: "absolute",
        fontSize: 9,
        color: "#999",
        transform: [{ translateX: -12 }],
    },
});
