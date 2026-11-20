# T-75: revisión real de Opus 5.5, intento 2

SHA-256 del recibo: c5d132681b5e775e2d313977776d44566a22d866da79f671ed4fc3d06f684741

# T-75 attempt 2: independent static review

This is a static read of the attached patch and context only. I executed no tests, builds or queries. All runtime results below are the ones you reported.

## Verdict

**One Important finding is open: Q-01, a corrupted table header.** It is a one-character fix with an E2E assertion to add. Once it is fixed and E2E-15 is re-run, I see no open Critical or Important findings.

**The prior findings are resolved:**
- **B1/C1:** brand attribution is no longer inferred from `resource`/`resource_id`.
- **D1:** the queries are bounded and use the indexes.
- **D2, Q1 and Q2:** addressed as you described.

## Findings

### Q-01 · Important · Corrupted "Acción" column header
- **Where:** `resources/js/Pages/Activity/Index.vue:118` reads `<th …>Acci�n</th>`.
- **Why I think it is in the file:** the same file renders `Acción` correctly at `:75`, and every other accented character in the patch is intact. That points to a stray non-UTF-8 byte on that one line, not a lossy paste.
- **Scenario:** every user of the new screen sees a broken column heading. Screen readers announce the replacement character as part of the header's accessible name, which undoes part of the accessibility work on this table.
- **Why no gate caught it:** E2E only targets `getByLabel('Acción')`, which is the select's label. Axe, ESLint, TypeScript and the build all accept the character.
- **Check:** `rg -n --pcre2 "Acci(?!ón)" resources/js/Pages/Activity/Index.vue`
- **Fix:**
  - Re-save the line as UTF-8.
  - Add `await expect(page.getByRole('columnheader', { name: 'Acción', exact: true })).toBeVisible();` to `tests/e2e/activity.spec.ts`.
  - Re-run E2E-15, since the criteria require serial runs with no code changes in parallel.

### Q-02 · Minor · `ApiController` attribution state is never reset
- **Where:** `app/Http/Controllers/Api/ApiController.php:28,36,88`
- **Scenario:** Laravel caches the controller instance on its `Route` (`Route::getController()`). Two requests on the same route can therefore share one instance:
  - multiple requests within one Pest test;
  - a long-lived worker that does not flush controllers.
  
  If the second request reaches `audit()` without calling `ownedBrand()`, it inherits the previous request's brand.
- **Impact:** limited. `AuditLog` re-checks that the current actor owns the brand and drops it on `DENIED`, so it cannot leak across owners. The worst case is same-owner misattribution.
- **Asymmetry with MCP:** `SchedulerTool::handle` resets its state (`:69`) and declines multiple brands. Here a second `ownedBrand()` call simply overwrites the first.
- **Fix:** reset `auditBrandId` at the start of `respond()`, or record the first brand and null it on a conflict, to match the MCP adapter. No contract change is needed.

### Q-03 · Minor · Adapters disagree on who drops the brand
- **Where:** `app/Mcp/Tools/SchedulerTool.php:100-102`
- **Scenario:** the HTTP/Ownership branch omits `brandId` even for `ERROR` outcomes such as 403, 409 and 429. A 409 after a verified `brandFrom()` therefore stays personal, while a 409 raised as `PostStateConflict` (`:107`) is attributed.
- **Why it matters:** nothing leaks, and the docs already allow this. But `ApiController` passes the brand on every outcome and relies on `AuditLog` to drop it for `DENIED`. The two adapters use different conventions.
- **Fix:** pass `brandId: $this->auditBrand()` uniformly. `AuditLog:32` already drops it for `DENIED`.

### Q-04 · Minor · Historical-row test is tautological and runs DDL inside the test
- **Where:** `tests/Feature/Activity/ActivityLogTest.php:189-210`, with `$migration->down()` at `:195` and `up()` at `:204`.
- **Tautological:** the migration no longer has any data logic, so the test only asserts that a newly added nullable column is null.
- **DDL risk:**
  - On MySQL, `ALTER TABLE` implicitly commits the `RefreshDatabase` transaction, so this test's fixtures can persist into later tests. Later tests assert on the global `AuditLog::query()` order, e.g. `:247`.
  - A failure between `down()` and `up()` leaves the schema without `brand_id` for the rest of the run.
  - Your MySQL pass suggests the harness tolerates this today, but the test stays fragile.
- **Fix:** replace it with plain `activityEntry(..., ['brand_id' => null])` fixtures and assert they are visible personally. That still covers the user-visible behaviour.

### Q-05 · Minor · Test gaps on new attribution paths
- The `PostApiController.php:35` change (`get_post` → `$post->brand_id`) has no direct test. The writer test calls `AuditWriter` directly, and the REST test covers `list_posts` only.
- The `brandFrom()` name path (`SchedulerTool.php:183-184`) has no test.
- Add one assertion each.

### D-01 · Minor · Unfiltered page plan relies on dense visibility
- **What the plan shows:** the no-brand page query uses `audit_logs_created_at_prune` with a backward index scan, `rows_examined 51` and `filtered 19%`. That cost is cheap only because the fixture makes 1/3 of all rows visible.
- **Unmeasured cases:**
  - an owner with few rows in a large multi-tenant table;
  - a rare `action` filter with no brand selected.
  
  If the optimizer keeps the ordering index, MySQL walks `created_at` backwards until 51 rows match. In the worst case that is a scan of the whole retained table.
- **Already efficient:** brand-selected pages (`(brand_id, created_at)` plus the PK) and counts.
- **Action:** this does not block closure. Run one more `EXPLAIN ANALYZE` with a sparse user (e.g. 50 visible rows out of 60k) and with a rare action. Only act if it degrades.

### Nits (no action required)
- **`created_at` is typed as nullable but is not.**
  - `ActivityController.php:83` uses `?->` and `types/activity.d.ts:8` types `created_at` as `string | null`.
  - The plan's `key_length: 4` for the `created_at` index shows the column is `NOT NULL`, so this handling is dead code.
- **`?user_id=<self>` returns 404 for a user with no visible rows.** `ActivityController.php:47-48` validates against the actor list, so a bookmarked URL breaks after a retention purge. An empty list would be friendlier, though the current behaviour is consistent with other invalid selectors.
- **Brand rows are only ever by the owner.**
  - `AuditLog:32-33` requires the actor to own the brand, so newly written brand rows always have `user_id = owner`.
  - As a result, the `$branded` branch and the "Usuario" options only ever contain the owner, and the fixture at `ActivityLogTest.php:68` cannot be produced by the writer.
  - The read side is still correct as defense in depth. Consider one sentence in `docs/usuario/actividad.md` saying that other people's actions on your brand are not attributed to it.
- **Leftover in the migration:** the comment and blank line inside `up()` (`:18-19`) can go.

## Criteria status

| Criterion | Status |
|---|---|
| A · Only own activity plus owned brands | **Met.** Read side: the disjoint `user_id = me` and `brand ∈ owned ∧ user ≠ me` branches (`ActivityController:36-40`) cover exactly the visible set, and a selected brand is pre-authorized (`:33`). Write side: attribution only from explicit context, owner re-check, `DENIED` dropped (`AuditLog:32-34`). Multiple brands or no context stays personal. |
| A · Filters and pagination | **Met.** Composable filters; brand-timezone day bounds including DST; uniform 404 for foreign and missing selectors; total order `created_at DESC, id DESC`; cursor scoped to user plus filter hash; the total sums the disjoint counts correctly in every filter combination. |
| A · Retention shown in the UI | **Met** for the config value, including the 0 and N cases. The claim that 0 disables cleanup depends on the pruner, which is not in the packet. |
| A · Adversarial review with no open Critical/Important | **Not met until Q-01 is fixed.** |
| A · Pest and serial Playwright green | **Met per your reported evidence.** After Q-01, the E2E-15 run must be repeated. |
| A · Docs and changelog | **Met.** ES and EN changelog, user guide, docs index and dedicated page; the historical limits are disclosed honestly. |
| B · Correctness | No logic defects. Q-02 and Q-03 are consistency issues, not correctness breaks given the central re-check. |
| C · Security | No findings. No `detail`, `ip`, `token_id`, `resource` or `resource_id` reaches the client; selectors don't reveal existence; actor IDs are limited to the visible set; there is no cross-owner path. Prior B1/C1 is closed. |
| D · Performance | Bounded queries using the indexes, as measured; the write path adds one indexed PK lookup per attributed audit. D-01 is an unmeasured edge case, not a blocker. |

## Code quality verdict

**Acceptable once Q-01 is fixed.**
- **Cohesion:** the controller is a single action with one reused `$applyFilters` closure. Attribution is split cleanly: adapters collect verified context, and `AuditLog` is the single authority that re-checks it.
- **Error handling:** the writer still logs failures and never throws. No MCP or REST result contracts changed.
- **Complexity:** removing `AuditResource` and the backfill reduced it. What remains is proportional to the task.
- **Tests:** meaningful and adversarial, covering the colliding-id RED case, instance reuse, multiple brands, integrity mismatch, DST, cursor binding and field privacy. Q-04 is the one weak test.
- **Queries:** efficient for the measured shape. See D-01 for the sparse case.
