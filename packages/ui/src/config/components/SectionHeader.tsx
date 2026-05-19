import React from "react";
import { View, Text, StyleSheet } from "react-native";

type Props = { title: string };

export function SectionHeader({ title }: Props) {
    return (
        <View style={styles.container}>
            <Text style={styles.text}>{title}</Text>
        </View>
    );
}

const styles = StyleSheet.create({
    container: {
        paddingHorizontal: 16,
        paddingTop: 20,
        paddingBottom: 4,
        borderBottomWidth: 1,
        borderBottomColor: "#e0e0e0",
        marginBottom: 4,
    },
    text: {
        fontSize: 11,
        fontWeight: "600",
        color: "#888",
        textTransform: "uppercase",
        letterSpacing: 0.5,
    },
});
