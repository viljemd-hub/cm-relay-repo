# events/

Event queue: sprejem webhookov od upstream providerja, normalizacija, `GET
/api/v1/events?cursor=` in `POST /api/v1/events/{id}/ack` iz `docs/relay_api_contract.md`.
Idempotency po `idempotency_key` je obvezna od prvega dne — webhook retry ne sme
nikoli ustvariti duplicate reservation na CM strani.

Dolgoročno tudi nosilec cross-source (API ↔ ICS) dedupe/reconciliation
podatkov (`external_reservation_links`, `sync_sources`, `reconciliation_runs`
v `docs/data_model.md`) — glej `docs/ics_fallback_and_connectivity_modes.md`
v CM repojih za matcher pravila in source precedence.

**Not yet implemented.**
