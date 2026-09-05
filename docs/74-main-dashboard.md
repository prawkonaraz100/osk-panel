# 74. Panel główny OSK — dashboard

Data weryfikacji: 2026-09-05

**Route:** `/`  
**Kontekst:** zalogowany `Panel główny`  
**Źródło:** bieżący zalogowany ekran + pełny tekst dashboardu przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

## 1. Układ

Dashboard składa się z czterech głównych obszarów:

1. `Licencje`,
2. `Egzaminy wewnętrzne`,
3. `Powiadomienia`,
4. `Kalendarz`.

Układ desktopowy obserwowany na ekranie:
- górny lewy: `Licencje`,
- górny prawy: `Egzaminy wewnętrzne`,
- dolny lewy: `Powiadomienia`,
- dolny prawy: `Kalendarz`.

## 2. Karta `Licencje`

Potwierdzone dane/projekcje:
- `Aktywne licencje`,
- `Dostępne licencje`.

Zaobserwowany snapshot:
- aktywne: `15`,
- dostępne: `93`.

Wartości są dynamiczne i nie mogą być hardkodowane.

Potwierdzone akcje:
- `Więcej` -> panel licencji,
- `Przydziel licencje`,
- `Kup licencje` -> `/licencje/wykup`.

W naszym produkcie karta jest projekcją danych z modułu licencji, nie osobnym źródłem prawdy.

## 3. Karta `Egzaminy wewnętrzne`

Potwierdzona projekcja:
- `Dostępne egzaminy`.

Zaobserwowany snapshot:
- `160`.

Potwierdzone akcje:
- `Więcej` -> `/egzamin-wewnetrzny/panel`,
- `Przeprowadź egzamin`,
- `Kup egzamin` -> `/egzamin-wewnetrzny/wykup`.

W naszym produkcie liczba dostępnych egzaminów jest projekcją wspólnego inventory; panel szczegółowy nadal rozróżnia źródło darmowe/opłacone.

## 4. `Powiadomienia` — w praktyce activity/audit feed

Sekcja jest przewijaną listą zdarzeń systemowych dotyczących organizacji OSK. Zaobserwowane wpisy zawierają:
- typ/tytuł zdarzenia,
- datę i godzinę,
- opis obiektu/operacji,
- aktora, np. `przez Właściciela`,
- opcjonalny link do kursanta/obiektu,
- dla części wpisów akcję `Rozwiń`,
- dla płatności akcję `Przejdź`.

### Zaobserwowane klasy zdarzeń

Kursanci:
- `Dodano kursanta`,
- `Zmieniono kursanta`,
- `Zarchiwizowano kursanta`,
- `Przywrócono z archiwum kursanta`.

Kursy / PKK:
- `Dodano kurs`,
- `Zmieniono kurs`,
- `Usunięto kurs`.

Płatności kursanta:
- `Dodano płatność kursanta`,
- `Dodano wpłatę kursanta`,
- `Usunięto płatność kursanta`.

Licencje:
- `Dodano licencje`,
- `Usunięto licencje`,
- `Rozpoczęto płatność - licencje`.

Egzaminy wewnętrzne:
- `Dodano egzamin wewnętrzny`,
- `Przeprowadzono egzamin wewnętrzny`,
- `Rozpoczęto płatność - egzamin wewnętrzny`.

Kalendarz:
- `Dodano wydarzenie`,
- `Zmieniono wydarzenie`,
- `Usunięto wydarzenie`.

Reklamy:
- `Zmieniono reklamę`.

### Przykładowe dane wpisów

Egzamin zakończony może pokazywać:
- wynik (`Nie zaliczony`),
- punkty,
- kursanta,
- kategorię,
- język.

Licencja może pokazywać:
- kursanta,
- okres,
- język,
- identyfikator dostępu,
- aktora.

Płatność zakupu może pokazywać:
- ilość sztuk / strukturę pakietu,
- wartość,
- link `Przejdź` do transakcji.

Zmiana kursu może mieć akcję `Rozwiń`; dokładna zawartość rozwinięcia nie została jeszcze przechwycona.

## 5. Wniosek architektoniczny — jeden event/audit stream

Sekcja `Powiadomienia` nie powinna być u nas zbudowana jako ręcznie składane komunikaty z wielu kontrolerów.

Rekomendowany model:
- każda ważna operacja domenowa emituje zdarzenie,
- zdarzenie trafia do `organization_activity_events`,
- dashboard czyta jedną projekcję activity feed,
- ten sam event może zasilać audyt, powiadomienia i historię obiektu.

Minimalne pola eventu:
- `id`,
- `organization_id`,
- `event_type`,
- `aggregate_type`,
- `aggregate_id`,
- `actor_user_id`,
- `actor_role_snapshot`,
- `occurred_at`,
- `title`,
- `summary`,
- `metadata_json`,
- `target_route` nullable,
- `diff_json` nullable.

Dla zdarzeń `changed_*` rekomendujemy zapis `before/after` lub diff, aby `Rozwiń` mogło pokazywać dokładną zmianę.

## 6. Karta `Kalendarz`

Dashboard osadza uproszczony kalendarz bez lewego panelu zasobów.

Potwierdzone elementy:
- przycisk `Pełny kalendarz` -> `/kalendarz`,
- `Dodaj wydarzenie`,
- nawigacja poprzedni/następny okres,
- `Dzisiaj`,
- widoki `Miesiąc`, `Tydzień`, `Dzień`,
- obserwowany widok miesięczny.

Karta powinna korzystać z tego samego źródła danych co pełny moduł kalendarza.

## 7. Projekt własnego dashboardu

Dashboard powinien być agregatorem, nie osobnym subsystemem biznesowym.

Źródła prawdy:
- `license_inventory` / licencje,
- `internal_exam_inventory_ledger`,
- `organization_activity_events`,
- kalendarz / zdarzenia.

Rekomendowane API może zwracać jeden lekki `dashboard_summary`, ale wartości muszą być liczone/projektowane ze źródłowych modułów.

## 8. Potwierdzone akcje

- `open_main_dashboard`
- `view_active_license_count`
- `view_available_license_count`
- `open_license_management_from_dashboard`
- `assign_license_from_dashboard`
- `buy_license_from_dashboard`
- `view_available_exam_count`
- `open_exam_management_from_dashboard`
- `conduct_exam_from_dashboard`
- `buy_exam_from_dashboard`
- `view_organization_activity_feed`
- `open_linked_activity_entity`
- `open_activity_payment`
- `expand_activity_item_action_visible`
- `view_embedded_calendar`
- `open_full_calendar_from_dashboard`
- `add_calendar_event_from_dashboard`
- `switch_dashboard_calendar_view`
- `navigate_dashboard_calendar_period`

## 9. Niewiadome nieblokujące implementacji

- dokładna zawartość `Rozwiń` przy wpisach zmian,
- limit/paginacja/lazy loading feedu,
- czy dashboard ma dodatkowe sekcje przy innych danych/rolach,
- reguły widoczności widgetów zależnie od uprawnień,
- stany empty/error/loading.

Powyższe detale można zaprojektować elastycznie w naszym produkcie.
