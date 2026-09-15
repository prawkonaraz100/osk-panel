# 134. Disaster recovery authority

Data: 2026-09-13

Status: **PRE-PRODUCTION AUTHORITY**

## 1. Cel

Ten dokument ustala mierzalne recovery targets core v1. Nie twierdzi, że docelowa infrastruktura już je spełnia.

Go-live wymaga osobnego restore drill, który zmierzy osiągnięty recovery point i restore time.

## 2. Tier 0 — PostgreSQL business authority

RPO: **maksymalnie 5 minut**.

RTO: **maksymalnie 60 minut**.

Do tier 0 należą dane, których utrata mogłaby zmienić formalny lub finansowy fakt: kursy i godziny, egzaminy, inventory, finanse kursanta, zamówienia i płatności platformowe, audit oraz durable domain events.

Wymagany mechanizm produkcyjny musi zapewniać szyfrowany backup bazowy, PITR lub równoważny mechanizm osiągający RPO, kopię poza podstawowym failure domain oraz restore do izolowanego środowiska.

## 3. Tier 1 — formalne object assets

RPO: **maksymalnie 60 minut**.

RTO: **maksymalnie 240 minut**.

Wymagane jest versioning albo równoważna ochrona, szyfrowanie oraz możliwość odtworzenia pojedynczego obiektu i wybranego zakresu.

Temporary uploads nie dziedziczą automatycznie tej klasy — obowiązuje privacy/retention authority.

## 4. Tier 2 — projekcje odtwarzalne

RPO nie oznacza tutaj dopuszczalnej utraty business authority. Projekcja może być utracona, jeżeli jest deterministycznie odtwarzalna z zachowanego źródła.

RTO: **maksymalnie 240 minut**.

Przykłady: dashboard-safe activity, notifications i inne projekcje pochodne, o ile zachowany authority pozwala na ich odtworzenie.

## 5. Redis

Redis w obecnym projekcie jest elementem nietrwałym i **nie jest źródłem prawdy**.

RTO reprovision: **maksymalnie 30 minut**.

Awaria Redis nie może wymagać odzyskania formalnego faktu, wyniku egzaminu, płatności, inventory ani kursu z Redis. Recovery test ma potwierdzić, że aplikacja może wrócić z pustym Redis po odtworzeniu durable dependencies.

## 6. Release artifacts

Kod i artefakt release mają odtwarzalny reference z Git/CI.

RTO aplikacji: **maksymalnie 60 minut**.

Nie wolno opierać DR na ręcznie zmodyfikowanym serwerze bez odtwarzalnego artefaktu.

## 7. Restore drill

Przed production go-live trzeba przeprowadzić co najmniej:
1. restore PostgreSQL do izolowanego środowiska,
2. pomiar recovery point i utraty czasu względem celu 5 minut,
3. pomiar pełnego restore time względem 60 minut,
4. restore reprezentatywnego formalnego obiektu,
5. integralność krytycznych rekordów,
6. uruchomienie z pustym Redis,
7. raport z timestampami, wersją policy i corrective actions.

Po go-live drill powtarzamy nie rzadziej niż co 90 dni i po istotnej zmianie architektury backupu.

## 8. Granice authority

Ten gate nie wybiera AWS/GCP/Azure ani konkretnego backup providera.

Nie wolno oznaczyć PITR, object versioning lub off-site copy jako istniejące bez dowodu z docelowej infrastruktury.

Zmiana targetów na bardziej rygorystyczne nie wymaga zmiany modelu danych. Rozluźnienie targetów wymaga jawnej zmiany authority i nowego drill.

Provider-specific PKK nadal pozostaje odroczony.

## 9. Closure evidence

Hardening H4 is closed on accepted implementation commit:

- accepted commit: `46c7d9d83c558581a70a956c47c84c5b55b105b0`,
- accepted tree: `57c618eb4128895807776b04c330958af47269b0`,
- validation helper: `150c96756346d8a323c8e3ee03f3ccca0789df48`,
- helper Implementation CI #260: **5/5 PASS**,
- accepted Implementation CI #261: **5/5 PASS**,
- accepted PostgreSQL suite: **176 tests / 2654 assertions**,
- backend Pint + PHPStan: **PASS**,
- frontend lint + typecheck + build + audit: **PASS**,
- secret scan: **PASS**,
- contracts and traceability: **PASS**.

H4 defines recovery targets and required capabilities. It does **not** claim that production PITR, object versioning or off-site backup already exist. Production go-live remains blocked until the restore drill and target-infrastructure evidence pass.

Next production-hardening gate: **restore drill harness and evidence contract**.

