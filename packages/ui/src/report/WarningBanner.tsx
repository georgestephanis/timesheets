import React from "react";
import { View, Text, StyleSheet } from "react-native";

type Props = { warnings: string[] };

export function WarningBanner({ warnings }: Props) {
    if (!warnings.length) return null;
    return (
        <View style={styles.container}>
            {warnings.map((w, i) => (
                <Text key={i} style={styles.text}>
                    ⚠ {w}
                </Text>
            ))}
        </View>
    );
}

const styles = StyleSheet.create({
    container: {
        margin: 12,
        padding: 10,
        backgroundColor: "#fff8e1",
        borderWidth: 1,
        borderColor: "#f5c518",
        borderRadius: 6,
    },
    text: {
        fontSize: 12,
        color: "#7a5000",
        lineHeight: 18,
    },
});
