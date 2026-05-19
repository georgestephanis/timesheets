/**
 * @timesheets/test-fixtures
 *
 * Synthetic golden fixtures representing the PHP renderJson() and config shapes.
 * Use these as the source of truth when writing parity tests for the TypeScript engine.
 *
 * Fixtures:
 *   reportSingleDay   — one day, two projects, commits, harvest external data, timelines
 *   reportMultiDay    — two days, demonstrates per-day timeline keying
 *   reportWithWarnings — includes the optional top-level warnings array
 *   configMinimal     — smallest valid config.json that exercises all top-level sections
 */

import { createRequire } from "module";

const require = createRequire(import.meta.url);

/** @type {import('@timesheets/contracts').Report} */
export const reportSingleDay = require("./report-single-day.json");

/** @type {import('@timesheets/contracts').Report} */
export const reportMultiDay = require("./report-multi-day.json");

/** @type {import('@timesheets/contracts').Report} */
export const reportWithWarnings = require("./report-with-warnings.json");

/** @type {import('@timesheets/contracts').Config} */
export const configMinimal = require("./config-minimal.json");
