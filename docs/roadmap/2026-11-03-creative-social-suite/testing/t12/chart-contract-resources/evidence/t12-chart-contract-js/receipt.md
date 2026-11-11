# C1 JS contract implementation receipt

Scope: only chart-spec.js, document.js, fonts.js, studio-chart-spec.test.mjs and studio-chart-document.test.mjs. Existing WIP retained; before/document.js and before/fonts.js preserve the exact starting files. document.patch and fonts.patch compare against those starting files, not repository HEAD.

TDD skill read: custom-agents/tdd/SKILL.md.

RED: studio-chart-spec.test.mjs::C1 normalizes omitted options, style and precision into owned frozen defaults failed with actual options undefined versus the exact contract defaults, 2026-11-06. Executed against an identity stub (not an import error). Raw: red-defaults.txt.

RED: red-contract.txt contains the functional failures before implementation for rejected fixture mutations, unsafe descriptors/sparse data, option semantics, style limits, chart document persistence/dimensions and chart private-font discovery. 27 failed / 1 passed. The full valid fixture passed the identity stub and is a characterization, not claimed as TDD. The v2 test initially also had a transition on its only/last page; a second page was added to satisfy that existing unrelated timeline rule, and captions were added to verify preservation. The 2 MiB ceiling test was added after implementation and is explicitly a characterization of the existing document limit.

GREEN: green-spec.txt 24/24. green-contract-final.txt 29/29. green-regression-gc.txt 159/159, Node 22.23.2, --max-old-space-size=512 --expose-gc --experimental-test-isolation=none. Initial regression runner omitted --expose-gc (green-regression.txt:158/159); the sole failure requested the GC runner option. No production change was made for that failure.

Regression command: node --max-old-space-size=512 --expose-gc --experimental-test-isolation=none --test tests/frontend/studio-chart-spec.test.mjs tests/frontend/studio-chart-document.test.mjs tests/frontend/studio-document.test.mjs tests/frontend/studio-advanced-document.test.mjs tests/frontend/studio-advanced-loading.test.mjs tests/frontend/studio-timeline.test.mjs tests/frontend/studio-timeline-operations.test.mjs tests/frontend/studio-captions.test.mjs tests/frontend/studio-chart-binding.test.mjs tests/frontend/studio-bar-geometry.test.mjs tests/frontend/studio-bar-prepared.test.mjs

Public font registry addition: style(element) returns fontFamily/fontWeight/fontStyle from chart.style or a text element; private font metadata supplies its actual registered weight/style. family(element) now recognizes chart.style. usedStudioFonts recursively collects both text and chart IDs and preserves the existing scoped-resource/byte checks.

Limitations: pure JS contract checks only. No Chromium, build, PHP, database, real network or models. No canvas/Editor/Inspector implementation. Canvas font-wait walker remains assigned to root. C2 typed-table validation and C3 rendering/UI/export remain outside this subtask. T12 stays open. No UI/render/AV acceptance claimed. No git mutation or fixture/doc/ledger edits.
