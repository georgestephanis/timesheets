import React, { createContext, useCallback, useContext, useEffect, useState } from "react";
import { NativeEngine } from "./config/NativeEngine";
import { EngineClient } from "./report/EngineClient";

export type SidecarState = "idle" | "starting" | "running" | "no-script" | "error";

type SidecarContextValue = {
    state: SidecarState;
    error: string;
    client: EngineClient | null;
    appleIntelligencePort: number;
    activeLlmIndex: number;
    setActiveLlmIndex: (index: number) => void;
    start: () => Promise<void>;
    locateAndStart: () => Promise<void>;
};

const SidecarContext = createContext<SidecarContextValue>({
    state: "idle",
    error: "",
    client: null,
    appleIntelligencePort: 0,
    activeLlmIndex: 0,
    setActiveLlmIndex: () => {},
    start: async () => {},
    locateAndStart: async () => {},
});

export function SidecarProvider({ children }: { children: React.ReactNode }) {
    const [state, setState] = useState<SidecarState>("idle");
    const [error, setError] = useState("");
    const [client, setClient] = useState<EngineClient | null>(null);
    const [appleIntelligencePort, setAppleIntelligencePort] = useState(0);
    const [activeLlmIndex, setActiveLlmIndexState] = useState(0);
    const setActiveLlmIndex = useCallback(
        (index: number) => {
            setActiveLlmIndexState(index);
            if (client) client.activeLlmIndex = index;
        },
        [client],
    );

    const start = useCallback(async () => {
        setState("starting");
        setError("");
        try {
            const existing = await NativeEngine.getSidecarPort();
            if (existing > 0) {
                setClient(new EngineClient(existing));
                setState("running");
                const aiPort = await NativeEngine.getAppleIntelligencePort();
                setAppleIntelligencePort(aiPort);
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
            const aiPort = await NativeEngine.getAppleIntelligencePort();
            setAppleIntelligencePort(aiPort);
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
        <SidecarContext.Provider
            value={{
                state,
                error,
                client,
                appleIntelligencePort,
                activeLlmIndex,
                setActiveLlmIndex,
                start,
                locateAndStart,
            }}
        >
            {children}
        </SidecarContext.Provider>
    );
}

export function useSidecar(): SidecarContextValue {
    return useContext(SidecarContext);
}
