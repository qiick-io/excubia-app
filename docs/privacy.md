# Privacy

What personal data Excubia stores, why, for how long, where it goes, and how a person's data is
erased (fleet convention F29). Excubia sends no telemetry. The monitored projects push health,
runtime and exception data, which is about software, not people, except where noted below.

## Personal data

| Table and columns                                                                                                                                                      | What and why                                                   | Kept until                                                                                                                                                                                                                         |
| ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- | -------------------------------------------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `users`: `first_name`, `last_name`, `email`, `username`, `last_login_at`, `last_login_ip`, `locale`, `timezone`, password and two-factor secrets (hashed or encrypted) | The dashboard account and its sign-in                          | Erasure                                                                                                                                                                                                                            |
| `sessions`: `user_id`, `ip_address`, `user_agent`                                                                                                                      | Signed-in sessions                                             | Session lifetime, or erasure                                                                                                                                                                                                       |
| `browser_sessions`: `public_id`, `user_id`, `ip_address`, `user_agent_summary`, `is_remembered`, `authenticated_at`, `last_activity_at`, `expires_at`, `revoked_at`    | Session list and revocation history                            | Unrevoked rows expire at the session deadline. Revoked rows are pruned one absolute session lifetime after revocation. Erasure deletes both immediately.                                                                           |
| `password_reset_tokens`: `email`                                                                                                                                       | A pending password reset                                       | 60 minutes, or erasure                                                                                                                                                                                                             |
| `passkeys`                                                                                                                                                             | Passkey credentials and their names                            | Erasure                                                                                                                                                                                                                            |
| `user_identities` (qiick/laravel-oidc)                                                                                                                                 | The SSO subject linked to the account                          | Erasure                                                                                                                                                                                                                            |
| `agent_conversations`, `agent_conversation_messages`                                                                                                                   | Assistant chats (the assistant is off by default)              | Erasure, or the user deleting them                                                                                                                                                                                                 |
| `notifications`                                                                                                                                                        | In-app alert notifications                                     | Erasure                                                                                                                                                                                                                            |
| `principals`: `name`                                                                                                                                                   | The user's principal (qiick/laravel-access), owner of API keys | Anonymised at erasure; the row stays as an erased tombstone                                                                                                                                                                        |
| `api_keys`: `name`, `description`, `last_used_ip`, `last_used_user_agent`, `created_by`, `revoked_by`, `revocation_note`                                               | Personal API keys and who acted on them                        | Anonymised at erasure; the revoked row stays                                                                                                                                                                                       |
| `activity_log`: `causer_*`, `subject_*`, `properties`, `attribute_changes`                                                                                             | The audit trail of changes and sign-ins                        | Data retention setting (365 days by default); at erasure the person's values in the payloads are replaced by `[erased]`                                                                                                            |
| `webhook_deliveries` (qiick/laravel-outbound): `subject_public_id`, the encrypted payload                                                                              | An alert webhook about an assigned finding names the assignee  | Payload 30 days at most; at erasure a pending delivery is cancelled and every payload about the person is dropped                                                                                                                  |
| `alert_logs.summary` and `subject_public_id`, other users' `notifications.data`, queued alert messages                                                                 | An alert about an assigned finding names the assignee          | Alert history retention; at erasure the alerts are found by the person's public id (never by text), the name becomes `[erased]`, a message still queued sends `[erased]` instead, and failed alert jobs that name them are deleted |
| `findings.assigned_user_id`, `exception_groups.assigned_user_id`                                                                                                       | Who works on a finding or an exception                         | The assignment, or erasure (the link points at the tombstone)                                                                                                                                                                      |
| `erasure_requests`: `subject` (encrypted), `user_id`                                                                                                                   | An erasure in progress                                         | The end of the erasure: both become null when the case closes                                                                                                                                                                      |

When browser session management is available, the session list shows the IP address and a summary of the browser and operating system.

**Outbound destinations.** Alert channels the operator configures (Slack, Telegram, email, the
alert webhook) receive alert text, which names an assignee. An AI provider receives questions and
report data only when the operator turns the assistant on; tools never return user data. The Strong password policy sends a 5-character SHA-1 prefix to Have I Been Pwned (see Local passwords).

## Local passwords

Excubia stores the bcrypt hash of a local password in `users.password` to verify sign-ins. A password change replaces that hash. Account erasure replaces it with a hash of a random value and deactivates the account.

When the Strong policy checks a new password, the server sends a padded HTTPS range request to `api.pwnedpasswords.com`, operated by Have I Been Pwned. The request carries the first five hexadecimal characters of the password's SHA-1 digest and ordinary network metadata such as the app server's IP address. Excubia compares the returned suffixes locally. [Have I Been Pwned API documentation](https://haveibeenpwned.com/API/v3#PwnedPasswords).

The lookup is transient. If the provider is unavailable, the application log records a generic warning and the HTTP status when available.

## Uploaded images

If the operator [enables upload scanning](self-hosting.md#upload-malware-scanning), Excubia streams the bytes of new project logos and branding images to the configured ClamAV daemon before saving them. It sends only the image bytes and the scanner protocol framing. It logs generic warnings for detections and unavailable scans. The operator controls the daemon and its network access.

## Export

An admin reads a person's account in Users and their activity in the activity log (filter by
user). There is no self-service export yet.

## Erasure

Deleting a user (Users → Delete, or Profile → Delete account) runs the resumable erasure of the
qiick fleet erasure standard (`.qiick/standards/erasure.md`, `ErasureOrchestratorService`):

0. In one transaction under the user's row lock, after the last-super-admin check: the
   `erasure_requests` row with the encrypted snapshot is written, and the person is locked out
   (inactive, unusable password, active and revoked session records deleted, principal erased so every API key stops).
1. API keys (`ApiKeysEraser`, `anonymise`): every owned key is revoked and made inert (new UUID,
   token material replaced, free text, usage and restrictions cleared); attribution on other keys
   is cleared.
2. SSO identities and logout state (`OidcEraser`, `delete`).
3. Webhook deliveries (`OutboundEraser`, `delete`): pending ones about the person are cancelled,
   payloads dropped.
4. Idempotency records (`HttpBasicsEraser`, `delete`; none while they live in the cache store).
5. Excubia's own data: assistant chats, passkeys, their reset token and notifications deleted;
   `[erased]` replaces their values in the activity log (their own model events, the request IP
   and user agent of the rows they caused, failed logins under their email or username), in the
   alerts that name them, found by their public id (history, other users' notifications; a
   queued message redacts the name when it runs, on any queue; failed alert jobs are deleted).
6. Access: the user row becomes an anonymised, soft-deleted tombstone (`deleted+<public id>@…`,
   `deleted_<public id>`, every personal column cleared, locale and timezone reset); the principal stays erased with an
   anonymised name.
7. Close: one `erasure_completed` audit entry (case id and counts, no personal data), then the
   case is `completed` and its snapshot and user link are dropped.

Only one executor advances a case: a claim with a 15-minute lease, and every step runs in the
transaction that locks the case and checks the claim, together with its checkpoint, so an
executor that lost the case neither runs a step nor writes. A step is `done` only after its
commit and its after-commit work (cache entries forgotten); a step left `committed` by a crash
runs again. Alert texts cut the summary, never the assignee's name, and an alert, a queued
message or a webhook naming a person reads their row under a shared lock first, so the erasure
either waits for it or it sees the person erased. Once step 0 commits, a write that a request authorised earlier (profile, a new API key, an admin's update, the usage
stamp of a key) is refused or skipped. A step that fails leaves the case `failed` with a
code and logs only the case id, the step and the exception class; `excubia:resume-erasures` (hourly, also
`--dry-run`) replays the steps not done, from the snapshot. A snapshot that no longer decrypts (a
changed `APP_KEY`) is marked `snapshot_unreadable`, logged as an error and listed by every run of
the command, which then fails; it needs an admin.

### Deviation from the standard: a tombstone instead of a hard delete

The standard's step 5 runs `AccessEraser` and then `forceDelete()`s the user. Excubia keeps the
user row as an anonymised, soft-deleted tombstone and marks the principal erased
(`PrincipalDirectory::markErased()`) instead, and erases API keys in `anonymise` mode. Reasons:

- **Audit integrity.** Activity rows, revoked API keys, assignments and group history reference
  the user and the principal. A hard delete would cascade or orphan them; the trail must stay
  readable ("deleted user"), only without the person's data (conformance check C17).
- **RESTRICT foreign keys.** `api_keys.owner_id` and `api_keys.owner_principal_id` are
  `RESTRICT`, so a key outlives its owner as evidence; the tombstone and the erased principal are
  what they point at, and neither can authenticate again.

The tombstone holds no personal data: every personal column is replaced or cleared, and the email
and username become unique values derived from the public id, so the person can be invited again.

### Not covered by the erasure

- Backups and logs keep their own retention; restore drills must not bring an erased person back.
- Alert texts already sent to Slack, Telegram or email stay with those services.
- There is no legal hold or restriction (Art. 18) switch yet; answering within one month is the
  operator's duty.
