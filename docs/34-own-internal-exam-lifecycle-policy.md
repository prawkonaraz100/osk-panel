# 34. Własny produkt — elastyczny lifecycle egzaminu wewnętrznego

Data decyzji: 2026-09-05

**Zakres:** własny panel administracyjny OSK / PrawkoNaRaz  
**Status:** `OWN_PRODUCT_DESIGN_DECISION`  
**Powód:** dalsze szczegóły zachowania konkurenta po wygenerowaniu linku lub uruchomieniu egzaminu lokalnego nie są możliwe do wiarygodnej weryfikacji w panelu demonstracyjnym.

Ta decyzja **nie opisuje zachowania konkurenta**. Jest architekturą naszego produktu opartą na potwierdzonych potrzebach biznesowych OSK.

---

## 1. Zasada nadrzędna

Nie wiążemy egzaminu z jednym prostym licznikiem `exam_count--`.

Rozdzielamy:
1. **pulę egzaminów OSK**,
2. **rezerwację sztuki z puli**,
3. **dostęp do egzaminu**,
4. **próbę egzaminacyjną**,
5. **wynik i dokumentację próby**.

Dzięki temu ten sam silnik obsługuje:
- link zdalny,
- egzamin na bieżącym stanowisku,
- egzamin na innym zarejestrowanym stanowisku OSK,
- anulowanie przed startem,
- wygaśnięcie linku,
- awarię stanowiska,
- powtórzenie egzaminu,
- audyt i rozliczenie puli.

---

## 2. Pula egzaminów — model `available / reserved / consumed`

Rekomendowany ledger:

`available -> reserved -> consumed`

oraz:

`reserved -> released -> available`

### `available`
Egzamin znajduje się w puli OSK i nie jest powiązany z żadnym aktywnym dostępem/próbą.

### `reserved`
Jedna sztuka została czasowo zarezerwowana dla konkretnego dostępu lub próby, ale egzamin jeszcze się nie rozpoczął.

### `consumed`
Egzamin został faktycznie rozpoczęty zgodnie z polityką rozliczeniową.

### Rekomendowany domyślny moment zużycia
**`consume_on_start`**.

Czyli:
- utworzenie linku = rezerwacja,
- wysłanie linku = nadal rezerwacja,
- samo otwarcie strony = nadal rezerwacja,
- faktyczny start próby = zużycie,
- wygaśnięcie/anulowanie przed startem = zwolnienie rezerwacji.

To jest domyślna polityka własnego produktu, nie fakt o konkurencie.

---

## 3. Polityka konfigurowalna

Architektura może wspierać kilka polityk bez zmiany modelu danych:

### `consume_on_start` — rekomendowana
Zużycie przy faktycznym rozpoczęciu próby.

### `consume_on_access_creation`
Zużycie już przy utworzeniu linku/dostępu. Możliwe jako wariant biznesowy, ale mniej przyjazne dla OSK.

### `consume_on_completion`
Zużycie dopiero po ukończeniu. Technicznie możliwe, ale nie rekomendowane jako domyślne, ponieważ utrudnia rozliczanie porzuconych i rozpoczętych prób.

Polityka powinna być parametrem serwisowym, a nie zaszytym warunkiem w kontrolerze.

---

## 4. Lifecycle dostępu do egzaminu

Proponowane stany:

`draft`
→ `ready`
→ `delivered_or_assigned`
→ `opened` (opcjonalny)
→ `started`
→ `completed`

Odgałęzienia przed startem:
- `cancelled`,
- `expired`,
- `revoked`.

Odgałęzienia po starcie:
- `technical_abort`,
- `invalidated`.

Dostęp nie jest wynikiem egzaminu. Wynik należy do `internal_exam_attempt`.

---

## 5. Lifecycle próby egzaminacyjnej

Minimalne stany:

- `created`,
- `in_progress`,
- `passed`,
- `failed`,
- `technical_abort`,
- `invalidated`.

Nie stosujemy booleanu `passed` jako jedynego stanu.

Każda próba przechowuje co najmniej:
- `organization_id`,
- `student_id`,
- `category_id`,
- `language_code`,
- `launch_mode`,
- `started_at`,
- `finished_at`,
- `status`,
- wynik/punkty zgodnie z silnikiem egzaminu,
- referencję do puli/rezerwacji,
- referencję do stanowiska lub tokenu zdalnego,
- audit trail.

---

## 6. Tryby uruchomienia

### A. `remote_link`
Administrator generuje link dla kursanta.

Flow:
1. wybór kursanta,
2. wybór kategorii,
3. wybór języka,
4. walidacja danych wymaganych dla trybu zdalnego,
5. rezerwacja egzaminu,
6. utworzenie bezpiecznego tokenu,
7. wysyłka e-mail,
8. start próby przez kursanta,
9. atomowe przejście `reserved -> consumed`,
10. wynik + historia.

Jeśli link wygaśnie lub zostanie unieważniony przed startem, rezerwacja wraca do puli.

### B. `local_current_workstation`
Administrator uruchamia egzamin na komputerze, na którym aktualnie pracuje.

Flow:
1. walidacja kursanta/kategorii/języka,
2. atomowe sprawdzenie puli,
3. utworzenie próby,
4. zużycie sztuki przy starcie,
5. przejście UI do silnika egzaminu.

### C. `assigned_exam_station` — własne rozszerzenie
Administrator z recepcji wskazuje zarejestrowane stanowisko egzaminacyjne w OSK i uruchamia egzamin na tym stanowisku.

To odpowiada naszemu scenariuszowi dwóch komputerów i nie jest przypisywane konkurentowi.

Stanowisko może być:
- `online`,
- `offline`,
- `available`,
- `occupied`,
- `disabled`.

Jedno stanowisko może mieć maksymalnie jedną aktywną sesję egzaminacyjną.

OSK może posiadać wiele stanowisk równocześnie.

---

## 7. Awaria i odzyskiwanie

### Awaria przed startem
- próba nie jest rozpoczęta,
- rezerwacja zostaje zwolniona lub może zostać przeniesiona na inne stanowisko,
- brak zużycia puli przy polityce `consume_on_start`.

### Awaria po starcie
Domyślnie sztuka pozostaje `consumed`, ponieważ próba została rozpoczęta.

Administrator może otrzymać kontrolowaną akcję `Oznacz jako awarię techniczną`.

Ewentualny zwrot sztuki do puli jest możliwy wyłącznie poprzez audytowaną operację korekty, np. gdy:
- egzamin nie zdążył realnie wystartować,
- nie zapisano żadnej odpowiedzi,
- administrator posiada wymagane uprawnienie.

Nigdy nie przywracamy puli przez prostą edycję licznika.

---

## 8. Walidacja danych zależna od trybu

Nie wymagamy identycznego zestawu danych dla wszystkich sposobów uruchomienia.

### Remote link
Co najmniej:
- kursant,
- kategoria,
- język,
- poprawny adres e-mail,
- dane wymagane przez nasz formalny model egzaminu.

### Local / assigned station
Co najmniej:
- kursant,
- kategoria,
- język,
- dane identyfikacyjne wymagane formalnie.

E-mail nie musi być technicznym warunkiem lokalnego egzaminu, jeśli nie jest potrzebny do procesu.

PKK jest walidowane zgodnie z kontekstem kursu/kategorii i przepisami, a nie globalnie jako zawsze obowiązkowe pole każdego egzaminu.

---

## 9. Link zdalny

Własny link powinien mieć:
- nieprzewidywalny token,
- datę ważności,
- możliwość unieważnienia,
- kontrolę liczby użyć,
- przypisanie do jednej próby/kursanta/OSK,
- ochronę przed równoległym rozpoczęciem,
- audit wygenerowania, wysłania, otwarcia i startu.

Rekomendowany stan:
- token może być ponownie wysłany bez tworzenia nowej rezerwacji,
- administrator może go unieważnić przed startem,
- po rozpoczęciu próby token nie może uruchomić drugiego egzaminu.

---

## 10. Konkurencyjność i blokady

Nie kopiujemy niejasnego ograniczenia konkurenta jako globalnego `1 kursant na całe OSK`.

Nasza zasada:
- **1 aktywna próba na jedno stanowisko egzaminacyjne**,
- jeden kursant nie może mieć dwóch równoległych aktywnych prób tej samej sesji,
- OSK może prowadzić równoległe egzaminy na wielu zarejestrowanych stanowiskach, jeśli konfiguracja na to pozwala,
- wszystkie blokady są egzekwowane po stronie serwera.

---

## 11. Ledger zamiast licznika

Nie przechowujemy wyłącznie pola `available_exam_count` jako źródła prawdy.

Źródłem prawdy jest ledger zdarzeń, np.:
- `exam_credit_granted`,
- `exam_credit_purchased`,
- `exam_credit_reserved`,
- `exam_credit_released`,
- `exam_credit_consumed`,
- `exam_credit_adjusted`.

Widoczny licznik w panelu jest projekcją ledgera.

To zabezpiecza przed:
- podwójnym zużyciem,
- problemami przy retry,
- wyścigami requestów,
- ręcznymi korektami bez historii.

---

## 12. Minimalny model techniczny

### `exam_inventory_entries`
- id
- organization_id
- source_type
- source_id nullable
- delta
- reason
- created_at

### `exam_reservations`
- id
- organization_id
- student_id
- access_id
- status
- reserved_at
- expires_at nullable
- released_at nullable
- consumed_at nullable

### `internal_exam_accesses`
- id
- organization_id
- student_id
- category_id
- language_code
- launch_mode
- token_hash nullable
- station_id nullable
- status
- expires_at nullable
- created_by

### `internal_exam_attempts`
- id
- organization_id
- student_id
- access_id
- category_id
- language_code
- started_at
- finished_at nullable
- status
- result nullable
- score nullable

### `exam_stations`
- id
- organization_id
- location_id nullable
- name
- station_key/device binding
- status
- last_seen_at

---

## 13. Decyzja MVP

Dla MVP rekomendujemy:

1. `consume_on_start`,
2. dwa tryby obowiązkowe:
   - remote link,
   - current workstation,
3. model stanowisk egzaminacyjnych przygotowany w domenie, nawet jeśli UI `assigned_exam_station` pojawi się chwilę później,
4. rezerwację linku z automatycznym zwrotem po wygaśnięciu,
5. możliwość ręcznego unieważnienia linku przed startem,
6. historię wielu prób,
7. audit wszystkich zmian puli i prób,
8. brak hard-coded globalnego limitu jednego kursanta na OSK.

---

## 14. Co przestaje być blokującą niewiadomą

Nie musimy już znać dokładnego zachowania konkurenta w zakresie:
- momentu zużycia egzaminu,
- TTL linku,
- resend/revoke,
- zakresu blokady `1 kursant jednocześnie`,
- zachowania po awarii,
- zwrotu egzaminu przy niewykorzystanym linku.

Są to od teraz **jawne decyzje naszej architektury**, a nie wymagania parytetu.

W dokumentacji konkurenta pozostają oznaczone jako `COMPETITOR_UNVERIFIED`, ale nie blokują implementacji naszego rozwiązania.
