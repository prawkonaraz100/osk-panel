# 12. Macierz akcji i skutków ubocznych

| Moduł | Akcja | Warunek | Skutek | Audit | Powiadomienie |
|---|---|---|---|---:|---:|
| Auth | register | poprawne dane + regulamin | user + organization | tak | e-mail |
| Student | create | staff permission | student | tak | opcjonalnie |
| PKK | fetch | uprawnienie + dane profilu | pkk_profile | tak | przy błędzie |
| PKK | update training | linked PKK | external + local state | tak | przy błędzie |
| PKK | return | elevated permission | external status change | tak | tak |
| License | purchase | order paid | inventory +N | tak | tak |
| License | assign | inventory > 0 | assignment | tak | tak |
| License | revoke unactivated | not activated | inventory +1 | tak | tak |
| License | activate | valid assignment | consumed/activated | tak | tak |
| Calendar | create drive | no conflicts | lesson/event | tak | tak |
| Calendar | reschedule | event active | changed slot | tak | tak |
| Calendar | cancel | allowed status | cancelled | tak | tak |
| Vehicle | mark unavailable | staff permission | blocks booking | tak | opcjonalnie |
| Exam | purchase | order paid | exam inventory +N | tak | tak |
| Exam | generate | inventory > 0 | assignment/token | tak | tak |
| Exam | start | valid token/session | started | tak | - |
| Exam | finish | started | result + consumed | tak | tak |
| Exam | card PDF | finished | generated document | tak | - |
| Payment | confirm webhook | valid signature | paid | tak | tak |
| Invoice | issue | payment/order valid | invoice | tak | e-mail |
| Role | assign | owner/admin | permission change | tak | tak |
| Impersonation | start | explicit permission | impersonation session | tak | banner UI |
| Ad | bid | auction open | bid | tak | result later |
| Ad | activate | paid + creative approved | campaign active | tak | tak |
