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

    async reassignSignal(type: string, key: string, project: string): Promise<void> {
        await this.post("/reassign-signal", { type, key, project });
    }

    async setGrouping(project: string, grouping: string): Promise<void> {
        await this.post("/set-grouping", { project, grouping });
    }

    async flagIgnored(projects: string[], ignored: boolean): Promise<void> {
        await this.post("/flag-ignored", { projects, ignored });
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
