# MCP server

Excubia can serve its data to AI clients such as Claude Code, Cursor and Claude Desktop through
the [Model Context Protocol](https://modelcontextprotocol.io) (MCP). The server is **read-only**
and **off by default**. A client connects with a personal API key and sees exactly what the key's
owner sees in Excubia: the same projects (projects restricted to admins stay hidden from
non-admins) and nothing else.

Nothing leaves your server unless a client that a user has given a key to asks for it. Excubia
never calls the client, and it sends no data anywhere when the server is off.

## Turn it on

Add to `.env` and clear the config cache (`php artisan config:clear`, or `excubia:upgrade`):

```dotenv
EXCUBIA_MCP_ENABLED=true
# Optional: requests per minute for one API key (default 60)
# EXCUBIA_API_KEY_REQUESTS_PER_MINUTE=60
```

The endpoint is `POST /mcp` on your Excubia URL, for example `https://excubia.example.com/mcp`.
While `EXCUBIA_MCP_ENABLED` is `false` it answers `404`.

The MCP server does not need the AI assistant (`EXCUBIA_AI_ENABLED`) or an AI provider: the
client brings its own model.

## Create an API key

1. Sign in to Excubia and open **Settings → API keys**.
2. Click **Create API key**, name it after the client (for example "Claude Code on my laptop"),
   keep the `mcp:read` scope and, if you want, pick an expiry date.
3. Copy the key (`exk_…`). It is shown once; Excubia stores only a hash of it.

The key acts as you. To see projects, you need the `can-show-projects` permission (every built-in
role has it). Revoke the key in the same page when you no longer use it; deactivating or deleting
your account stops all your keys at once. Creating, revoking and the first use of a key are in the
activity log, and so is every MCP tool call (`ai` log, event "AI tool").

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
                "EXCUBIA_AUTH": "Bearer exk_your_key"
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
tool off for both. Every tool is read-only; there are no write tools.

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

What the tools never return: push tokens, API keys, user data (names, emails, assignees), stack
frames or other file paths, server host names, and the deprecation sources. This is covered by a
test for both the assistant and the MCP server.

## Limits and errors

- `401`: the key is missing, unknown, revoked or expired, or its owner is deactivated.
- `403`: the key lacks the `mcp:read` scope.
- `404`: `EXCUBIA_MCP_ENABLED` is not `true`.
- `429`: more than 20 failed attempts per minute from one IP, or more than
  `EXCUBIA_API_KEY_REQUESTS_PER_MINUTE` requests per minute with one key. The `Retry-After`
  header says when to try again.

Behind a reverse proxy, set `TRUSTED_PROXIES` so the failed-attempt limit counts the client's IP,
not the proxy's.
