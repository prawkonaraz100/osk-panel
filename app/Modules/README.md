# Application modules

This directory is the modular-monolith boundary for OSK Panel application modules.

S5-FOUND-001 materializes the first core foundation only:
- IdentityTenant: authenticated principal, active tenant membership, permission + per-permission scope, membership governance.
- OrganizationSettings: optimistic-concurrency settings transaction for confirmed basic/company/address fields.
- AuditNotification: minimal atomic audit + domain-event + outbox boundary required by critical foundation mutations.

Later core modules remain intentionally absent until their dedicated implementation slices.
