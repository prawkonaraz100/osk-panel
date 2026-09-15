# 59. Egzamin wewnętrzny — Wykup egzaminy

Data weryfikacji: 2026-09-05

**Route:** `/egzamin-wewnetrzny/wykup`  
**Kontekst:** `Egzamin wewnętrzny -> Wykup egzaminy`  
**Źródło:** bieżący zalogowany ekran + screenshot przekazany podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

---

## 1. Znaczenie ekranu

Ekran służy do zakupu puli egzaminów wewnętrznych przez OSK.

To jest model ilościowy, nie czasowy:
- operator podaje liczbę egzaminów,
- system wylicza cenę,
- po skutecznym zakupie/płatności pula dostępnych egzaminów OSK powinna zostać zwiększona.

Własny model rozdziela:

`exam_order -> payment -> internal_exam_inventory -> exam_access/exam_attempt`

Zakup puli nie jest tym samym co wygenerowanie egzaminu konkretnemu kursantowi ani wykonanie egzaminu.

---

## 2. Ilość egzaminów

Główna sekcja:
- `Ile egzaminów potrzebujesz?`

Potwierdzone kontrolki:
- pole ilościowe,
- suwak/regulator ilości,
- dynamiczna cena pozycji.

Zaobserwowany stan:
- liczba egzaminów: `51`,
- cena: `62.73 zł`.

Z tego snapshotu wynika cena jednostkowa `1.23 zł / egzamin` dla tego konkretnego konta/stanu oferty, ale nie traktujemy jej jako stałej reguły produktu.

Nie potwierdzono:
- minimalnej ilości,
- maksymalnej ilości self-service,
- kroku suwaka,
- reguł rabatowych/progów cenowych.

---

## 3. Podsumowanie

Prawy panel `Podsumowanie` pokazuje:
- `Liczba egzaminów`,
- `Do zapłaty`.

W obserwowanym stanie:
- `Liczba egzaminów: 51`,
- `Do zapłaty: 62.73 zł`.

Na tym ekranie nie było osobno widocznego pola rabatu ani rozbicia VAT, inaczej niż na ekranie zakupu licencji.

Nie wyciągamy z tego wniosku, że rabaty/VAT nie istnieją w backendzie — jedynie że nie są eksponowane w tym obserwowanym podsumowaniu.

---

## 4. Metody płatności

Potwierdzone radio:
- `Płatności online PayU`,
- `Przelew bezpośredni`.

Potwierdzona akcja:
- `Kup teraz`.

Flow płatności po kliknięciu nie został wykonany w demo.

Pozostaje `UNOBSERVABLE_OR_TO_VERIFY`:
- redirect/checkout PayU,
- dane przelewu,
- status oczekujący,
- moment księgowania,
- anulowanie/nieudana płatność,
- moment zwiększenia inventory egzaminów.

---

## 5. Większy wolumen

Ekran zawiera box informacyjny:
- przy większej liczbie egzaminów należy skontaktować się z działem obsługi klienta kluczowego.

To potwierdza istnienie procesu handlowego poza standardowym self-service.

Nie potwierdzono, czy limit:
- jest twardym limitem technicznym,
- zależy od konta,
- zależy od ustaleń handlowych,
- wpływa na cenę jednostkową.

Dla naszego produktu rekomendowane:
- standardowy zakup self-service,
- konfigurowalne limity,
- account-level pricing / rabaty,
- możliwość ręcznego zwiększenia puli przez administrację po zamówieniu handlowym.

---

## 6. Różnica względem licencji

Licencja jest produktem czasowym (31/90/180 dni), natomiast egzamin jest jednostką zużywalną.

Dlatego dla egzaminów preferujemy inventory ilościowe:
- `exam_units_available`,
- `exam_units_reserved` (opcjonalnie),
- `exam_units_consumed`.

Nie kopiujemy modelu `duration_days` z licencji.

---

## 7. Model dla naszego produktu

Rekomendowane encje:
- `internal_exam_products` / aktualny produkt egzaminowy,
- `orders`,
- `order_items`,
- `payments`,
- `internal_exam_inventory_ledger`,
- `internal_exam_accesses`,
- `internal_exam_attempts`.

Ważne reguły:
- cena liczona server-side,
- order item zapisuje snapshot ceny jednostkowej, ilości, VAT i rabatu z chwili zakupu,
- zmiana późniejszego cennika nie zmienia historii,
- zaksięgowanie płatności zwiększa inventory dokładnie raz,
- operacje idempotentne,
- brak możliwości zejścia puli poniżej zera.

---

## 8. Lifecycle inventory egzaminów dla naszego produktu

Minimalny model:

`ordered -> awaiting_payment -> paid -> inventory_granted`

Następnie na etapie realizacji:

`available_unit -> allocated/reserved -> exam_started -> exam_completed -> consumed`

Dokładny moment zużycia u konkurenta nie wynika z tego ekranu; wcześniejsze źródła wskazują na zużycie po wykonaniu egzaminu, ale ekran zakupu sam tego nie potwierdza.

Dla naszego produktu moment konsumpcji powinien być jawnie zdefiniowany i audytowany.

---

## 9. Potwierdzone akcje

- `open_internal_exam_purchase`
- `set_internal_exam_quantity`
- `adjust_internal_exam_quantity_with_slider`
- `view_internal_exam_order_total`
- `select_online_payment`
- `select_direct_bank_transfer`
- `submit_internal_exam_purchase`

---

## 10. Wymagania bezpieczeństwa/spójności

- tenant-scoped order,
- cena i suma liczone server-side,
- idempotentne utworzenie zamówienia,
- idempotentne księgowanie płatności,
- inventory zwiększane dokładnie raz,
- audit zamówienia, płatności i przyznania puli,
- snapshot ceny na `order_item`,
- brak polegania na cenie przesłanej z frontendu,
- kontrola concurrency przy konsumpcji jednostek egzaminu.

---

## 11. Pozostałe niewiadome

- min/max ilości,
- krok suwaka,
- dokładny cennik/progi/rabaty,
- czy cena jest liniowa dla wszystkich ilości,
- zachowanie `Kup teraz`,
- PayU callback UX,
- instrukcja przelewu bezpośredniego,
- moment przyznania puli przy przelewie,
- dokument sprzedaży/faktura,
- statusy zamówienia po zakupie,
- anulowanie nieopłaconego zamówienia.

Te luki nie blokują projektu własnego modułu commerce/inventory.


## 12. Accepted INTERNAL-EXAM-PURCHASE-UI-001 — 2026-09-15

Własny ekran `/egzamin-wewnetrzny/wykup` jest częścią accepted runtime od
`a9d96553caff3a6b6d3955c224627451a4effd14` (PR #127).

Implementacja została przyjęta bez przenoszenia obserwowanej ceny `1,23 zł`
ani wartości `62,73 zł` do kodu runtime.

### Aktualny stan implementacji

Backend udostępnia read-only projection:

`GET /api/v1/internal-exam/purchase-offer`

Projection:

- wymaga permission `exams.purchase`,
- rozwiązuje ten sam pojedynczy aktywny `internal_exam` catalog item co
  `POST /api/v1/internal-exam/orders`,
- korzysta z tego samego `CommercePricingCatalog`,
- zwraca display name, unit price, list unit price, pricing revision i flagę
  `sample_data`,
- nie przyjmuje ceny, product id ani catalog code od klienta.

Order create przy zapisie ponownie rozwiązuje catalog i pricing authority.
Podgląd w przeglądarce nie jest więc źródłem ceny.

Frontend:

- pobiera aktualną ofertę wyłącznie z
  `GET /api/v1/internal-exam/purchase-offer`,
- pozwala ustawić całkowitą liczbę egzaminów przez numeric input i stepper,
- wysyła do `POST /api/v1/internal-exam/orders` wyłącznie
  `quantity` i `payment_method`,
- nie wysyła ceny, VAT, rabatu ani totalu,
- pokazuje finalny total z odpowiedzi utworzonego Order,
- oznacza `sample_data=true` jako cenę developerską,
- fail-closed przy braku server-side oferty/cennika,
- nie hardkoduje business max quantity ani slidera, ponieważ min/max/step
  nadal są `TO_VERIFY`,
- nie przyznaje inventory przed spełnieniem istniejącej polityki płatności.

### Development sample support

Istniejący `SAMPLE_DATA_ENABLED` został rozszerzony o osobny, jawnie
developerski catalog item:

- catalog code: `SAMPLE-INTERNAL-EXAM`,
- product kind: `internal_exam`,
- przykładowa cena: **2,00 PLN za jednostkę**,
- pricing revision: `sample-dev-2026-09-15-v1`.

Ta wartość jest przykładem developerskim używanym wyłącznie po to, żeby nie
blokować productization. Nie jest finalnym cennikiem i nie wynika z
obserwowanej ceny konkurencyjnego ekranu. Sample mode pozostaje zabroniony
w `APP_ENV=production`.

### Metody płatności

Obserwowane PayU pozostaje historycznym faktem z audytowanego ekranu, ale własny
accepted runtime nie deklaruje aktywnego providera PayU. UI wysyła neutralne kody:

- `bank_transfer`,
- `online_payment`.

Order create tworzy wyłącznie istniejący lokalny pending payment intent dla
dodatniego totalu. Provider-specific redirect/callback pozostaje deferred.

### Acceptance evidence

Candidate exact-head:

`a9d96553caff3a6b6d3955c224627451a4effd14`

- Implementation CI #746 / `35021646666`: **5/5 PASS**,
- API Contract Gate #547 / `35021646668`: **PASS**,
- PostgreSQL: **389 tests / 6444 assertions — PASS**,
- deterministic restore: **122 -> 122 PASS**.

Accepted `main`:

- PR #127,
- Implementation CI #747 / `35022787325`: **6/6 PASS**,
- API Contract Gate #548 / `35022787327`: **PASS**,
- release artifact ID: `10418766869`,
- release archive SHA-256:
  `60e1686b033facdd52bfd368210f59fa6d337b355b608ada72bc4c8b8df0c546`,
- GitHub artifact ZIP SHA-256:
  `c208c25b2b313117a48bdf3c555de86aa9ef46fb61f3368d2ac63e91d2130fe0`,
- PostgreSQL: **389 tests / 6444 assertions — PASS**,
- deterministic restore: **122 -> 122 PASS**,
- schema fingerprint:
  `f3cab286cc8f0aabef219971d90afe424a8dab694c47ade2f517a3d95970716d`.

Pozostałe niewiadome z sekcji 11 pozostają niewiadomymi biznesowymi/providerowymi;
akceptacja tego runtime ich nie rozstrzyga.

