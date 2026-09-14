# 190. CORE-V1-ORGANIZATION-SETTINGS-PROVIDER-NEUTRAL-AUTHORITY-001

Data: 2026-09-14

**Status:** `IN_VALIDATION`

## Decision

PrawkoNaRaz OSK must operate fully without importing, fetching or configuring PKK.

PKK/PWPW is an optional integration bounded context. Its absence may block only explicit PKK integration actions.

The following operations are provider-neutral:
- `PATCH /api/v1/organization`
- `GET /api/v1/organization/settings`
- `PATCH /api/v1/organization/settings`

## API ownership correction

`osk_registry_number` is removed from generic `Organization` and `UpdateOrganizationRequest`; its sole owner is `pkk_integration_settings.osk_registry_number`.

The ordinary settings projection no longer embeds PKK fields. A settings page may compose the optional PKK section from the dedicated `/organization/integrations/pkk` API, but failure, absence or freeze of that integration cannot fail the provider-neutral settings request.

## Provider-neutral settings

`GET /organization/settings` reads only User, Organization, organization contact address, organization_settings and accepted terms authorities. It performs no PKK table read and no provider call.

`PATCH /organization/settings` may change supported user names and company/address fields under `organization_settings.version` optimistic concurrency. It must not create or mutate `pkk_integration_settings`.

## Email boundary

The current database authority explicitly leaves email-change reverification policy pending. Therefore this gate does not invent email mutation. E-mail remains readable in settings, while changing it requires a later dedicated identity email-change authority.

## PKK freeze

All dedicated PKK/PWPW routes and provider behavior remain `FROZEN_UNTIL_EXPLICIT_UNFREEZE`.

## Runtime-independent service rule

Organization lifecycle, ordinary settings, manual student creation, training, calendar, internal exams, documents, licenses and other provider-neutral workflows must not acquire a hidden PKK prerequisite.

## Scope

No schema/migration change and no PKK runtime activation occur in this authority gate.

## Closure classification

After this authority is PASS, a fresh closure audit must reclassify `organization.update`, `organization.settings.get` and `organization.settings.update` as implementation-ready. The 14 explicit PKK bindings remain frozen.
