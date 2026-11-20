# metricool-t75-r1-opus55

Modelo: claude-opus-5-5. SHA-256 del recibo JSON: b184b7b0eae7c9bde4772603d0d741a33c0f93947fb9c1d4057f23d260233949

# T-75 Activity history, attempt 1: independent review

This is a static review only. I ran no tests. Browser and Playwright status is pending, as you stated, and I don't count that as a code defect.

## Must-fix finding

**B1/C1. Critical: a successful action can be attributed to another tenant's brand, which breaks isolation**
- **Where:** `app/Support/AuditResource.php:42` and the matching backfill rule at `database/migrations/2026_11_17_193000_add_brand_to_audit_logs.php:42`.
- **The gap:** When `outcome === 'ok'`, attribution only checks that the resource exists. It never checks that `context.user_id` is the actor.
- **How the bad id gets in:** Callers can pass ids that are not the resource's own id:
  - `SchedulerTool.php:57` falls back to `brand_id` even when `resource()` is `post`, `report`, `design` and so on.
  - The success path at `:110` uses `$result['id'] ?? … ?? $resourceId`.
- **Scenario:**
  1. User X calls an MCP tool with `resource()='post'` and `brand_id=5`. The result has no `id`, as in a list or summary tool.
  2. The call succeeds, so `resource='post', resource_id=5` is recorded.
  3. `AuditResource` resolves `posts.id=5`, which belongs to user Y's brand.
  4. The row gets Y's `brand_id`.
  5. Y now sees X's name, the action, the channel, the time and the resource reference in `/activity`, and X appears in Y's user filter.
- **Same bug elsewhere:**
  - With `resource='brand'`, a successful row with a foreign id is attributed the same way.
  - The backfill applies the identical rule to historical rows.
- **Fix:** Ownership is single-owner (`Brand::ownedBy`), so a legitimate successful action is always on the actor's own resource. Require `(int) $context->user_id === $actor?->id` for every outcome:
  - in `AuditResource:42`, drop the `$outcome !== 'ok' &&` condition;
  - in the migration at `:42`, use `$entry->user_id == $context->user_id` regardless of outcome.

  The existing tests still hold: the success test at `ActivityLogTest:147` uses the owner as actor. The backfill test's `$success` row is by `$actor` on the owner's post, so its expectation changes to `null`. That is correct under single ownership.
- **Missing tests:**
  - a successful write whose `resource_id` points at a foreign resource must give `brand_id` null;
  - the same case for the backfill.

## A. Acceptance

| Criterion | Status |
|---|---|
| Verified and authenticated access | Met (`web.php:80` is inside `auth, verified`; test at `:134`) |
| Only personal activity plus owned brands | **Not met** until B1 is fixed. The query side (`ActivityController:34-36`) is correct; the attribution side can leak. |
| Composable brand, user, action and date filters | Met. Dates are converted in the brand's timezone, the DST case is tested, and foreign selectors give a uniform 404. |
| Cursor pagination | Met. The cursor is scoped to the user and a hash of the filters; tested. |
| A-08 retention in UI | Met (reads config; the 0 and N cases are tested) |
| Docs and changelog | Docs are present (`docs/usuario/actividad.md`). **The changelog is not in the packet**, so I can't verify it. |
| Adversarial A+B(+C), no open Important/Critical | Not met (B1) |
| Pest and Playwright green | Pest is reported green. Playwright is pending, as you stated. |

## B. Correctness
- **B1** above.
- No other correctness defects found. Specifically:
  - the date bounds (`startOfDay`, plus a day, then UTC) are correct;
  - `to >= from` is validated;
  - the "Cuenta eliminada" fallback and the null brand fallback ("Cuenta") behave correctly.

## C. Security
- **C1:** the same issue as B1.
- No other findings:
  - `detail`, `ip` and `token_id` are not serialized;
  - the selectors give a uniform 404;
  - the cursor cannot be replayed across users or filters;
  - the action filter only narrows the already-authorized `$visible` set.

## D. Performance

**D1. Important: the main query sorts by `id` while the indexes lead with `created_at`, and the filters scan the full visible set**
- **Sort:** `ActivityController:56` sorts by `latest('id')` under an `OR`. The indexes are `(user_id, created_at)` and `(brand_id, created_at)`, so neither branch can use an index for the `id` sort. That means a filesort over every visible row on every page.
- **Filter options:** Every request also runs, with no bound:
  - a `DISTINCT action` over all visible rows (`:76`);
  - a subquery collecting the user ids of all visible rows (`:37`);
  - the total count (`CursorPage:55`).
- **Scale:** Every MCP and API call writes a row and retention defaults to 365 days. An active owner with several brands will hit four full scans of the visible range per page load.
- **Fix:**
  - Order by `created_at desc, id desc`. The column is non-null and `CursorPage` already validates date cursor columns. This lets each branch of the `OR` use its index.
  - Accept the scans as known debt, or bound the action and actor option lists. For example, derive them from owned brands and the actor, cache them briefly, or limit them to the selected date range.
- **Severity:** I rate this Important only because "efficient bounded queries" is an explicit criterion. It is not a correctness issue.

**D2. Minor: the backfill issues one `UPDATE` per row**
- **Where:** `migration:43`.
- **Scenario:** A large historical table means N round trips while the deploy holds the migration.
- **Fix:** Within each chunk, group the ids by the target `brand_id` and run `whereIn('id', …)->update(...)` once per group.

## Code quality verdict

**Acceptable once B1 is fixed.** The code is cohesive and readable:
- the controller is a single action;
- the attribution helper is isolated;
- the backfill duplicates the table map intentionally, as you justified;
- failure handling in the writer is preserved (it logs and never throws);
- the tests are relevant and adversarial (foreign rows, hidden fields, DST, cursor binding).

Minor items:
- **Q1. Minor: wrong type for the user list.** `resources/js/Pages/Activity/Index.vue:15` types `users` as `BrandRef[]`. Declare an `ActivityUser { id; name }` in `types/activity.d.ts` so a later change to `BrandRef` doesn't silently mistype users.
- **Q2. Minor: cross-field validation split out.** `ActivityController:26-28` runs a second `validator()` for `to >= from`. Putting `'to' => ['nullable','date_format:Y-m-d','after_or_equal:from']` in the main rules does the same in one place; `after_or_equal` skips when `from` is absent.
- **Q3. Minor: test gap.** No test covers B1's scenario, a successful audit whose `resource_id` resolves to another user's resource.
