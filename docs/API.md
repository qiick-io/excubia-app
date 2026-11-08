# Ingest API

Projects that install [`qiick/excubia-core`](https://github.com/qiick-io/excubia-core) push to three endpoints of the app. This page describes them as they are frozen for the 1.x line: every excubia-core 1.x can push to every Excubia App 1.x. Which core version each feature needs: [compatibility.md](./compatibility.md). How the data is validated, stored and kept: [SECURITY-MODEL.md](./SECURITY-MODEL.md).

| Endpoint               | Sent by (excubia-core)                                    | Payload                                                                                                                               |
| ---------------------- | --------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `POST /api/reports`    | `vendor/bin/excubia push`, `php artisan excubia:push`     | a health report ([`report-schema.json`](https://github.com/qiick-io/excubia-core/blob/develop/docs/report-schema.json))               |
| `POST /api/runtime`    | `php artisan excubia:push-runtime` (every minute, opt-in) | runtime aggregates ([`runtime-schema.json`](https://github.com/qiick-io/excubia-core/blob/develop/docs/runtime-schema.json))          |
| `POST /api/exceptions` | `php artisan excubia:push-runtime` (every minute, opt-in) | masked exception groups ([`exception-schema.json`](https://github.com/qiick-io/excubia-core/blob/develop/docs/exception-schema.json)) |

The envelope of all three is [`push-schema.json`](https://github.com/qiick-io/excubia-core/blob/develop/docs/push-schema.json) (`$defs.runtimeEnvelope`, `$defs.exceptionsEnvelope` for the batches). The core freezes these files in its [`docs/CONTRACTS.md`](https://github.com/qiick-io/excubia-core/blob/develop/docs/CONTRACTS.md) and describes the protocol in [`docs/SPEC.md` section 13](https://github.com/qiick-io/excubia-core/blob/develop/docs/SPEC.md#13-push-protocol-approved-2026-11-02). There are no other routes under `/api`.

## Common rules

### Transport and authentication

- `POST`, JSON body (`Content-Type: application/json`), answers are always JSON. Send `Accept: application/json`.
- HTTPS. excubia-core refuses plain HTTP except to `localhost` and does not follow redirects, so a redirect is a failed push.
- `Authorization: Bearer <project token>`. The token is created per project in the app (shown once) and stored only as a SHA-256 hash. It never goes in the URL or the body. A user session does not authenticate a push.

### Protocol version

- Every envelope carries `protocolVersion`. The app accepts **`1`**, which every excubia-core 1.x sends, for the whole 1.x line.
- The report inside `/api/reports` also carries `report.schemaVersion`, which must be `1`.
- `/api/runtime` and `/api/exceptions` also need `type` (`runtime`, `exceptions`); `/api/runtime` needs `runtime.bucketSeconds` = `60`.

### Idempotency (`batchId`)

- `batchId` is a UUID v4 (`^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$`, lower case), unique per project and endpoint.
- The first push of a `batchId` is stored and answers `201`. Any later push with the same `batchId` to the same project and endpoint stores nothing and answers `200` with `"status": "duplicate"`, also when two retries arrive at once. The body of the repeat is not compared with the first one.
- excubia-core keeps the `batchId` across HTTP retries and resends a runtime or exception batch with the same id until it gets a 2xx, so a retry never counts twice.
- The body is validated before the `batchId` is looked up: an invalid repeat still answers `422`.

### Limits

| Limit         | Value                                                                                                                                                                          | Answer |
| ------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ | ------ |
| Body size     | `EXCUBIA_PUSH_MAX_KILOBYTES` (default 1024 KB), checked after the token. PHP's `post_max_size` caps it first (16 MB in the Docker image).                                      | `413`  |
| JSON depth    | PHP's default decoding depth (512). A deeper body, like a body that is not valid JSON, decodes to nothing and fails validation on the required fields.                         | `422`  |
| Field lengths | per field in the request validators (for example an exception message of at most 1000 characters, at most 50 frames, runtime dimensions of at most 2000 characters)            | `422`  |
| Rate          | `EXCUBIA_PUSH_REQUESTS_PER_MINUTE` (default 30) per minute, per project for a valid token, per client IP for a missing or unknown token. The three endpoints share the bucket. | `429`  |

The rate limit runs before the token check, so a throttled client gets `429` whatever its token. A `429` carries `Retry-After` (seconds) and `X-RateLimit-Limit` / `X-RateLimit-Remaining`; excubia-core retries it with backoff and, for batches, keeps the data for the next run.

### Order of the checks

1. Rate limit → `429`
2. Token: missing, unknown or revoked → `401`; the project is disabled → `403`
3. Body size → `413`
4. Validation of the envelope and the payload → `422`
5. Store, or find the `batchId` → `201` or `200`

Every 4xx is counted per hour on Settings → System (endpoint, status and a reason: `token`, `disabled`, `too_large`, `throttled`, or the first invalid field such as `runtime.aggregates.*.count`). The body, the token and the IP are never kept.

### Status codes and bodies

Success bodies are JSON objects; excubia-core decodes them and fails a push whose 2xx body is not a JSON object or list.

| Status | When                                      | Body                                                                                               |
| ------ | ----------------------------------------- | -------------------------------------------------------------------------------------------------- |
| `201`  | new `batchId`, stored                     | `{"status": "stored"}`; `/api/reports` adds `"reportId": <integer>`                                |
| `200`  | `batchId` already received                | `{"status": "duplicate"}`; `/api/reports` adds the `reportId` of the stored report                 |
| `401`  | missing, unknown or revoked token         | `{"message": "Missing, unknown or revoked project token."}`                                        |
| `403`  | the project is disabled in the app        | `{"message": "This project is disabled in Excubia."}`                                              |
| `405`  | any method other than `POST`              | `{"message": "The GET method is not supported for route api/reports. Supported methods: POST."}`   |
| `413`  | body above `EXCUBIA_PUSH_MAX_KILOBYTES`   | `{"message": "The body is larger than 1024 KB."}` (the configured value)                           |
| `422`  | invalid envelope or payload, invalid JSON | `{"message": "<first error>[ (and N more errors)]", "errors": {"<field path>": ["<message>", …]}}` |
| `429`  | rate limit                                | `{"message": "Too Many Attempts."}` with `Retry-After`                                             |
| `5xx`  | server error                              | `{"message": "Server Error"}` (with `APP_DEBUG` off); excubia-core keeps batches and retries       |

Every error body has a `message` string. With `APP_DEBUG=true` (never in production) framework errors such as `405`, `429` and `5xx` also carry `exception`, `file`, `line` and `trace`. A `422` also has `errors`: the keys are field paths of the envelope with list indexes as numbers (`runtime.aggregates.0.count`), the values lists of messages. An unsupported protocol version answers:

```json
{
    "message": "Unsupported protocolVersion. This Excubia app accepts: 1. Update qiick/excubia-core or the app.",
    "errors": {
        "protocolVersion": [
            "Unsupported protocolVersion. This Excubia app accepts: 1. Update qiick/excubia-core or the app."
        ]
    }
}
```

and an unsupported report schema version answers the same way under `report.schemaVersion` (`Unsupported report schemaVersion. This Excubia app accepts: 1.`).

excubia-core maps the status to its exit code (`3` for every non-2xx) and prints `excubia-app rejected the project token (HTTP 401)` for `401` / `403`, `excubia-app did not accept the report (HTTP <status>)` otherwise.

### Unknown fields and types

- Unknown keys anywhere in the envelope or payload are accepted and ignored, so a newer core with additive fields keeps pushing.
- `/api/runtime`: an aggregate whose `type` the app does not know (sent by a newer core) only needs a string `type`. It is skipped, counted on the project (`skipped_runtime_aggregates`, shown on Settings → System) and never stored, and the push still answers `201` (or `200`) for the known types.
- New check ids in a report are stored and shown under their own id.

## `POST /api/reports`

Envelope: `protocolVersion`, `batchId`, `sentAt` (UTC, `Y-m-d\TH:i:s\Z`), `report`. Payload: [`report-schema.json`](https://github.com/qiick-io/excubia-core/blob/develop/docs/report-schema.json) version 1. The app requires `report.schemaVersion`, `report.checks` (each with `checkId`, `name`, `status` and `findings`) and `report.upgradePlan`; `generatedAt`, `release`, `deployedAt`, `schedule` and `environment` are optional. `environment` (excubia-core 1.0.0-beta.4, `php artisan excubia:check` / `excubia:push` only) is the pushing app's `app.env`: a value that does not match `^[A-Za-z0-9_-]{1,64}$` is dropped, never rejected, and a report without it keeps the project's last known environment.

```http
POST /api/reports HTTP/1.1
Authorization: Bearer <project token>
Content-Type: application/json
Accept: application/json

{"protocolVersion": 1, "batchId": "6f1c2b8e-4d3a-4f5b-9c7d-1e2f3a4b5c6d", "sentAt": "2026-11-07T08:00:00Z",
 "report": {"schemaVersion": 1, "checks": [], "upgradePlan": []}}
```

```http
HTTP/1.1 201 Created
Content-Type: application/json

{"status": "stored", "reportId": 42}
```

## `POST /api/runtime`

Envelope (`$defs.runtimeEnvelope`): `protocolVersion`, `type: "runtime"`, `batchId`, `sentAt`, `runtime` with `bucketSeconds` (`60`), `aggregates` (a list, may be empty), an optional `release` and an optional `environment` (excubia-core 1.0.0-beta.4, the same pattern and rules as the report's). Payload: [`runtime-schema.json`](https://github.com/qiick-io/excubia-core/blob/develop/docs/runtime-schema.json). Each aggregate of a known type must carry `bucket`, `sampleRate`, `count`, `errorCount`, the dimensions and metrics of its type, and, for timed types, `totalMs`, `maxMs` and an optional 12-bucket `histogram`. Aggregates are additive: the app sums entries with the same type, bucket, sample rate and dimensions, also across batches.

```json
{
    "protocolVersion": 1,
    "type": "runtime",
    "batchId": "6f1c2b8e-4d3a-4f5b-9c7d-1e2f3a4b5c6d",
    "sentAt": "2026-11-07T08:00:00Z",
    "runtime": { "bucketSeconds": 60, "aggregates": [] }
}
```

Answers `201 {"status": "stored"}` or `200 {"status": "duplicate"}`.

## `POST /api/exceptions`

Envelope (`$defs.exceptionsEnvelope`): `protocolVersion`, `type: "exceptions"`, `batchId`, `sentAt`, `exceptions` with `groups` (a list, may be empty) and an optional `release`. Payload: [`exception-schema.json`](https://github.com/qiick-io/excubia-core/blob/develop/docs/exception-schema.json). Each group carries `class`, `fingerprint` (64 hex characters), the masked `message`, `frames` (paths relative to the project), `previous`, `context` (`kind`, `name`), `environment`, `count`, `firstSeen` and `lastSeen`. Groups are additive: the app adds `count` and widens `firstSeen` / `lastSeen` for the same fingerprint.

```json
{
    "protocolVersion": 1,
    "type": "exceptions",
    "batchId": "6f1c2b8e-4d3a-4f5b-9c7d-1e2f3a4b5c6d",
    "sentAt": "2026-11-07T08:00:00Z",
    "exceptions": { "groups": [] }
}
```

Answers `201 {"status": "stored"}` or `200 {"status": "duplicate"}`.

## Versioning policy

- **1.x is additive only.** Within 1.x the app may add an endpoint, accept a new optional field, accept a new runtime type or check id, or add a key to a success body. It never removes or renames an endpoint, a field it accepts or a key it answers, never makes an optional field required, never narrows a value it accepts, and never changes a status code or the shape of an error body (`message`, and `errors` on a `422`).
- **Protocol version 1 for the whole 1.x line.** A breaking change to the envelope or a payload would be `protocolVersion` 2, shipped only in a new major of both the app and the core.
- **Frozen by tests.** `tests/Feature/Api/IngestApiContractTest.php` sends every case above to every endpoint and compares the status and body with `tests/Fixtures/ingest-api-contract.json`; `scripts/e2e/core-contract.sh` pushes with a real excubia-core. A change to the snapshot must come with a CHANGELOG entry and an update of this page.
