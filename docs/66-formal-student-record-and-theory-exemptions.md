# 66. Formalny kurs OSK — obowiązek ewidencji kursanta i zwolnienia z teorii

Data weryfikacji: 2026-09-05

**Zakres:** własny panel admin OSK / formalne szkolenie kandydatów na kierowców  
**Status:** `LEGAL_VERIFIED`  
**Cel:** rozstrzygnąć, czy egzamin wewnętrzny może dotyczyć osoby niebędącej w bazie kursantów oraz kiedy kursant nie realizuje teorii / teoretycznego egzaminu wewnętrznego.

---

## 1. Decyzja dla naszego produktu

Dla formalnego kursu OSK **nie obsługujemy anonimowego ani tymczasowego `ad_hoc_candidate`** jako osoby egzaminowanej.

Każda osoba szkolona musi mieć trwały rekord `student` i powiązany rekord szkolenia/kursu (`course_enrollment`) zanim system dopuści formalne czynności szkoleniowe lub formalny egzamin wewnętrzny.

To nie oznacza, że przepisy nakazują używanie naszej konkretnej aplikacji. Przepisy nakazują OSK prowadzenie wymaganej ewidencji i dokumentacji osoby szkolonej. Jeżeli nasz system ma być formalnym systemem obsługi OSK, jego rekord kursanta musi odwzorowywać tę ewidencję.

---

## 2. Obowiązek ewidencji osoby szkolonej

Rozporządzenie szkoleniowe wymaga, aby OSK przed przyjęciem na szkolenie m.in.:
- sprawdził warunki przyjęcia,
- wpisał imię i nazwisko osoby szkolonej,
- wpisał PESEL albo datę urodzenia, gdy PESEL nie został nadany,
- wpisał rodzaj szkolenia do książki ewidencji osób szkolonych,
- przydzielił instruktora prowadzącego,
- wydał kartę przeprowadzonych zajęć albo utworzył ją w systemie teleinformatycznym OSK.

Wniosek produktowy:

`formal_training_activity -> existing student + enrollment`

Nie dopuszczamy formalnej jazdy, teorii ani egzaminu wewnętrznego bez rekordu szkolenia.

---

## 3. Ewidencja godzin jest obowiązkowa

Po zakończeniu lub przerwaniu szkolenia do książki ewidencji osób szkolonych wpisuje się m.in. liczbę godzin części teoretycznej i/lub praktycznej przeprowadzonych w OSK oraz datę zakończenia/przerwania.

Profil kandydata na kierowcę po zakończeniu szkolenia jest również aktualizowany o kategorię oraz liczbę godzin zajęć teoretycznych i praktycznych.

W naszym systemie godziny nie mogą być tylko polem ręcznie nadpisywanym na końcu kursu. Potrzebujemy źródłowej ewidencji zajęć i projekcji sumy:

`training_session -> duration -> verified attendance -> training_hour_ledger -> course totals`

Dla praktyki godzina szkolenia = 60 minut. Dla teorii godzina szkolenia = 45 minut.

---

## 4. C+E po C — brak teorii, ale kursant nadal jest formalnie ewidencjonowany

To dokładnie przypadek omawiany podczas audytu.

Dla kategorii `C+E`:
- ustawa nie przewiduje części teoretycznej egzaminu państwowego dla tej kategorii,
- rozporządzenie szkoleniowe nie określa minimalnej liczby godzin teorii dla `C+E`,
- minimalna liczba zajęć praktycznych wynosi **25 godzin**,
- egzamin państwowy `C+E` jest praktyczny,
- egzamin wewnętrzny ma część praktyczną; część teoretyczna jest wykonywana tylko wtedy, gdy jest wymagana.

Czyli typowy formalny przebieg osoby posiadającej `C`, która robi `C+E`, to:

`student + course C+E -> 25 h praktyki -> praktyczny egzamin wewnętrzny -> praktyczny egzamin państwowy`

Brak teorii **nie oznacza braku kursanta w ewidencji**. W systemie trzeba nadal dokumentować m.in. realizację praktyki i zakończenie szkolenia.

---

## 5. Kategorie +E — brak państwowej teorii

Art. 51 ust. 2 pkt 1 ustawy wymienia kategorie, dla których przeprowadza się część teoretyczną egzaminu państwowego:
- AM,
- A1,
- A2,
- A,
- B1,
- B,
- C1,
- C,
- D1,
- D,
- T,
- oraz tramwaj.

Na tej liście nie ma:
- B+E,
- C1+E,
- C+E,
- D1+E,
- D+E.

Dla tych kategorii system nie powinien wymagać państwowej teorii ani teoretycznego egzaminu wewnętrznego jako warunku standardowego przebiegu szkolenia.

Minimalne godziny praktyki z § 9 ust. 1 pkt 2 rozporządzenia szkoleniowego:
- B+E — 15 h,
- C1+E — 20 h,
- C+E — 25 h,
- D1+E — 20 h,
- D+E — 25 h.

---

## 6. Zwolnienie po wcześniejszym zdaniu państwowej teorii — art. 23a

Jeżeli osoba uzyskała już pozytywny wynik z części teoretycznej egzaminu państwowego, art. 23a ustawy zwalnia ją:
- z części teoretycznej szkolenia,
- z części teoretycznej egzaminu wewnętrznego.

To oznacza, że dla kategorii, dla której normalnie teoria istnieje (np. B, C, D), kursant może najpierw samodzielnie przygotować się i zdać państwową teorię, a następnie w OSK realizować wyłącznie wymagane elementy praktyczne i praktyczny egzamin wewnętrzny.

W systemie potrzebujemy zatem reguły:

`state_theory_passed = true -> theory_training_required = false -> internal_theory_exam_required = false`

Nie zwalnia to z formalnej ewidencji kursanta ani z wymaganej praktyki.

---

## 7. Uznanie wcześniejszej teorii z niższej kategorii

Obowiązujące rozporządzenie egzaminacyjne (§ 10 ust. 1) przewiduje przypadki, w których osobę uznaje się za posiadającą pozytywny wynik części teoretycznej egzaminu.

Dotyczy to m.in.:
- `A2`, gdy osoba posiada `A1` albo pozytywny wynik egzaminu A1,
- `A`, gdy osoba posiada `A1` lub `A2` albo odpowiedni pozytywny wynik,
- `B`, gdy osoba posiada `B1` albo pozytywny wynik B1,
- `C`, gdy osoba posiada `C1` albo pozytywny wynik C1,
- `D`, gdy osoba posiada `D1` albo pozytywny wynik D1.

Spójnie z tym § 9 ust. 1 pkt 3–4 rozporządzenia szkoleniowego wyłącza minimalne wymagania teoretyczne odpowiednio dla:
- B po B1,
- C po C1,
- D po D1,
- A2 po A1,
- A po A1/A2.

Dla naszego systemu te przypadki muszą być rozwiązywane przez konfigurowalny silnik reguł, a nie przez ręczne wpisywanie `0 godzin teorii` bez podstawy.

---

## 8. Macierz uproszczona

| Przypadek | Teoria szkolenia | Teoretyczny egzamin wewnętrzny | Praktyka | Praktyczny egzamin wewnętrzny |
|---|---:|---:|---:|---:|
| C+E po C | nie | nie | 25 h | tak |
| B+E po B | nie | nie | 15 h | tak |
| C1+E po C1 | nie | nie | 20 h | tak |
| D1+E po D1 | nie | nie | 20 h | tak |
| D+E po D | nie | nie | 25 h | tak |
| C po C1 | nie | nie / teoria uznana | wg aktualnych reguł praktyki | tak |
| D po D1 | nie | nie / teoria uznana | wg aktualnych reguł praktyki | tak |
| A2 po A1 | nie | nie / teoria uznana | wg aktualnych reguł praktyki | tak |
| A po A1/A2 | nie | nie / teoria uznana | wg aktualnych reguł praktyki | tak |
| dowolna kategoria z teorią po zdaniu państwowej teorii przed kursem | nie | nie | wymagana praktyka | tak |

Uwaga: tabela jest skrótem logiki. Finalny wymiar praktyki może zależeć od posiadanych uprawnień i szkolenia równoległego; musi go wyliczać osobny rule engine zgodny z § 9.

---

## 9. Wymagania dla panelu admin OSK

Profil kursanta musi posiadać co najmniej relacje do:
- `course_enrollments`,
- kategorii szkolenia,
- rodzaju szkolenia,
- PKK / danych identyfikacyjnych, gdy dotyczą danego trybu,
- instruktora prowadzącego,
- lokalizacji,
- sesji teorii,
- sesji praktyki,
- formalnie zaliczonych godzin,
- informacji o teorii państwowej / uznaniu teorii,
- podstawy zwolnienia z teorii,
- egzaminów wewnętrznych,
- daty zakończenia lub przerwania szkolenia.

### Kluczowe pola wyliczane przez rule engine
- `theory_training_required`,
- `minimum_theory_minutes`,
- `internal_theory_exam_required`,
- `practical_training_required`,
- `minimum_practical_minutes`,
- `internal_practical_exam_required`,
- `exemption_basis_code`,
- `exemption_evidence_reference`.

OSK nie powinien ręcznie ustawiać tych flag bez śladu audytowego.

---

## 10. Konsekwencja dla modułu egzaminu wewnętrznego

Formalny `internal_exam_attempt` musi wskazywać:
- `student_id` — wymagane,
- `course_enrollment_id` — wymagane,
- `organization_id`,
- kategorię,
- rodzaj egzaminu (`theory` / `practical`),
- podstawę prawną/konfiguracyjną tego, czy dana część jest wymagana,
- snapshot danych kursanta i szkolenia.

Nie dopuszczamy:
- `student_id = null` dla formalnego egzaminu OSK,
- egzaminu formalnego dla osoby wprowadzonej wyłącznie jako jednorazowy kandydat,
- wygenerowania części teoretycznej, jeżeli rule engine wskazuje, że teoria nie jest wymagana.

Jeżeli UI konkurenta ma `Dodaj nowego kursanta` bezpośrednio w drawerze egzaminu, w naszym produkcie ta akcja powinna najpierw **utworzyć trwałego kursanta i formalny rekord szkolenia**, a dopiero potem wrócić do generowania egzaminu.

---

## 11. Podstawy prawne użyte przy decyzji

1. Ustawa z 5 stycznia 2011 r. o kierujących pojazdami — tekst jednolity Dz.U. 2025 poz. 1226:
   - art. 23 i 23a,
   - art. 51 ust. 2.
2. Rozporządzenie Ministra Infrastruktury i Budownictwa z 4 marca 2016 r. w sprawie szkolenia osób ubiegających się o uprawnienia do kierowania pojazdami, instruktorów i wykładowców — tekst jednolity Dz.U. 2018 poz. 1885, z późn. zm.:
   - § 6,
   - § 8,
   - § 9,
   - § 17–21.
3. Rozporządzenie Ministra Infrastruktury z 24 listopada 2023 r. w sprawie egzaminowania osób ubiegających się o uprawnienia do kierowania pojazdami, szkolenia, egzaminowania i uzyskiwania uprawnień przez egzaminatorów oraz wzorów dokumentów stosowanych w tych sprawach — Dz.U. 2023 poz. 2659, z późn. zm.:
   - § 10 ust. 1.

---

## 12. Decyzja architektoniczna

Własny panel OSK ma być **course-first**, nie `exam-first`.

Poprawna relacja:

`organization -> student -> course_enrollment -> training sessions/hours -> required internal exam parts -> exam attempts`

Egzamin jest elementem przebiegu formalnego szkolenia, a nie niezależnym narzędziem do egzaminowania dowolnej osoby spoza ewidencji OSK.
