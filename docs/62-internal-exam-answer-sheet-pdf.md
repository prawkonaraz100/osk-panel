# 62. Egzamin wewnętrzny — PDF „Arkusz odpowiedzi”

Data weryfikacji: 2026-09-05

**Kontekst:** `/egzamin-wewnetrzny/panel` -> konkretna próba -> `Pobierz wydruk`  
**Źródło:** rzeczywisty pobrany plik `Arkusz_Odpowiedzi_000000000000.pdf` przekazany podczas audytu  
**Status:** `USER_CONFIRMED_DOWNLOADED_DOCUMENT`

---

## 1. Format dokumentu

Potwierdzony dokument:
- PDF,
- 1 strona A4 w obserwowanym przykładzie,
- tytuł `ARKUSZ ODPOWIEDZI`,
- podtytuł: arkusz przeprowadzonego egzaminu teoretycznego osoby ubiegającej się o uprawnienia do prowadzenia pojazdów.

Dokument jest przypisany do konkretnej próby egzaminu, nie tylko do kursanta.

---

## 2. Dane osoby egzaminowanej

Sekcja `OSOBA EGZAMINOWANA` zawiera:
- `Nr ewidencyjny`,
- `Nazwisko`,
- `Imię`,
- `Numer PESEL`.

Zaobserwowany przykład:
- nr ewidencyjny: `445645645646465`,
- nazwisko: `Nowak`,
- imię: `Ala`,
- PESEL: `000000000000`.

### Wniosek domenowy

Te dane powinny być historycznym snapshotem próby egzaminacyjnej. Późniejsza edycja profilu kursanta nie może zmieniać już wygenerowanego/utrwalonego arkusza egzaminu.

---

## 3. Dane egzaminu

Sekcja `EGZAMIN` zawiera:
- `Data egzaminu`,
- `Egzamin w zakresie kategorii`.

Zaobserwowany przykład:
- data: `26-01-2026`,
- kategoria: `B`.

W samym PDF nie ma godziny egzaminu, mimo że panel administracyjny pokazuje datę i godzinę próby.

Dla naszego produktu rekomendujemy przechowywać pełny timestamp w rekordzie próby, a na dokumencie renderować format wymagany przez szablon dokumentu.

---

## 4. Struktura pytań

Arkusz zawiera dokładnie 32 pozycje testowe w obserwowanym wariancie:
- pytania podstawowe: pozycje `1-20`,
- pytania specjalistyczne: pozycje `21-32`.

Pytania podstawowe są rozbite wizualnie na:
- `1-12`,
- `13-20` (`Pytania podstawowe (cd)`).

Pytania specjalistyczne:
- `21-32`.

### Ważna granica

To jest obserwowany format egzaminu w tym dokumencie. Nie hardkodujemy liczby/układu pytań w warstwie domenowej bez osobnej konfiguracji wersji egzaminu.

---

## 5. Dane zapisywane per pytanie

Dla każdej pozycji testowej dokument pokazuje:
- `Numer pytania w teście` — pozycja 1..32,
- `Identyfikator pytania`,
- `Liczba pkt. za pytanie`,
- `Udzielona odpowiedź`,
- `Liczba pkt. uzyskanych`.

To jest kluczowy dowód, że do odtworzenia dokumentacji egzaminu nie wystarczy przechowywać tylko końcowego wyniku.

Dla naszego produktu każda próba powinna mieć niezmienny zestaw `attempt_questions`/`attempt_answers`.

Rekomendowane pola per pozycja:
- `attempt_id`,
- `position`,
- `section` (`basic` / `specialized`),
- `question_id_snapshot`,
- `question_version_id` lub content revision reference,
- `max_points_snapshot`,
- `answer_snapshot`,
- `awarded_points`,
- `answered_at nullable`,
- `is_correct nullable`.

Jeżeli pytania/odpowiedzi w bazie zostaną później zaktualizowane, historyczna próba musi nadal odtwarzać stan z chwili egzaminu.

---

## 6. Obserwowany niezaliczony egzamin

W przekazanym arkuszu:
- wszystkie 32 pola `Udzielona odpowiedź` mają wartość `BRAK`,
- każda pozycja ma `0` punktów uzyskanych,
- `Suma punktów uzyskanych: 0`,
- `Wynik egzaminu: NEGATYWNY`.

To jest spójne z panelem administracyjnym, gdzie próba była oznaczona jako `Niezaliczony`.

### Mapowanie prezentacyjne

Dla obserwowanej próby:
- panel: `Niezaliczony`,
- PDF: `NEGATYWNY`.

Nie zakładamy jeszcze dokładnej etykiety PDF dla próby zaliczonej, dopóki nie zobaczymy takiego dokumentu.

---

## 7. Punktacja

Arkusz przechowuje wagę punktową każdego pytania jako snapshot.

Zaobserwowano wartości:
- 3 pkt,
- 2 pkt,
- 1 pkt.

Dokument pokazuje zarówno:
- maksymalną liczbę punktów za pozycję,
- liczbę punktów faktycznie uzyskanych.

### Wymaganie dla naszego produktu

Scoring musi być liczony server-side i zapisany audytowo per pozycja oraz w agregacie próby.

Nie wolno przy generowaniu historycznego wydruku przeliczać starej próby na podstawie aktualnych reguł/punktacji pytania, jeśli konfiguracja egzaminu mogła się zmienić.

---

## 8. Wynik końcowy

Dolna część tabeli zawiera:
- `Suma punktów uzyskanych`,
- `Wynik egzaminu`.

Rekomendowane pola na `internal_exam_attempt`:
- `score_total`,
- `score_max_snapshot`,
- `pass_threshold_snapshot`,
- `result` (`passed` / `failed`),
- `completed_at`.

Próg zaliczenia nie jest pokazany na przekazanym PDF-ie, więc nie zapisujemy go jako potwierdzony element dokumentu konkurenta.

---

## 9. Podpisy

Na dole dokumentu znajdują się dwa miejsca do podpisu:
- `(podpis osoby egzaminowanej)`,
- `(podpis i pieczątka osoby egzaminującej)`.

To potwierdza, że dokument jest przygotowany również do workflow papierowego: wydruk -> podpis kursanta -> podpis/pieczątka osoby egzaminującej.

Dla naszego produktu zachowujemy możliwość:
- wydruku papierowego,
- późniejszego rozszerzenia o podpis elektroniczny, bez usuwania wariantu papierowego.

---

## 10. Czego dokument nie pokazuje

W obserwowanym PDF-ie nie ma:
- treści pytań,
- treści wariantów odpowiedzi,
- poprawnej odpowiedzi,
- godziny rozpoczęcia/zakończenia,
- czasu trwania egzaminu,
- nazwy OSK,
- danych osoby egzaminującej (poza miejscem na podpis/pieczątkę),
- numeru dokumentu/próby wprost,
- informacji o źródle jednostki egzaminu (darmowa/opłacona),
- progu zaliczenia,
- technicznego ID attemptu.

To nie oznacza, że dane te nie istnieją w backendzie; jedynie nie są renderowane na obserwowanym arkuszu.

---

## 11. Wymagania trwałości dokumentu

Dla naszego produktu dokument po zakończeniu egzaminu musi być odtwarzalny w sposób deterministyczny.

Wymagamy snapshotów:
- danych kandydata,
- kategorii,
- daty egzaminu,
- zestawu i kolejności pytań,
- identyfikatorów pytań,
- punktacji per pytanie,
- odpowiedzi kandydata,
- przyznanych punktów,
- sumy punktów,
- końcowego wyniku,
- wersji szablonu dokumentu.

Rekomendacja:
- po zakończeniu próbę oznaczyć jako immutable business record,
- korekty administracyjne realizować jako jawne zdarzenia/korekty z audytem, nie przez cichą zmianę historycznych odpowiedzi.

---

## 12. Generowanie dokumentu

Potwierdzony entry point konkurenta:
- `/exam/download?id=<exam_id>`.

Dla naszego produktu:
- dokument generowany server-side,
- autoryzacja tenant + permission,
- stable attempt ID,
- audyt pobrania,
- możliwość ponownego wygenerowania identycznego dokumentu z immutable snapshotu,
- opcjonalnie zachowanie wygenerowanego artefaktu/hash dokumentu dla formalnego audytu.

Rekomendowane pola dokumentu:
- `attempt_id`,
- `document_type = internal_exam_answer_sheet`,
- `template_version`,
- `generated_at`,
- `generated_by`,
- `content_hash`,
- `storage_key nullable`.

---

## 13. Model danych — minimum

### `internal_exam_attempts`
- `id`,
- `organization_id`,
- `student_id`,
- `candidate_first_name_snapshot`,
- `candidate_last_name_snapshot`,
- `candidate_pesel_snapshot nullable`,
- `registry_or_pkk_snapshot`,
- `category_snapshot`,
- `exam_date/time`,
- `status`,
- `score_total`,
- `result`,
- `completed_at`,
- `exam_definition_version`,
- audit metadata.

### `internal_exam_attempt_questions`
- `id`,
- `attempt_id`,
- `position`,
- `section`,
- `question_id_snapshot`,
- `question_version_id nullable`,
- `max_points_snapshot`,
- `answer_snapshot nullable`,
- `awarded_points`,
- `is_correct nullable`.

### `internal_exam_documents`
- `id`,
- `attempt_id`,
- `type`,
- `template_version`,
- `generated_at`,
- `generated_by`,
- `content_hash`,
- `storage_key nullable`.

---

## 14. Potwierdzone funkcje

- `download_exam_answer_sheet_pdf`
- `render_candidate_identity_snapshot`
- `render_exam_date_and_category`
- `render_all_exam_question_positions`
- `render_question_identifier_per_position`
- `render_max_points_per_question`
- `render_candidate_answer_per_question`
- `render_awarded_points_per_question`
- `render_total_score`
- `render_exam_result`
- `render_candidate_signature_line`
- `render_examiner_signature_and_stamp_line`

---

## 15. Pozostałe niewiadome

- wygląd arkusza dla wyniku pozytywnego,
- exact label wyniku pozytywnego,
- czy arkusz dla innych kategorii ma inną liczbę/układ pytań,
- zachowanie przy odpowiedziach częściowych,
- sposób oznaczania konkretnych odpowiedzi (`TAK/NIE`, `A/B/C` itd.),
- czy dokument jest generowany przed ukończeniem egzaminu,
- czy PDF jest przechowywany jako artefakt czy generowany dynamicznie,
- czy istnieje wersjonowanie szablonu u konkurenta,
- czy podpisany papier jest później skanowany/załączany do systemu.

Te luki nie blokują implementacji własnego, pełnego modelu historycznego egzaminu.
