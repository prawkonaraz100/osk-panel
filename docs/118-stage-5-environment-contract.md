# 118. Stage 5 deterministic environment contract

Status: `S5-ENV-001 PASS candidate`

## Scope
Ten krok materializuje wyłącznie lokalne i testowe usługi runtime wymagane przed migracjami domenowymi: PostgreSQL, Redis oraz nieprodukcyjny endpoint S3-compatible. Nie tworzy migracji Stage 4, domenowych modeli ani feature/UI.

## Pinned local services
- PostgreSQL `17.11-alpine` — jedyny runtime/test DB driver.
- Redis `8.10.1-alpine` — cache, queue i distributed lock test substrate; nigdy source of truth.
- Moto Server `5.2.2` — hermetyczny, token-free adapter AWS SDK/S3 do local/test.

Moto jest celowo używany zamiast współczesnego LocalStack, ponieważ S5-ENV-001 nie może wymagać zewnętrznego konta/licencji ani sekretnego auth tokenu tylko do uruchomienia testowego S3. Piny obrazów są konkretne; ich aktualizacja wymaga ponownego smoke gate.

## Local flow
Wymagania hosta: Docker Compose, PHP 8.5 z `pdo_pgsql` i `redis`, Composer oraz Node 24.20.0.

```bash
cp .env.example .env
php artisan key:generate
composer install
npm ci
./scripts/runtime-up.sh
php artisan test
```

`runtime-up.sh` czeka na health wszystkich usług i uruchamia `runtime-health.sh`. Ręczny health check: `./scripts/runtime-health.sh`. Zatrzymanie: `./scripts/runtime-down.sh`. Pełne wyczyszczenie danych developerskich PostgreSQL: `./scripts/runtime-down.sh -v`.

## Test contract
`phpunit.xml` wskazuje jawnie PostgreSQL, Redis oraz lokalny S3 endpoint. Flaga path-style jest normalizowana do boolean przed przekazaniem do AWS SDK. `RuntimeServicesTest` wykonuje realne połączenie SQL, Redis cache/queue/lock oraz S3 create-bucket/write/read/delete przez AWS SDK/Flysystem. Brak usług jest failure, nie fallbackiem do SQLite/array/local storage.

## Data and secret policy
Hasło `osk_panel_local_only` oraz AWS `test/test` są wyłącznie stałymi developerskimi dla izolowanego local/test runtime i nie są credentials produkcyjnymi. Fixtures i smoke tests nie mogą zawierać prawdziwych danych osobowych ani sekretów produkcyjnych. Production provisioning, secret manager, backup i RPO/RTO nie są rozstrzygane przez S5-ENV-001.

## CI parity
Ten sam `compose.yml`, konfiguracja PHPUnit i smoke tests są uruchamiane na świeżym runnerze jako bramka S5-ENV-001. Runner tworzy wyłącznie efemeryczny pusty `.env`; plik nie jest commitowany. Stały implementation CI, jego trigger matrix i policy gates należą wyłącznie do `S5-CI-001`.

## Boundary
S5-ENV-001 nie materializuje 170-node migration DAG, nie wdraża 491 finalnych kontraktów jako testów frameworkowych i nie tworzy feature/UI. Kolejny krok to wyłącznie `S5-MIG-001`, po osobnej instrukcji.
