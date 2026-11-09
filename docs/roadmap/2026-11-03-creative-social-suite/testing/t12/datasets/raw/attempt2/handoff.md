# Dataset resource correction attempt2 — 2026-11-06 Europe/Madrid

Scope: A1Important TDD sequence and B1Minor pagination offset overflow only. Only service and permanent test changed; models/migration remain byte-identical to attempt1. All five current/baseline SHA in manifest.json; full new-source diff in full.diff and delta against frozen review-attempt1 in delta.diff. Source copy under source/; removed implementation snapshot removed-before-red.php. Parent remains sole ledger/docs writer. T12 stays open.

## A1: deleted, observed RED, reimplemented

Deleted before running any new RED:

1. Both create/replace DB::transaction wrappers (so rollback support was absent, not merely mocked).
2. Both Throwable catch blocks that deleted the newly generated file before rethrowing.
3. The put(false) failure guard in snapshot.

Ownership, schema validation, revision creation, metadata updates, snapshot writer, resource fclose and archive/restore code remained in place. `removed-before-red.php` is the actual source after deletion, before reimplementation. No blind restore followed. Reimplemented create/replace through a new private persistRevision(callable) that owns one DB transaction and removes only its captured new path on failure; snapshot independently rejects put(false). This retains writer reservation and revision checks inside the transaction.

Four independent focal commands were run while deleted code was absent, then those same four independent commands after reimplementation. Each was one test; setup succeeded. Files named red-<filter>.txt / green-<filter>.txt contain raw results:

| Filter | Actual RED | GREEN |
|---|---|---|
| test_failed_write_removes_only_new_file_and_rolls_back_metadata | Expected historical file list; actual also contained new partial file | 1/1,9 assertions |
| test_revision_insert_failure_rolls_back_only_new_file | Expected metadata name Stable; actual Failed after injected SQLite revision insert failure | 1/1,6 assertions |
| test_create_revision_insert_failure_rolls_back_dataset_and_new_file | Expected one dataset; actual two after injected SQLite revision insert failure | 1/1,4 assertions |
| test_create_failed_write_removes_only_new_file_and_dataset | Expected original file list; actual also contained partial file | 1/1,7 assertions |

The two original tests retain their assertions. The bundled create-insert portion was split into its own focused test with stronger error/snapshot checks. A create-write test was added so both operations have independent write and insertion failure evidence. The initial attempt1 tests remain described as characterization; this new cycle does not retroactively relabel them as RED.

## B1: integer-safe pagination

Two additional independent focal commands failed before pagination code changed: filters test_listing_extreme_page_is_empty and test_history_extreme_page_is_empty. Each failed because PHP_INT_MAX returned the first row while an empty page was expected. Raw red files retain complete failures.

New shared private paginateMetadata computes only safe division/subtraction before Laravel can multiply offset. If page-1 exceeds intdiv(PHP_INT_MAX, perPage), it counts the scoped real total and returns an empty ordinary LengthAwarePaginator with the requested current page and perPage. It does not clamp the page or perform a floating-point offset. Representable offsets retain existing pagination. Public API, ownership, metadata fields, ordering, search and perPage clamp1..100 are unchanged.

Both focal GREEN: 1/1,25 assertions each. Each tests PHP_INT_MAX, reported368934881474191034, greatest safe page intdiv(max,25)+1, first overflowing page +2, ordinary empty page2, page1, perPage1 and existing clamp1000→100. Current page/total/perPage verified; storage forbidden. These are two new tests, not a claimed additional independent test per loop iteration.

## Reproduction and final result

Each focal command uses this prefix and its exact filter from the tables:

```powershell
php -d memory_limit=256M vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap storage/framework/testing/t12-datasets/bootstrap.php --filter <filter> tests/Feature/Studio/StudioDatasetsTest.php
```

Final checks after Pint formatting:

```powershell
php -d memory_limit=256M vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap storage/framework/testing/t12-datasets/bootstrap.php --log-junit storage/framework/testing/t12-datasets/attempt2/green-final.xml tests/Feature/Studio/StudioDatasetsTest.php
php vendor/bin/pint --test app/Services/Studio/StudioDatasets.php app/Models/Studio/StudioDataset.php app/Models/Studio/StudioDatasetRevision.php database/migrations/2026_11_06_010000_create_studio_datasets.php tests/Feature/Studio/StudioDatasetsTest.php
```

GREEN19/19,169 assertions, no errors/failures/skips; Pint PASS. This is initial15 plus two separated/added create-failure cases plus two pagination cases. Raw final output/JUnit in green-final.txt/xml. No codec or real environment/DB/network/native work. Existing 52MB performance reviewer probe remains mocked storage, allocator peak208MiB under256M; no real52MB disk/RSS/HTTP validation is newly claimed.
