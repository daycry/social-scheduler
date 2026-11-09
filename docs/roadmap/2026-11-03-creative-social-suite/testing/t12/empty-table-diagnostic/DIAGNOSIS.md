# Private F1–F3 proposal — no product changes

Scope: T12 remains open (8/23); consumer-local-qa2 is NO-PASS. No browser or native runtime has been executed by this preparation. No full-app or accessibility acceptance is claimed.

F1 evidence: public consumer-local-qa2/raw/1440-axe-empty-historic.json has violations=[], incomplete=[th-has-data-cells], one serious table node, related header Serie 1. This is an incomplete/manual-review outcome, not a confirmed violation. Public E2E B4 explicitly requires 0 tbody rows in historic revision 1 while current revision 2 contains a row.

F2 isolation: StudioChartsDialog.vue line 118 always renders caption/thead and maps displayedRows to tbody. With 0 rows, visible column headers have no data cells. tests/frontend/studio-charts-dialog.test.mjs covers historical validity and table presence but does not assert empty-state semantics or execute axe. Readiness, stale binding, contrast, and replacement with current revision are not explanations for this specific rule outcome.

Installed axe-core 4.10.3 local primary sources:

- axe.js 20474–20518: thHasDataCellsEvaluate traverses down from column headers, checks getHeaders(cell).includes(header), and returns undefined when any header lacks a related cell. This causes incomplete; it does not return false.
- 20223–20276: getAllCells uses table.rows, and getHeaders traverses the grid across sections.
- 12498–12522: toGrid expands colspan into repeated cell slots; table.rows includes footer rows.
- 32093–32103: rule remains the real data-table rule. No filter or exemption is proposed.

F3 falsifiable hypothesis: with unchanged real table/thead/empty tbody, a visible tfoot cell spanning all 3 columns and saying “Esta revisión no contiene filas.” gives each header an associated cell and communicates a genuine table-wide empty state. The native 3-state probe must reproduce baseline incomplete and show rule passes (not inapplicable) for footer candidate and real 1-row control, with no violations. Pure source inspection predicts this but DOES NOT CONFIRM native axe behavior. If the candidate does not satisfy those conditions, do not apply it.

Semantic evaluation: tfoot is a table-wide summary row, and the message describes this revision's absence of data. It is outside tbody, preserving 0 data rows. The cell spans all selected columns including Fila. It adds no row ID, numeric value, historical row or provider data. Retained caption/column headers preserve the shape of the selected dataset. The existing external status keeps pagination and actual row count. This is a defensible proposal requiring native evidence and later full-app strict QA; single-rule success cannot establish full accessibility or assistive-technology usability.

Alternatives: an external status alone is already present and does not change header/cell relationships; an empty-state tbody row would violate the explicit 0 tbody row contract; hiding headers/table or presentation role would remove semantics and could evade the rule. These alternatives are not proposed. A caption-only semantic redesign would change the visible/accessible structure and requires a separate product decision.

Fixture limitation: latest axe has only the table opening tag, not complete outerHTML. Baseline is explicitly RECONSTRUCTED from that opening tag/scope, real Vue table template and B4 labels Año/Serie 1. The one-row control uses already-authorized fictional B4 datum new_row / Current only / 25. It is not a captured live DOM. All current build6 manifest CSS is copied inline read-only; no external paths are served.

Root execution after review only:

`python -B storage/framework/testing/t12-chart-empty-table-diagnosis1/run.py --execute "revision T12 empty table diagnosis1 aprobada; ejecuta una pasada local"`

Resources: canonical Q guard, 90s deadline, 3GiB aggregate Job, 6GiB physical preflight, 3GiB floor, 100ms sampling; Node512; browser deadline80s/supervisor85s; no server/network/DB/PHP/build/PDF. Immutable execution1; no retries. Screenshots, observed table HTML, labels, body/footer counts and native cell/span/section details are captured per case; header associations are tested by the real axe rule. Root must review sources, execute once, inspect real evidence, and acknowledge before any F4 fix/TDD.
