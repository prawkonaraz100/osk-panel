# 135. Restore drill harness

Data: 2026-09-13

Status: **CI IMPLEMENTED / PRODUCTION EVIDENCE PENDING**

## 1. Cel

H5 materializuje deterministyczny restore drill w CI bez fałszywego twierdzenia, że lokalny emulator dowodzi gotowości infrastruktury produkcyjnej.

Authority targetów pozostaje w docs/134-disaster-recovery-authority.md.

## 2. PostgreSQL

Po pełnym PostgreSQL-backed test suite CI:

1. wykonuje pg_dump bieżącej bazy bez jej modyfikowania,
2. tworzy osobną bazę osk_panel_restore_drill,
3. wykonuje pg_restore,
4. porównuje liczbę publicznych tabel,
5. porównuje fingerprint listy tabel,
6. porównuje row-county krytycznych tabel.

Krytyczny zestaw obejmuje formalne kursy/godziny, egzaminy, inventory, finanse, orders, audit i domain events.

Źródłowa baza nigdy nie jest targetem restore.

## 3. Formalny object asset

Moto/S3 drill:
- włącza versioning,
- zapisuje v1,
- nadpisuje v2,
- odczytuje historyczne VersionId v1,
- przywraca v1 jako aktualny obiekt,
- weryfikuje zawartość,
- czyści bucket po teście.

To potwierdza kontrakt aplikacyjny i mechanikę S3 version restore na emulatorze. Nie dowodzi, że production bucket ma już versioning.

## 4. Redis

Po durable restore harness wykonuje FLUSHALL i PING.

Oczekiwany wynik PONG przy pustym Redis potwierdza, że recovery contract nie traktuje Redis jako źródła business authority.

## 5. Raport

Harness emituje JSON zawierający:
- recovery policy version,
- environment_kind=ci_emulated,
- production_target_evidence=false,
- source i target DB,
- table counts i schema fingerprint,
- liczbę sprawdzonych krytycznych tabel,
- snapshot RPO,
- zmierzony elapsed time,
- Redis result,
- object storage result.

CI failuje przy każdej niespójności.

## 6. Granica produkcyjna

H5 **nie zamyka** P1 target-infrastructure restore drill.

Przed go-live nadal wymagane są rzeczywiste dowody z docelowego środowiska:
- PostgreSQL PITR/recovery point,
- off-primary failure-domain copy,
- production object versioning/restore,
- zmierzone RPO/RTO,
- raport operatora z corrective actions.

Dopiero taki drill może oznaczyć production recovery targets jako proven.
