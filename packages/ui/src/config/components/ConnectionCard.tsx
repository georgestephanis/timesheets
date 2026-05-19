import React from "react";
import { View, Text, Pressable, StyleSheet } from "react-native";

type Props = {
    title: string;
    children: React.ReactNode;
    onRemove: () => void;
};

export function ConnectionCard({ title, children, onRemove }: Props) {
    return (
        <View style={styles.card}>
            <View style={styles.header}>
                <Text style={styles.title}>{title}</Text>
                <Pressable onPress={onRemove} style={styles.removeBtn}>
                    <Text style={styles.removeBtnText}>Remove</Text>
                </Pressable>
            </View>
            <View style={styles.body}>{children}</View>
        </View>
    );
}

const styles = StyleSheet.create({
    card: {
        marginHorizontal: 16,
        marginVertical: 8,
        borderWidth: 1,
        borderColor: "#ddd",
        borderRadius: 6,
        overflow: "hidden",
        backgroundColor: "#fafafa",
    },
    header: {
        flexDirection: "row",
        alignItems: "center",
        justifyContent: "space-between",
        paddingHorizontal: 12,
        paddingVertical: 8,
        backgroundColor: "#f0f0f0",
        borderBottomWidth: StyleSheet.hairlineWidth,
        borderBottomColor: "#ddd",
    },
    title: {
        fontSize: 13,
        fontWeight: "600",
        color: "#333",
    },
    removeBtn: {
        paddingHorizontal: 8,
        paddingVertical: 3,
        borderWidth: 1,
        borderColor: "#c00",
        borderRadius: 3,
    },
    removeBtnText: {
        fontSize: 11,
        color: "#c00",
    },
    body: {
        paddingBottom: 4,
    },
});
