# Correction 2: A1 Unicode parity

READY. Sources frozen after the following pure Node checks. The initial t12-chart-contract-js snapshot and evidence were not rewritten.

Scope: resources/js/Studio/chart-spec.js and tests/frontend/studio-chart-spec.test.mjs only. Before copies, before-sha256.json, patches and source-sha256.json are in this directory. No document/fonts/fixtures/docs/ledger/OpenAPI change in this correction.

RED: studio-chart-spec.test.mjs::C1 Unicode rejects isolated high/low surrogate in title/subtitle/source/x_title/y_title failed with Missing expected exception (TypeError), 2026-11-06. Ten focal tests failed before production code was changed; raw red-unicode.txt, NODE_EXIT=1. No import or syntax error.

Minimal fix: the existing prohibited-control character class now also includes the surrogate range, with the Unicode regex flag. Unicode regex matching operates on code points, so valid high/low surrogate pairs remain valid non-BMP code points; isolated code units are rejected. No cast, replacement or silent normalization.

GREEN: green-unicode.txt 15/15, NODE_EXIT=0. Ten original RED rejection tests plus five explicitly labeled valid-pair characterizations. green-regression.txt 174/174, NODE_EXIT=0: the previous 159 tests plus 15 new tests. The shared fixture remains unchanged (2 valid and 18 invalid cases).

Focal command: node --max-old-space-size=512 --experimental-test-isolation=none --test --test-name-pattern='C1 Unicode' tests/frontend/studio-chart-spec.test.mjs

Regression command: node --max-old-space-size=512 --expose-gc --experimental-test-isolation=none --test tests/frontend/studio-chart-spec.test.mjs tests/frontend/studio-chart-document.test.mjs tests/frontend/studio-document.test.mjs tests/frontend/studio-advanced-document.test.mjs tests/frontend/studio-advanced-loading.test.mjs tests/frontend/studio-timeline.test.mjs tests/frontend/studio-timeline-operations.test.mjs tests/frontend/studio-captions.test.mjs tests/frontend/studio-chart-binding.test.mjs tests/frontend/studio-bar-geometry.test.mjs tests/frontend/studio-bar-prepared.test.mjs

No PHP, browser, build, database, external environment or network execution. No UI/render/export/AV acceptance. T12 remains open. Handoff: review 2 against correction2/source-sha256.json.
