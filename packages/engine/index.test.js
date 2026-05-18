import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { TimesheetsEngine } from "./index.js";
import {
    classifyVscode,
    classifySlack,
    classifySsh,
    projectForSignals,
    projectForExternal,
    isAfkAt,
    classifyAndAggregate,
} from "./index.js";

describe("TimesheetsEngine", () => {
    describe("constructor", () => {
        it("stores resolved configPath", () => {
            const engine = new TimesheetsEngine("/tmp/config.json");
            assert.ok(engine.configPath.endsWith("config.json"));
        });

        it("derives cacheDir from configPath", () => {
            const engine = new TimesheetsEngine("/tmp/config.json");
            assert.ok(engine.cacheDir.endsWith("reports"));
        });

        it("starts with null config", () => {
            const engine = new TimesheetsEngine("/tmp/config.json");
            assert.equal(engine.config, null);
        });
    });

    describe("resolveDateRange", () => {
        it("returns from and to unchanged", () => {
            const engine = new TimesheetsEngine("/tmp/config.json");
            const range = engine.resolveDateRange("2024-01-15", "2024-01-16");
            assert.deepEqual(range, { from: "2024-01-15", to: "2024-01-16" });
        });

        it("accepts same-day range", () => {
            const engine = new TimesheetsEngine("/tmp/config.json");
            const range = engine.resolveDateRange("2024-01-15", "2024-01-15");
            assert.equal(range.from, range.to);
        });
    });

    describe("generateReport", () => {
        it("returns report skeleton with correct shape", async () => {
            const engine = new TimesheetsEngine(
                new URL("../test-fixtures/config-minimal.json", import.meta.url).pathname,
            );
            const range = { from: "2024-01-15", to: "2024-01-15" };
            const report = await engine.generateReport(range);

            assert.ok(typeof report.from === "string");
            assert.ok(typeof report.to === "string");
            assert.ok(typeof report.tz === "string");
            assert.ok(typeof report.days === "object");
            assert.ok(!report.warnings || Array.isArray(report.warnings));
        });

        it("loads config on first call", async () => {
            const engine = new TimesheetsEngine(
                new URL("../test-fixtures/config-minimal.json", import.meta.url).pathname,
            );
            assert.equal(engine.config, null);
            await engine.generateReport({ from: "2024-01-15", to: "2024-01-15" });
            assert.ok(engine.config !== null);
        });

        it("uses config timezone in report", async () => {
            const engine = new TimesheetsEngine(
                new URL("../test-fixtures/config-minimal.json", import.meta.url).pathname,
            );
            const report = await engine.generateReport({
                from: "2024-01-15",
                to: "2024-01-15",
            });
            assert.equal(report.tz, "America/New_York");
        });
    });
});

describe("classifyVscode", () => {
    it("extracts project from 'file — project' title", () => {
        assert.equal(classifyVscode("index.js — myproject"), "myproject");
    });

    it("strips unsaved-changes bullet", () => {
        assert.equal(classifyVscode("● index.js — myproject"), "myproject");
    });

    it("returns null for app-name-only titles", () => {
        assert.equal(classifyVscode("Visual Studio Code"), null);
    });

    it("returns null for empty string", () => {
        assert.equal(classifyVscode(""), null);
    });

    it("returns title itself when no em-dash separator", () => {
        assert.equal(classifyVscode("myproject"), "myproject");
    });
});

describe("classifySlack", () => {
    it("parses channel title", () => {
        const r = classifySlack("general (Channel) - Acme Corp - Slack");
        assert.deepEqual(r, { workspace: "Acme Corp", channel: "general", kind: "Channel" });
    });

    it("parses Threads view", () => {
        const r = classifySlack("Threads - Acme Corp - Slack");
        assert.deepEqual(r, { workspace: "Acme Corp", channel: "__threads__", kind: "view" });
    });

    it("parses Activity view", () => {
        const r = classifySlack("Activity - Acme Corp - Slack");
        assert.deepEqual(r, { workspace: "Acme Corp", channel: "__activity__", kind: "view" });
    });

    it("returns null for non-Slack title", () => {
        assert.equal(classifySlack("Random window title"), null);
    });
});

describe("classifySsh", () => {
    it("extracts hostname from ssh command in title", () => {
        assert.equal(classifySsh("ssh user@example.com — bash"), "user@example.com");
    });

    it("returns null when no ssh command present", () => {
        assert.equal(classifySsh("bash — ~/code"), null);
    });
});

describe("projectForSignals", () => {
    const config = /** @type {any} */ ({
        timezone: "UTC",
        projects: {
            myproject: {
                vscode_dirs: ["myproject"],
                domains: ["example.com"],
                apps: ["MyApp"],
            },
        },
    });

    it("matches by vscode_dir (case-insensitive)", () => {
        assert.equal(projectForSignals({ vscode_dir: "MyProject" }, config), "myproject");
    });

    it("matches by host domain", () => {
        assert.equal(projectForSignals({ host: "example.com" }, config), "myproject");
    });

    it("matches subdomain via domain rule", () => {
        assert.equal(projectForSignals({ host: "sub.example.com" }, config), "myproject");
    });

    it("returns null when no match", () => {
        assert.equal(projectForSignals({ app: "Unknown App" }, config), null);
    });
});

describe("isAfkAt", () => {
    const t0 = new Date("2024-01-15T10:00:00Z");
    const afk = [
        { start: new Date("2024-01-15T10:00:00Z"), end: new Date("2024-01-15T10:05:00Z"), status: "afk" },
        { start: new Date("2024-01-15T10:05:00Z"), end: new Date("2024-01-15T10:10:00Z"), status: "not-afk" },
    ];

    it("returns true when t is within afk interval", () => {
        assert.ok(isAfkAt(new Date("2024-01-15T10:02:00Z"), afk));
    });

    it("returns false when t is in not-afk interval", () => {
        assert.ok(!isAfkAt(new Date("2024-01-15T10:07:00Z"), afk));
    });

    it("returns false when t is before all afk events", () => {
        assert.ok(!isAfkAt(new Date("2024-01-15T09:00:00Z"), afk));
    });
});

describe("classifyAndAggregate", () => {
    const config = /** @type {any} */ ({
        timezone: "UTC",
        projects: {
            myproject: { vscode_dirs: ["myproject"] },
        },
    });

    it("aggregates a window event into bucket", () => {
        const events = {
            window: [
                {
                    start: new Date("2024-01-15T10:00:00Z"),
                    end: new Date("2024-01-15T10:30:00Z"),
                    app: "Code",
                    title: "index.js — myproject",
                    url: "",
                },
            ],
            afk: [],
            input: [],
        };
        const { bucket } = classifyAndAggregate(events, [], [], config, "UTC");
        assert.ok(bucket["2024-01-15"]);
        assert.ok(bucket["2024-01-15"]["myproject"]);
        assert.ok(bucket["2024-01-15"]["myproject"].seconds > 0);
    });

    it("skips afk events", () => {
        const events = {
            window: [
                {
                    start: new Date("2024-01-15T10:00:00Z"),
                    end: new Date("2024-01-15T10:30:00Z"),
                    app: "Code",
                    title: "index.js — myproject",
                    url: "",
                },
            ],
            afk: [
                {
                    start: new Date("2024-01-15T09:50:00Z"),
                    end: new Date("2024-01-15T10:40:00Z"),
                    status: "afk",
                },
            ],
            input: [],
        };
        const { bucket } = classifyAndAggregate(events, [], [], config, "UTC");
        assert.ok(!bucket["2024-01-15"]?.["myproject"]);
    });

    it("places unmatched vscode dir in unmatched.vscode", () => {
        const events = {
            window: [
                {
                    start: new Date("2024-01-15T10:00:00Z"),
                    end: new Date("2024-01-15T10:30:00Z"),
                    app: "Code",
                    title: "index.js — unknownproject",
                    url: "",
                },
            ],
            afk: [],
            input: [],
        };
        const { unmatched } = classifyAndAggregate(events, [], [], config, "UTC");
        assert.ok(unmatched.vscode["unknownproject"] > 0);
    });

    it("attaches commit to correct date and project", () => {
        const events = { window: [], afk: [], input: [] };
        const commits = [
            {
                dt: new Date("2024-01-15T14:00:00Z"),
                project: "myproject",
                repo: "repo",
                sha: "abc",
                subj: "fix bug",
            },
        ];
        const { bucket } = classifyAndAggregate(events, commits, [], config, "UTC");
        assert.ok(bucket["2024-01-15"]["myproject"].commits.length === 1);
    });
});
