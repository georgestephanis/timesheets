// TypeScript engine for timesheets application
// This will replace the PHP core functionality

import { promises as fs } from "fs";
import path from "path";

/**
 * Engine class for handling timesheet data processing
 */
class TimesheetsEngine {
    constructor(configPath = "./config.json") {
        this.configPath = path.resolve(configPath);
        this.config = null;
        this.cacheDir = path.join(path.dirname(this.configPath), "reports");
    }

    /**
     * Load configuration from file
     * @returns {Promise<import('@timesheets/contracts').Config>}
     */
    async loadConfig() {
        const configData = await fs.readFile(this.configPath, "utf8");
        this.config = JSON.parse(configData);
        return this.config;
    }

    /**
     * Resolve date range for report generation
     * @param {string} fromDate - Start date (YYYY-MM-DD)
     * @param {string} toDate - End date (YYYY-MM-DD)
     * @returns {import('@timesheets/contracts').DateRange}
     */
    resolveDateRange(fromDate, toDate) {
        return { from: fromDate, to: toDate };
    }

    /**
     * Generate report for a date range
     * @param {import('@timesheets/contracts').DateRange} range
     * @param {Object} [options]
     * @returns {Promise<import('@timesheets/contracts').Report>}
     */
    async generateReport(range, options = {}) {
        if (!this.config) {
            await this.loadConfig();
        }
        return {
            from: range.from,
            to: range.to,
            tz: this.config?.timezone || "UTC",
            days: {},
            unmatched: {},
            warnings: [],
            timelines: {},
        };
    }

    /**
     * Load sources for a date range (placeholder for Phase 2 implementation)
     * @param {import('@timesheets/contracts').Config} config
     * @param {import('@timesheets/contracts').DateRange} range
     * @returns {Promise<Object>}
     */
    async loadSourcesForRange(config, range) {
        return {
            activitywatch: [],
            chrome: [],
            git: [],
            integrations: [],
        };
    }

    /**
     * Load fresh source slice (placeholder for Phase 2 implementation)
     * @param {import('@timesheets/contracts').Config} config
     * @param {string} date - YYYY-MM-DD
     * @returns {Promise<Object>}
     */
    async loadFreshSourceSlice(config, date) {
        return {};
    }

    /**
     * Classify and aggregate data (placeholder for Phase 2 implementation)
     * @param {Object} sources
     * @returns {Promise<Object>}
     */
    async classifyAndAggregate(sources) {
        return {
            bucket: {},
            unmatched: {},
            timelines: {},
        };
    }

    /**
     * Save configuration with backup to reports/config/ following project convention.
     * Backup path: reports/config/config.engine.<ISO-timestamp>.json
     * @param {import('@timesheets/contracts').Config} newConfig
     * @returns {Promise<void>}
     */
    async saveConfigWithBackup(newConfig) {
        const timestamp = new Date().toISOString().replace(/[:.]/g, "-");
        const backupDir = path.join(this.cacheDir, "config");
        const backupPath = path.join(backupDir, `config.engine.${timestamp}.json`);

        await fs.mkdir(backupDir, { recursive: true });
        try {
            const currentConfig = await fs.readFile(this.configPath, "utf8");
            await fs.writeFile(backupPath, currentConfig);
        } catch (error) {
            if (error?.code !== "ENOENT") {
                throw error;
            }
        }
        await fs.writeFile(this.configPath, JSON.stringify(newConfig, null, 2));
        this.config = newConfig;
    }
}

export { TimesheetsEngine };
