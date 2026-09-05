# 51. Kalendarz — „Ważne daty”

Data weryfikacji: 2026-09-05

**Kontekst:** `/kalendarz` -> filtr `Ważne daty`  
**Źródło:** bieżący zalogowany panel demonstracyjny + obserwacja formularza `Dodaj wydarzenie`  
**Status:** `USER_CONFIRMED_EMPTY_STATE / OWN_PRODUCT_DECISION`

## 1. Co potwierdzono u konkurenta

- Na głównym ekranie kalendarza istnieje osobny checkbox/filtr `Ważne daty`.
- `Ważne daty` nie występują jako opcja w formularzu ręcznego `Dodaj wydarzenie`.
- W obserwowanym koncie demonstracyjnym nie było żadnych `Ważnych dat` widocznych w kalendarzu.
- Z tego powodu nie udało się zobaczyć wyglądu pojedynczego wpisu, jego szczegółów ani źródła.

## 2. Najsilniejszy wniosek

`Ważne daty` należy traktować jako osobną warstwę/projekcję wpisów wyświetlanych w tej samej siatce kalendarza, a nie jako zwykłe ręczne wydarzenia.

Nie jest potwierdzone, z jakich dokładnie danych konkurent je generuje.

Możliwe źródła, których **nie oznaczamy jako fakt konkurenta**:
- terminy dokumentów pracowników,
- badania lekarskie/psychologiczne,
- ważność legitymacji/uprawnień,
- przegląd/OC/AC pojazdu,
- terminy kursów lub inne zdarzenia systemowe.

## 3. Decyzja dla naszego produktu

W naszym panelu `Ważne daty` będą `system_generated_calendar_items`.

Nie będą zapisywane jako zwykły `calendar_event` tworzony przez sekretariat. Zamiast tego kalendarz będzie renderował projekcję terminów z domen źródłowych.

Przykładowe źródła dla naszego produktu:
- `staff_document_expiry`,
- `staff_medical_expiry`,
- `staff_psychological_expiry`,
- `vehicle_inspection_expiry`,
- `vehicle_oc_expiry`,
- `vehicle_ac_expiry`,
- inne przyszłe terminy oznaczone jako kalendarzowe.

## 4. Zasada architektoniczna

Źródłowa data pozostaje w swojej domenie (np. pojazd/pracownik). Kalendarz nie kopiuje jej do drugiej tabeli jako niezależnej prawdy.

Warstwa kalendarza może używać:
- query/projection,
- materialized read model,
- event projection/cache,

ale zmiana terminu dokumentu powinna automatycznie zmienić odpowiadającą mu `Ważną datę`.

## 5. Zachowanie UI dla naszego produktu

- checkbox `Ważne daty` pokazuje/ukrywa systemowe terminy,
- wpis ma źródło i typ,
- kliknięcie prowadzi do obiektu źródłowego lub jego szczegółów,
- wpis systemowy nie powinien być ręcznie edytowany jak zwykłe wydarzenie,
- ostrzeżenia mogą mieć progi `upcoming / due / expired`,
- filtry zasobów powinny zawężać również odpowiednie ważne daty.

## 6. Status audytu

Dokładny rendering i źródło `Ważnych dat` u konkurenta pozostają `UNOBSERVABLE_IN_DEMO_NONBLOCKING`.

To nie blokuje implementacji naszego kalendarza.
