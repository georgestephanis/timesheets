import { useCallback } from "react";

function getNestedValue(obj: unknown, path: string | string[]): unknown {
    const segments = Array.isArray(path) ? path : path.split(".");
    return segments.reduce((curr: unknown, key) => {
        if (curr == null || typeof curr !== "object") return undefined;
        return (curr as Record<string, unknown>)[key];
    }, obj);
}

export type FieldPathOptions = {
    arrayAsTextarea?: boolean;
};

export function useFieldPath(
    draft: object | null,
    path: string | string[],
    setField: (path: string | string[], value: unknown) => void,
    opts: FieldPathOptions = {},
) {
    const rawValue = draft ? getNestedValue(draft, path) : undefined;

    let value: unknown;
    if (opts.arrayAsTextarea && Array.isArray(rawValue)) {
        value = rawValue.join("\n");
    } else {
        value = rawValue ?? "";
    }

    const onChange = useCallback(
        (newValue: unknown) => {
            let coerced: unknown = newValue;
            if (opts.arrayAsTextarea && typeof newValue === "string") {
                coerced = newValue
                    .split("\n")
                    .map((s) => s.trim())
                    .filter(Boolean);
            }
            setField(path, coerced);
        },
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [path, setField, opts.arrayAsTextarea],
    );

    return { value, onChange };
}
