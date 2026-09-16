# CM Relay — skeleton (ni aktiven servis)

Ta repo je **prazno ogrodje**, ne delujoč servis. Sledi dvema arhitekturnima
dokumentoma (glej `/home/apartma/Dokumenti/Guestbook/`):

- `CM_Relay_OTA_arhitektura_2026-08-10.md` — osnovna OTA connectivity arhitektura
- `CM_Ecosystem_Community_AI_ICS_Addendum_2026-08-10.md` — nadgradnja: Relay
  ni samo OTA transportni sloj, ampak nosilec treh vzporednih storitev

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
 Booking/Airbnb          Public discovery       AI query layer
 Beds24/Channex          Availability share     Referrals
 WuBook/native           Community search       Future services
```

CM Relay je centralni, provider-agnostic registry, entitlement in
connectivity service za prostovoljno vključene CM Free/Plus/PRO namestitve.
Lokalni CM (source of truth za rezervacije/cene/availability) se opcijsko
poveže prek **CM Connector** (glej `common/lib/cm_connector.php` v
`channel-manager-free` in `pro-dev-repo`), z 4 neodvisnimi service opt-in
stikali: Connectivity, Community, Sharing/referrals, AI discovery.

## Dve trdni pravili, ki veljajo ne glede na fazo

> **ICS fallback je trajna core capability vseh CM izdaj in ga nobena
> prihodnja API integracija ne sme odstraniti ali narediti odvisnega od
> centralnega Relayja.** ICS engine živi vedno v lokalnem CM, nikoli tukaj.

> **Vključitev v CM Ecosystem (katerakoli storitev - Connectivity, Community,
> Sharing, AI) je vedno zavestna, razložena in modularna opt-in odločitev
> uporabnika.** Nobena storitev se ne vklopi sama, ločeno od ostalih.

## Stanje

- **Faza A (trenutno):** samo ta skelet — mapna struktura, protokol osnutek, provider
  adapter interface, Community/AI/health/dedupe rezervirana mesta. Brez delujoče
  logike, brez baze, brez deploya.
- **Faza B:** lokalni CM Connector MVP (v CM repojih) — ni del tega repoja.
- **Faza C:** dejanska implementacija modulov spodaj — šele potem, ko je izbran prvi
  upstream provider (Beds24 / WuBook / Channex, glej OTA brief §12/§15) in ko so
  odgovori na odprta vprašanja iz §20 znani.
- **Faza D:** prvi provider adapter (`src/providers/`).
- Community portal, AI query engine in resnična OTA API koda **niso** del
  trenutne faze — glej Ecosystem addendum §32: barebone mora biti samo
  *ready for it*, ne dograjen produkt.

## Jezik / framework / hosting

Namenoma **še ni odločeno**. Ker ta skelet ne vsebuje delujoče logike, izbira ne
blokira ničesar. Odločitev pade v Fazi C, po izbiri upstream providerja — glej
odprta tehnična vprašanja v `CM_Relay_OTA_arhitektura_2026-08-10.md` §20.

## Struktura

```
docs/
  relay_api_contract.md       -- kanonična kopija (usklajena s CM repoji)
  data_model.md                -- entitete: installations, events, mappings, community, ...
src/
  providers/CmConnectivityProviderAdapter.php   -- provider-neutral interface (OTA brief §8)
  auth/         registry/      consent/
  installations/ events/       commands/
  mappings/     providers/     audit/
  status/       community/     ai_discovery/
```

## Trdna pravila (ne kršiti, ko se Faza C začne)

- Relay ne sme postati drugi PMS ali trajna rezervacijska baza.
- Noben globalni master secret; vsaka CM namestitev ima svoj token (revoke/rotate).
- Provider credentials (Channex/Beds24/WuBook/...) živijo samo tukaj, nikoli v javnem
  CM Free source-u.
- Webhook retry ne sme nikoli ustvariti duplicate reservation na CM strani —
  idempotency po `idempotency_key` je obvezna od prvega dne.
- Guestbook/PII podatki gostov v Relay NE sodijo.
- AI Discovery ni source of truth in ne sme izmišljati availability/cen/pogojev —
  neznan podatek je `unknown`, ne ugibanje.
- Community članstvo ni pogojeno z OTA API entitlementom (in obratno).
