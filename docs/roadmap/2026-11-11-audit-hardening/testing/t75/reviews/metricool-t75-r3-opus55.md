# T-75: revisión final real de Opus 5.5

SHA-256 del recibo: 6f75eab3cb57e2b5229dee73ba11a6ebd2c528709b31a885f852a8691cd01863

# T-75 attempt 3: static follow-up review

This is a static read of the delta and the supplied files. I ran nothing. All runtime results below are the ones you reported or the logs you attached.

## Verdict

**No Critical or Important defects remain.** Q-01 through Q-05 and D-01 are resolved. There is one new Minor documentation-precision item in text added by this delta (N-01). It does not block closure.

## Resolution of R2 findings

| ID | Status | Basis |
|---|---|---|
| **Q-01** | **Resolved** | `Index.vue:118` now reads `Acción`. `activity.spec.ts:33` asserts it with an exact `columnheader` role. The r33 browser log shows E2E-15 passing on desktop, mobile and mobile-360. That run matches the metadata: 13 passed, 2 skipped, start 23:19:37, about 1.2 min, and `source_hashes_verified_after_browser: true`. The table only renders when there are entries (`:105`), and entries exist by `:33`, so the assertion actually runs. |
| **Q-02** | **Resolved** | See the Q-02 notes below. |
| **Q-03** | **Resolved** | `SchedulerTool.php:102` now passes `auditBrand()` uniformly. `OwnershipException` and HTTP 404 map to `DENIED`, and `AuditLog.php:32-34` drops the brand for those. The test at `ActivityLogTest.php:328-337` covers 403, 409 and 429 (attributed) and 404 (dropped centrally even though the post was verified). |
| **Q-04** | **Resolved** | See the Q-04 notes below. |
| **Q-05** | **Resolved** | `:339-346` covers the explicit `brandId: $post->brand_id` path through `PostApiController::show`. `:348-354` covers the trimmed, case-insensitive name path in `brandFrom()` at `SchedulerTool.php:183-184`. |
| **D-01** | **Resolved** | See the D-01 notes below. |

**Q-02 notes** (`ApiController.php:30-35, 41-43, 95-96`):
- The `WeakMap` is keyed by request, so requests cannot inherit each other's context, even on a reused controller instance.
- Repeated `ownedBrand()` calls for the same brand collapse because the map is keyed by brand id. Two distinct brands decline attribution, which matches the MCP adapter and the docs.
- I agree that resetting in `respond()` would be wrong, because `PostApiController::store` calls `ownedBrand()` first. The probe at `:49-60` keeps that order. With the third case `[$one->id, $two->id]`, the sequence `[$one, null, null]` catches all of these regressions:
  - a reset in `respond()`;
  - the old instance-scoped scalar;
  - "last wins".
- Entries are released when the request is collected. The values are int-keyed arrays with no back-reference to the request.

**Q-04 notes** (`ActivityLogTest.php:209-224`):
- There is no DDL left inside the transaction.
- The test asserts four things:
  - owner visibility ordered by `id DESC` on equal `created_at`;
  - `brand === null`;
  - the foreign actor's row is excluded;
  - zero rows under the brand filter.
- Migration recovery is covered separately (`migration_recovery` in the metadata).

**D-01 notes:**
- With 50 visible rows out of 60k, all at the oldest end, the page query uses a sort-union index merge over `(user_id, created_at)` and `(brand_id, created_at)`. It reads 50 rows from each range, not a scan of the table.
- The counts and the action list use covering indexes.
- Your decision to keep the query code unchanged is justified by the evidence.

**Evidence tie-out (consistent):**
- **Pest totals:** 4296 + 7 new cases = 4303. The new cases are `:315` (1), `:328` (4 datasets), `:339` (1) and `:348` (1).
- **Focal file:** the cases add up to 32, which matches the 32 / 241 in both the focal and the MySQL focal logs.

## New findings

### N-01 · Minor · The collaborator sentence implies an attribution path that does not exist
- **Where:** `docs/usuario/actividad.md:31`
- **Problem:** the sentence says collaborator actions stay personal "cuando no hay contexto comprobado del propietario". That conditional implies that, with verified context, a collaborator's action could be attributed to the brand.
- **Why it cannot happen:** the writer only attributes a brand that the **actor** owns: `Brand::ownedBy($user->id)` at `app/Services/AuditLog.php:33`. A collaborator never owns it, so that branch is unreachable for them.
- **Scenario:** an owner reads the guide and expects a collaborator's verified actions to appear under the brand filter. They never will. The owner also cannot see them in the unfiltered view, per `:7`.
- **Fix (text only):** «Las acciones de otros usuarios sobre tus marcas no se atribuyen a la marca: quedan en el historial personal de quien las realizó y no aparecen en el tuyo.»
- **Not a blocker:** the stated outcome is correct; only the condition is misleading.

No new correctness, security or performance defects were introduced by the changes to `ApiController`, `SchedulerTool`, the migration, the Vue file or the tests.

## Residual notes (no action required)

1. **Context loss is fail-safe.** If an adapter ever passes different `Request` objects to `ownedBrand()` and `audit()` (for example a `FormRequest` and `request()`), the map lookup misses and the entry stays personal. It is never misattributed. All current call sites in `PostApiController` pass the same `$request`.
2. **What the sparse plan does and does not cover.**
   - Sort-union materializes every visible row before the `LIMIT`, so page cost scales with the actor's visible history, not with table size.
   - The dense case (20k visible rows) used the backward ordered scan instead.
   - The optimizer crossover between those two plans is unmeasured. Nothing indicates it degrades.
   - The visible-actors query drives from a table scan of `users` only because the fixture has 2 users. With a large `users` table, MySQL should instead drive from the roughly 2-row derived table and look users up by primary key. If you re-measure, seed a realistic `users` count.
3. **No hash binding for the MySQL focal run.** The MySQL focal log is not hash-bound to the final source. Its only link is the matching 32 / 241 count. Since Q-04 removed the DDL, the MySQL-specific risk that run guarded against no longer exists.
4. **Migration whitespace.** `migration…add_brand_to_audit_logs.php:18` still has a blank line before `up()`'s closing brace. This is cosmetic only.

## Criteria status

| Criterion | Status |
|---|---|
| **A** · Scope, filters, pagination, retention, docs | **Met.** The doc addition is accurate in outcome. N-01 is a wording precision issue. |
| **A** · Adversarial review with no open Critical/Important | **Met.** |
| **A** · Pest and serial Playwright green | **Met per supplied evidence:** Pest 4294 passed / 9 skipped; Playwright 13 passed / 2 skipped / 0 flaky, re-run after Q-01; node 778/778; typecheck 0 errors. |
| **B** · Correctness | **Met.** Attribution is request-scoped and declines multiple brands. Both adapters now follow one convention: collect verified context and let `AuditLog` decide. |
| **C** · Security | **Met.** There is still no cross-owner path. Explicit brands are re-checked centrally against the actor, and `DENIED` always drops the brand. The new HTTP error attribution exposes the owner's own actions only to that owner. |
| **D** · Performance | **Met.** The sparse and rare-action plans are bounded by visible rows. There are six indexed queries per request. These are local measurements, not a capacity guarantee. |

## Code quality verdict

**Acceptable, with no conditions.**
- **Cohesion and convention:** attribution state lives where the request lives, and the REST and MCP adapters now behave the same way.
- **Error handling and contracts:** the business and error response contracts are unchanged.
- **Tests:** the new tests target the exact regressions they fix: call order, request isolation, the central `DENIED` drop, the explicit model brand and the name lookup.
- **Weakest test fixed:** the one weak test from R2 (Q-04) is now behavioural and has no DDL.
- **Optional:** N-01 is a one-sentence doc fix.
