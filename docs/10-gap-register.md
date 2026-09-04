# 10. Gap register — elementy do weryfikacji po zalogowaniu

Poniższych rzeczy nie da się uczciwie potwierdzić wyłącznie z publicznej warstwy serwisu.

| ID | Obszar | Co trzeba sprawdzić | Priorytet |
|---|---|---|---|
| GAP-01 | Dashboard | dokładne KPI, widgety, alerty | medium |
| GAP-02 | Kursanci | pola formularza i statusy | high |
| GAP-03 | Pracownicy | role, pola, uprawnienia | high |
| GAP-04 | Pojazdy | pola, dokumenty, przypomnienia | high |
| GAP-05 | Kalendarz | widok dzień/tydzień/miesiąc, drag&drop, rezerwacja kursanta | high |
| GAP-06 | PKK | dokładne formularze, komunikaty, statusy błędów | critical |
| GAP-07 | Licencje | filtry, kolumny, masowe akcje | high |
| GAP-08 | Postępy | metryki, progi, eksport | high |
| GAP-09 | Egzamin | konfiguracja testu, ekrany wyniku, PDF karty | critical |
| GAP-10 | Wykłady | struktura materiałów i player | medium |
| GAP-11 | Faktury | format, integracja księgowa | medium |
| GAP-12 | Impersonacja | zakres dozwolonych działań | high |
| GAP-13 | Reklamy | bieżący model zakupu vs licytacja | medium |
| GAP-14 | Multi-language | czy język jest per licencja, użytkownik czy kurs | medium |
| GAP-15 | Powiadomienia | kanały i konfiguracja | medium |

## Jak kontynuować audyt

Po uzyskaniu legalnego dostępu do panelu DEMO lub własnego konta OSK wykonujemy dla każdego ekranu:

1. screenshot,
2. URL/route,
3. breadcrumb/menu,
4. pola,
5. przyciski,
6. walidacje,
7. modale,
8. stany błędów,
9. request/response z DevTools wyłącznie dla własnej sesji i w granicach uprawnień,
10. rezultat biznesowy akcji,
11. wpis do `01-feature-map.md` i `02-screen-inventory.md`.
