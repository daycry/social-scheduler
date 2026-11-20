# T-76: cotejo real Opus 5.5, intento 3

SHA-256 del recibo: 7ad323303ad6e0c126f35d52f0ff85233142fa1f4e7e39084ef87529d1f98e92

# T76 follow-up review (attempt 3): Opus 5.5

This is a static review of the supplied delta, the numbered sources and the stated evidence. No test results are mine. Runtime figures are as reported to me, and the final full PHP run and the focal browser rerun are still pending.

## Verdicts

| Area | Verdict |
|---|---|
| **A. Acceptance** | **Conditionally met.** Criteria 1 and 2 hold statically. N1 is reclassified as product policy, N2 and N3 are fixed, and V1 is closed. Closure still depends on the pending full PHP and focal browser reruns, followed by hash re-verification. |
| **B. Correctness** | **Pass.** The N3 marker logic holds across the sequences I traced. There are no new defects. |
| **C. Security** | **Pass.** The delta changes a string, client-side status state, tests and docs. It adds no input surface or authorization change. |
| **D. Performance** | **Pass.** The only runtime addition is an O(1) comparison inside an existing sync watcher. |
| **E. Code quality** | **Pass, with one Minor finding (Q-N2a).** One stated verification claim is not supported by the supplied tests. |

## N1: rebuttal accepted; withdrawn as a defect

I accept the rebuttal. The contract requires a "strictly future" date and specifies no minimum editing duration. `BestTimesService.php:66` (`$at->gt($now)`) satisfies that exactly. The cache test at `BestTimeSuggestionTest.php:126-136` deliberately asserts that a slot one minute ahead (09:59 Madrid proposes 10:00) is valid.

The algorithm was also accepted in R1/R2 and frozen. A lead time would change which slot wins, so it would be a change to an approved behaviour, not a bug fix.

The other points also hold:
- No fixed lead time can bound an arbitrary editing session.
- Stale dates fail safely: client `dateErrorList` and server validation both reject them, and the soft GET notice preserves form contents.
- `planificar.md:15` now tells the user the proposal is a point-in-time value and to requery if its hour passes.

**Classification:** optional product policy, not a defect and not accepted debt. A configurable lead time, or a client hint when the proposal is under N minutes away, remains a valid future enhancement if product wants it.

## N2: fixed; one evidence gap (Q-N2a)

`PostController.php:210` now reads "La fecha sugerida del enlace no es válida. Elige otra fecha o consulta Mejor hora." The wording refers only to the link's date. It makes no claim about what the form currently holds.

The wording is also accurate in the harder case: a user fixes the date manually, the save fails for another reason, and `back()` returns to `create?best_time=<now-past>`. The link's date really is invalid there. The original contradiction is resolved.

### Q-N2a · Minor (E, test/evidence accuracy)
**Where:** `tests/Feature/Parity/BestTimeSuggestionTest.php:160` and `:174`; `tests/e2e/best-time-suggestion.spec.ts:143`

The metadata says the HTTP tests "assert exact neutral text". The supplied tests do not:
- `:160` uses `str_contains($notice, 'no es válida')`.
- `:174` only checks for a non-empty string.
- The e2e test uses `toContainText('no es válida')`.

All three would also pass with the old "…no es válida y no se ha aplicado…" text.

**Scenario:** a later edit restores the misleading "no se ha aplicado" clause. Every supplied test stays green, so the N2 fix has no regression guard.

**Fix:** assert the exact string with `->where('bestTimeNotice', 'La fecha sugerida del enlace no es válida. Elige otra fecha o consulta Mejor hora.')` at `:160`. Do the same at `:174` if the non-scalar branch is meant to share the text.

**Missing fact:** if an exact-text assertion exists in another file, give its file:line and this finding closes. I also cannot see whether the non-scalar branch at `PostController.php:202-206` emits the same string. The delta only shows the `:210` branch.

This is not blocking for acceptance. It should be corrected, or the claim struck from the closure notes.

## N3: fixed correctly

I traced `useBestTimeSuggestion.js:19-30, 35, 61, 111-113`:

- **Normal deferred feedback.** The flow is `appliedDate = X`, then notice, then `apply()`, then `finally` clears `activeRequest`. The parent prop then updates on the next flush, so the sync watcher sees `value === X` and does not clear. The status survives.
- **Synchronous parent update inside `apply()`.** `activeRequest` is still set, so the guard prevents clearing in any case. Because the value equals `X`, nothing would clear even without the guard.
- **Later manual edit to Y.** With no request active and `Y !== X`, the notice is cleared and the marker dropped. Restoring `X` afterwards does not resurrect the notice, which is correct.
- **New request, reset, brand/network/timezone change, unmount.** The marker is cleared at `:35` and `:61`.
- **Non-success notices.** The "cambiaste la fecha" notice and the insufficient-data reason are untouched by manual edits because `appliedDate` stays `undefined`. Both remain true statements, so this is acceptable.
- **`apply()` throws.** The `catch` blanks the notice and leaves `appliedDate` set. A later edit then clears an already-empty notice, which is harmless.

The Node test at `best-time-suggestion.test.mjs:173-184` is two-sided:
- `:180` fails a naive "clear on any change" implementation.
- `:183` fails the pre-fix code. This matches the reported pre-change failure, "expected empty, got Fecha propuesta".

The browser test at `spec.ts:66-79` additionally proves two things:
- The real date picker reflects `scheduled_at` unchanged, so no format normalization occurs. Normalization would otherwise clear the status immediately and `:68` would fail.
- Clearing the date via "Quitar fecha" empties `role=status`.

**Nit, not actionable:** at `:24`, the `!activeRequest` guard is effectively redundant, since `:61` already clears `appliedDate` when a request starts. It is harmless and documents intent.

## V1: closed by evidence; no production change needed

**Calendar payload.** `CalendarService.php:65` maps each brand to `['id', 'name', 'timezone' => $b->timezone]`.

**Fallback parity.** `BestTimesService.php:89` uses `$brand->timezone ?: 'UTC'` and `BestTimeSuggestion.vue:33` uses `props.brand?.timezone || 'UTC'`. Both treat `null` and `''` as `UTC`. Values that are falsy in PHP but truthy in JS, such as `'0'`, are not valid timezones and are outside the realistic domain.

**Prior coverage was already non-UTC.** `helpers.ts:489` defaults `seedComposerBrand` to `Europe/Madrid`, so earlier calendar e2e runs already exercised the non-UTC client match. The R2 risk of "invisible because UTC" did not apply.

**New evidence:**
- `BestTimeSuggestionTest.php:199-205` asserts the Inertia `brands.0.timezone`.
- `:207-211` asserts the empty-timezone endpoint returns `UTC`.
- `spec.ts:126-128` asserts `Europe/Madrid` and then follows the navigation. The navigation only happens if the client-side `data.timezone !== snapshot.timezone` check passed, so this proves the full calendar-to-composer path end to end.

**Remaining gap (Nit):** no Node test feeds a `null`/`''` brand timezone through the widget. The fallback is a single visible expression, so I am not raising it as a finding.

V1 does not escalate to Important. Closed.

## Other R1/R2 findings

None reopened. No new evidence affects B1, B2, B3, Q1–Q5, the NUL guard or the race fix. `BestTimesService.php` is unchanged, as stated.

## Gate assessment

**Already established, per supplied evidence:**
- The R2 combined gate (full Pest 4318 passed, serial Playwright 120 passed with 0 skipped/failed/flaky, qa-gate VERDE after the NUL guard, 1766 hashes) was met before this delta.
- The post-delta snapshot is green: Node 789/0, SQLite and MySQL 8.4.3 at 84 passed/731 assertions each, TS 0, Prettier on 12 files, ESLint, Pint, and the build.

**Scope of the reruns.** The delta touches one controller string, one composable, docs and tests. A full PHP rerun plus the focal 18-test browser rerun (6 tests × 3 projects) is proportionate.

**Missing fact:** whether any other e2e spec or PHP test asserts the old "no se ha aplicado" wording or the Mejor hora status region. The full PHP rerun covers the PHP side. For the browser side, a text search for "no se ha aplicado" across `tests/e2e` would settle it.

## Required before closure

1. The final full PHP suite must be genuinely green. Do not substitute partial or SQLite-only runs.
2. The focal 18-test browser rerun of `best-time-suggestion.spec.ts` must be green with 0 skipped/failed/flaky, including the new `:73-79` clear-status step and the `:64`/`:126` timezone assertions.
3. Hashes must be re-verified unchanged after both runs.
4. **Recommended, not blocking:** fix Q-N2a by making the notice assertion exact, or remove the "assert exact neutral text" claim from the closure notes.

## Summary of dispositions

| Item | Disposition |
|---|---|
| N1 | Rebuttal accepted. Optional product policy, not a defect and not debt. |
| N2 | Fixed. New Minor Q-N2a: the regression guard is weaker than claimed. |
| N3 | Fixed correctly, with two-sided Node coverage and a real browser assertion. |
| V1 | Closed by payload, fallback-parity, HTTP and browser evidence. |