import { useReducer, useCallback } from "react";
import type { Config } from "./configSchema";

type State = {
    original: Config | null;
    draft: Config | null;
    isDirty: boolean;
};

type Action =
    | { type: "RESET"; config: Config }
    | { type: "SET_FIELD"; path: string; value: unknown }
    | { type: "DISCARD" }
    | { type: "SAVED"; savedConfig: Config };

function setNestedValue(obj: Record<string, unknown>, path: string, value: unknown): Record<string, unknown> {
    const dot = path.indexOf(".");
    if (dot === -1) {
        if (value === undefined) {
            const next = { ...obj };
            delete next[path];
            return next;
        }
        return { ...obj, [path]: value };
    }
    const head = path.slice(0, dot);
    const rest = path.slice(dot + 1);
    const child = (obj[head] as Record<string, unknown>) ?? {};
    return { ...obj, [head]: setNestedValue(child, rest, value) };
}

function reducer(state: State, action: Action): State {
    switch (action.type) {
        case "RESET":
            return { original: action.config, draft: action.config, isDirty: false };
        case "SET_FIELD": {
            if (!state.draft) return state;
            const draft = setNestedValue(
                state.draft as unknown as Record<string, unknown>,
                action.path,
                action.value,
            ) as Config;
            return { ...state, draft, isDirty: true };
        }
        case "DISCARD":
            return { ...state, draft: state.original, isDirty: false };
        case "SAVED":
            return { original: action.savedConfig, draft: action.savedConfig, isDirty: false };
        default:
            return state;
    }
}

export function useConfigDraft() {
    const [state, dispatch] = useReducer(reducer, {
        original: null,
        draft: null,
        isDirty: false,
    });

    const reset = useCallback((config: Config) => {
        dispatch({ type: "RESET", config });
    }, []);

    const setField = useCallback((path: string, value: unknown) => {
        dispatch({ type: "SET_FIELD", path, value });
    }, []);

    const discard = useCallback(() => {
        dispatch({ type: "DISCARD" });
    }, []);

    const markSaved = useCallback((savedConfig: Config) => {
        dispatch({ type: "SAVED", savedConfig });
    }, []);

    return {
        draft: state.draft,
        isDirty: state.isDirty,
        reset,
        setField,
        discard,
        markSaved,
    };
}
