# T12 chart data: evidence mirror (tasks.md remains canonical)

Command prefix for focal runs: `powershell -NoProfile -File storage/framework/testing/t12-chart-data/run.ps1 -Filter <filter>`.

| Criterion/filter | RED raw directory and result | GREEN raw directory and result |
|---|---|---|
| normalization preserves | run_bf46518498a04ed99f406066cc621e08: 1 failed, identity assertion [] | run_e5b10899a93b43848e5e526d02a7285b: 1 passed, 2 assertions |
| normalization rejects (25 schema/limits datasets) | run_bb09cefb6afb409981fc569d0219bea5: 25 failed, exception not thrown | run_52356869ace94a36a9ad228f0d684f3f: normalization neighborhood 27 passed, 30 assertions |
| import preserves/rejects | run_39acb36e25a34ec59e643f6bbdb68c25: 16 failed (identity [] and exceptions absent); corrected unclosed fixture independently rerun run_7e2ffc8df0bd4e60b83d220fd46e1231: 5 malformed datasets failed | run_62344585af6b489d898a5ee893b40a38: import neighborhood 17 passed, 25 assertions |
| import canonicalizes CRLF inside quoted field | run_9614d9389f18496685be9917e280c8c3: 1 failed identity [] vs canonical LF table | run_62344585af6b489d898a5ee893b40a38: included in 17 passed |
| export lexical bytes and validation | run_9bf722018c854178a0f8cb18266d2e5b: output assertion failed and validation exception absent; two downstream Input is empty errors are NOT independent RED criteria | run_6a282d10156d4b67b9afbe6fc49a879f: 4 passed, 14 assertions including textual roundtrip and explicit type loss |
| literal leading U+FEFF identity | run_d02baf37eeca464aafb7ce04b8690fc9: 1 failed identity U+FEFF stripped, 1 exact-byte characterization already passed | run_837556837c1d41fca9ae323a2f34da44: 1 passed, 2 assertions |

All raw directories above contain `output.txt` and the test snapshot used at that point. Earlier harness errors and run_2f8ad3c47a2040e8a946d05591a1252f (warning-prone assertion corrected immediately) are not counted as criterion evidence.

Final post-format focal neighborhood, command without -Filter: run_9781174ea5774cff92e5998dcb9ddc15/output.txt

{"tool":"pest","result":"passed","tests":50,"passed":50,"assertions":74,"duration_ms":102}

Existing independent CSV neighbor was copied unchanged from tests/Unit/CsvWriterTest.php into an isolated --test-directory, with --no-configuration --bootstrap vendor/autoload.php. Raw: neighbors_f138ce7e4b2d48a6aa929a82619ac952/output.txt

{"tool":"pest","result":"passed","tests":3,"passed":3,"assertions":15,"duration_ms":35}

`php vendor/bin/pint app/Services/Studio/StudioChartData.php tests/Feature/Studio/StudioChartDataTest.php --test --no-interaction`

{"tool":"pint","result":"passed"}

`php -l app/Services/Studio/StudioChartData.php` => No syntax errors detected in app/Services/Studio/StudioChartData.php

`php -l tests/Feature/Studio/StudioChartDataTest.php` => No syntax errors detected in tests/Feature/Studio/StudioChartDataTest.php

Limitations: internal lexical CSV/TSV codec only; no spreadsheet formula neutralization, download endpoint, table editor, chart renderer, XLSX, connected sources or Office exports. CSV/TSV does not preserve numeric/null types or IDs. Headerless zero-row output is empty by definition and cannot reimport into a table (no column metadata); header-bearing zero-row output roundtrips. No Laravel bootstrap, database, .env, Chromium, FFmpeg, model/native process or network was used. No QA acceptance or T12 closure claimed. Sources frozen at manifest hashes pending independent review.

## Review B1 Minor correction: attempt 2

Confirmed defect: import semantic text validation reported data-row index while CSV syntax validation reported CSV record index. Shared validator now receives the number of header records; imported header labels use record 1 and column, imported data rows include the header offset. Direct normalize keeps data-row indexing. No exception-message rewriting.

Sources and manifest before correction: review/attempt1/. Frozen corrected sources, manifest and two delta patches: review/attempt2/. Root manifest and complete new-file patches reflect attempt 2.

Command: `powershell -NoProfile -File storage/framework/testing/t12-chart-data/run.ps1 -Filter 'CSV record|normalization errors continue'`

RED run_fc4440664acc4cda9a5da6f0504a3515/output.txt: 7 tests, 3 passed, 4 failed, 10 assertions. Two imported data-cell cases (NUL and 10001 characters) expected row 3 but got row 2; two header-cell cases (NUL and 501 characters) expected row 1 column 2 but lacked record context. Headerless import and direct-normalize cases already passed and are characterizations, not additional REDs. Multiline valid field counts as one CSV record.

GREEN run_882dc4209b1442d8bd3b937048412745/output.txt:
{"tool":"pest","result":"passed","tests":7,"passed":7,"assertions":14,"duration_ms":23}

Final full focal command without -Filter, run_44d5c6c36ad04f379c75172c23599e00/output.txt:
{"tool":"pest","result":"passed","tests":57,"passed":57,"assertions":88,"duration_ms":80}

Pint --test on the two source files: {"tool":"pint","result":"passed"}
Both source files pass php -l. Existing CsvWriter source remains untouched; its earlier independent 3-test/15-assertion result applies to unchanged code. T12 remains open; no integration or QA acceptance claimed.
