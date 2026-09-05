# 31. Kursanci — zakładka Postępy

Data weryfikacji: 2026-09-05

**Kontekst:** `/kursanci/<student_id>` -> zakładka `Postępy`  
**Źródło:** bieżący zalogowany ekran + dwa screenshoty przekazane podczas audytu  
**Status:** `USER_CONFIRMED_AUTH_SCREEN`

Dane konkretnego kursanta i login z konta demonstracyjnego nie są przepisywane jako dane referencyjne produktu.

---

## 1. Cel ekranu

Zakładka `Postępy` pozwala administratorowi OSK monitorować aktywność i wyniki kursanta w nauce online.

Ekran agreguje co najmniej cztery niezależne obszary:
- testy egzaminacyjne,
- pytania z bazy,
- `Podręcznik kursanta`,
- `Wykłady z lektorem`.

Dodatkowo pokazuje szczegółowe rozbicie pytań na działy podstawowe i specjalistyczne.

Nie należy spłaszczać tego do jednego pola `progress_percent`.

---

## 2. Kontekst wyboru konta i kategorii

W nagłówku `Postępy kursanta` widoczny jest selektor konta/dostępu kursanta zawierający:
- login/username,
- język konta, np. `EN`.

Po prawej stronie znajduje się selektor kategorii prawa jazdy.

Na obserwowanym ekranie wybrana była:
- `Kategoria B`.

W źródle UI zaobserwowano następujące opcje kategorii:
- A,
- B,
- C,
- D,
- T,
- A1,
- B1,
- C1,
- D1,
- AM,
- A2.

Traktujemy tę listę jako **zaobserwowane opcje**, nie jako deklarację kompletnego ustawowego słownika kategorii dla całego produktu.

### Wniosek domenowy
Statystyki są kontekstowe względem co najmniej:
- kursanta,
- konta/dostępu nauki,
- języka,
- kategorii.

API powinno więc jawnie przyjmować taki kontekst zamiast zwracać jeden globalny `student_progress`.

---

## 3. KPI testów

Potwierdzone dwa osobne liczniki:
- `Zdane testy`,
- `Niezdane testy`.

Każdy posiada:
- wartość liczbową,
- procentowy indykator.

Na obserwowanym rekordzie oba wynosiły zero i 0%.

Nie znamy jeszcze:
- mianownika używanego do procentu,
- czy procent odnosi się do wszystkich wykonanych testów czy do innego celu,
- jak wygląda stan z danymi.

---

## 4. KPI pytań

Potwierdzony blok `Pytania` z co najmniej:
- `Udzielonych odpowiedzi: <answered>/<total>`,
- procentem poprawnych odpowiedzi,
- procentem błędnych odpowiedzi.

Dla obserwowanej kategorii B licznik pokazywał:
- `0/2185` udzielonych odpowiedzi.

**Nie hardkodujemy 2185 jako stałej biznesowej.** To wartość bieżącej bazy/konfiguracji dla obserwowanego kontekstu i może zmieniać się wraz z bazą pytań.

Własny model statystyk powinien liczyć:
- `answered_count`,
- `available_question_count`,
- `correct_count`,
- `incorrect_count`,
- `correct_percent`,
- `incorrect_percent`.

---

## 5. Sekcje `Pytania` i `Testy`

Ekran zawiera dwa duże bloki analityczne:

### `Pytania`
Opis UI wskazuje, że administrator może sprawdzić:
- poprawność udzielonych odpowiedzi,
- liczbę rozwiązanych pytań.

Zaobserwowany empty state:
- `Brak danych`.

### `Testy`
Opis UI wskazuje, że administrator może sprawdzić:
- liczbę wykonanych testów,
- poprawność/wyniki testów.

Zaobserwowany empty state:
- `Brak danych`.

Do dalszego audytu potrzebny jest kursant posiadający dane, aby ustalić:
- czy są wykresy czy tabele,
- osie/czas,
- historię testów,
- możliwość wejścia w pojedynczy test,
- zakres dat i filtry.

---

## 6. `Podręcznik kursanta`

Potwierdzone elementy:
- `Postęp: <percent>`,
- pasek postępu,
- `Zaliczone działy`,
- licznik `<completed> z <total>`,
- `Zaliczone pytania kontrolne`,
- procent pytań kontrolnych.

Na obserwowanym ekranie:
- postęp 0%,
- zaliczone działy 0 z 13,
- pytania kontrolne 0%.

Wniosek:
Postęp podręcznika jest co najmniej dwuwymiarowy:
1. ukończenie jednostek/działów,
2. zaliczenie pytań kontrolnych.

Nie należy wyprowadzać formalnego ukończenia wyłącznie z samego procentu przewinięcia/otwarcia treści.

---

## 7. `Wykłady z lektorem`

Potwierdzone elementy analogiczne do podręcznika:
- `Postęp: <percent>`,
- pasek postępu,
- `Zaliczone działy`,
- licznik ukończonych jednostek,
- `Zaliczone pytania kontrolne`,
- procent pytań kontrolnych.

Na obserwowanym ekranie:
- postęp 0%,
- UI pokazuje `Zaliczone działy: 0 z 774`,
- pytania kontrolne 0%.

### Ważna uwaga semantyczna
Nie zakładamy, że system faktycznie ma **774 działy**. Taki jest zaobserwowany label + licznik w UI, ale wcześniejsze źródła publiczne mówią o setkach slajdów/materiałów.

Klasyfikacja:
`OBSERVED_UI_COUNTER / UNIT_SEMANTICS_TO_VERIFY`.

W naszym modelu rozdzielamy:
- `sections/modules`,
- `content_units/slides/lessons`,
- `control_questions`,

zamiast odtwarzać potencjalnie nieprecyzyjną etykietę konkurenta.

---

## 8. Rozbicie pytań na działy

Ekran pokazuje dwie grupy:
- `Basic questions`,
- `Specialized questions`.

Dla każdego działu widoczny jest licznik w formacie:
`<answered>/<available>`.

Na obserwowanym koncie wszystkie liczniki zaczynały się od 0.

### Zaobserwowane działy podstawowe — 20

1. Warning signs — 113
2. Prohibition and mandatory signs — 102
3. Additional information, direction and location signs — 82
4. Horizontal road signs — 113
5. Light signals, signals given by the traffic controller — 93
6. Merging into traffic, equal intersections — 86
7. Crossroads with signs indicating the right of way — 127
8. Crossroads with traffic lights — 59
9. Crossroads or pedestrian crossings with traffic controllers, places of public transport stops — 27
10. Vehicle position on the road, entering and exiting the intersection, stopping and parking — 145
11. Changing lane, changing direction — 148
12. Overtaking — 164
13. Avoiding, passing, reversing — 62
14. Using exterior lights and vehicle signals — 54
15. The importance of taking special care towards other road users, getting out of the vehicle, securing the vehicle — 61
16. Behavior towards a pedestrian, towards a person with reduced mobility — 105
17. Behavior towards cyclists and children — 42
18. Behavior at railway and tram crossings — 97
19. General rules defining the driver's behavior in the event of a breakdown or accident, providing first aid — 60
20. Perception, assessment of situations and decision-making, especially in terms of reaction time and changes in driving behavior caused by the influence of alcohol, drugs and medicinal products, state of consciousness and fatigue — 45

### Zaobserwowane działy specjalistyczne — 11

1. Permissible vehicle speeds, restrictions — 41
2. Vehicle equipment related to safety, use of seat belts, headrests and seats — 22
3. Vehicle spacing and braking — 46
4. Risk factors related to different road conditions, in particular the change of these conditions depending on the weather and the time of day or night, the characteristics of different types of roads and the related applicable requirements — 38
5. Different fields of vision for drivers — 21
6. Driving technique — 31
7. Safety factors relating to the vehicle, cargo and persons transported — 88
8. Obligations of the vehicle owner/holder, insurance, required documents — 30
9. Mechanical aspects related to maintaining road safety — 56
10. Rescue operations — 26
11. Driving a vehicle with a trailer — 1

Suma zaobserwowanych mianowników wynosi 2185 i jest spójna z KPI `Udzielonych odpowiedzi: 0/2185` dla wybranego kontekstu.

Ponownie: te wartości są snapshotem bieżącej bazy dla obserwowanego kontekstu, nie stałą w kodzie.

---

## 9. Język taksonomii treści

Panel administratora jest wyświetlany po polsku, natomiast nazwy działów pytań w obserwowanym widoku były po angielsku. Jednocześnie wybrane konto kursanta miało język `EN`.

To wspiera model, w którym:
- UI panelu OSK ma własny locale,
- nazwy działów/taksonomia treści mogą być renderowane w języku konta nauki kursanta.

Klasyfikacja zależności przyczynowej:
`SUPPORTED_BY_OBSERVATION`, nie `FULLY_CONFIRMED_RULE`.

W naszym produkcie taksonomia pytań powinna być tłumaczalna niezależnie od języka panelu admina.

---

## 10. Model danych/statystyk

Rekomendowane projekcje zamiast jednej tabeli `student_progress`:

### `student_question_stats`
- student_id
- learning_account_id
- category_id
- taxonomy_topic_id nullable
- answered_count
- correct_count
- incorrect_count
- available_count
- snapshot/calculated_at

### `student_test_stats`
- passed_count
- failed_count
- attempts_count
- result history relation

### `student_content_progress`
- content_type (`handbook`, `lectures`)
- completed_units
- total_units
- progress_percent
- control_question_percent

### `question_taxonomy`
- stable topic code,
- group (`basic`, `specialized`),
- category applicability,
- translations.

Nie zapisujemy nazw działów jako kluczy domenowych.

---

## 11. Potwierdzone możliwości administratora

- otwarcie zakładki `Postępy`,
- wybór konta/dostępu kursanta,
- wybór kategorii,
- podgląd liczby zdanych testów,
- podgląd liczby niezdanych testów,
- podgląd statystyk odpowiedzi,
- podgląd postępu podręcznika,
- podgląd postępu wykładów z lektorem,
- podgląd postępu per dział pytań podstawowych,
- podgląd postępu per dział pytań specjalistycznych.

Nie zaobserwowano na tym ekranie akcji ręcznej korekty statystyk.

---

## 12. Czego nadal nie znamy

- dane/wykresy w sekcji `Pytania` dla aktywnego kursanta,
- dane/wykresy w sekcji `Testy`,
- szczegóły pojedynczego testu,
- historię aktywności w czasie,
- datę ostatniej nauki,
- czas spędzony w nauce,
- sposób obliczania procentów,
- czy można filtrować okres dat,
- pełny słownik kategorii poza zaobserwowanymi opcjami,
- exact semantics licznika `0 z 774` w wykładach,
- zachowanie dla kilku kont/dostępów jednego kursanta.

---

## 13. Acceptance criteria

### AC-STU-PROG-01 — context
Administrator może analizować postęp w kontekście kursanta, konta nauki i kategorii.

### AC-STU-PROG-02 — tests
System pokazuje osobno liczbę testów zdanych i niezdanych.

### AC-STU-PROG-03 — questions
System pokazuje liczbę udzielonych odpowiedzi oraz udział odpowiedzi poprawnych i błędnych.

### AC-STU-PROG-04 — taxonomy breakdown
Administrator widzi liczniki postępu per dział podstawowy i specjalistyczny.

### AC-STU-PROG-05 — handbook
Postęp podręcznika obejmuje ukończenie jednostek oraz pytania kontrolne.

### AC-STU-PROG-06 — lectures
Postęp wykładów z lektorem obejmuje ukończenie jednostek oraz pytania kontrolne.

### AC-STU-PROG-07 — dynamic totals
Mianowniki pytań i materiałów wynikają z aktualnej wersji katalogu/bazy, a nie ze stałych zakodowanych w frontendzie.

### AC-STU-PROG-08 — localization
Taksonomia treści posiada tłumaczenia niezależne od locale panelu OSK.

### AC-STU-PROG-09 — tenant isolation
Administrator może pobierać statystyki wyłącznie kursantów należących do jego OSK.
