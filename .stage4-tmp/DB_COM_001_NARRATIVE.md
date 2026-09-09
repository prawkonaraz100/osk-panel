
## 21. DB-COM-001 — wynik fixera: PASS

DB-COM-001 domyka tenant, tożsamość produktu oraz immutable pricing snapshot pozycji zamówienia. Nie zmienia lifecycle płatności, fulfillment ani purchase-history projection — DB-COM-002…008 pozostają OPEN.

### 21.1. Jeden canonical sale-SKU

Wprowadzamy globalny `commerce_catalog_items` jako stabilną tożsamość produktu sprzedawanego przez platformę. Nie jest to tenant-owned tabela i nie zastępuje specialized domain authority.

Potwierdzone rodzaje produktu:
- `license`,
- `internal_exam`,
- `generic_service`.

Dla `license` katalogowa pozycja musi wskazywać exact `license_product_id`. Dla egzaminu wewnętrznego i generic service sama pozycja commerce jest sale-SKU; nie wymyślamy niepotwierdzonych tabel `internal_exam_products` ani `service_products`. Exact downstream mapping pozostaje DB-COM-004/007.

Nie używamy jednego pozornego polymorphic FK `product_reference`.

### 21.2. OrderItem jest tenant-owned

`order_items` dostaje własny `organization_id`. Exact relacja do Order używa `(organization_id, order_id, currency) -> orders(organization_id, id, currency)`.

To jednocześnie blokuje:
- OrderItem z innego tenant,
- linię w innej walucie niż Order,
- późniejsze purchase provenance oparte wyłącznie na niezweryfikowanym `order_id`.

`order_items(organization_id,id)` staje się candidate key potrzebnym później dla exact downstream grant FKs.

### 21.3. Product kind jest częścią relacyjnej integralności

OrderItem przechowuje `commerce_catalog_item_id` oraz `product_kind`, a composite FK wymaga zgodności z `commerce_catalog_items(id,product_kind)`.

Macierz:
- `license` → `license_product_id` wymagany,
- `internal_exam` → `license_product_id = NULL`,
- `generic_service` → `license_product_id = NULL`.

Zmiana lub dezaktywacja katalogu nie może przepisać historycznego OrderItem.

### 21.4. Pricing snapshot

Kwoty pozostają w minor units. Pozycja zapisuje co najmniej:
- `quantity > 0`,
- `currency`,
- list unit amount,
- charged unit amount,
- unit discount amount,
- VAT rate snapshot w basis points,
- line total,
- immutable product snapshot,
- immutable pricing snapshot,
- snapshot content hash.

Reguły:
- `unit_discount = list_unit - charged_unit`,
- `0 <= charged_unit <= list_unit`,
- `line_total = quantity * charged_unit`,
- `Order.total = SUM(OrderItem.line_total)` na finalnej granicy transakcji.

VAT rate jest evidence snapshotem. DB-COM-001 nie wymyśla jednej uniwersalnej formuły netto/VAT ani provider-specific tax engine.

### 21.5. Server authority

Frontend może wysłać wybór SKU i quantity, ale cena, rabat, VAT i total nie są client authority. Backend ponownie rozwiązuje aktywną pozycję katalogową i pricing context, tworzy Order oraz wszystkie OrderItems i sprawdza sumę przed commit.

Order może zawierać kilka wariantów licencji. Egzaminy pozostają produktem ilościowym, nie kopią duration modelu licencji.

### 21.6. Immutability po utworzeniu Order

Po commit normalny lifecycle nie zmienia biznesowych pól OrderItem: tenant, Order, SKU, kind, quantity, currency, price/discount/VAT/total ani snapshotów.

Późniejsza zmiana katalogu, cennika lub display name nie zmienia historii. Korekta nie jest wykonywana przez ciche przepisywanie pozycji historycznej.

Payment status oraz fulfillment status nie są rozwiązane w tym blockerze.

### 21.7. Migration safety

`organization_id` i `currency` legacy OrderItem można backfillować wyłącznie z exact parent Order. To deterministyczna relacja, nie heurystyka.

Nie wolno:
- zgadywać SKU z nazwy, ceny lub timestampu,
- zgadywać LicenseProduct tylko z duration/ceny,
- tworzyć generic SKU wyłącznie po to, by ukryć ambiguous legacy reference,
- przeliczać historycznych cen/rabatów z bieżącego cennika,
- zmieniać line total bez historycznego dowodu,
- usuwać OrderItem, aby constraint przeszedł.

Ambiguous product/pricing history = FAIL + reviewed remediation.

### 21.8. Preservation gate

Zachowane bez zmian:
- DB-FIN-001 i DB-FIN-002,
- specialized LicenseProduct authority,
- Internal Exam jako quantity product,
- mixed license cart,
- Student Finance oddzielone od Platform Commerce,
- DB-COM-002…008 OPEN,
- `core-schema.yml` i `docs/87...` zamrożone,
- DB4_10+, Stage 5, Laravel migrations i UI nieruszone.

Stan po fixerze:
- P0 OPEN: **0**,
- P1 OPEN: **7**,
- resolved: **3/10**,
- result: **FAIL_WITH_7_P1_BLOCKERS**.

Następny dozwolony krok po central gate: **DB-COM-002 only**.

**STOP przed DB-COM-002.**
