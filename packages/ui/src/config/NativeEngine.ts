import { NativeModules } from "react-native";
import type { Config } from "./configSchema";

type NativeEngineModule = {
    getConfig(): Promise<Config | null>;
    saveConfig(config: Record<string, unknown>): Promise<string>;
    getConfigPath(): Promise<string>;
    setConfigDir(dir: string): Promise<string>;
    pickConfigDir(): Promise<string | null>;
};

const native = NativeModules.TimesheetsEngine as NativeEngineModule | undefined;

function assertNative(): NativeEngineModule {
    if (!native) throw new Error("TimesheetsEngine native module not available");
    return native;
}

export const NativeEngine = {
    getConfig(): Promise<Config | null> {
        return assertNative().getConfig();
    },
    saveConfig(config: Config): Promise<string> {
        return assertNative().saveConfig(config as Record<string, unknown>);
    },
    getConfigPath(): Promise<string> {
        return assertNative().getConfigPath();
    },
    setConfigDir(dir: string): Promise<string> {
        return assertNative().setConfigDir(dir);
    },
    pickConfigDir(): Promise<string | null> {
        return assertNative().pickConfigDir();
    },
};
