# Security

## Threat model

This is a **local-only tool**. The web UI is served by `php -S localhost:8000` and is intended to be accessed only from your own machine. There is no authentication layer — anyone who can reach port 8000 on your host can read your activity data and mutate your config.

### What this tool can access

- ActivityWatch SQLite databases (app focus, AFK, input events)
- Chrome history SQLite (URLs and page titles)
- Git repository paths and commit metadata
- External API responses from Harvest, ClickUp, GitHub, and any configured LLM endpoint

### Sensitive data in `config.json`

`config.json` holds plaintext credentials:

| Field                                     | What it grants                         |
| ----------------------------------------- | -------------------------------------- |
| `integrations.harvest[*].token`           | Read/write access to Harvest account   |
| `integrations.clickup[*].token`           | Read/write access to ClickUp workspace |
| `integrations.github[*]` (uses `gh` auth) | Read access to GitHub repos/activity   |
| `integrations.llm[*].api_key`             | Access to configured LLM endpoint      |

**Recommended:** restrict read access to `config.json`:

```bash
chmod 600 config.json
```

`config.json` is gitignored and must never be committed. The pre-commit hook does not enforce this — you are responsible for keeping it out of version control.

### DNS rebinding

`api.php` checks the `Origin` / `Host` request headers and rejects requests that do not originate from `localhost`. This mitigates DNS rebinding attacks where a malicious site tricks your browser into sending requests to `localhost:8000`.

If you expose the server on a non-localhost interface (e.g. `php -S 0.0.0.0:8000`), this protection no longer applies and anyone on your network can reach the API.

### Recommendations

- Run `php -S localhost:8000` only, never `0.0.0.0` or a public interface.
- Rotate any credentials that appear in old config backups under `reports/config/` if those files are shared.
- Keep `reports/` out of any synced or shared folder — it contains your raw activity data and timestamped config backups.
- Audit `reports/config/` periodically and delete old backups you no longer need.

## Reporting issues

This is a personal-use project with no formal security disclosure process. If you find a significant issue, open a GitHub issue or contact the maintainer directly.
