import React, { useState } from "react";
import { View, Text, TextInput, Switch, Pressable, StyleSheet } from "react-native";

export type FieldType =
    | "text"
    | "number"
    | "checkbox"
    | "textarea"
    | "password"
    | "segment"
    | "url"
    | "color"
    | "select";

const PRESET_COLORS = [
    "#C25E2A",
    "#F6B84A",
    "#16130F",
    "#F6F2EA",
    "#E53E3E",
    "#DD6B20",
    "#D69E2E",
    "#38A169",
    "#3182CE",
    "#805AD5",
    "#D53F8C",
    "#718096",
    "#FC8181",
    "#F6AD55",
    "#FAF089",
    "#9AE6B4",
    "#90CDF4",
    "#D6BCFA",
    "#FED7E2",
    "#BEE3F8",
];

function isValidHex(s: string): boolean {
    return /^#[0-9A-Fa-f]{3}([0-9A-Fa-f]{3})?$/.test(s);
}

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
    const [showPalette, setShowPalette] = useState(false);
    const [showSelect, setShowSelect] = useState(false);

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

            case "select": {
                const opts = options ?? [];
                const displayValue = value != null && value !== "" ? String(value) : null;
                return (
                    <View>
                        <Pressable
                            style={[styles.selectBox, showSelect && styles.selectBoxOpen]}
                            onPress={() => setShowSelect((v) => !v)}
                        >
                            <Text style={[styles.selectValue, !displayValue && styles.selectPlaceholder]}>
                                {displayValue ?? placeholder ?? "Select…"}
                            </Text>
                            <Text style={styles.selectChevron}>{showSelect ? "▴" : "▾"}</Text>
                        </Pressable>
                        {showSelect && (
                            <View style={styles.selectList}>
                                {opts.length === 0 ? (
                                    <Text style={styles.selectEmpty}>No options configured</Text>
                                ) : (
                                    opts.map((opt) => (
                                        <Pressable
                                            key={opt}
                                            style={[styles.selectOption, value === opt && styles.selectOptionActive]}
                                            onPress={() => {
                                                onChange(opt);
                                                setShowSelect(false);
                                            }}
                                        >
                                            <Text
                                                style={[
                                                    styles.selectOptionText,
                                                    value === opt && styles.selectOptionTextActive,
                                                ]}
                                            >
                                                {opt}
                                            </Text>
                                        </Pressable>
                                    ))
                                )}
                            </View>
                        )}
                    </View>
                );
            }

            case "color": {
                const hexStr = String(value ?? "");
                const swatchColor = isValidHex(hexStr) ? hexStr : "#cccccc";
                return (
                    <View>
                        <View style={styles.colorRow}>
                            <Pressable
                                onPress={() => setShowPalette((v) => !v)}
                                style={[styles.colorSwatch, { backgroundColor: swatchColor }]}
                            />
                            <TextInput
                                style={[styles.input, styles.colorInput]}
                                value={hexStr}
                                onChangeText={(t) => onChange(t)}
                                placeholder="#rrggbb"
                                placeholderTextColor="#aaa"
                                autoCorrect={false}
                                autoCapitalize="none"
                            />
                        </View>
                        {showPalette && (
                            <View style={styles.palette}>
                                {PRESET_COLORS.map((c) => (
                                    <Pressable
                                        key={c}
                                        style={[
                                            styles.paletteChip,
                                            { backgroundColor: c },
                                            hexStr.toLowerCase() === c.toLowerCase() && styles.paletteChipSelected,
                                        ]}
                                        onPress={() => {
                                            onChange(c);
                                            setShowPalette(false);
                                        }}
                                    />
                                ))}
                            </View>
                        )}
                    </View>
                );
            }

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
    selectBox: {
        flexDirection: "row",
        alignItems: "center",
        borderWidth: 1,
        borderColor: "#ccc",
        borderRadius: 4,
        paddingHorizontal: 8,
        paddingVertical: 5,
        backgroundColor: "#fff",
        minHeight: 28,
    },
    selectBoxOpen: {
        borderColor: "#888",
    },
    selectValue: {
        flex: 1,
        fontSize: 13,
        color: "#111",
    },
    selectPlaceholder: {
        color: "#aaa",
    },
    selectChevron: {
        fontSize: 10,
        color: "#888",
        marginLeft: 4,
    },
    selectList: {
        marginTop: 2,
        borderWidth: 1,
        borderColor: "#ccc",
        borderRadius: 4,
        backgroundColor: "#fff",
        overflow: "hidden",
    },
    selectOption: {
        paddingHorizontal: 10,
        paddingVertical: 7,
        borderBottomWidth: StyleSheet.hairlineWidth,
        borderBottomColor: "#eee",
    },
    selectOptionActive: {
        backgroundColor: "#f0f0f0",
    },
    selectOptionText: {
        fontSize: 13,
        color: "#111",
    },
    selectOptionTextActive: {
        fontWeight: "600",
    },
    selectEmpty: {
        paddingHorizontal: 10,
        paddingVertical: 7,
        fontSize: 12,
        color: "#aaa",
        fontStyle: "italic",
    },
    colorRow: {
        flexDirection: "row",
        alignItems: "center",
        gap: 8,
    },
    colorSwatch: {
        width: 28,
        height: 28,
        borderRadius: 4,
        borderWidth: 1,
        borderColor: "#ccc",
        flexShrink: 0,
    },
    colorInput: {
        flex: 1,
        fontFamily: "Menlo",
        fontSize: 12,
    },
    palette: {
        flexDirection: "row",
        flexWrap: "wrap",
        gap: 6,
        marginTop: 8,
        padding: 8,
        backgroundColor: "#f5f5f5",
        borderRadius: 4,
        borderWidth: 1,
        borderColor: "#e0e0e0",
    },
    paletteChip: {
        width: 24,
        height: 24,
        borderRadius: 3,
        borderWidth: 1,
        borderColor: "rgba(0,0,0,0.15)",
    },
    paletteChipSelected: {
        borderWidth: 2,
        borderColor: "#333",
    },
});
