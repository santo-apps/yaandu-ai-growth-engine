# Phase 2D — Meeting scheduling

## Scope and current status

Phase 2D builds on the existing tenant scheduling configuration, `SchedulingProviderInterface`, provider router, fake provider, meeting model, and conversation workspace. The old direct timestamp booking flow has been removed from API routing. Internal, human-assisted scheduling is implemented around persisted scheduling requests and offered slots. Public selection and live calendar providers are out of scope.

## Workflow

1. An assigned conversation owner or tenant owner/admin creates a request for a conversation whose sales context has raised meeting intent.
2. The request records its tenant, conversation, linked opportunity/contact, owner, requested timezone, duration, expiry, actor, and correlation ID.
3. Availability comes from the tenant's allowlisted provider. Application rules apply notice, horizon, weekdays, working hours, duration, buffers, and tenant meeting conflicts. At most five candidate slots are returned; no private event details are returned.
4. Each slot is stored against that request and expires with it. Selection accepts only an offered slot ID. Booking requires selection and rechecks provider availability.
5. Booking uses a stable provider idempotency key and a unique booking per scheduling request. Provider success precedes the `SCHEDULED` record, opportunity stage/activity update, conversation system event, and audit record.
6. Cancellation is an explicit authenticated action and calls the provider before persisting cancellation. Rescheduling is deferred.

Scheduling request states: `REQUESTED`, `AWAITING_SELECTION`, `SELECTED`, `BOOKING`, `BOOKED`, `CANCELLED`, `EXPIRED`, and `FAILED`. Meeting states currently used: `SCHEDULED` and `CANCELLED`; human completion/no-show updates are not yet exposed.

## Provider abstraction

`SchedulingProviderInterface` abstracts availability, booking, retrieval, cancellation, and rescheduling operations. `SchedulingProviderRouter` selects an enabled tenant provider only when it is present in `SCHEDULING_ENABLED_PROVIDERS`. Only `FakeSchedulingProvider` is implemented and enabled by default. It produces deterministic business-hour availability and idempotent fake bookings. Google Calendar, Microsoft Outlook, and Calendly integrations are not implemented or verified.

## Tenant settings and timezones

Tenant settings include IANA tenant and calendar-owner timezones, 30/45/60 minute duration, buffers, minimum notice, booking horizon, allowed ISO weekdays, working hours, title/description templates, and a tenant member as the default owner. Settings changes require an owner/admin. The owner timezone governs provider queries and working-hour policy; the request timezone governs displayed slot times and can be supplied by a human when the prospect's timezone is known. If it is not supplied, the explicitly configured tenant timezone is used; no prospect timezone is inferred. Meeting instants are stored in UTC with both owner and display timezone snapshots. Date arithmetic uses PHP/Carbon timezone support so DST transitions use the timezone database.

## Security and consistency

- All scheduling endpoints require Sanctum and active tenant resolution.
- Scheduling mutations require an assigned conversation owner or tenant owner/admin; settings require owner/admin.
- Requests, slots, meetings, opportunities, conversations, and contacts are tenant-scoped in queries and composite foreign keys.
- Booking accepts a persisted selected slot ID, never caller-provided timestamps.
- Provider event details/tokens are not exposed. Meeting URL fields are tenant-authenticated only and currently empty for the fake provider.
- Provider calls are limited to the fake provider. Provider failure does not mark a meeting booked; a safe failure state is persisted.
- No provider callback or public token endpoint exists; signature/replay controls are therefore deferred with callbacks.

## UI and API

The conversation workspace exposes settings, availability generation/refresh, slot selection, explicit booking, and meeting summaries. Dashboard request/booked/upcoming counts query the tenant's database records.

Authenticated API routes are registered under `/api/v1`:

- `GET/PUT /scheduling-configuration`
- `POST /conversations/{conversation}/scheduling-requests`
- `POST /scheduling-requests/{request}/availability`
- `POST /scheduling-requests/{request}/select-slot`
- `POST /scheduling-requests/{request}/book`
- `GET /meetings`, `GET /meetings/{booking}`, `POST /meetings/{booking}/cancel`

## Verification boundary and remaining work

The SQLite PHPUnit suite passes **96 tests / 527 assertions**. Scheduling feature coverage includes offered-slot-only booking, idempotent retries, expired requests, cross-tenant request/meeting access, unassigned-member denial, fake-provider failure recovery, cancellation, and the legacy arbitrary-timestamp route being absent. PHP syntax checks, route registration, Vue TypeScript compilation, and the Vite production build pass. PostgreSQL migrations through `2026_10_05_000020` are applied and `migrate:status` reports them as ran.

Live calendar APIs, external invite delivery, public scheduling links, meeting URL integration, and rescheduling remain unverified or deferred. This is a controlled scheduling foundation, not a general calendar application. Production deployment/security hardening is still required.
