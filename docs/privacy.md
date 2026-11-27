# Privacy

See the [security model](SECURITY-MODEL.md) for Excubia's data flows, access controls and retention.

## Local passwords

Excubia stores the bcrypt hash of a local password in `users.password` to verify sign-ins. A password change replaces that hash. Account erasure replaces it with a hash of a random value and deactivates the account.

When the Strong policy checks a new password, the server sends a padded HTTPS range request to `api.pwnedpasswords.com`, operated by Have I Been Pwned. The request carries the first five hexadecimal characters of the password's SHA-1 digest and ordinary network metadata such as the app server's IP address. Excubia compares the returned suffixes locally. [Have I Been Pwned API documentation](https://haveibeenpwned.com/API/v3#PwnedPasswords).

The lookup is transient. If the provider is unavailable, the application log records a generic warning and the HTTP status when available.
