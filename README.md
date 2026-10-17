# CM Relay

The connectivity hub for [CM Free / CM Plus](https://github.com/viljemd-hub/channel-manager) installations — the piece that lets a small, self-hosted channel manager talk to the big OTA world (Airbnb, Vrbo, Booking.com, and beyond) without giving up ownership of its own calendar, prices, or guest data.

> Own your calendar, own your data — and still show up everywhere a guest might look.

## Why this exists

CM Free/Plus is built around the idea that a small accommodation provider shouldn't need a SaaS subscription just to keep their own calendar straight. But OTA distribution is a different problem: Airbnb, Vrbo and Booking.com don't talk ICS-only self-hosted apps directly — they talk to certified channel managers through providers like Channex.

CM Relay is that bridge. It's a thin, provider-agnostic hub that sits between any number of self-hosted CM installations and whichever OTA connectivity provider is behind it today. Local CM stays the source of truth for reservations, prices and availability — Relay only ever carries what needs to go out, and what comes back.

## What's real today

This isn't a skeleton — it's running production traffic:

- **Channex PMS certification passed**, subscribed to their production platform. Not a sandbox exercise — real properties, real OTA accounts.
- **Live two-way sync** proven end-to-end: a price change in a local CM calendar reaches the OTA's own listing within the same minute; availability (including admin-side manual blocks) stays consistent in both directions.
- **Per-channel price correction** — one CM price, independently adjusted per OTA (e.g. to absorb a platform's commission) without ever touching CM's own stored price.
- **Channel-presence confirmation gate** — before a direct-booking confirmation code goes out to a guest, Relay independently verifies the block is actually visible on every connected channel first. Closes the exact race condition that makes direct bookings risky without a channel manager.
- **Per-installation bearer tokens**, no global master secret. Every installation can be revoked or rotated independently.

Provider support today: **Channex** (Vrbo live, Airbnb in progress, Booking.com paused pending their Extranet step — see `docs/cm_relay/`). The provider interface (`src/providers/CmConnectivityProviderAdapter.php`) is deliberately adapter-based, so a second provider (Beds24, WuBook, or a direct OTA integration) is an additional implementation, not a rewrite.

## Architecture

```text
                         CM ECOSYSTEM
                              │
                              ▼
                           CM Relay
                              │
        ┌─────────────────────┼──────────────────────┐
        │                     │                      │
        ▼                     ▼                      ▼
 OTA Connectivity        CM Community          Shared Services
        │                     │                      │
 Vrbo (live)             Public discovery       AI query layer
 Airbnb (in progress)    Availability share     Referrals
 Booking.com (paused)    Community search       Future services
```

OTA Connectivity is live. CM Community and Shared Services are the roadmap this hub is deliberately built to support, not yet built out — see `docs/cm_relay/` for the design notes.

Two rules that hold regardless of what gets built on top:

> **ICS fallback is a permanent core capability of every CM edition, and no future API integration may remove it or make it depend on this Relay.** The ICS engine always lives in local CM, never here — a paid Relay outage or a provider going down must never take down a local installation's basic calendar.

> **Joining any CM Ecosystem service (Connectivity, Community, Sharing, AI discovery) is always an explicit, explained, modular opt-in.** No service turns itself on, and none is bundled with another.

## Data model

- **Registry** (`installations`, `property_mappings`, `unit_mappings`, `audit_log`, `consent_records`) — one shared SQLite database. Grows slowly (one row per installation), never the bottleneck at the realistic scale this serves.
- **Events/commands** — a separate SQLite file per installation, not one shared table. Matches CM's own existing per-unit/per-year JSON convention, just carried over to the Relay. See `docs/data_model.md`.

Secrets (`secrets/`) and runtime data (`data/`) are gitignored and were never part of this repository's history — provider API keys, installation tokens and webhook secrets live only on the deployment, never in source.

## Getting involved

This is still a young, fast-moving project — the Relay exists to make a specific thing (small self-hosted CM installations reaching real OTA distribution) work, not to be a general-purpose integration platform. That said:

- Read `docs/relay_api_contract.md` and `docs/data_model.md` first — they're the canonical reference, kept in sync with the CM repos that call this API.
- `docs/cm_relay/` has the in-progress design notes for what's being built next (ICS fallback modes, the channel-presence confirmation gate, community/shared-services groundwork).
- Issues and PRs are welcome — especially a second provider adapter, or sharper eyes on the webhook/idempotency path.

## Hard rules (don't break these)

- Relay never becomes a second PMS or a permanent reservation store — CM stays the source of truth.
- No global master secret; every CM installation has its own token (revocable/rotatable independently).
- Provider credentials (Channex/Beds24/WuBook/...) live only here, never in the public CM Free source.
- Webhook retries must never create a duplicate reservation on the CM side — idempotency keys are non-negotiable from day one.
- Guest PII never belongs in Relay. That's CM Guestbook's job, kept deliberately separate.
- AI Discovery (when built) is never a source of truth and never invents availability/prices/conditions — unknown stays `unknown`, never guessed.
- Community membership is never conditioned on OTA API entitlement, or vice versa.

## Related projects

- [channel-manager](https://github.com/viljemd-hub/channel-manager) — CM Free, the self-hosted reservation manager this Relay exists to support.

## License

AGPLv3 — see `LICENSE`. Same license as CM Free: if you run a modified version of this as a network service, you share your changes too.
