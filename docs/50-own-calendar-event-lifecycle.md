# 50. Własny lifecycle wydarzeń kalendarza

Data decyzji: 2026-09-05

**Status:** `OWN_PRODUCT_DECISION`

## 1. Powód decyzji

Panel demonstracyjny konkurenta pokazuje formularz tworzenia wydarzenia, ale nie pozwala faktycznie zapisać nowego wpisu. Z tego powodu nie da się wiarygodnie sprawdzić:
- kliknięcia istniejącego wydarzenia,
- szczegółów wydarzenia,
- edycji,
- anulowania,
- przenoszenia,
- usuwania,
- drag & drop,
- komunikatów konfliktów po zapisie.

Te elementy oznaczamy jako `UNOBSERVABLE_IN_DEMO_NONBLOCKING`.

Nie zgadujemy zachowania konkurenta. Definiujemy własny, spójny lifecycle.

## 2. Typy wpisów

Ręczne eventy:
- `general_event`
- `driving_lesson`

`important_date` pozostaje osobnym typem/projekcją i nie jest tworzone ręcznie tym samym formularzem.

## 3. Statusy własnego eventu

Minimalny zestaw:
- `scheduled` — zaplanowane,
- `completed` — zakończone,
- `cancelled` — odwołane.

Dla jazd opcjonalnie później:
- `no_show_student`,
- `no_show_instructor`.

Nie wdrażamy twardego delete jako podstawowej operacji dla eventu, który miał znaczenie operacyjne lub historyczne.

## 4. Tworzenie

Po poprawnym zapisie event otrzymuje `scheduled`.

Przed zapisem serwer sprawdza:
- tenant ownership wszystkich zasobów,
- konflikt instruktora,
- konflikt pojazdu,
- konflikt lokalizacji,
- konflikt kursanta,
- wymagane dokumenty/uprawnienia,
- poprawność czasu i trwania.

## 5. Szczegóły wydarzenia

Kliknięcie wpisu w kalendarzu u nas powinno otwierać drawer/detail zawierający co najmniej:
- typ,
- nazwę,
- datę i godzinę,
- czas trwania / godzinę zakończenia,
- kursanta,
- instruktora,
- pojazd,
- miejsce spotkania,
- status,
- autora i czas utworzenia,
- historię zmian, jeżeli użytkownik ma uprawnienia.

Akcje:
- `Edytuj`,
- `Przenieś` / zmień termin,
- `Odwołaj`,
- opcjonalnie `Oznacz jako zakończone`.

## 6. Edycja i przenoszenie

Zmiana daty, godziny, czasu trwania lub zasobów ponownie uruchamia pełny conflict/compliance check.

Zmiana jest audytowana:
- kto,
- kiedy,
- stara wartość,
- nowa wartość.

Przeniesienie jest technicznie edycją `starts_at` / `duration_minutes`, niezależnie czy UI używa drag & drop czy drawera.

## 7. Anulowanie

`Odwołaj` ustawia `cancelled` zamiast usuwać historię.

Rekomendowane pola:
- `cancelled_at`,
- `cancelled_by`,
- `cancellation_reason nullable`.

Dzięki temu później można raportować odwołane jazdy i historię grafiku.

## 8. Usuwanie

Hard delete dopuszczamy tylko dla technicznego wpisu utworzonego omyłkowo i bez powiązanej historii, jeśli polityka produktu na to pozwoli.

Domyślnie dla normalnej pracy OSK używamy anulowania, nie kasowania.

## 9. Drag & drop

Może zostać dodany jako UX po MVP.

Jeżeli zostanie wdrożony:
- po upuszczeniu musi wykonać te same walidacje co normalna edycja,
- przy konflikcie UI cofa zmianę i pokazuje powód,
- drag & drop nie może omijać reguł backendu.

## 10. Powiadomienia

Architektura eventu powinna pozwalać później na:
- powiadomienie kursanta o utworzeniu jazdy,
- powiadomienie o zmianie terminu,
- powiadomienie o odwołaniu,
- przypomnienie przed jazdą.

Kanały (e-mail/SMS/push) są osobną decyzją produktu i nie są wymagane do MVP kalendarza.

## 11. Ważne daty

`important_date` nie powinno wymagać ręcznego duplikowania danych dokumentów w kalendarzu.

Docelowo powinno być generowane/projektowane z domen źródłowych, np.:
- ważności dokumentów pracownika,
- ważności badań,
- OC/AC/przeglądu pojazdu,
- innych terminów, które później uznamy za potrzebne.

Źródło konkretnych `Ważnych dat` u konkurenta pozostaje niezweryfikowane.

## 12. Acceptance criteria

### AC-CAL-LIFE-01
Nowy event po utworzeniu ma status `scheduled`.

### AC-CAL-LIFE-02
Każda edycja terminu lub zasobów wykonuje server-side conflict check.

### AC-CAL-LIFE-03
Odwołanie zachowuje event i historię zamiast usuwać rekord.

### AC-CAL-LIFE-04
Zmiany krytycznych pól są audytowane.

### AC-CAL-LIFE-05
Kliknięcie eventu otwiera read/detail view z możliwością przejścia do edycji zgodnie z uprawnieniami.

### AC-CAL-LIFE-06
Drag & drop, jeśli zostanie wdrożony, korzysta z tych samych reguł backendowych co zwykła edycja.
