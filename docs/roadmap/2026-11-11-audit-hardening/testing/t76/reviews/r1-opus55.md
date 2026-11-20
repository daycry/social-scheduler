# T-76: revisión real Opus 5.5, intento 1

SHA-256: 31e6791e076600b13a179507e5b96e34bae5534cbb31f09f0f67490e20fd13db

# T76 static review (attempt 1): Opus 5.5

This is a static review of the supplied patch and context. I ran nothing. Runtime claims are the ones you supplied.

## Verdicts

| Area | Verdict |
|---|---|
| **A. Acceptance** | **Not yet.** Functional criteria 1–2 are met per the evidence. The criterion "no open Critical/Important findings" fails (see B1, Q1). The user doc carries a draft marker. T75 is still unintegrated. |
| **B. Correctness** | **Changes required.** One Important finding (B1) and two Minor. |
| **C. Security** | **Pass.** No findings. |
| **D. Performance** | **Pass.** No findings. |
| **E. Quality** | **Changes required.** One Important finding (Q1) and four Minor. |

---

## B. Correctness

### B1 · Important: a stale `best_time` turns the composer GET into a redirect, and plausibly a loop
**Where:** `app/Http/Controllers/PostController.php:204-213`

`compose()` throws a `ValidationException` when `best_time` is past or ambiguous. On a real Inertia or browser GET this is not a 422. Laravel redirects to `url()->previous()`, and the Referer header takes precedence there.

**Scenario 1: likely redirect loop.**
1. The calendar proposes today at 18:00 and opens `/brands/1/posts/create?best_time=…T18:00`.
2. The user keeps the date and submits at 18:02.
3. The store fails validation because the date is now past, and calls `back()`. Referer is the create URL with `best_time`.
4. The redirected GET fails `best_time` validation again and calls `back()` to the same Referer. Redirects preserve the original Referer, so this repeats.
5. The browser hits its redirect cap. Inertia shows a network error, and the real validation message ("fecha pasada") never appears.

**Scenario 2:** a reload or reopened tab after the time passes bounces the user to another page. The flashed `best_time` error is never rendered there.

**Why the tests miss it:** `BestTimeSuggestionTest.php:154-165` uses `getJson`. That exercises a JSON path that no user takes.

**Fix:**
- A stale, past or ambiguous prefill should never fail the page. Render the composer with `initialScheduledAt = null` and add a non-blocking notice prop, for example `bestTimeNotice: 'La fecha sugerida ya no es válida…'`.
- Keep `LocalDateTime::parseUnique` as the gate for what gets injected.
- Optionally strip `best_time` from the URL after mount with `router.replace` or `history.replaceState`, so `back()` returns to a clean URL.
- Replace the `getJson` dataset with `get()` assertions: 200, null prefill, notice present.
- Add one E2E: open with an expired `best_time` and assert the composer renders.

### B2 · Minor: the calendar `v-else` was silently rebound
**Where:** `resources/js/Pages/Calendar/Index.vue` header

The new `<BestTimeSuggestion v-if="suggestionBrand">` sits between `<Link v-if="createHref">` and `<span v-else>`. The `v-else` now pairs with `suggestionBrand`, not `createHref`.

**Scenario:** `createHref` is set but `suggestionBrand` is null. That happens if the shell brand or `filters.brand_id` is not in `props.brands`, or if `suggestionBrand` later gets stricter. The page then shows both "Crear publicación" and "Elige una marca para crear una publicación".

**Fix:**
```vue
<template v-if="createHref">
  <Link …/>
  <BestTimeSuggestion v-if="suggestionBrand" …/>
</template>
<span v-else>…</span>
```

### B3 · Minor: the edit-path prefill fallback depends only on the server
**Where:** `resources/js/Composables/useComposer.js:34`

`props.post?.scheduled_at ?? props.initialScheduledAt` falls through for an existing undated draft. Today it is safe only because the server sends `null` on edit.

**Fix:** use `props.post ? (props.post.scheduled_at ?? '') : (props.initialScheduledAt ?? '')`. This makes "edit ignores the prefill" hold on the client as well.

---

## C. Security: pass

- **Ownership:** `Brand::ownedBy(user)->find($brand)` returns 404 for foreign and nonexistent brands. The string parameter plus `whereNumber` removes the huge-id 500. Heatmap queries are `ownedBy` scoped. The response is aggregate data only.
- **Input validation:** `network` is validated against `Network::offered()`. `best_time` is constrained by `date_format` plus `parseUnique`, so there is no injection surface into Inertia props. The parameter is a prefill only, and `create()` still authorizes `update` on the brand.
- **Transport and auth:** GET is read-only, so CSRF does not apply. `sessionFetch` enforces same-origin and `redirect: 'error'`. Auth and verified middleware are tested: 401 and 403.
- **Observation, not a finding:** authorization uses `ownedBy`, while the composer uses a Policy. They are equivalent today because the policies use `Ownership::matches`. Keep them aligned if collaborator access is ever added.

## D. Performance: pass

- `suggest()` iterates at most 3 slots × 14 days. It makes a `getTransitions` call over a ±1-day window per matching date. This is trivial.
- The heatmap reuses the existing cache, and the cache key includes the timezone hash. Dates are recomputed fresh, which is correct and tested.
- On the frontend, the deep watch is over a handful of networks. Concurrent clicks are prevented by `busy`.

---

## E. Quality

### Q1 · Important: the core ranking contract has no test
**Where:** `app/Services/BestTimesService.php:74-75`

The packet's central rule is: best average, then sample count, then nearest future. Every PHP case seeds a single slot, so neither tie-break is exercised.

**Scenario:** someone "simplifies" the comparator to nearest-first, or flips a sign. All 45 focal tests stay green.

**Fix:** add a dataset with three slots:
- (a) higher average, further away, beats a nearer lower-average slot;
- (b) equal average, more posts, wins;
- (c) equal average and posts, the nearer slot wins.

### Q2 · Minor: the Prettier evidence doesn't match the visible formatting
**Where:**
- `ScheduleSection.vue`: two consecutive blank lines, and a one-line block arrow `{ form.scheduled_at = …; }`
- `useBestTimeSuggestion.js:33`: `() => { disposed = true; reset(); }`, and several lines well over typical print widths
- `BestTimeSuggestion.vue`: the multi-argument `watch(...)` layout

Prettier never keeps a non-empty block on one line, and it collapses repeated blank lines. Either these files are outside the Prettier config, or the "focal Prettier green" run did not include them.

**Fix:** run `prettier --check` on the exact changed-file list and record that list in `verification.json`.

### Q3 · Minor: the component layer is only covered by E2E, and the typing boundary should be stated
**What is tested:** the Node tests drive the composable in an `effectScope`, not the SFC.

**What is not tested:**
- `BestTimeSuggestion.vue`: defaulting to the first network, keeping the selection when the list changes, and resetting on network change.
- `ScheduleSection.bestTimeNetworks`: selected accounts versus usable accounts.
- E2E: the multi-network `<select>` path.

**Typing:** the strict program reaches the component, the composable and `best-time.d.ts` through the Calendar imports. It does not reach `ScheduleSection.vue` or `useComposer.js`. So the composer-side bindings (`brand` shape, `networks` mapping, `applyBestTime`) are unchecked, and only the composer E2E covers them. This is acceptable as a documented T68 boundary. The ledger should say so explicitly rather than "tipados".

**Fix:** either add a mounted-component test (Vue test utils or similar) for network selection and reset, or record the gap. Add an E2E with two networks.

### Q4 · Minor: the calendar default network may be one the brand doesn't use
**Where:** `Calendar/Index.vue` `suggestionNetworks`

Without a network filter, the list is the whole catalog and the first entry is preselected. For an Instagram-only brand, the first click likely returns "no data".

The behaviour is honest, but it reads as broken.

**Fix:** order or filter by networks that have accounts or history for `suggestionBrand`, or require an explicit choice. The latter matches the doc's "selecciona una marca y una red".

### Q5 · Minor: docs and gating
**Where:** `docs/usuario/planificar.md:3`, `docs/README.md`, `CHANGELOG*`

- `planificar.md:3` ships the line "Borrador de T-76: pendiente de verificar la interfaz y de revisión." in user-facing documentation. Remove it before closure.
- `docs/README.md` links `usuario/actividad.md`, and the CHANGELOG files announce T75. Merging T76 before T75 would leave a dangling link and a false changelog entry.

The declared dependency covers this only if merge order is enforced. Add it as an explicit closure check.

---

## What holds up well

- **`LocalDateTime::parseUnique`** is correct for gaps, overlaps, fractional offsets and overflow dates. It is shared by the service and the controller, so there is no divergent parsing.
- **The composable's race handling is solid:** request identity checks, scope and identity checks on the response, the manual-edit guard, the expiry check, and disposal. The fetch-ignores-abort harness is a genuinely meaningful test.
- **Accessibility:** a persistent `role="status"`, `role="alert"` for errors, a labelled select and `aria-busy`.
- **Contracts:** the existing heatmap and query contracts are untouched. The selected network is honoured, with no Instagram fallback, and that is tested.

## Required before closure

1. Fix B1 and Q1, and add the tests described for each.
2. Fix B2 and remove the draft line from `planificar.md` (Q5). Both are small edits.
3. Re-run the focal Pest suite on SQLite and MySQL, then Node and the E2E-16 browser run, and record the exact Prettier file list.
4. Integrate T75, then test the T75+T76 combination.
