# Security Policy

## Supported versions

Excubia App is pre-release. Security fixes go to the latest `0.x` release.

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
