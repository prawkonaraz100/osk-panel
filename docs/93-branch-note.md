# 93. Historical consolidation branch note

**Status:** `HISTORICAL_SUPERSEDED`

Ten dokument zachowuje wyłącznie kontekst dawnej konsolidacji dokumentacji.
Pierwotna praca w docs 81+ była prowadzona na branchu
`docs-consolidation-2026-09-05`.

Od 2026-09-15 branch ten nie jest już źródłem bieżącej pracy. Jego zaakceptowana
historia została wypromowana do `main`, a sam historyczny ref został usunięty w
zweryfikowanym cleanupie branchy.

Bieżąca reguła jest następująca:

- `main` jest jedynym canonical base/publication branch,
- nowe krótkotrwałe branche zadaniowe startują z aktualnego `main` i wracają do
  `main` przez PR,
- `archive/branch-snapshot-2026-09-15` jest wyłącznie archiwum historii branchy i
  nie wolno go merge'ować do `main`,
- nie należy odtwarzać ani wznawiać `docs-consolidation-2026-09-05`.

Aktualne authority: `AGENTS.md`, `docs/227-current-project-status-authority.md`
oraz `specs/current-project-status.yml`.
