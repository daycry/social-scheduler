# Review attempt2 corrections: B1 and D1

2026-11-06. Four source files changed: SchedulerTool, ManageStudioDataset, LimitStudioDatasetPayload, StudioDatasetApiTest. Before14copies+SHA saved under attempt2-before BEFORE source changes; after14copies+SHA under attempt2-after. attempt2-delta.patch is relative to review1 sources; adapter-delta.patch remains complete assignment delta against original shared baselines. No9core sources changed, no documentation/contract/OpenAPI edits, no commits, no T12 closure.

## Verified B1 / float serialization

Vendor Laravel/Mcp/Response.php Response::json uses JSON_THROW_ON_ERROR|UNESCAPED_SLASHES|UNESCAPED_UNICODE but not PRESERVE_ZERO_FRACTION. Response::text(string) is the supported equivalent content carrier. RED focal before code: actual McpClient tools/call import with typed literal JSON values `[0.0,1.0,-0.0,null,""]`, followed by actual tools/call rows, produced `[0,1,0,null,""]`;1test failed at5assertions. Input fixture is literal JSON inside text, so test encoding cannot erase fractions before server receives it.

Minimum fix: protected SchedulerTool::resultResponse defaults to existing Response::json for all other41tools; ManageStudioDataset overrides only that hook to Response::text(json_encode(result, StudioDatasetInput::JSON_FLAGS)). Focal GREEN1/1,6assert,502ms256M; both PHP float identities and canonical text `"values":[0.0,1.0,-0.0,null,""]` asserted.

## Verified D1 / MIME guard bypass

RED focal before code: instrumented real Request + real ValidateMcpHeaders + HttpTransport + SchedulerServer::handle/start, eight small cases: text/plain, application/JSON, no Content-Type, application/json, crossed with absent Content-Length or inconsistent Content-Length=1. All SDK calls returned200 with30discovered tools; guard's pre-SDK bounded resource read count was0 for first6cases and1 only for lowercaseapplication/json controls.1test failed at17assertions. No gigantic payload was generated.

Fix: cap every POST /mcp independently of isJson; keep JSON-only dataset HTTP matching and53MiB, MCP160MiB. Non-POST MCP GET/SSE and unrelated callers bypass this middleware without any body read. Supported Symfony content initialization still preserves request/resolvers/headers. Focal GREEN1/1,17assert,1239ms256M. GET/unrelated characterization1/1,4assert,584ms. No media rejection introduced; mixed-case JSON continues through real SDK correctly.

## Newly verified D1 shape consequence

Before fix, actual Laravel kernel authenticated MCPcreate accepted all6invalid shape cases: rows:{} or columns:{"0":{id,label}}, each under text/plain, application/JSON, missing Content-Type. All returned200/isErrorfalse and persisted an invalidly accepted empty table because SDK associative decoding collapsed original object/list identities. RED1test failed at7assertions.

Fix: ManageStudioDataset typed-table raw-object reread now depends on actual /mcp path and presence of table, independently of MIME; raw body is already bounded for all MCP POSTs. Focal GREEN1/1,7assert,1384ms256M, all6return isErrortrue. Domain normalization remains untouched.

## Verification

Each focal uses `php -d memory_limit=256M vendor/bin/phpunit tests/Feature/Studio/StudioDatasetApiTest.php --filter <method>`.

Methods: test_mcp_rows_preserve_float_zero_fractions_and_negative_zero_from_literal_import; test_all_mcp_post_media_types_are_bounded_before_real_sdk_decoding; test_mcp_typed_shape_rejection_does_not_depend_on_content_type; test_mcp_get_stream_and_unrelated_post_do_not_read_request_body_in_dataset_guard.

After changes/Pint, standard with fresh UUID LARAVEL_STORAGE_PATH:

`php -d memory_limit=256M vendor/bin/phpunit tests/Feature/Studio/StudioDatasetApiTest.php --filter '^(?!.*test_public_upload_accepts_real_25000000_byte_csv_and_paginates)' --log-junit storage/framework/testing/t12-dataset-api/implementation/attempt2-standard.xml`

Actual output: `{"tool":"phpunit","result":"passed","tests":26,"passed":25,"assertions":208,"duration_ms":6706,"skipped":1}`.22prior tests +4newtests; original opt-in MCPgrande skipped,25MB excluded intentionally.25MB and51MB probes already measured; serialization/MIME fixes do not justify rerunning large payloads.

Shared MCP regression after SchedulerTool change, official cached-config disposable harness:

`php -d memory_limit=256M vendor/bin/pest --bootstrap storage/framework/testing/t12-dataset-api/implementation/pest-bootstrap.php tests/Feature/Studio/StudioApiMcpTest.php`

Actual output15/15PASS,199assert,3330ms,0risky. All14assignedPHPfiles Pint --test PASS. Sources frozen for independent review2. Prior ROOT fixture-path harness failure remains documented separately; not recounted as product RED.
