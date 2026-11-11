# TDD evidence (private; root to transfer to canonical ledger)

No Laravel bootstrap, .env or DB loaded. Only composer autoload plus private candidate. All writes stayed within this folder. No commits, network, deps, build, UI or native requests.

Functional interface stubs threw LogicException before implementation; these are missing-behavior REDs, not import/name errors. Existing 57 tests and later edge/boundary additions are explicitly characterization, not fabricated RED.

## RED stream boundaries

`php -d memory_limit=256M vendor/pestphp/pest/bin/pest --no-configuration --bootstrap storage/framework/testing/t12-stream-codec/bootstrap.php storage/framework/testing/t12-stream-codec/StreamTest.php --filter "stream boundaries" --colors=never`

```text
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":0,"duration_ms":71,"errors":1,"error_details":[{"test":"P\\Storage\\framework\\testing\\t12streamcodec\\StreamTest::__pest_evaluable_stream_boundaries_preserve_BOM_Unicode_quotes_CRLF_and_literal_BOM","file":"\\laragon\\www\\metricool\\storage\\framework\\testing\\t12-stream-codec\\CandidateChartData.php","line":13,"message":"Streaming import not implemented","trace":["C:\\laragon\\www\\metricool\\storage\\framework\\testing\\t12-stream-codec\\CandidateChartData.php:13","C:\\laragon\\www\\metricool\\storage\\framework\\testing\\t12-stream-codec\\StreamTest.php:9"]}]}
```

## RED server dataset

`php -d memory_limit=256M vendor/pestphp/pest/bin/pest --no-configuration --bootstrap storage/framework/testing/t12-stream-codec/bootstrap.php storage/framework/testing/t12-stream-codec/StreamTest.php --filter "server dataset" --colors=never`

```text
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":0,"duration_ms":62,"errors":1,"error_details":[{"test":"P\\Storage\\framework\\testing\\t12streamcodec\\StreamTest::__pest_evaluable_server_dataset_profile_accepts_over_inline_budget_without_changing_default","file":"\\laragon\\www\\metricool\\storage\\framework\\testing\\t12-stream-codec\\CandidateChartData.php","line":92,"message":"Input must be valid UTF-8 and at most 2 MiB","trace":["C:\\laragon\\www\\metricool\\storage\\framework\\testing\\t12-stream-codec\\CandidateChartData.php:92","C:\\laragon\\www\\metricool\\storage\\framework\\testing\\t12-stream-codec\\CandidateChartData.php:99","C:\\laragon\\www\\metricool\\storage\\framework\\testing\\t12-stream-codec\\StreamTest.php:16"]}]}
```

## RED unknown server

`php -d memory_limit=256M vendor/pestphp/pest/bin/pest --no-configuration --bootstrap storage/framework/testing/t12-stream-codec/bootstrap.php storage/framework/testing/t12-stream-codec/StreamTest.php --filter "unknown server" --colors=never`

```text
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"duration_ms":86,"failed":1,"failures":[{"test":"P\\Storage\\framework\\testing\\t12streamcodec\\StreamTest::__pest_evaluable_unknown_server_profile_fails_closed","file":"\\laragon\\www\\metricool\\storage\\framework\\testing\\t12-stream-codec\\StreamTest.php","line":22,"message":"Exception \"InvalidArgumentException\" not thrown.","trace":["C:\\laragon\\www\\metricool\\storage\\framework\\testing\\t12-stream-codec\\StreamTest.php:22"]}]}
```

## RED JSON budget

`php -d memory_limit=256M vendor/pestphp/pest/bin/pest --no-configuration --bootstrap storage/framework/testing/t12-stream-codec/bootstrap.php storage/framework/testing/t12-stream-codec/StreamTest.php --filter "JSON budget" --colors=never`

```text
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":1,"duration_ms":120,"failed":1,"failures":[{"test":"P\\Storage\\framework\\testing\\t12streamcodec\\StreamTest::__pest_evaluable_JSON_budget_stops_before_traversing_a_virtual_huge_tail","file":"\\laragon\\www\\metricool\\storage\\framework\\testing\\t12-stream-codec\\StreamTest.php","line":31,"message":"Failed asserting that 'row 1000, column 1 must be a string, finite number or null' [ASCII](length: 58) contains \"Canonical table exceeds 2 MiB\" [ASCII](length: 29).","trace":["C:\\laragon\\www\\metricool\\storage\\framework\\testing\\t12-stream-codec\\StreamTest.php:31"]}]}
```

## RED export chunks

`php -d memory_limit=256M vendor/pestphp/pest/bin/pest --no-configuration --bootstrap storage/framework/testing/t12-stream-codec/bootstrap.php storage/framework/testing/t12-stream-codec/StreamTest.php --filter "export chunks" --colors=never`

```text
{"tool":"pest","result":"failed","tests":1,"passed":0,"assertions":0,"duration_ms":17,"errors":1,"error_details":[{"test":"P\\Storage\\framework\\testing\\t12streamcodec\\StreamTest::__pest_evaluable_export_chunks_yields_complete_lexical_records_matching_string_wrapper","file":"\\laragon\\www\\metricool\\storage\\framework\\testing\\t12-stream-codec\\CandidateChartData.php","line":267,"message":"Streaming export not implemented","trace":["C:\\laragon\\www\\metricool\\storage\\framework\\testing\\t12-stream-codec\\CandidateChartData.php:267","C:\\laragon\\www\\metricool\\storage\\framework\\testing\\t12-stream-codec\\StreamTest.php:92"]}]}
```

## GREEN and characterizations

- `green-initial.txt`: 61/61,102 assertions,728ms after initial changes.
- `green-final.txt`: 65/65,174 assertions,699ms after formatting; legacy57 unchanged behavior. Additional split/EOF/invalid chunk, exact JSON with escape/number/key-order variants passed initially and are characterizations.
- `green-25MB.txt`: 1/1,9 assertions,14237ms; exact25MB/hash/first-last/+1. Peak PHP allocator58,720,256B under memory_limit256M.
- `green-52MB.txt`: 1/1,4 assertions,267ms; exact52M/+1 canonical boundary characterization.
- `green-all-final.txt`: final combined run, read raw result.
- `pint-final.txt`: passed, five private PHP files.

No source app/test promotion has happened. `service.patch` and `tests.patch` describe only the proposed two-file change; `proposed/` contains app-namespace candidates. `manifest.json` binds source/candidate hashes and the exact25MB fixture hash. Full T12 remains open.


Exact final command:

```powershell
php -d memory_limit=256M vendor/pestphp/pest/bin/pest --no-configuration --bootstrap storage/framework/testing/t12-stream-codec/bootstrap.php storage/framework/testing/t12-stream-codec/LegacyTest.php storage/framework/testing/t12-stream-codec/StreamTest.php storage/framework/testing/t12-stream-codec/LargeDatasetTest.php --colors=never
```

JSON52M boundary proof independently counts `strlen(json_encode(['columns'=>columns,'rows'=>[]], flags))`, then each complete row encoding plus commas; it fills the exact difference with two-byte backslashes and one-byte ASCII if odd. It does not call or inspect the candidate counter. Exact52M normalizes unchanged; one more ASCII byte throws. The 25MB fixture SHA256 is `20f9d6b6754f93fb94fc301a0e137392bfed36673509703943d5f2e698c040a1`.

The first combined run passed67/67,187 assertions,16104ms,PHP peak60,817,408B, but PowerShell surfaced the diagnostic written to STDERR as NativeCommandError/shell exit1. Diagnostic output was changed to STDOUT and the combined run repeated; see final raw log and PHP_EXIT capture. This was runner output handling, not a product test failure.

Final repeated combined run: 67/67,187 assertions,14710ms,peak PHP60,817,408B,memory_limit256M,PHP_EXIT=0. Final raw log `green-all-final.txt`.
