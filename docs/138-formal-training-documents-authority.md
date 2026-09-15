# 138. Formal training documents — authority

Data: 2026-09-13

Status: **GATE 1 — AUTHORITY**

Slice: `CORE-V1-FORMAL-TRAINING-DOCUMENTS-001`

## 1. Dlaczego ten slice istnieje

Stage-4 training authority celowo pozostawiło:
- `exact_document_regeneration_pipeline: deferred_to_document_and_audit_slice`,
- `document_regeneration_delivery_and_signing_pipeline: later_document_audit_slice`.

H1–H7 zamknęły repozytoryjny hardening. Formalne dokumenty są więc ostatnią odrębną częścią kroku 10 z `AGENTS.md`, a nie rozszerzeniem PKK.

PKK provider/runtime pozostaje zamrożony do odwołania.

## 2. Dokumenty

### Training record card

Canonical formalny dokument kursu jest generowany z danych kursanta, CourseEnrollment, aktualnego TrainingRequirementProfile oraz źródłowej ewidencji zajęć i godzin.

PDF nie jest źródłem prawdy. Źródłem są rekordy domenowe.

### Theory delivery journal

Dodatkowy dziennik teorii może pokazywać chronologicznie:
- datę,
- dział/moduł, jeżeli istnieje canonical module source,
- czas przyjęty/zaliczony przez OSK,
- instruktora/źródło, gdy dotyczy.

Nie nazywamy go urzędowym wzorem, dopóki taki status nie wynika z osobnej zweryfikowanej podstawy. Jeżeli obecny system nie ma canonical module-level source, nazw działów nie wolno odgadywać z kolejności sesji.

## 3. PAPER / ELECTRONIC

Tryby:
- **PAPER** — domyślny,
- **ELECTRONIC** — opcjonalny.

Wybór należy do CourseEnrollment. Można go ustawić przy tworzeniu kursu i zmienić tylko przed formalnym startem. Normalna zmiana od momentu `started_at` jest zabroniona.

Dla kursu utworzonego już po/backdated `started_at` tryb musi być ustalony w momencie tworzenia; brak wartości daje PAPER.

Tryb dokumentacji nie zależy od działania PWPW i nie wykonuje żadnego provider call.

## 4. PAPER workflow

1. System buduje preview z aktualnego evidence.
2. Instruktor lub uprawniony reviewer sprawdza dane.
3. Błędy poprawia się w źródle: sesji, attendance, ledgerze, requirement profile albo innych właściwych rekordach.
4. System ponownie buduje evidence bundle.
5. Reviewer zatwierdza konkretny hash evidence.
6. System tworzy canonical PDF.
7. Dokument można wydrukować.
8. Podpis odręczny odbywa się na papierze.
9. Opcjonalnie można później dołączyć skan podpisanego dokumentu.

Nie ma edytora PDF do ręcznego „poprawiania” formalnych faktów.

## 5. ELECTRONIC workflow

1. Preview.
2. Korekta źródeł.
3. Authenticated approval konkretnego evidence bundle.
4. Canonical electronic record / PDF.
5. Historia approval i prezentacji jest audytowalna.

Nie twierdzimy w tym gate, że zwykłe kliknięcie approval jest kwalifikowanym podpisem elektronicznym. Nie implementujemy XAdES ani innego mechanizmu podpisu bez osobnej weryfikacji prawnej i technicznej.

## 6. Czas i ewidencja

Authority przechowuje minuty.

- teoria: 1 godzina szkoleniowa = 45 minut,
- praktyka: 1 godzina szkolenia = 60 minut.

Główny dokument pokazuje **czas przyjęty formalnie przez OSK**, czyli projection z TrainingHourLedger i innych zweryfikowanych źródeł.

Widok szczegółów może pokazać:
- oryginalny start/koniec sesji,
- source ledger entry,
- correction/reversal lineage.

Nie hardkodujemy „26 h” dla wszystkich kursów. Wymagany wymiar pochodzi z aktualnego TrainingRequirementProfile, a wykonany czas z ledgeru. Jeżeli późniejszy curriculum authority rozdzieli np. właściwą teorię i pierwszą pomoc na konkretne moduły, dziennik może ten podział renderować bez zmiany źródła godzin.

## 7. Evidence snapshot

Canonical document snapshotuje:
- Course version,
- requirements revision,
- template version/hash,
- renderer version,
- evidence bundle hash,
- final content hash,
- reviewer i czas approval,
- generator i czas generation.

To samo evidence + template + renderer powinno prowadzić do tego samego canonical content hash.

## 8. Regeneration

Nie zmieniamy starego PDF po korekcie źródeł.

Gdy zmienia się fakt użyty w dokumencie — np. student identity, category/training type, relevant instructor/location, requirement profile, formal hour ledger, recognized external training albo relevant exam evidence — bieżący evidence hash różni się od ostatniego dokumentu.

Wtedy:
- stary dokument zostaje historycznym dowodem,
- projection pokazuje `regeneration_required`,
- po ponownym review/approval powstaje nowa revision.

Dla zamkniętego kursu correction źródła nadal wymaga istniejącego explicit correction mode.

## 9. Dane osobowe i PKK

Dokument może zawierać tylko dane potrzebne do formalnego celu.

Audit/outbox/log nie mogą zawierać plaintext PESEL ani PKK.

PKK:
- nie jest wymagane do działania tego slice,
- nie wykonujemy PWPW call,
- nie implementujemy PKK reconciliation,
- opcjonalne przyszłe pole dokumentu nie odmraża modułu PKK.

## 10. Schema boundary

W obecnym 170-node Stage-4 DAG **nie ma** course-training document tables.

Nie wolno:
- dopisywać przypadkowej migracji Laravel poza authority,
- przepisywać Stage-4 DAG tylko po to, aby „zmieścić” dokumenty,
- używać `internal_exam_documents` jako course document storage,
- używać `legal_documents` od regulaminów jako training record storage.

Gate 1 definiuje nowy, osobny Stage-5 migration-extension authority. Dopiero Gate 2 może zmaterializować:
- CourseEnrollment document mode,
- versioned formal-training templates,
- immutable document revisions,
- append-only document events.

## 11. Gate 1 acceptance

PASS wymaga:
- PAPER default i ELECTRONIC option opisane,
- lock po formalnym starcie,
- canonical source list,
- no direct PDF editing,
- evidence hash + immutable revision model,
- regeneration triggers,
- PKK explicitly out of scope,
- Stage-4 DAG/identity untouched,
- next gate jawnie nazwany jako Stage-5 extension migration authority.

## 12. Gate 1 closure evidence

Gate 1 is closed on accepted authority commit:

- validation helper: `76b6568d7c6485216d985deaf3b15c7baaec4997`,
- validation tree: `a0bbf4bd9eccf2c9bb9f66973077378906be4305`,
- helper Implementation CI #279: **5/5 PASS**,
- accepted authority commit: `abb29ed18da7985ce9c74a11588c735351771da5`,
- accepted authority tree: `a0bbf4bd9eccf2c9bb9f66973077378906be4305`,
- accepted Implementation CI #280: **5/5 PASS**,
- PostgreSQL suite: **187 tests / 2763 assertions**,
- deterministic restore harness: **PASS**,
- backend Pint + PHPStan: **PASS**,
- frontend lint + typecheck + build + audit: **PASS**,
- contracts and traceability: **PASS**,
- secret scan: **PASS**.

Gate 1 changes no executable migration and leaves the Stage-4 170-node DAG and its execution identity untouched.

Next gate: **FORMAL-DOC-002 — Stage-5 document schema and migration-extension authority**.

PKK remains frozen until explicit unfreeze after authoritative PWPW guidance.

