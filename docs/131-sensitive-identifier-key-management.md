# 131. Sensitive identifier key management and rotation

Data: 2026-09-12

Status: **ACTIVE HARDENING AUTHORITY**

## 1. Scope

This decision covers recoverable sensitive identifiers already used by core v1:

- Student PESEL,
- local course-scoped PKK identity stored in `pkk_profiles`.

It does not enable or specify provider-backed PKK runtime. PWPW/provider credentials, live calls, provider signatures, status mapping and reconciliation remain deferred under `docs/129-pkk-deferred-pending-pwpw-guidance.md`.

## 2. Encryption and lookup are separate cryptographic purposes

Recoverable values are encrypted through Laravel's application encrypter.

Encryption key ring:

- current write key: `APP_KEY`,
- read-only rollover keys: `APP_PREVIOUS_KEYS`,
- new writes always use the current key,
- old ciphertext may be read only while the required previous key remains configured.

Exact equality / collision lookup uses a separate HMAC key ring:

- current write key: `SENSITIVE_IDENTIFIER_LOOKUP_KEY`,
- rollover keys: `SENSITIVE_IDENTIFIER_PREVIOUS_LOOKUP_KEYS`,
- the current lookup key **must not equal** `APP_KEY`,
- current and previous lookup keys must contain at least 32 bytes of high-entropy secret material,
- new hashes always use the current lookup key,
- equality and duplicate checks evaluate hashes derived from the current key and every explicitly configured previous key.

A plain SHA-256 identifier hash is forbidden.

## 3. Rotation sequence

### Encryption key rollover

1. Provision the new `APP_KEY` in the secret manager.
2. Move the prior application key to `APP_PREVIOUS_KEYS`.
3. Deploy and verify that old ciphertext decrypts and new writes use the current key.
4. Re-encryption of retained ciphertext is a separate reviewed maintenance operation.
5. Remove an old encryption key only after no retained ciphertext requires it.

### Lookup HMAC rollover

1. Provision a new independent `SENSITIVE_IDENTIFIER_LOOKUP_KEY`.
2. Keep the prior lookup key in `SENSITIVE_IDENTIFIER_PREVIOUS_LOOKUP_KEYS`.
3. Deploy before accepting writes under the new key.
4. New identifiers write only the new HMAC.
5. Equality/collision checks use current + previous candidates, so an old-row hash cannot be bypassed by rotating the current key.
6. A previous lookup key may be retired only after the governed data has left retention or an explicitly reviewed re-key process has removed every dependency on that key.

## 4. Formal-history rule

Cryptographic rollover is not a business edit.

The system must not fabricate a new PKK identity revision merely because the HMAC key changed. Existing Stage-4 PKK lineage and immutable formal history remain authoritative.

Any future bulk re-key procedure affecting formal PKK rows requires a dedicated audited maintenance design. It must not be smuggled into ordinary CourseEnrollment update flow.

## 5. Fail-closed behavior

Sensitive identifier writes fail when:

- the current lookup key is missing,
- it is shorter than the minimum key-material requirement,
- it equals `APP_KEY`,
- previous-key configuration is malformed.

No fallback from the dedicated lookup key to `APP_KEY` is allowed.

## 6. Secret storage and operations

Production key material:

- is injected from a secret manager or equivalent protected deployment environment,
- is never committed to Git,
- is separate per environment,
- is access-controlled with least privilege,
- has an owner and rotation record outside application business data.

Logs, audit events and activity projections must never contain these keys or plaintext PESEL/PKK.

## 7. Executable evidence

Hardening tests verify:

- lookup HMAC differs from the `APP_KEY`-derived hash,
- previous-key rollover still blocks duplicate PESEL within the tenant,
- a PKK value written under the old lookup key remains semantically equal after rollover,
- the rollover does not create a fake PKK identity revision.

This document resolves the key-management design blocker in `docs/95-open-items-severity.md`; privacy/retention, legal category re-verification, backup/restore and business RPO/RTO remain separate production-hardening gates.
