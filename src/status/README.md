# status/

`GET /api/v1/connectivity/status` in `POST /api/v1/connectivity/full-sync` iz
`docs/relay_api_contract.md`; kasneje osnova za centralni status page.

Bo poročal generični health state enum (`HEALTHY|DEGRADED|FALLBACK_ACTIVE|
OFFLINE|SUSPENDED|NOT_CONFIGURED`) in connectivity mode (`ICS_ONLY|
API_PRIMARY_ICS_STANDBY|API_PRIMARY_ICS_SHADOW|API_DEGRADED_ICS_ACTIVE|
API_ONLY`) na installation/channel — glej
`docs/ics_fallback_and_connectivity_modes.md` v CM repojih.

**Not yet implemented.**
