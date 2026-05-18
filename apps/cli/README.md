# PHP CLI

PHP CLI entry point for the timesheets activity report.

## Usage

Invoke via the root wrapper (so cron jobs and shell aliases don't need to change):

```bash
php activity-report.php [options]
./activity-report.php [options]   # after chmod +x activity-report.php
```

Or invoke directly:

```bash
php apps/cli/activity-report.php [options]
```

## Structure

```
activity-report.php   Entry point: defines PROJECT_ROOT, loads config, requires src/, calls main()
```

Shared PHP logic lives in `src/` at the project root. Both this entry point and the web
UI (`apps/web/`) share that code via `PROJECT_ROOT`.

See the root [README.md](../../README.md) for full CLI usage documentation.
