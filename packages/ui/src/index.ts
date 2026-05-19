// Brand
export { Brand } from "./brand";

// Config UI
export { ConfigScreen } from "./config/ConfigScreen";
export { useConfigDraft } from "./config/useConfigDraft";
export { useFieldPath } from "./config/useFieldPath";
export { configSchema } from "./config/configSchema";
export type {
    Config,
    ProjectConfig,
    GroupingConfig,
    HarvestConnection,
    ClickUpConnection,
    GitHubConnection,
    LlmConnection,
    ClockifyConnection,
} from "./config/configSchema";
export { NativeEngine } from "./config/NativeEngine";

// Sidecar lifecycle
export { SidecarProvider, useSidecar } from "./SidecarContext";
export type { SidecarState } from "./SidecarContext";

// Report UI
export { ReportScreen } from "./report/ReportScreen";
export { EngineClient } from "./report/EngineClient";
export type { Report, ProjectReport, TimelineSegment, CommitRecord } from "./report/EngineClient";
