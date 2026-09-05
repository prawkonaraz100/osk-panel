# 44. Własny dokument dostępu kursanta — polityka produktu

Data decyzji: 2026-09-05

**Status:** `OWN_PRODUCT_DECISION`

## Cel

Dokument dostępowy ma być przede wszystkim praktycznym narzędziem dla sekretariatu OSK. Przyjmujemy, że w wielu szkołach pracownik biura zakłada konto kursanta, ustawia mu hasło, aktywuje dostęp i wręcza gotową kartkę z danymi. Kursant ma móc po prostu wejść na stronę i się zalogować.

Nie wymuszamy modelu, w którym każdy kursant samodzielnie ustawia i aktywuje konto.

## Dwa wspierane tryby

### 1. OSK-managed credentials — rekomendowany dla biura

Pracownik OSK:
1. tworzy lub wybiera dostęp kursanta,
2. ustawia albo generuje hasło początkowe,
3. opcjonalnie od razu aktywuje przypisaną licencję,
4. generuje PDF/kartkę,
5. przekazuje kursantowi gotowy login + hasło.

Kursant:
- otwiera stronę,
- wpisuje login i hasło,
- korzysta z kursu.

Zmiana hasła przy pierwszym logowaniu nie jest obowiązkowa w tym trybie, chyba że OSK lub polityka bezpieczeństwa ją włączy.

### 2. Learner self-service — opcjonalny

Pracownik OSK tworzy dostęp i przekazuje link/token aktywacyjny. Kursant sam ustawia hasło i aktywuje dostęp.

Ten tryb pozostaje dostępny jako alternatywa, ale nie jest jedynym modelem produktu.

## Zawartość dokumentu w trybie OSK-managed

PDF/kartka powinna zawierać:
- logo/nazwę platformy,
- opcjonalnie nazwę i dane OSK,
- imię i nazwisko kursanta,
- login lub e-mail dostępu,
- **jawne hasło początkowe ustawione lub wygenerowane przez sekretariat**,
- adres logowania,
- QR prowadzący do strony logowania,
- krótki komunikat typu `Twoje konto jest gotowe — zaloguj się`.

Jeżeli sekretariat aktywował już licencję, dokument nie powinien instruować kursanta, aby wykonywał dodatkową aktywację.

## Kluczowa zasada bezpieczeństwa

Jawne hasło może być pokazane i wydrukowane **w momencie jego ustawiania/generowania**, ale po zapisaniu system przechowuje wyłącznie bezpieczny hash hasła.

System nie posiada funkcji `pokaż stare hasło`.

Jeżeli kursant zgubi kartkę lub hasło jest nieznane:
1. sekretariat wybiera `Ustaw nowe hasło`,
2. system przyjmuje lub generuje nowe hasło,
3. nowe hasło jest pokazane jednorazowo,
4. można od razu wygenerować nową kartkę/PDF,
5. do bazy trafia wyłącznie hash nowego hasła.

Dzięki temu zachowujemy praktyczny workflow sekretariatu bez przechowywania haseł w odwracalnej postaci.

## Aktywacja przez sekretariat

Nasz produkt ma wspierać jawną akcję administracyjną:
- `Aktywuj dostęp teraz`.

Przed wykonaniem system pokazuje:
- kursanta,
- wariant licencji,
- moment rozpoczęcia okresu ważności,
- przewidywaną datę zakończenia.

Po potwierdzeniu:
- `license_assignment` przechodzi do stanu aktywnego,
- zapisujemy `activated_at`,
- zapisujemy `activated_by_user_id`,
- zdarzenie trafia do audytu.

Jeżeli okres licencji zaczyna biec od aktywacji, UI musi to jasno komunikować sekretariatowi przed kliknięciem.

## Rekomendowany lifecycle — tryb OSK-managed

`learning_access_created -> password_set_by_osk -> license_assigned_not_activated -> osk_activates -> active -> expired`

Jeżeli OSK nie aktywuje od razu:

`learning_access_created -> password_set_by_osk -> license_assigned_not_activated -> learner_or_osk_activates -> active -> expired`

## QR

QR może prowadzić do:
- strony logowania,
- strony logowania z już wpisanym loginem,
- bezpiecznej strony konkretnego dostępu.

QR **nie powinien zawierać jawnego hasła**. Hasło pozostaje czytelne na kartce jako osobne pole.

## Ponowne wydrukowanie

Ponieważ starego hasła nie przechowujemy w sposób odwracalny:
- bez zmiany hasła system może ponownie wydrukować login i instrukcję, ale nie odtworzy starego hasła,
- jeśli kartka ma ponownie zawierać jawne hasło, sekretariat ustawia nowe hasło i generuje nowy PDF.

Możemy w UI połączyć to w jedną akcję:
- `Wygeneruj nowe hasło i pobierz kartkę`.

## Uprawnienia

Nie każdy pracownik powinien móc ustawiać hasła i aktywować licencje.

Osobne permissiony:
- `student_access.manage_credentials`,
- `student_access.reset_password`,
- `student_license.activate`,
- `student_access.download_credentials_pdf`.

Owner może delegować je pracownikowi biurowemu/sekretariatowi.

## Audyt

Zapisujemy:
- kto utworzył dostęp,
- kto ustawił/resetował hasło,
- kiedy hasło zostało zmienione (bez zapisu samego hasła),
- kto pobrał dokument,
- kto aktywował licencję,
- kiedy nastąpiła aktywacja.

## Reguły dla hasła

- hasło może być wpisane ręcznie przez sekretariat albo wygenerowane,
- w UI przy ustawianiu dostępny jest `Pokaż/ukryj hasło`,
- można skopiować hasło przed zapisaniem,
- po zapisaniu nie oferujemy funkcji odzyskania starego hasła,
- hasło w bazie przechowujemy wyłącznie jako silny hash,
- wymagania długości/złożoności powinny być rozsądne dla realnego OSK i nie utrudniać niepotrzebnie pracy sekretariatu.

## Minimalny wygląd kartki

**Dane do logowania**

Imię i nazwisko: Jan Kowalski  
Login: `jankowalski123`  
Hasło: `PrzykladoweHaslo7`  
Strona: `prawkonaraz.pl/logowanie`

[QR]

`Konto jest aktywne. Zaloguj się powyższymi danymi.`

To jest nasz własny układ — nie kopiujemy layoutu ani tekstów konkurenta.

## Acceptance criteria

### AC-ACCESS-OWN-01 — OSK can set password
Uprawniony pracownik OSK może ustawić hasło początkowe kursanta.

### AC-ACCESS-OWN-02 — plaintext available for handoff
Hasło może być widoczne i wydrukowane podczas tworzenia/resetu danych dostępowych.

### AC-ACCESS-OWN-03 — no recoverable password storage
Po zapisaniu aplikacja nie przechowuje odwracalnej kopii hasła.

### AC-ACCESS-OWN-04 — reset and reprint
Jeśli wymagany jest nowy wydruk z hasłem, sekretariat może ustawić nowe hasło i pobrać nowy dokument.

### AC-ACCESS-OWN-05 — OSK activation
Uprawniony pracownik może aktywować licencję za kursanta, po jawnym potwierdzeniu rozpoczęcia okresu ważności.

### AC-ACCESS-OWN-06 — learner friction optional
Kursant nie musi wykonywać dodatkowej zmiany hasła ani aktywacji, jeśli OSK przygotowało konto w trybie managed.

### AC-ACCESS-OWN-07 — audit
Ustawienie/reset hasła, pobranie dokumentu i aktywacja licencji są audytowane.
