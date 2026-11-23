# CM Relay - Technical Whitepaper

## What this is

CM Relay is a connectivity hub that lets a small, independent accommodation provider - running its own system, not a SaaS platform - reach real OTA distribution (Booking.com, Airbnb, and others via Channex) without losing ownership of its own calendar, prices, or guest data.

It grew out of a real incident, not an abstract principle: a double booking caused by Booking.com's own error, made worse by their unresponsiveness in actually fixing it. The guests involved - from Malta - were understandably upset at first. But in the course of working through it, offering an alternative stay, it became clear they weren't attached to "some accommodation" - they were attached to that specific place. They ended up cancelling and resolving it themselves, booking a pricier alternative, with far more grace than the situation demanded of them. That's the moment CM Free (and everything built since) actually started: a system that treats a host's own calendar as the one source of truth, so a platform's own sync failure doesn't get to decide who else's trip gets ruined.

CM Relay extends that same principle to OTA connectivity itself: operate under a single vetted, reviewed Channex reseller agreement, and share the real cost of that agreement - fixed platform fee plus a genuinely degressive per-unit rate - across every participant who joins, CM-software user or not. One contract review done once, shared honestly, rather than every small host separately absorbing a channel manager's full minimum fee alone.

Everything described below is real, deployed code - not a roadmap.

## How participation works

There's no public self-serve signup - every property is personally reviewed before it touches a live OTA account, the same discipline Channex itself applied before granting CM Relay reseller access in the first place. Once invited:

```
POST /api/v1/signup/request
Body: {
  invite_code: "<from your invite link>",
  property: { title, currency, country, city, property_type? },
  units: [ { title, rate, occ_adults?, occ_children? }, ... ],
  liability: { accepted: true, signer_name, signer_email }
}
```

This only ever queues a submission for human review - it never touches Channex directly. A confirmation link, sent only after approval, is the one and only way a real bearer token gets issued (never returned directly from an API response, so a leaked review-queue entry can't leak a usable credential).

### Pricing

Computed from real unit count, not negotiated case by case - the same table applies to every participant:

| Units | Price/unit/month |
|---|---|
| 1st-3rd | €3.00 |
| 4th-6th | €2.55 |
| 7th-10th | €2.10 |
| 11th+ | €1.65 |

Plus a one-time connection fee: €30 (property + first unit) + €3/additional unit.

Example: a 20-unit property works out to 3×€3.00 + 3×€2.55 + 4×€2.10 + 10×€1.65 = **€41.55/month**, plus a one-time €87 connection fee.

## Authentication

Every endpoint below (except signup) requires:

```
Authorization: Bearer <installation token>
```

One token per installation - no shared/global secret, no cross-tenant access. Revocable and rotatable independently of any other participant.

## Mapping rooms to OTA rate plans

```
POST /api/v1/mappings
Body: {
  cm_property_id, provider: "channex", provider_property_id,
  cm_unit_id, provider_room_type_id, provider_rate_plan_id,
  price_corrector_pct?
}
```

A single room type can have multiple mappings - this is how independently-priced rate plans (e.g. refundable vs. non-refundable) work: two mappings for the same room, each pointing at its own channel rate plan, each with its own `price_corrector_pct`. The underlying stored price is pushed to both, adjusted independently on the way out, never modified at the source.

- `GET /api/v1/mappings` lists current mappings and correctors.
- `DELETE /api/v1/mappings?unit_mapping_id=<id>` removes a stale one, scoped strictly to the authenticated installation.

## Pushing availability, prices, and restrictions

```
POST /api/v1/commands
Body: {
  type, unit: "<cm_unit_id>", reason: "price" | "availability" | "min_stay" | "full_sync",
  idempotency_key, payload
}
```

A background worker drains this queue and pushes to the channel provider, respecting its account-wide rate limit (shared across all participants, not per-installation). Concretely:

- `reason: "price"` - a date→price map, pushed per mapping with its own corrector applied.
- `reason: "availability"` / `"min_stay"` - real occupancy segments plus min/max nights; multiple pending pushes for the same room collapse into one (each payload is already a full fresh snapshot, not a diff, so only the newest matters).
- `reason: "full_sync"` - the first push when a mapping goes live: a full forward window of rates + availability + restrictions in exactly two API calls, matching the channel provider's own certification requirement for a batched sync rather than a slow drip.

Availability is keyed by room type (shared across every rate plan on that room); price and restrictions are keyed per rate plan, so independently-priced plans genuinely differ.

### Occupancy-based pricing (different price per guest count)

For a rate plan that needs a different price for 1 guest vs. 2 guests on the same room and date, `reason: "price"` accepts `payload.prices_by_occupancy` as an alternative to the flat `payload.prices`:

```json
{
  "prices_by_occupancy": {
    "2026-12-24": { "1": 70.00, "2": 80.00 }
  }
}
```

Each occupancy tier gets the same per-mapping corrector applied as flat prices, and is pushed using the channel provider's native occupancy-pricing mechanism (confirmed, not assumed - verified directly against a real rate plan on production) rather than averaged into a single number.

One real constraint worth knowing up front: an occupancy-priced rate plan has to be created that way from the start - the channel provider does not allow converting an existing flat-rate plan to occupancy pricing later. If this matters for a specific property, raise it before the initial mapping is created, not after.

## Getting reservations back - polling with acknowledgement, not raw webhook delivery

```
GET /api/v1/events
```

Returns queued events (`reservation.created` / `.modified` / `.cancelled`, plus a few operational ones), each already normalized - never the raw, unverified webhook body from the channel provider. Events stay queued until explicitly acknowledged:

```
POST /api/v1/events_ack
Body: { event_id }
```

Nothing is deleted on acknowledgement, only marked - so a reservation that arrives while a participant's own system is briefly offline is still there, unmissed, on the next poll.

## Checking whether a channel is actually live

```
GET /api/v1/channel_status?cm_unit_id=<id>
```

A mapping existing doesn't guarantee the channel itself is active (e.g. approved but not yet switched on). This checks live against the provider directly and reports real status per mapping.

## Adding a property or room after connecting

```
POST /api/v1/properties/request
Body (kind="provision"): { cm_property_id, property: {...}, units: [...] }
```

Same human-review discipline as initial signup - queues a request, never acts on the channel provider directly. Poll:

```
GET /api/v1/properties/request_status?cm_property_id=<id>
```

## What's intentionally not promised yet

- **A ready-made sandbox/test environment** - not something generally available today; worth a direct conversation for any specific onboarding.
- **A standing, signed DPA template** - infrastructure runs on EU-hosted servers, but the actual data-processing agreement paperwork is handled per relationship, not auto-generated.
- **Occupancy-based pricing has not yet carried real traffic** - the mechanism (see above) is built and verified against the channel provider's real API shape, but no property has actually pushed occupancy-tiered prices through it yet. First real use will be the first real test, not a formality.

Everything else above is live, running code - happy to walk through any part of it in more depth or arrange a live look at how it actually behaves.
