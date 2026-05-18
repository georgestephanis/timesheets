import React, { useState } from "react";
import { View, Text, TextInput, Switch, Pressable, StyleSheet } from "react-native";

export type FieldType = "text" | "number" | "checkbox" | "textarea" | "password" | "segment" | "url";

type Props = {
    label: string;
    value: unknown;
    onChange: (value: unknown) => void;
    type?: FieldType;
    options?: string[];
    placeholder?: string;
    hint?: string;
};

export function FieldRow({ label, value, onChange, type = "text", options, placeholder, hint }: Props) {
    const [showPassword, setShowPassword] = useState(false);

    const renderControl = () => {
        switch (type) {
            case "checkbox":
                return <Switch value={Boolean(value)} onValueChange={(v) => onChange(v)} />;

            case "textarea":
                return (
                    <TextInput
                        style={[styles.input, styles.textarea]}
                        value={String(value ?? "")}
                        onChangeText={(t) => onChange(t)}
                        multiline
                        numberOfLines={4}
                        placeholder={placeholder}
                        placeholderTextColor="#aaa"
                    />
                );

            case "password":
                return (
                    <View style={styles.inputRow}>
                        <TextInput
                            style={[styles.input, styles.flex1]}
                            value={String(value ?? "")}
                            onChangeText={(t) => onChange(t)}
                            secureTextEntry={!showPassword}
                            placeholder={placeholder}
                            placeholderTextColor="#aaa"
                        />
                        <Pressable onPress={() => setShowPassword((v) => !v)} style={styles.showBtn}>
                            <Text style={styles.showBtnText}>{showPassword ? "Hide" : "Show"}</Text>
                        </Pressable>
                    </View>
                );

            case "segment":
                return (
                    <View style={styles.segmentRow}>
                        {(options ?? []).map((opt) => (
                            <Pressable
                                key={opt}
                                onPress={() => onChange(opt)}
                                style={[styles.segBtn, value === opt && styles.segBtnActive]}
                            >
                                <Text style={[styles.segBtnText, value === opt && styles.segBtnTextActive]}>{opt}</Text>
                            </Pressable>
                        ))}
                    </View>
                );

            case "number":
                return (
                    <TextInput
                        style={styles.input}
                        value={value == null ? "" : String(value)}
                        onChangeText={(t) => {
                            if (t === "") {
                                onChange(undefined);
                                return;
                            }
                            const n = Number(t);
                            if (!isNaN(n)) onChange(n);
                        }}
                        keyboardType="numeric"
                        placeholder={placeholder}
                        placeholderTextColor="#aaa"
                    />
                );

            default:
                return (
                    <TextInput
                        style={styles.input}
                        value={String(value ?? "")}
                        onChangeText={(t) => onChange(t)}
                        placeholder={placeholder}
                        placeholderTextColor="#aaa"
                    />
                );
        }
    };

    const isCheckbox = type === "checkbox";

    return (
        <View style={[styles.row, isCheckbox && styles.rowCheckbox]}>
            {isCheckbox ? (
                <>
                    {renderControl()}
                    <View style={styles.checkboxLabelWrap}>
                        <Text style={styles.label}>{label}</Text>
                        {hint ? <Text style={styles.hint}>{hint}</Text> : null}
                    </View>
                </>
            ) : (
                <>
                    <Text style={styles.label}>{label}</Text>
                    <View style={styles.controlWrap}>
                        {renderControl()}
                        {hint ? <Text style={styles.hint}>{hint}</Text> : null}
                    </View>
                </>
            )}
        </View>
    );
}

const styles = StyleSheet.create({
    row: {
        flexDirection: "row",
        alignItems: "flex-start",
        paddingHorizontal: 16,
        paddingVertical: 8,
        borderBottomWidth: StyleSheet.hairlineWidth,
        borderBottomColor: "#e8e8e8",
        minHeight: 44,
    },
    rowCheckbox: {
        alignItems: "center",
    },
    label: {
        width: 200,
        fontSize: 13,
        color: "#333",
        paddingTop: 6,
        flexShrink: 0,
    },
    controlWrap: {
        flex: 1,
    },
    checkboxLabelWrap: {
        flex: 1,
        paddingLeft: 8,
    },
    input: {
        fontSize: 13,
        color: "#111",
        borderWidth: 1,
        borderColor: "#ccc",
        borderRadius: 4,
        paddingHorizontal: 8,
        paddingVertical: 4,
        backgroundColor: "#fff",
        minHeight: 28,
    },
    textarea: {
        minHeight: 80,
        textAlignVertical: "top",
    },
    inputRow: {
        flexDirection: "row",
        alignItems: "center",
    },
    flex1: {
        flex: 1,
    },
    showBtn: {
        marginLeft: 8,
        paddingHorizontal: 8,
        paddingVertical: 4,
        borderWidth: 1,
        borderColor: "#ccc",
        borderRadius: 4,
    },
    showBtnText: {
        fontSize: 12,
        color: "#555",
    },
    segmentRow: {
        flexDirection: "row",
        borderWidth: 1,
        borderColor: "#ccc",
        borderRadius: 4,
        overflow: "hidden",
    },
    segBtn: {
        paddingHorizontal: 12,
        paddingVertical: 5,
        backgroundColor: "#f5f5f5",
        borderRightWidth: StyleSheet.hairlineWidth,
        borderRightColor: "#ccc",
    },
    segBtnActive: {
        backgroundColor: "#007AFF",
    },
    segBtnText: {
        fontSize: 12,
        color: "#333",
    },
    segBtnTextActive: {
        color: "#fff",
        fontWeight: "600",
    },
    hint: {
        fontSize: 11,
        color: "#888",
        marginTop: 2,
    },
});
