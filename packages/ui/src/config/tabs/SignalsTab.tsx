import React from "react";
import { View, Text, StyleSheet } from "react-native";

export function SignalsTab() {
    return (
        <View style={styles.container}>
            <Text style={styles.title}>Unmatched Signals</Text>
            <Text style={styles.body}>
                Signal reassignment will be available once the report engine (Phase 3) is connected.
            </Text>
        </View>
    );
}

const styles = StyleSheet.create({
    container: {
        flex: 1,
        padding: 24,
        alignItems: "center",
        justifyContent: "center",
    },
    title: {
        fontSize: 16,
        fontWeight: "600",
        color: "#333",
        marginBottom: 8,
    },
    body: {
        fontSize: 13,
        color: "#888",
        textAlign: "center",
        maxWidth: 320,
    },
});
