# Privacy

What personal data Excubia stores, why, for how long, where it goes, and how a person's data is
erased (fleet convention F29). Excubia sends no telemetry. The monitored projects push health,
runtime and exception data, which is about software, not people, except where noted below.

## Personal data

| Table and columns | What and why | Kept until |
| --- | --- | --- |
| `users`: `first_name`, `last_name`, `email`, `username`, `last_login_at`, `last_login_ip`, `locale`, `timezone`, password and two-factor secrets (hashed or encrypted) | The dashboard account and its sign-in | Erasure |
| `sessions`: `user_id`, `ip_address`, `user_agent` | Signed-in sessions | Session lifetime, or erasure |
| `password_reset_tokens`: `email` | A pending password reset | 60 minutes, or erasure |
| `passkeys` | Passkey credentials and their names | Erasure |
| `user_identities` (qiick/laravel-oidc) | The SSO subject linked to the account | Erasure |
| `agent_conversations`, `agent_conversation_messages` | Assistant chats (the assistant is off by default) | Erasure, or the user deleting them |
| `notifications` | In-app alert notifications | Erasure |
| `principals`: `name` | The user's principal (qiick/laravel-access), owner of API keys | Anonymised at erasure; the row stays as an erased tombstone |
| `api_keys`: `name`, `description`, `last_used_ip`, `last_used_user_agent`, `created_by`, `revoked_by`, `revocation_note` | Personal API keys and who acted on them | Anonymised at erasure; the revoked row stays |
| `activity_log`: `causer_*`, `subject_*`, `properties`, `attribute_changes` | The audit trail of changes and sign-ins | Data retention setting (365 days by default); at erasure the person's values in the payloads are replaced by `[erased]` |
| `webhook_deliveries` (qiick/laravel-outbound): `subject_public_id`, the encrypted payload | An alert webhook about an assigned finding names the assignee | Payload 30 days at most; at erasure a pending delivery is cancelled and every payload about the person is dropped |
| `alert_logs.summary`, other users' `notifications.data`, queued alert messages | An alert about an assigned finding names the assignee | Alert history retention; at erasure the name is replaced by `[erased]` and queued alert messages that name them are dropped (database queue) |
| `findings.assigned_user_id`, `exception_groups.assigned_user_id` | Who works on a finding or an exception | The assignment, or erasure (the link points at the tombstone) |
| `erasure_requests`: `subject` (encrypted), `user_id` | An erasure in progress | The end of the erasure: both become null when the case closes |

**Outbound destinations.** Alert channels the operator configures (Slack, Telegram, email, the
alert webhook) receive alert text, which names an assignee. An AI provider receives questions and
report data only when the operator turns the assistant on; tools never return user data.

## Export

An admin reads a person's account in Users and their activity in the activity log (filter by
user). There is no self-service export yet.

## Erasure

Deleting a user (Users → Delete, or Profile → Delete account) runs the resumable erasure of the
qiick fleet erasure standard (`.qiick/standards/erasure.md`, `ErasureOrchestratorService`):

0. In one transaction under the user's row lock, after the last-super-admin check: the
   `erasure_requests` row with the encrypted snapshot is written, and the person is locked out
   (inactive, unusable password, sessions deleted, principal erased so every API key stops).
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
   alert history and in other users' notifications; queued alert messages that name them are
   dropped.
6. Access: the user row becomes an anonymised, soft-deleted tombstone (`deleted+<public id>@…`,
   `deleted_<public id>`, every personal column cleared, locale and timezone reset); the principal stays erased with an
   anonymised name.
7. Close: one `erasure_completed` audit entry (case id and counts, no personal data), then the
   case is `completed` and its snapshot and user link are dropped.

Only one executor advances a case (a claim with a 15-minute lease, checked at every checkpoint
and at the close), and once step 0 commits, a write that a request authorised earlier (profile,
a new API key, an admin's update) is refused. A step that fails leaves the case `failed` with a
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
- Alert texts already sent to Slack, Telegram or email stay with those services. Queued alert
  messages are dropped on the `database` queue only; with Redis, a message already queued is
  still sent.
- There is no legal hold or restriction (Art. 18) switch yet; answering within one month is the
  operator's duty.
