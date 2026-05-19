import React, { createContext, useCallback, useContext, useEffect, useState } from "react";
import { NativeEngine } from "./config/NativeEngine";
import { EngineClient } from "./report/EngineClient";

export type SidecarState = "idle" | "starting" | "running" | "no-script" | "error";

type SidecarContextValue = {
    state: SidecarState;
    error: string;
    client: EngineClient | null;
    start: () => Promise<void>;
    locateAndStart: () => Promise<void>;
};

const SidecarContext = createContext<SidecarContextValue>({
    state: "idle",
    error: "",
    client: null,
    start: async () => {},
    locateAndStart: async () => {},
});

export function SidecarProvider({ children }: { children: React.ReactNode }) {
    const [state, setState] = useState<SidecarState>("idle");
    const [error, setError] = useState("");
    const [client, setClient] = useState<EngineClient | null>(null);

    const start = useCallback(async () => {
        setState("starting");
        setError("");
        try {
            const existing = await NativeEngine.getSidecarPort();
            if (existing > 0) {
                setClient(new EngineClient(existing));
                setState("running");
                return;
            }
            const scriptPath = await NativeEngine.getEngineScriptPath();
            if (!scriptPath) {
                setState("no-script");
                return;
            }
            const port = await NativeEngine.startSidecar();
            setClient(new EngineClient(port));
            setState("running");
        } catch (e: unknown) {
            setError(e instanceof Error ? e.message : String(e));
            setState("error");
        }
    }, []);

    const locateAndStart = useCallback(async () => {
        try {
            const picked = await NativeEngine.pickEngineScript();
            if (picked) await start();
        } catch (e: unknown) {
            setError(e instanceof Error ? e.message : String(e));
            setState("error");
        }
    }, [start]);

    useEffect(() => {
        start();
    }, [start]);

    return (
        <SidecarContext.Provider value={{ state, error, client, start, locateAndStart }}>
            {children}
        </SidecarContext.Provider>
    );
}

export function useSidecar(): SidecarContextValue {
    return useContext(SidecarContext);
}
