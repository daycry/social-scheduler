# Adapter implementation evidence (transcribed terminal results)

No T12 closure. No TCP/server acceptance claimed. All functional requests below use Laravel's real HTTP kernel, routes, auth, services and disposable SQLite/storage; file upload probes use UploadedFile. No production .env loaded by StudioDatasetApiTest. No domain/codec/export sources changed.

Each focal command used `php -d memory_limit=256M vendor/bin/phpunit tests/Feature/Studio/StudioDatasetApiTest.php --filter <test_name>` unless specified. Date 2026-11-06. RED results are actual observed outcomes before corresponding implementation, not reconstructed mutations.

| Criterion / test_name suffix (all begin test_) | Observed RED | Focal GREEN |
|---|---|---|
| routes_create_list_and_read_safe_metadata_preserve_typed_table | expected201 got404;1test/1assert |1/10assert |
| replace_conflict_archive_restore_history_and_isolation | expected200 got405;1/1 |1/12 |
| rows_are_revision_pinned_paginated_and_corruption_returns_503 | expected200 got404;1/1 | rows pair2/18 |
| rows_budget_counts_unescaped_bytes_without_skipping_or_truncating | expected200 got404;1/1 | rows pair2/18 |
| json_body_stream_is_bounded_before_decode_without_trusting_content_length | expected413 got422;1/1 | guard/export/import3/23 |
| export_streams_safe_default_faithful_text_and_typed_json | expected200 got404;1/1 | guard/export/import3/23 |
| multipart_import_creates_replaces_and_preserves_json_identity | expected201 got404;1/1 | guard/export/import3/23 |
| public_upload_accepts_real_25000000_byte_csv_and_paginates | expected201 got404;1/2 |1/7;7575ms;allocator127926272B;256M |
| mcp_dataset_is_discovered_and_shares_abilities_ownership_and_facade | tools/list missing manage_studio_dataset;1/3 |1/13 |
| web_routes_require_verified_owner_and_csrf_with_preserved_strings | expected200 got404;1/1 |1/4 |
| mcp_rejects_explicit_null_optional_pagination_and_revision | expected isError:true gotfalse;1/2 |1/4 |
| corrupt_export_fails_before_headers_and_mcp_exposes_safe_status | MCP message missing503;1/6 | withtwocharacterizations3/31 |
| invalid_upload_and_storage_failure_do_not_mutate_prior_revision | file:null got500 Undefined array key text vs422;1/1 |1/8; storage rollback already domain property, characterization |
| api_rejects_csrf_field_as_an_unknown_key | expected422 got201;1/1 | withauditcharacterization2/8 |
| mcp_schema_is_closed_and_declares_typed_cell_string_limit | additionalProperties defaulttrue vsfalse;1/1 | schema+MCPactions2PASS |
| read_operations_reject_unknown_json_body_fields | expected422 got200;1/1 |1/3 |
| mcp_large_escaped_typed_json_import_with_explicit_memory_profile | expected200 got413 at53MiB cap;1/1 |1/5;2924ms;512M;allocator490733568B |

Additional first-run characterization: abilities/unknown keys/object rows 1/9; typed numeric/null/Unicode 1/12 (fixture corrected to send literal0.0, postJson's default encoding erased fraction before server); invalid JSON/missing Content-Length/request user+route resolver preservation; MCP actions import/replace/history/archive/restore/list; audit dataset_id/malformedid. No invented RED credited.

Latest standard run (after source edits):

`php -d memory_limit=256M vendor/bin/phpunit tests/Feature/Studio/StudioDatasetApiTest.php --filter '^(?!.*test_public_upload_accepts_real_25000000_byte_csv_and_paginates)'`

`{"tool":"phpunit","result":"passed","tests":22,"passed":21,"assertions":174,"duration_ms":5131,"skipped":1}`

The skipped test is intentionally opt-in MCP 51MB;25MB upload deliberately excluded after its focused successful run. Large probes are not repeatedly run just to make aggregate counts prettier.

Large MCP command: set only process `T12_DATASET_MCP_LARGE=1`, run PHP `-d memory_limit=512M ... --filter test_mcp_large_escaped_typed_json_import_with_explicit_memory_profile`, restore/remove flag. No app memory_limit change. It streams fixture directly to temporary request body; typed row bytes51016513 plus columns/JSONstructure; escaped ASCII RPC envelope153030414B.51rows x100columns,5000Unicode e-acute characters/cell. Response metadata only. Observed `memory_limit=512M allocator_peak_bytes=490733568`; not RSS, not a256M acceptance claim. Vendor `HttpTransport::run` line45 consumes raw getContent, so raw is correctly retained along with SDK/JSON bag decoding. Largest fixture not repeated after schema/read-validation-only changes.

Pint check of all14 assigned PHP files: `{"tool":"pint","result":"passed"}`. Shared baseline copies and before/after SHA256 live in source-manifest.json; adapter-delta.patch contains only this assignment's delta against those copies plus8newfiles. Full shared WIP predates this assignment and remains preserved.

Shared Pest verification completed without production environment hooks or TestCase/vendor changes: `php -d memory_limit=256M vendor/bin/pest --bootstrap storage/framework/testing/t12-dataset-api/implementation/pest-bootstrap.php tests/Feature/Studio/StudioApiMcpTest.php` => `{"tool":"pest","result":"passed","tests":15,"passed":15,"assertions":199,"duration_ms":751}`. Catalog42 tools confirmed by real paginated tools/list and registered server classes. Harness creates its seed with an absent environment sentinel before Kernel bootstrap, exports scalar/array-only dummy configuration, sets existing relative APP_CONFIG_CACHE + LARAVEL_STORAGE_PATH to UUID-private paths before generic parent TestCase runs, and asserts cached config/sqlite memory/dummy key/private storage. Laravel LoadEnvironmentVariables returns before Dotenv for cached configuration. First harness run had15risky due seed error handlers; official HandleExceptions::flushState() cleans seed handlers, final0risky. No bootstrap env flag introduced; exploratory failing test for that discarded approach was removed before delivery.

Open: independent adversarial review; real TCP multipart/server PHP limits; deployment512M for large MCP; XLSX/chunk protocol/editor/chart/document/Office work; T12 remains open. API upload is the actual fallback for clients unable to send large tool requests.

## ROOT reproducibility correction

ROOT independent root-verification1/kernel-standard run observed22tests:20PASS,1error,1skip,173assertions. Error in guard fixture was `tempnam(): file created in the system temporary directory`: the three temporary file probes incorrectly assumed ignored implementation scratch existed below the active storage root. This was a harness/CI reproducibility error, not a product RED. Original ROOT evidence remains in root-verification1.

Only StudioDatasetApiTest changed: setUp creates a fresh UUID fixture directory below active storage/framework/testing/t12-dataset-api; all3tempnam sites use it. Existing finally unlink remains, with no other storage deletion. Focal guard with a fresh LARAVEL_STORAGE_PATH UUID root:1/1PASS,1assert,553msPHP256M. Standard with a different fresh UUID storage root:22tests,21PASS,1optinSKIP,174assert,5430msPHP256M. PintPASS.25MB/MCPlarge probes not repeated for a fixture-path-only correction. Manifest/delta regenerated for14assignedfiles; only test source SHA changed.
