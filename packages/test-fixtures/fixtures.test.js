import { describe, it } from "node:test";
import assert from "node:assert/strict";
import { reportSingleDay, reportMultiDay, reportWithWarnings, configMinimal } from "./index.js";

/**
 * Validates that a value conforms to the ProjectReport shape.
 * @param {unknown} p
 */
function assertProjectReport(p) {
    assert.ok(typeof p === "object" && p !== null, "ProjectReport must be object");
    assert.ok(typeof p.grouping === "string" || p.grouping === null, "grouping must be string|null");
    assert.ok(typeof p.seconds === "number", "seconds must be number");
    assert.ok(typeof p.active_seconds === "number", "active_seconds must be number");
    assert.ok(typeof p.activity_ratio === "number", "activity_ratio must be number");
    assert.ok(Array.isArray(p.external) || typeof p.external === "object", "external must be array or object");
    assert.ok(typeof p.detail === "object", "detail must be object");
    assert.ok(Array.isArray(p.commits), "commits must be array");
    for (const commit of p.commits) {
        assert.ok(typeof commit.time === "string", "commit.time must be string");
        assert.ok(typeof commit.sha === "string", "commit.sha must be string");
        assert.ok(typeof commit.subj === "string", "commit.subj must be string");
        assert.ok(typeof commit.repo === "string", "commit.repo must be string");
    }
}

/**
 * Validates that a value conforms to the Report envelope shape.
 * @param {unknown} r
 */
function assertReport(r) {
    assert.ok(typeof r === "object" && r !== null, "Report must be object");
    assert.ok(typeof r.from === "string", "from must be string");
    assert.ok(typeof r.to === "string", "to must be string");
    assert.ok(typeof r.tz === "string", "tz must be string");
    assert.ok(typeof r.days === "object", "days must be object");

    for (const [date, projects] of Object.entries(r.days)) {
        assert.match(date, /^\d{4}-\d{2}-\d{2}$/, `day key ${date} must be YYYY-MM-DD`);
        for (const project of Object.values(projects)) {
            assertProjectReport(project);
        }
    }

    assert.ok(typeof r.unmatched === "object", "unmatched must be object");
    for (const bucket of Object.values(r.unmatched)) {
        assert.ok(typeof bucket === "object", "unmatched bucket must be object");
    }

    if ("warnings" in r) {
        assert.ok(Array.isArray(r.warnings), "warnings must be array when present");
        for (const w of r.warnings) {
            assert.ok(typeof w === "string", "each warning must be string");
        }
    }

    if ("timelines" in r) {
        assert.ok(typeof r.timelines === "object", "timelines must be object when present");
        for (const [date, segments] of Object.entries(r.timelines)) {
            assert.match(date, /^\d{4}-\d{2}-\d{2}$/, `timeline key ${date} must be YYYY-MM-DD`);
            assert.ok(Array.isArray(segments), "timeline segments must be array");
            for (const seg of segments) {
                assert.ok(typeof seg.s === "number", "segment.s must be number");
                assert.ok(typeof seg.e === "number", "segment.e must be number");
                assert.ok(typeof seg.p === "string", "segment.p must be string");
                assert.ok(typeof seg.g === "string" || seg.g === null, "segment.g must be string|null");
            }
        }
    }
}

describe("fixtures", () => {
    describe("reportSingleDay", () => {
        it("conforms to Report shape", () => assertReport(reportSingleDay));
        it("has exactly one day", () => assert.equal(Object.keys(reportSingleDay.days).length, 1));
        it("has timelines keyed by date", () => {
            const dateKey = Object.keys(reportSingleDay.days)[0];
            assert.ok(dateKey in reportSingleDay.timelines);
        });
    });

    describe("reportMultiDay", () => {
        it("conforms to Report shape", () => assertReport(reportMultiDay));
        it("has exactly two days", () => assert.equal(Object.keys(reportMultiDay.days).length, 2));
        it("has timelines for each day", () => {
            for (const date of Object.keys(reportMultiDay.days)) {
                assert.ok(date in reportMultiDay.timelines, `missing timeline for ${date}`);
            }
        });
    });

    describe("reportWithWarnings", () => {
        it("conforms to Report shape", () => assertReport(reportWithWarnings));
        it("has a non-empty warnings array", () => {
            assert.ok(Array.isArray(reportWithWarnings.warnings));
            assert.ok(reportWithWarnings.warnings.length > 0);
        });
    });

    describe("configMinimal", () => {
        it("has required top-level fields", () => {
            assert.ok(typeof configMinimal.timezone === "string");
            assert.ok(typeof configMinimal.paths === "object");
            assert.ok(typeof configMinimal.projects === "object");
            assert.ok(Array.isArray(configMinimal.git_authors));
        });
        it("has at least one project", () => assert.ok(Object.keys(configMinimal.projects).length > 0));
        it("project groupings reference defined groupings", () => {
            const groupings = Object.keys(configMinimal.groupings ?? {});
            for (const [, proj] of Object.entries(configMinimal.projects)) {
                if (proj.grouping) {
                    assert.ok(
                        groupings.includes(proj.grouping),
                        `project grouping "${proj.grouping}" not in groupings`,
                    );
                }
            }
        });
    });
});
