import React from "react";
import { ScrollView, View, Text, TextInput, Pressable, Alert, StyleSheet } from "react-native";
import { SectionHeader } from "../components/SectionHeader";
import { FieldRow } from "../components/FieldRow";
import { useFieldPath } from "../useFieldPath";
import type { Config } from "../configSchema";

type Props = {
    draft: Config;
    setField: (path: string | string[], value: unknown) => void;
};

type GroupingCardProps = {
    name: string;
    draft: Config;
    setField: (path: string | string[], value: unknown) => void;
    onRemove: () => void;
};

function GroupingCard({ name, draft, setField, onRemove }: GroupingCardProps) {
    const b = `groupings.${name}`;

    const color = useFieldPath(draft, `${b}.color`, setField);
    const logo = useFieldPath(draft, `${b}.logo`, setField);
    const aliases = useFieldPath(draft, `${b}.aliases`, setField, { arrayAsTextarea: true });
    const timeTracking = useFieldPath(draft, `${b}.time_tracking`, setField);
    const harvestConn = useFieldPath(draft, `${b}.harvest_connection`, setField);

    const harvestConnectionNames = (draft.integrations?.harvest ?? []).map((c) => c.name);

    const handleRemove = () => {
        Alert.alert("Remove Grouping", `Remove "${name}"? This cannot be undone until you discard.`, [
            { text: "Cancel", style: "cancel" },
            { text: "Remove", style: "destructive", onPress: onRemove },
        ]);
    };

    return (
        <View style={styles.card}>
            <View style={styles.cardHeader}>
                <Text style={styles.cardTitle}>{name}</Text>
                <Pressable onPress={handleRemove} style={styles.removeBtn}>
                    <Text style={styles.removeBtnText}>Remove</Text>
                </Pressable>
            </View>
            <FieldRow label="Color" value={color.value} onChange={color.onChange} type="color" />
            <FieldRow label="Logo URL" value={logo.value} onChange={logo.onChange} type="url" />
            <FieldRow
                label="Aliases"
                value={aliases.value}
                onChange={aliases.onChange}
                type="textarea"
                hint="Alternate names for this grouping (one per line)"
            />
            <FieldRow
                label="Time tracking"
                value={timeTracking.value || "none"}
                onChange={timeTracking.onChange}
                type="segment"
                options={["none", "harvest", "clickup", "clockify"]}
            />
            {timeTracking.value === "harvest" && (
                <FieldRow
                    label="Harvest connection"
                    value={harvestConn.value}
                    onChange={harvestConn.onChange}
                    type="select"
                    options={harvestConnectionNames}
                    placeholder="Select connection…"
                />
            )}
        </View>
    );
}

export function GroupingsTab({ draft, setField }: Props) {
    const [addingName, setAddingNameState] = React.useState("");
    const [showAdd, setShowAdd] = React.useState(false);

    const groupings = draft.groupings ?? {};
    const names = Object.keys(groupings);

    const handleAdd = () => {
        const name = addingName.trim();
        if (!name) return;
        if (groupings[name] !== undefined) {
            Alert.alert("Name taken", `A grouping named "${name}" already exists.`);
            return;
        }
        setField("groupings", { ...groupings, [name]: {} });
        setAddingNameState("");
        setShowAdd(false);
    };

    const handleRemove = (name: string) => {
        const { [name]: _removed, ...rest } = groupings;
        setField("groupings", rest);
    };

    return (
        <ScrollView>
            {names.length === 0 && <Text style={styles.empty}>No groupings. Add one below.</Text>}
            {names.map((name) => (
                <GroupingCard
                    key={name}
                    name={name}
                    draft={draft}
                    setField={setField}
                    onRemove={() => handleRemove(name)}
                />
            ))}

            <View style={styles.addSection}>
                {showAdd ? (
                    <View style={styles.addRow}>
                        <TextInput
                            style={styles.addInput}
                            value={addingName}
                            onChangeText={setAddingNameState}
                            placeholder="Grouping name"
                            placeholderTextColor="#aaa"
                            autoFocus
                            onSubmitEditing={handleAdd}
                        />
                        <Pressable onPress={handleAdd} style={styles.addConfirmBtn}>
                            <Text style={styles.addConfirmBtnText}>Add</Text>
                        </Pressable>
                        <Pressable
                            onPress={() => {
                                setShowAdd(false);
                                setAddingNameState("");
                            }}
                            style={styles.cancelBtn}
                        >
                            <Text style={styles.cancelBtnText}>Cancel</Text>
                        </Pressable>
                    </View>
                ) : (
                    <Pressable onPress={() => setShowAdd(true)} style={styles.addBtn}>
                        <Text style={styles.addBtnText}>+ Add Grouping</Text>
                    </Pressable>
                )}
            </View>
        </ScrollView>
    );
}

const styles = StyleSheet.create({
    empty: {
        padding: 20,
        color: "#888",
        textAlign: "center",
    },
    card: {
        marginHorizontal: 16,
        marginTop: 16,
        borderWidth: 1,
        borderColor: "#ddd",
        borderRadius: 6,
        overflow: "hidden",
        backgroundColor: "#fafafa",
    },
    cardHeader: {
        flexDirection: "row",
        alignItems: "center",
        justifyContent: "space-between",
        paddingHorizontal: 12,
        paddingVertical: 8,
        backgroundColor: "#f0f0f0",
        borderBottomWidth: StyleSheet.hairlineWidth,
        borderBottomColor: "#ddd",
    },
    cardTitle: {
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
    addSection: {
        padding: 16,
    },
    addBtn: {
        paddingVertical: 8,
        paddingHorizontal: 12,
        borderWidth: 1,
        borderColor: "#007AFF",
        borderRadius: 4,
        alignSelf: "flex-start",
    },
    addBtnText: {
        fontSize: 13,
        color: "#007AFF",
    },
    addRow: {
        flexDirection: "row",
        alignItems: "center",
        gap: 8,
    },
    addInput: {
        flex: 1,
        fontSize: 13,
        borderWidth: 1,
        borderColor: "#ccc",
        borderRadius: 4,
        paddingHorizontal: 8,
        paddingVertical: 4,
        backgroundColor: "#fff",
        color: "#111",
    },
    addConfirmBtn: {
        paddingHorizontal: 12,
        paddingVertical: 5,
        backgroundColor: "#007AFF",
        borderRadius: 4,
    },
    addConfirmBtnText: {
        fontSize: 12,
        color: "#fff",
        fontWeight: "600",
    },
    cancelBtn: {
        paddingHorizontal: 8,
        paddingVertical: 5,
    },
    cancelBtnText: {
        fontSize: 12,
        color: "#888",
    },
});
