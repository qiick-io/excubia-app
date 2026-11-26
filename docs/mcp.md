# MCP server

Excubia can serve its data to AI clients such as Claude Code, Cursor and Claude Desktop through
the [Model Context Protocol](https://modelcontextprotocol.io) (MCP). The server is **off by
default**. A client connects with a personal API key and sees exactly what the key's owner sees in
Excubia: the same projects (projects restricted to admins stay hidden from non-admins) and nothing
else. A key with the `mcp:write` scope can also use the [write tools](#write-tools) (resolve or
assign exceptions, dismiss findings, mute alerts…), with its owner's permissions.

Nothing leaves your server unless a client that a user has given a key to asks for it. Excubia
never calls the client, and it sends no data anywhere when the server is off.

## Turn it on

Add to `.env` and clear the config cache (`php artisan config:clear`, or `excubia:upgrade`):

```dotenv
EXCUBIA_MCP_ENABLED=true
# Optional: requests per minute for one API key (default 60)
# API_KEYS_DEFAULT_RATE_LIMIT_PER_MINUTE=60
```

The endpoint is `POST /mcp` on your Excubia URL, for example `https://excubia.example.com/mcp`.
While `EXCUBIA_MCP_ENABLED` is `false` it answers `404`.

The MCP server does not need the AI assistant (`EXCUBIA_AI_ENABLED`) or an AI provider: the
client brings its own model.

## Create an API key

1. Sign in to Excubia and open **Settings → API keys**.
2. Click **Create API key**, name it after the client (for example "Claude Code on my laptop"),
   and choose **Read** or **Write** under **AI clients (MCP server)**. **Read** is the default
   when available. **Write** includes Read and enables the [write tools](#write-tools).
   You can also pick an expiry date. To change scopes later, create a new key and revoke the old one.
3. Copy the key (`exc_live_…` or `exc_test_…`). It is shown once; Excubia stores only a hash of it.

Your account needs an explicit assignment of `can-use-ai`. In **Users**, assign the account a
role that grants this permission, such as **Admin** or a dedicated role. A **Super Admin** can
add that role to their own account. When your account has no MCP scope to grant, the selector
and Create button are disabled.

The key uses your assigned permissions. To see projects over MCP, you also need `can-show-projects`
from a role such as Reader or Admin. Revoke the key on the same page when you no longer use it.
Deactivating or deleting your account stops all your keys at once. Creating, revoking and the first use of a key are in the
activity log, and so is every MCP tool call (`ai` log, event "AI tool", with `surface: mcp`, the
tool, the arguments and the outcome).

Keep the key out of shared files: put it in an environment variable or your client's secret store,
not in a config file you commit.

## Connect a client

The examples use `https://excubia.example.com/mcp` and a key in the environment variable
`EXCUBIA_API_KEY`. Replace the URL with your Excubia URL.

### Claude Code

```bash
claude mcp add --transport http excubia https://excubia.example.com/mcp \
  --header "Authorization: Bearer $EXCUBIA_API_KEY"
```

Add `--scope user` to use it in every project. `claude mcp list` shows whether it connected.

### Cursor

In `~/.cursor/mcp.json` (every project) or `.cursor/mcp.json` (one project):

```json
{
    "mcpServers": {
        "excubia": {
            "url": "https://excubia.example.com/mcp",
            "headers": {
                "Authorization": "Bearer ${env:EXCUBIA_API_KEY}"
            }
        }
    }
}
```

### Claude Desktop

Claude Desktop's custom connectors sign in with OAuth, which Excubia does not offer. Use the
[`mcp-remote`](https://www.npmjs.com/package/mcp-remote) bridge (it needs Node.js) in
`claude_desktop_config.json` (Settings → Developer → Edit Config):

```json
{
    "mcpServers": {
        "excubia": {
            "command": "npx",
            "args": [
                "-y",
                "mcp-remote",
                "https://excubia.example.com/mcp",
                "--header",
                "Authorization:${EXCUBIA_AUTH}"
            ],
            "env": {
                "EXCUBIA_AUTH": "Bearer exc_live_your_key"
            }
        }
    }
}
```

The header has no space after the colon because some clients split arguments on spaces; the
space sits inside the `EXCUBIA_AUTH` value. Restart Claude Desktop after saving.

### Other clients

Any client that speaks MCP over streamable HTTP works: point it at the `/mcp` URL and send the
header `Authorization: Bearer <key>`. To try the server by hand, run
`php artisan mcp:inspector mcp` on the Excubia host and add the same header.

## Tools

The tools are the AI assistant's, so both answer the same way. **Settings → AI → Tools** turns a
tool off for both. `tools/list` returns every tool in one page.

### Read tools

Every key with `mcp:read` sees these. They carry the hints `readOnlyHint` and `idempotentHint`.

| Tool                    | What it returns                                                                                                 |
| ----------------------- | --------------------------------------------------------------------------------------------------------------- |
| `list-projects`         | Every project you can see: name, slug, enabled, stale, last report and the latest report summary.               |
| `search-findings`       | Findings that warn or fail across projects, by project, severity, package, advisory, check or date range.       |
| `get-latest-report`     | One project's latest report: its findings and skipped checks.                                                   |
| `compare-reports`       | What is new and what was resolved between two reports of a project (the latest two by default).                 |
| `get-report-history`    | The report summaries of a project, newest first.                                                                |
| `get-upgrade-plan`      | The suggested upgrade steps of a project.                                                                       |
| `list-exception-groups` | A project's exception groups seen in a time range (1h to 14d): class, message, where, counts, releases, status. |
| `get-runtime-summary`   | A project's runtime over a time range: requests with percentiles, slowest routes, slow queries, jobs, and more. |
| `get-schedule-status`   | A project's scheduled tasks: status, last and next due time, last run and the runs per outcome.                 |

`search-findings` gives each finding its `fingerprint`, which the finding write tools take;
`list-exception-groups` gives each group its `id`.

### Write tools

Listed only for a key with **`mcp:write`**, and only while `EXCUBIA_AI_WRITE_ENABLED` and
`EXCUBIA_MCP_WRITE_ENABLED` are on (both default `true`). An `mcp:read` key never sees them in
`tools/list`, and calling one answers an unknown-tool error. They carry `readOnlyHint: false` and
`destructiveHint: false`: they change data, delete nothing, and the client asks you before each
call. Creating the key with `mcp:write` is your consent, so Excubia asks for no approval of its
own (the in-app assistant does).

Each tool runs the same action as the button in the UI, with your permissions on a project you can
see: a reader without `can-manage-exceptions` cannot resolve an exception over MCP either.

| Tool                      | What it does                                                                                                                                                  | Needs                   |
| ------------------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------- | ----------------------- |
| `update-exception-status` | Resolve, ignore or reopen exception groups of one project, by id (at most 200).                                                                               | `can-manage-exceptions` |
| `assign-items`            | Assign exception groups (by id) or findings (by fingerprint) to `me`, to a user by email who can see the project, or to `nobody`.                             | the list's permission   |
| `dismiss-finding`         | Dismiss a finding with a reason, for good or until a day (a snooze).                                                                                          | `can-dismiss-findings`  |
| `restore-finding`         | Remove a finding's dismissal or snooze.                                                                                                                       | `can-dismiss-findings`  |
| `mute-alert`              | Mute one alert event of a project for 1h, 24h, 7d or forever (`target: rule`), or open a one-off or weekly maintenance window (`target: maintenance_window`). | `can-manage-alerts`     |
| `unmute-alert`            | End every mute of an alert event, or close a maintenance window by the id `mute-alert` returned.                                                              | `can-manage-alerts`     |
| `refresh-report`          | Queue a new report of the Excubia system project ("Refresh now"). Other projects push their own reports.                                                      | `can-run-checks`        |

There are no destructive or security tools: nothing deletes a project or touches push tokens,
API keys, users, roles or settings.

### Turn the write tools off

- `EXCUBIA_MCP_WRITE_ENABLED=false`: MCP is read-only for every key; the assistant may still write.
- `EXCUBIA_AI_WRITE_ENABLED=false`: no write anywhere, in the assistant or over MCP.
- **Settings → AI → Tools**: untick single tools, for the assistant and MCP.
- Or give the client a key without `mcp:write`.

Clear the config cache after changing `.env` (`php artisan config:clear`, or `excubia:upgrade`).

### What the tools never return

Push tokens, API keys, user data (names, emails, assignees), stack frames or other file paths,
server host names, and the deprecation sources, from a read or a write tool. This is covered by a
test for both the assistant and the MCP server.

## Limits and errors

- `401`: the key is missing, unknown, revoked or expired, or its owner is deactivated.
- `403`: the key lacks the `mcp:read` scope. (A missing `mcp:write` hides the write tools instead.)
- `404`: `EXCUBIA_MCP_ENABLED` is not `true`.
- `403 request_not_allowed`: the key's request restrictions refuse this request.
- `429`: more than 20 failed attempts per minute from one IP (`API_KEYS_FAILED_ATTEMPTS_PER_MINUTE`;
  then even a correct key gets 429 until the window ends), or more than
  `API_KEYS_DEFAULT_RATE_LIMIT_PER_MINUTE` requests per minute with one key. The `Retry-After`
  and `X-RateLimit-*` headers say when to try again.

Behind a reverse proxy, set `TRUSTED_PROXIES` so the failed-attempt limit counts the client's IP,
not the proxy's.
