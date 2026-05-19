// Typed fetch client for the engine sidecar HTTP API (Tier 2 IPC).

export type Report = {
    from: string;
    to: string;
    tz: string;
    days: Record<string, Record<string, ProjectReport>>;
    unmatched: Record<string, Record<string, number>>;
    timelines?: Record<string, TimelineSegment[]>;
    warnings?: string[];
    summaries?: Record<string, string>;
    cachedAt?: number;
};

export type ProjectReport = {
    grouping: string | null;
    seconds: number;
    active_seconds: number;
    activity_ratio: number;
    external: Record<string, unknown> | unknown[];
    detail: Record<string, Record<string, number>>;
    commits: CommitRecord[];
};

export type CommitRecord = {
    time: string;
    sha: string;
    subj: string;
    repo: string;
};

export type TimelineSegment = {
    s: number;
    e: number;
    p: string;
    g: string | null;
};

export class EngineClient {
    private base: string;
    activeLlmIndex = 0;

    constructor(port: number) {
        this.base = `http://127.0.0.1:${port}`;
    }

    async status(): Promise<{ ok: boolean }> {
        return this.get("/status");
    }

    async getReport(from: string, to: string, rebuild = false): Promise<Report> {
        const params = new URLSearchParams({ from, to });
        if (rebuild) params.set("rebuild", "1");
        return this.get(`/report?${params}`);
    }

    async reassignSignal(type: string, key: string, project: string, createProject = false): Promise<void> {
        await this.post("/reassign-signal", { type, key, project, createProject });
    }

    async setGrouping(project: string, grouping: string): Promise<void> {
        await this.post("/set-grouping", { project, grouping });
    }

    async flagIgnored(projects: string[], ignored: boolean): Promise<void> {
        await this.post("/flag-ignored", { projects, ignored });
    }

    async generateSummary(date: string): Promise<{ summary: string }> {
        return this.post("/generate-summary", { date, llmIndex: this.activeLlmIndex });
    }

    async suggestAssignments(
        date: string,
    ): Promise<{ suggestions: Array<{ kind: string; value: string; project: string; reason: string }> }> {
        return this.post("/suggest-assignments", { date, llmIndex: this.activeLlmIndex });
    }

    async getIntegrationCatalog(): Promise<{ harvest: string[]; clickup: string[] }> {
        return this.get("/integration-catalog");
    }

    async discoverRepos(): Promise<{
        repos: Array<{ path: string; name: string; recent: boolean; assigned: string | null }>;
    }> {
        return this.get("/discover-repos");
    }

    private async get<T>(path: string): Promise<T> {
        const res = await fetch(this.base + path);
        const body = await res.json();
        if (!res.ok) throw new Error((body as { error?: string }).error ?? `HTTP ${res.status}`);
        return body as T;
    }

    private async post<T>(path: string, payload: unknown): Promise<T> {
        const res = await fetch(this.base + path, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            body: JSON.stringify(payload),
        });
        const body = await res.json();
        if (!res.ok) throw new Error((body as { error?: string }).error ?? `HTTP ${res.status}`);
        return body as T;
    }
}
