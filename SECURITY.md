# Security Policy

## Supported versions

| Version                            | Supported                                                            |
| ---------------------------------- | -------------------------------------------------------------------- |
| 1.x (latest minor)                 | Yes: security fixes ship as a patch release of the latest 1.x minor  |
| 1.x (older minors)                 | No: upgrade to the latest 1.x minor (no breaking changes within 1.x) |
| 1.0.0 betas and release candidates | Until 1.0.0 is released, the latest pre-release only                 |
| 0.x and earlier                    | No                                                                   |

Run the latest release of the 1.x line: the Docker image tag and `composer.json` carry the version, and Settings → System shows it.

The threat model, data flows, retention and who can see what are in [`docs/SECURITY-MODEL.md`](docs/SECURITY-MODEL.md).

## Reporting a vulnerability

Please do not open a public issue. Use GitHub's private vulnerability reporting ("Report a vulnerability" on the Security tab of `qiick-io/excubia-app`). We aim to acknowledge reports within 72 hours.

Excubia App has no telemetry. Only package names and versions leave the server, through `qiick/excubia-core`. Reports showing otherwise are treated as critical.

The optional AI assistant is the one exception, and it is off by default (`EXCUBIA_AI_ENABLED=false`). When you turn it on, its read-only tools send data to the AI provider in `config/ai.php` (Ollama on the same network by default):

- project names and slugs;
- report summaries;
- finding details: package names, versions, messages, fix hints, advisory IDs;
- upgrade steps;
- the questions users type.

Push tokens, user data and file paths are never sent. A cloud provider is an explicit choice of the operator.
