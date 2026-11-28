# T-76: cotejo real Opus 5.5, intento 2

SHA-256: 0028c5c2c56ed82f2e5ae465e5e137c587e8ca811aa6800c73d6df36651e95f5

# T76 follow-up review (attempt 2): Opus 5.5

This is a static, read-only review of patch `cd324c0a…`. I ran no tests. All runtime figures below are the ones you supplied.

I tried to look up surrounding code in the execution copy, but my tool session got stuck repeating the same search and returned nothing usable. Everything here therefore rests on the patch and the supplied context. Where a conclusion depends on code outside the patch, I say so.

## Verdicts

| Area | Verdict |
|---|---|
| **A. Acceptance** | **Conditionally met.** Criteria 1 and 2 hold statically. No Critical or Important findings remain open. Docs are updated. Two items are still open: the full combined Pest + serial Playwright gate, and the V1 verification below. |
| **B. Correctness** | **Pass.** B1, B2, B3 and the NUL interaction are fixed correctly. One new Minor finding (N1). |
| **C. Security** | **Pass.** No findings. The new `ValueError` catch is narrow, and no new input surface was added. |
| **D. Performance** | **Pass.** No change since R1. |
| **E. Code quality** | **Pass.** Q1–Q5 are resolved. Two Minor findings (N2, N3). |

## Re-evaluation of the R1 findings

- **B1 (Important): closed.**
  - `PostController.php:202-212` never throws on `best_time`.
  - Array, empty, `null` after `ConvertEmptyStringsToNull`, malformed, NUL, past, gap and overlap values all render 200 with a notice. The tests use `get()` and assert Inertia props, which is the user's actual path.
  - The B1 loop is gone. A store POST that fails will `back()` to `create?best_time=…`, and that GET now always returns 200. `preserveState` keeps the form, so the user's edits survive.
- **NUL interaction: correct and narrow.** `LocalDateTime.php:18` catches `Exception|ValueError` around the two calls that can raise for expected bad input, and nothing else. The round-trip check at `:21` still guards overflow dates.
- **Q1 (Important): closed.** The three ranking datasets each fail only under their matching mutant, per the r37 logs. The Wednesday clock makes the proximity case independent of heatmap order. This is correctly described as added coverage, not a new implementation cycle.
- **B2 and B3: closed.**
  - `Calendar/Index.vue`: the `<template v-if="createHref">` / `v-else` pairing is now correct.
  - `useComposer.js:34`: the explicit `props.post ? … : …` branch is correct.
- **Q2: closed.** The 12 exact files are listed. `embeddedLanguageFormatting: off` explains why the earlier SFC script blocks were not reformatted. The large `useComposer.js` reflow is mechanical formatter output and I treat it as such.
- **Q3: closed.**
  - The mounted tests run the real compiled `<script setup>` under a custom renderer.
  - The limit is stated honestly: the template is not exercised there, and browser tests cover it.
  - The typing boundary is now explicit in the metadata.
- **Q4: closed.** The calendar requires an explicit network choice. Both the component test and the browser test enforce this.
- **Q5: closed.** The draft marker is removed and T75 is integrated.
- **Race fix:** the synchronous `dateRevision` watcher (`useBestTimeSuggestion.js:17-24`) correctly catches edit-then-restore. Its test fails without the fix.

## New findings

### N1 · Minor (B): a proposal can land minutes ahead and be unschedulable by submit time
**Where:** `BestTimesService.php:65` (`$at->gt($now)`); the test at `BestTimeSuggestionTest.php:120-128` endorses this.

**Scenario:**
1. At 09:59 Madrid time, the service proposes 10:00.
2. The client check passes and the date is applied.
3. By the time the user finishes the post, `dateErrorList` blocks scheduling or the store rejects it as past.

**Impact:** no data is lost, but the "one click" result is unusable.

**Fix:** require a minimum lead time, for example `$at->gt($now->addMinutes(15))`, possibly configurable. Adjust the cache test to match.

### N2 · Minor (Q): the notice is wrong when it appears after a failed save
**Where:** `PostController.php:209`, `Compose.vue:36-42`

**Scenario:**
1. A valid prefill expires while the user is editing.
2. The save fails validation.
3. `back()` re-renders the page with the notice "no se ha aplicado", but `preserveState` keeps the form, which still shows that date.
4. The user sees the notice plus "debe ser futura" against a date the notice says was not applied.

**Fix:** either suppress the notice when the request has flashed errors, or reword it neutrally: "La fecha sugerida del enlace ya no es válida."

### N3 · Minor (Q): the success status goes stale
**Where:** `useBestTimeSuggestion.js:103`

"Fecha propuesta: X" remains in the `role="status"` region after the user changes the date by hand. Clear `notice` in the existing `dateRevision` watcher when no request is in flight.

## V1 · Verify before closure (unconfirmed, possibly Important)
**Where:** `Calendar/Index.vue` (`suggestionBrand` from `props.brands`); `useBestTimeSuggestion.js:79-84`

The client compares `data.timezone !== snapshot.timezone`, and the snapshot uses `props.brand?.timezone || 'UTC'`.

**Risk:** if the Calendar page's `brands` prop lacks `timezone`, every non-UTC brand gets "No se pudo obtener una propuesta válida". The same happens if the heatmap's fallback timezone for a brand with an empty timezone is not `'UTC'`.

**Why tests might miss it:** the calendar E2E passes, but if `seedComposerBrand` or the factory uses UTC, a missing field is invisible. Whether the calendar brand list exposes `timezone`, and whether strict TS checks the template binding, is outside the patch, and I could not confirm it.

**Check:**
1. Confirm the controller payload includes `timezone`.
2. Confirm the heatmap's fallback equals `'UTC'`.
3. Ideally, run one calendar E2E with a `Europe/Madrid` brand.

If either check fails, this becomes **Important B**.

## Required before closure
1. Resolve V1, by evidence or by a one-line fix.
2. Run the final combined full Pest suite plus the serial Playwright regression after the NUL guard. The current browser preflight predates it.
3. N1–N3 are optional, but N1 is recommended.
