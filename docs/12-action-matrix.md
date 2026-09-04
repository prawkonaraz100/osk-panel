# 12. Macierz akcji i skutków ubocznych

> `Źródło`: `CURRENT` = bieżąca publiczna oferta/route, `RULES` = regulamin, `HIST` = starszy indeks/aktualność, `DESIGN` = nasz wymóg projektowy. Nie traktować `DESIGN` jako dowodu zachowania 360.

| Moduł | Akcja | Źródło | Warunek | Skutek biznesowy / zapis | Audit | Powiadomienie |
|---|---|---|---|---|---:|---:|
| Auth | register | CURRENT | poprawne dane + regulamin | user + organization | tak | e-mail wg implementacji |
| Auth | login | CURRENT | poprawne credentials | session | security log | - |
| Auth | social login | CURRENT | provider callback | identity + session | security log | - |
| Auth | remember me | CURRENT | user opt-in | dłuższa sesja wg polityki | security log | - |
| Auth | request password reset by email/login | CURRENT | istniejący identyfikator lub neutralna odpowiedź | reset request/token | security log | e-mail |
| Auth | resume ReturnUrl | CURRENT | poprawne logowanie + bezpieczny lokalny return URL | redirect do pierwotnej trasy | - | - |
| Account | request closure | RULES | użytkownik składa żądanie | closure request | tak | potwierdzenie |
| Account | block | RULES/DESIGN | naruszenie/polityka operatora | account blocked | tak | zależnie od polityki |
| Session | replace prior session | RULES | nowa sesja przy polityce single-session | poprzednia sesja revoked | tak | opcjonalnie |
| Student | create | CURRENT context / AUTH verify UI | staff permission | student | tak | opcjonalnie |
| Student | create access by email | RULES | student/email + license assignment | account invitation/access | tak | e-mail |
| Student | create access by credentials | RULES | student + generated login/password | student credential provisioning | tak, bez hasła | przekazanie danych wg kanału |
| Student | change default category | CURRENT/HIST 2026 | dozwolona kategoria | learning preference changed | tak | - |
| PKK | fetch | CURRENT | permission + profile data | pkk_profile | tak | przy błędzie |
| PKK | view details | CURRENT | linked/accessible PKK | read only | read log opcjonalny | - |
| PKK | update training | CURRENT | linked PKK | external + local state | tak | przy błędzie |
| PKK | return to school | CURRENT | elevated permission | external status change | tak | tak/recommended |
| PKK | return to authority | CURRENT | elevated permission | external status change | tak | tak/recommended |
| PKK | return expired | CURRENT | profile condition | external status change | tak | tak/recommended |
| PKK | history | CURRENT | permission | show operation history | read log opcjonalny | - |
| License | purchase | CURRENT/RULES | order paid | inventory +N | tak | tak |
| License | select language | HIST product update | supported language | assignment config | tak | - |
| License | assign | RULES | inventory > 0 | inventory -1 + assignment | tak | tak |
| License | delete/revoke unactivated | RULES | assignment not activated | assignment ended + inventory +1 atomically | tak | tak |
| License | activate | RULES | valid assignment | consumed/activated + start access | tak | tak |
| License | view progress | CURRENT | assigned/authorized | read learning stats | read log optional | - |
| Entitlement | payment confirmed | RULES | trusted payment confirmation | status paid | tak | tak |
| Entitlement | make activation available | RULES/DESIGN | paid + explicit activation product | activation_available | tak | UI/e-mail optional |
| Entitlement | activate access | RULES | activation_available | activated_at + access period begins | tak | tak |
| Calendar | schedule for self | CURRENT | permission | lesson/event | tak | tak/recommended |
| Calendar | schedule for employee | CURRENT | permission + employee | lesson/event assigned | tak | tak/recommended |
| Calendar | publish self-book slot | CURRENT | permission | student-bookable availability | tak | optional |
| Calendar | student book slot | CURRENT business capability | available slot + authorized student | booking/event | tak | tak |
| Calendar | view instructor activity | CURRENT | authorized staff | read activity | optional | - |
| Work time | record/view | CURRENT | role permission | work-time entry/summary | tak for changes | - |
| Vehicle | mark unavailable | DESIGN | staff permission | blocks booking in our design | tak | opcjonalnie |
| Training | open instructor training | CURRENT/HIST 2026 | entitlement | load category program | - | - |
| Training | play lesson | CURRENT/HIST 2026 | assigned/entitled | lesson session | progress log | - |
| Training | record progress | CURRENT/HIST 2026 | lesson activity | learning_progress | tak/system | - |
| Training | start control questions | CURRENT/HIST 2026 | end/availability of section | attempt starts | system | - |
| Training | retry control questions | CURRENT/HIST 2026 | previous/current attempt | new attempt | system | - |
| Training | skip control questions | CURRENT/HIST 2026 | user chooses skip | move forward without attempt completion | system | - |
| Exam | purchase | CURRENT/RULES | order paid | exam inventory +N | tak | tak |
| Exam | generate/link | RULES | inventory > 0 | assignment/link | tak | tak |
| Exam | start local | RULES | available assigned exam | local exam session | tak | - |
| Exam | start by link | RULES | valid exam access | started | tak | - |
| Exam | finish | RULES | started | result + exam consumed | tak | tak |
| Exam | save card digitally | RULES | finished | exam_card persisted | tak | - |
| Exam | print/download card | RULES | card/result available | document generated | tak | - |
| Payment | create/start | CURRENT/RULES | valid order | payment attempt | tak | - |
| Payment | confirm webhook | DESIGN | valid signature + idempotency | paid | tak | tak |
| Payment | upload transfer confirmation | RULES | bank transfer path | attachment/request for verification | tak | tak/operator |
| Invoice | view/download | HIST/TO_VERIFY | invoice exists | document read | read log optional | - |
| Ranking | view reviews/ranking | CURRENT/RULES | public/authorized | read | - | - |
| Ranking | report review | RULES | OSK disputes review | moderation request | tak | operator |
| Impersonation | start | HIST/DESIGN | explicit permission | impersonation session | tak | persistent banner |
| Impersonation | stop | HIST/DESIGN | active impersonation | restore actor context | tak | - |
| Ad | select city | CURRENT | region exists | auction catalog filtered | - | - |
| Ad | select placement | CURRENT | placement available | offer/auction context | - | - |
| Ad | view auction terms | RULES/CURRENT | auction visible | read opening/min increment/deadline | - | - |
| Ad | place binding bid | RULES | auction open + valid minimum/increment | immutable bid recorded | tak | optional immediate |
| Ad | request bid rejection | RULES | bidder requests operator action | rejection request | tak | operator |
| Ad | view bid history | RULES | allowed visibility | bids/history | read log optional | - |
| Ad | request bidder name hiding | RULES | bidder/OSK request | privacy request | tak | operator |
| Ad | auction settle | RULES/DESIGN | auction ended | winner selected, tie by earlier bid | tak | winner e-mail |
| Ad | pay winning order | RULES | auction won | payment state | tak | tak |
| Ad | upload desktop creative | RULES | winning/order/campaign state | creative version | tak | operator review |
| Ad | upload mobile creative | RULES | placement requires/mobile variant | creative version | tak | operator review |
| Ad | request creative service | RULES | customer requests paid help | service request/order | tak | operator |
| Ad | approve creative | RULES | operator review | creative approved | tak | customer |
| Ad | reject creative | RULES | invalid/noncompliant creative | creative rejected + revision needed | tak | customer |
| Ad | activate text fallback | RULES | missing creative + rules permit | text creative/fallback | tak | customer/operator |
| Ad | activate campaign | RULES/DESIGN | paid + accepted/fallback creative | campaign active | tak | tak |
| Sponsored article | order | RULES | valid commercial order | article order | tak | tak |
| Sponsored article | submit own content | RULES | order exists | draft/content | tak | editorial |
| Sponsored article | request copywriting | RULES | order exists | editorial service task | tak | editorial |
| Sponsored article | submit assets | RULES | order/draft | assets | tak | editorial |
| Sponsored article | moderate/edit | RULES | editorial permission | revised draft/status | tak | customer as needed |
| Sponsored article | publish | RULES | approved + commercial conditions | promoted publication | tak | tak |
| Sponsored article | archive after promotion | RULES | promotion ended | archived/current-news state | system | - |
| Partner banner | download | RULES/HIST | available asset | asset delivered | optional | - |
| Partner banner | request implementation help | RULES | customer asks | support/lead ticket | tak | support |
| Commercial services | request SEO/WWW contact | CURRENT | form/lead data | commercial lead | tak | sales/support |

## Akcje, których nie uznajemy już za potwierdzone

| Akcja | Status | Powód |
|---|---|---|
| `export_progress` | DESIGN/INFERRED | brak publicznego dowodu eksportu postępów |
| `pause_campaign` | TO_VERIFY_AUTH | brak publicznego dowodu klientowego przycisku pauzy |
| `refund` | DESIGN/process | regulamin opisuje reklamacje/odstąpienie, nie panelowy refund |
| `download_invoice` jako bieżący moduł | HISTORICAL_INDEX / TO_VERIFY_AUTH | stary indeks Faktury, stara trasa obecnie 404 |

## Krytyczne transakcje atomowe

### Cofnięcie licencji
`check not activated -> revoke assignment -> restore inventory -> audit -> commit`

### Jawna aktywacja
`check paid + activation_available -> create activation once -> start entitlement -> audit -> commit`

### Bid
`lock auction -> validate open/minimum/increment -> persist server timestamped bid -> commit`

### Settlement aukcji
`close auction -> rank valid bids by amount DESC, sequence ASC -> select winner -> create winning order -> audit -> notify`
