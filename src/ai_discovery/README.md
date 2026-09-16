# ai_discovery/

Naravno-jezikovni query layer nad `community/` search API-jem: naravni jezik
→ strukturirana namera → Community Search API → realni CM podatki → rezultati
(glej `docs/community_and_shared_services.md` v CM repojih, addendum §5–6).

**Trdno pravilo:** AI ni source of truth in ne sme izmišljati availability,
cen ali booking pogojev — manjkajoč podatek je `unknown`, ne ugibanje.
Poizvedbe se beležijo minimizirano (`ai_query_log_minimized` v
`docs/data_model.md`), brez PII.

**Not yet implemented.**
