# T12 dataset resource handoff (partial T12 only)

Owned five source files are new (baseline ABSENT); current SHA256 and unchanged codec SHA are in `manifest.json`. `full.diff` contains each complete new source file. No ledger, docs, codec, HTTP, MCP, UI, config, real environment, MySQL or network changes.

## Reproduction

From repository root:

```powershell
php -d memory_limit=256M vendor/phpunit/phpunit/phpunit --no-configuration --bootstrap storage/framework/testing/t12-datasets/bootstrap.php --log-junit storage/framework/testing/t12-datasets/green-final.xml tests/Feature/Studio/StudioDatasetsTest.php
php vendor/bin/pint --test app/Services/Studio/StudioDatasets.php app/Models/Studio/StudioDataset.php app/Models/Studio/StudioDatasetRevision.php database/migrations/2026_11_06_010000_create_studio_datasets.php tests/Feature/Studio/StudioDatasetsTest.php
```

The bootstrap sets testing/SQLite-memory, array cache/session and a nonexistent config-cache path before application creation. The permanent PHPUnit TestCase explicitly avoids RefreshDatabase, selects a nonexistent environment file before application bootstrap, sets SQLite-memory and unique private local storage, creates only minimal users/brands schemas and the owned migration. It never migrates the application database. File evidence lives under this private scratch directory. Test data remains disposable.

## Actual TDD evidence (2026-11-06 Europe/Madrid)

`red-creation.txt`: focused `--filter test_creation`, 1 test / 1 assertion / 1 failure: null is not StudioDataset. `green-creation.txt`: 1/1, 9 assertions. Initial missing-class setup error was corrected and is not counted as RED.

`red-resource.txt`: resource run, 8 tests, 1 pass, 7 failures, 18 assertions. Distinct failures: replacement revision1 !=2; typed read expected table !=[]; foreign operation missing404; archive still null; list total0 !=1; corrupt read missing503; external mutation missing independent-transaction guard. This was a grouped RED run, not seven claimed independent command executions. `green-resource.txt`: 8/8,72 assertions.

`characterization.txt`: 14/14,107 assertions. These additional checks already passed existing implementation and are characterization, not invented RED: partial-write cleanup, revision-insert transaction rollback, SQLite writer-before-read, 2.25MB roundtrip, invalid digest/schema/path, validation/name-only revisions/immutability.

`red-json-shape.txt`: focused `--filter test_corrupt_json_object`, 1 test / 1 assertion / 1 failure: expected503 for rows:{} with matching digest/size. This was fixed by preserving JSON object/list identity during decoding, then converting only objects into codec input arrays. `green-json-shape.txt` and `green-final.txt`: 15/15,108 assertions. JUnit in `green-final.xml`. Pint final passed. Early unmatched-brace and PHPUnit helper-name errors were setup mistakes, not counted as RED.

## Stable candidate internal API

All IDs are explicit integer user/brand identifiers. Every dataset argument accepts `int|StudioDataset`; model attributes are ignored and ownership is reread from DB. Unauthorized/missing/cross-brand gives404. Mutations reject any existing caller transaction before storage. Names are trimmed and validated; table JSON is validated with fixed dataset codec profile only.

- `create(userId, brandId, string name, array table): StudioDataset`
- `replace(userId, brandId, dataset, int expectedRevision, string name, array table): StudioDataset`
- `read(userId, brandId, dataset, ?int revision=null): array` — explicit immutable revision or capture current revision from one dataset lookup; archived historical reads allowed. Missing revision404; absent/corrupt/inconsistent private file503 with generic message.
- `archive(userId, brandId, dataset): StudioDataset`, `restore(...)` — idempotent timestamp transition, no new table revision/files.
- `present(userId, brandId, dataset): array`
- `listing(userId, brandId, string search='', int page=1, int perPage=25): LengthAwarePaginator`
- `history(userId, brandId, dataset, int page=1, int perPage=25): LengthAwarePaginator`

Presentation/list fields: id,brand_id,name,revision,row_count,column_count,archived_at,created_at,updated_at. History fields: id,dataset_id,brand_id,revision,name,row_count,column_count,sha256,json_bytes,created_at. Paginator items are Eloquent metadata models with only these selected columns; normal paginator serialization is safe. No user_id,path,disk,table,rows or columns. List excludes archived; search escapes %, _ and ! literally; list idDESC and history revisionDESC; page>=1 and perPage clamped1..100. No storage access on list/history/presentation.

Revision metadata is immutable through model update/delete; service never mutates a revision. FK cascading deletion remains possible for parent lifecycle. Revision files are local/private generated UUID names at `studio-datasets/<user>/<brand>/<uuid>.json`; no caller disk/path/hash. Path policy is rechecked before read. New partial files alone are removed on failed writes/DB inserts/optimistic conflicts; historical and unrelated files survive. DB retains metadata, no table JSON column. SQLite reserves writer with UPDATE id=id before first in-transaction SELECT; other drivers use row lock for mutation.

## Memory and scope limits

Writer encodes bounded individual rows and writes/hash-counts into php://temp with 1MiB memory spill, then local put(stream); no complete extra canonical JSON string. Reader checks bounded metadata and file size before bounded readStream(bytes+1), verifies SHA and JSON/schema, frees raw JSON bytes before codec normalization; object-to-array conversion changes containers while sharing cell strings. Largest resource test is >2MiB (2.25MB), memory_limit256M. No new 25MB/52MB stress run was claimed; codec stress verification belongs to its already promoted subblock. This is not OS RSS or upload/editor validation.

No T12 closure, final-product QA, adversarial acceptance, external deployment, import/download adapters or charts are claimed. T12 broader editor/XLSX/connections/Office requirements remain open.
