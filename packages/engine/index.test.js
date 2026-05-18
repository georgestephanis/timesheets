import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { TimesheetsEngine } from "./index.js";

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
            assert.ok(Array.isArray(report.warnings));
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
