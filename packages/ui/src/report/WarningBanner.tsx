import React from "react";
import { View, Text, StyleSheet } from "react-native";

type Props = { warnings: string[] };

export function WarningBanner({ warnings }: Props) {
    if (!warnings.length) return null;
    return (
        <View style={styles.container}>
            {warnings.map((w, i) => (
                <Text key={i} style={styles.text} selectable>
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
        backgroundColor: "#FEF9EE",
        borderWidth: 1,
        borderColor: "#F6B84A",
        borderRadius: 6,
    },
    text: {
        fontSize: 12,
        color: "#7a4800",
        lineHeight: 18,
    },
});
