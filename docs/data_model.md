# CM Relay — podatkovni model (skica)

> Namenoma opisan kot sheme/entitete, ne kot SQL DDL za konkreten DBMS — izbira baze
> je odprta (glej README "Jezik / framework / hosting"). Dokončna oblika bo odvisna
> od izbranega prvega upstream OTA providerja (Beds24 / WuBook / Channex) in njegovih
> zahtev glede mappinga/ID-jev.

## installations
`id, installation_uuid (unique), edition, cm_version, connector_version, unit_count, locale, created_at, last_seen_at, status`

## installation_tokens
`id, installation_id, token_hash, issued_at, revoked_at, rotated_from`

## service_entitlements
`id, installation_id, service (npr. "connectivity"), plan, active_from, active_to, status`

## provider_accounts
`id, provider (channex|beds24|wubook|...), account_ref, credentials_ref (secret storage, ne plain), created_at`

## property_mappings
`id, installation_id, cm_property_id, provider, provider_property_id`

## unit_mappings
`id, property_mapping_id, cm_unit_id, provider_unit_id`

## events
`id (relay_event_id), provider_event_id, installation_id, idempotency_key (unique), type, channel, occurred_at, payload, status, delivery_attempts, next_retry_at, acknowledged_at, created_at`

Statusi: `received | normalized | queued | delivered | acknowledged | failed | dead_letter`

## commands
`id, installation_id, type (pushAvailability|pushRates|pushRestrictions|requestFullSync|...), payload, status, created_at, completed_at`

## audit_log
`id, actor, action, target_type, target_id, created_at` — brez PII gostov, brez celotnih payloadov.

## consent_records
`id, installation_id, data_scope, ecosystem_inclusion, terms_price, accepted_at` — audit kopija
soglasij, ki jih je uporabnik potrdil na CM strani (`cm_connector_save_consent()`).

---

## Razširitev — CM Ecosystem addendum (Community / Sharing / AI / ICS fallback, §27)

> Sledeče entitete so shema/opis, **niso implementirane**. Rezervirajo prostor,
> da barebone data model ne bo zasnovan samo okoli OTA rezervacij (addendum §0).

## services
Per-installation matrika neodvisnih service opt-inov, zrcali lokalni
`cm_connector.json → services` (`common/lib/cm_connector.php`):
`installation_id, ota_connectivity, community, community_referrals, ai_discovery`.

## capabilities
Finejša granulacija znotraj posamezne storitve (npr. `community.search`,
`community.referrals`, `ai.discovery`), ki jo entitlement lahko vklopi/izklopi
neodvisno od grobega `services` stikala — glej addendum §7 primer.

## public_properties / public_units
Minimizirana javna projekcija nastanitve/enote, objavljena samo če je
`community` opt-in aktiven in je posamezno polje izrecno dovoljeno (addendum §2):
`property_id, display_name, region, country, capacity, amenities, public_booking_url, community_enabled`.
Nikoli neposreden dostop do lokalne rezervacijske baze.

## availability_projection
Cached discovery sloj za hitro Community iskanje (addendum §3) — ločen od
`public_properties`, osvežuje se periodično; lokalni CM pred booking korakom
potrdi sveže stanje (`freshness_seconds`, glej addendum §25 primer). Ni source
of truth, samo pospešitev iskanja.

## community_search_log
Minimizirana evidenca Community iskanj (brez PII gosta), za `community.search`
event (addendum §4).

## referrals
`id, from_installation_id, to_installation_id, status (created|opened|converted), created_at`
— zrcali `community.referral.*` event tipe (addendum §4).

## ai_query_log_minimized
Minimizirana evidenca AI Discovery poizvedb (strukturirana namera, ne surovo
sporočilo gosta) — za audit/debug, brez PII.

## connectivity_health
Per-installation/per-channel health state: `installation_id, channel, state
(HEALTHY|DEGRADED|FALLBACK_ACTIVE|OFFLINE|SUSPENDED|NOT_CONFIGURED),
last_api_success_at, last_ics_sync_at` — glej
`docs/ics_fallback_and_connectivity_modes.md` v CM repojih (addendum §21).

## sync_sources
`reservation_id, source (manual|direct|inquiry|api_booking|api_airbnb|ics_booking|ics_airbnb|external),
first_seen_at, last_seen_at` — vhod za cross-source dedupe (addendum §14).

## external_reservation_links
`reservation_id, channel, api_external_id, ics_uid, arrival, departure, unit,
canonical_source, fingerprint` — dedupe/linking shema, glej addendum §14–16.
Source precedence: `local hard reservation > verified API > verified ICS >
unverified external hint`. Gostovo ime ni identifikator.

## reconciliation_runs
`id, installation_id, provider, started_at, finished_at, status, summary` —
zapis obnove po API/Relay outage-u, obvezen korak pred vrnitvijo v
`API_PRIMARY_ICS_STANDBY` (addendum §22).

## Stroga meja (velja za VSE zgornje entitete)

Nikoli: imena/e-maili/telefonske gostov, dokumenti, Guestbook podatki, AJPES podatki,
celotne rezervacije, prihodki, druge občutljive poslovne informacije. OTA reservation
payload sme operativno prehajati skozi `events`, ločeno od registry/telemetrije, z
lastno omejeno retencijo (glej brief §11).
