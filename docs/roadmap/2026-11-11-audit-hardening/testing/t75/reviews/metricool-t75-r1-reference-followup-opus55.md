# metricool-t75-r1-reference-followup-opus55

Modelo: claude-opus-5-5. SHA-256 del recibo JSON: db2e5800a95b59659a67be2a808d4c2a3c47c9a3b20196d56e705a7507dd92ee

# Recommendation: attribute brand from verified evidence, not from `(resource, resource_id)`

The candidate infers a brand from `(resource, resource_id)`, and that pair is not typed. `resource()` is a static per-tool label, while the id comes from `result['id']`, `result['dataset']['id']`, or a chain of request params ending in `brand_id`. The same-owner check stops cross-owner leaks. It cannot stop a same-owner mislabel, because the wrong row still belongs to the same user. No lookup can fix this after the fact.

The minimal fix is to record a brand only when the tool has itself loaded a brand-scoped model through the ownership-checked paths it already uses (`owned()` and `brandFrom()`). The brand then comes from the model that was loaded, never from an id interpreted after the fact. Whether the call succeeded plays no part in attribution.

## File changes (4 files, 1 deleted)

### 1. Delete `app/Support/AuditResource.php`

Its whole premise, inferring a brand from a loosely typed id, is the bug. The migration must not depend on an app class anyway.

### 2. `app/Services/AuditLog.php`: brand is passed in, never inferred

```php
public function record(?User $user, string $action, string $outcome, ?string $resource = null, int|string|null $resourceId = null, ?string $detail = null, string $channel = 'mcp', ?int $brandId = null): void
{
    $tokenId = $user?->currentAccessToken()?->id ?? null;

    try {
        AuditLogEntry::create([
            'user_id' => $user?->id,
            // Solo la marca que el llamante ya verificó como propia del actor; nunca se deduce de resource/resource_id.
            'brand_id' => $user !== null ? $brandId : null,
            // ...rest unchanged
```

Remove the `use App\Support\AuditResource;` import. All existing callers keep working and record personal rows.

### 3. `app/Mcp/Tools/SchedulerTool.php`: collect verified brand context

Add the state and helpers:

```php
/** Marcas verificadas como propias durante esta llamada (via owned()/brandFrom()). */
private array $auditBrands = [];

/** Las herramientas que validan la marca por otra via pueden declararla explicitamente. */
protected function attributeBrand(?int $brandId): void
{
    if ($brandId !== null) {
        $this->auditBrands[$brandId] = true;
    }
}

/** Una sola marca verificada y aun propia del actor; si hay varias o ninguna, la fila queda personal. */
private function auditBrand(User $user): ?int
{
    if (count($this->auditBrands) !== 1) {
        return null;
    }
    $id = (int) array_key_first($this->auditBrands);

    return Brand::ownedBy($user->id)->whereKey($id)->exists() ? $id : null;
}
```

Changes in `handle()`:

- Set `$this->auditBrands = [];` as the first statement. This keeps the result correct even if the container ever reuses tool instances.
- **Ability-denied** branch: unchanged (`run()` never ran, so the row stays personal).
- **`NotOwned`** branch: unchanged, explicitly no brand. Ownership failed, so nothing is attributed even if an earlier lookup succeeded.
- **All other branches** (validation, `ModelNotFoundException`, HTTP, conflict, internal error, OK): append `brandId: $this->auditBrand($user)`. For example:

```php
$audit->record($user, $action, AuditLog::OK, $this->resource(), $result['id'] ?? $result['dataset']['id'] ?? $resourceId, brandId: $this->auditBrand($user));
```

  Errors after a verified lookup are legitimately about that brand. The brand comes from the lookup, not from the outcome.

In `owned()`, record the brand after a successful scoped find:

```php
if ($found !== null) {
    $this->attributeBrand($found instanceof Brand ? (int) $found->getKey() : (is_numeric($found->getAttribute('brand_id')) ? (int) $found->getAttribute('brand_id') : null));

    return $found;
}
```

In `brandFrom()`, cover the name path (the `brand_id` path already goes through `owned()`):

```php
$brand = $matches->first() ?? throw new NotOwned(/* unchanged */);
$this->attributeBrand($brand->id);

return $brand;
```

Two design decisions to keep:

- **More than one distinct brand means personal.** A conflict is never resolved by picking one.
- **`auditBrand()` re-checks `brands.user_id`.** A `Post` owned by the user whose `brand_id` points at another user's brand, a data-integrity case, cannot leak. It costs one indexed `exists` query per call.

### 4. The migration: schema plus a conservative, allowlisted backfill

The historical rows lack the information that makes the live path safe: which loaded model the id came from. A row can only be backfilled when, for that specific action, the recorded id can only be that resource's root id. To qualify:

- the root param is required, and it wins the `post_id ?? report_id ?? …` chain;
- the result's `id` is the same entity, or the tool returns no `id` field;
- the resource's `brand_id` is immutable, otherwise today's brand is not the brand at the time of the call.

Each entry must be confirmed by reading that tool. If in doubt, leave it out. An empty list is a correct starting point.

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Acciones cuyo resource_id historico es inequivocamente el id raiz del recurso indicado
     * (parametro obligatorio, sin fallback a brand_id, resultado con el mismo id y brand_id inmutable).
     * Cada entrada se verifica leyendo la herramienta. Lo que no esta aqui queda personal.
     */
    private const SAFE = [
        // 'get_post' => ['post', 'posts'],      // ejemplo: confirmar antes de activar
        // 'get_report' => ['report', 'reports'],
    ];

    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['brand_id', 'created_at']);
            $table->index(['user_id', 'action']);
            $table->index(['brand_id', 'user_id', 'action']);
        });

        foreach (self::SAFE as $action => [$resource, $table]) {
            DB::table('audit_logs')->where('action', $action)->where('resource', $resource)
                ->where('outcome', 'ok')->whereNotNull('resource_id')->whereNotNull('user_id')
                ->orderBy('id')->chunkById(500, function ($entries) use ($table) {
                    $contexts = DB::table($table)
                        ->join('brands', function ($join) use ($table) {
                            $join->on('brands.id', '=', $table.'.brand_id')->on('brands.user_id', '=', $table.'.user_id');
                        })
                        ->whereIn($table.'.id', $entries->pluck('resource_id'))
                        ->get([$table.'.id', $table.'.brand_id', $table.'.user_id'])->keyBy('id');

                    $idsByBrand = [];
                    foreach ($entries as $entry) {
                        $context = $contexts->get($entry->resource_id);
                        if ($context && (int) $context->user_id === (int) $entry->user_id) {
                            $idsByBrand[(int) $context->brand_id][] = $entry->id;
                        }
                    }
                    foreach ($idsByBrand as $brandId => $ids) {
                        DB::table('audit_logs')->whereIn('id', $ids)->update(['brand_id' => $brandId]);
                    }
                });
        }
    }

    // down() unchanged from the candidate
};
```

This backfill excludes several kinds of rows, on purpose:

- **Rows with `resource = 'brand'`**: `AttachDesignAsset` logged asset ids under that label.
- **Denied and error rows**: they may carry the `brand_id` fallback or a guessed id.
- **Rows whose resource has since been deleted**: they have no evidence left.

## What stays personal (`brand_id = null`), and why

| Case | Reason |
|---|---|
| All historical rows outside `SAFE` | The id's meaning is unrecoverable. |
| Ability-denied calls | `run()` never ran, so nothing was verified. |
| `NotOwned` denials | Nothing was verified, and recording a brand here would hint at existence. |
| `ListBrands`, unfiltered `GetCalendar`, `list` actions | These are not about a single brand. |
| `ManageBrandCollaborators`, `ManagePostSeries` (create/list) | They pass a raw `brand_id` to services that validate internally. |
| Calls that touched two or more distinct brands | The row is ambiguous by construction. |
| Non-MCP callers of `AuditLog::record` | They are personal until they pass `brandId:` explicitly. |

The listed tools work correctly with no change:

- **`GetCalendar` with a brand or a saved view**: the brand comes from `brandFrom()` or from `owned(CalendarView)`.
- **`ExportDesign`**: the brand comes from `owned(Design)`, whatever id the result carries.
- **`AttachDesignAsset`**: the brand comes from `brandFrom()`, not from the asset id.
- **Post and report tools that use `owned()`**: the brand comes from the loaded model.

If you later want `ManageBrandCollaborators` attributed, add one line at the point where the service has already confirmed ownership, e.g. `$this->attributeBrand($brand)`. Do not add an `owned()` call in front of the service, because that changes its error semantics.

## Docs (keep this wording honest)

> The Brand filter shows only entries whose brand was verified as the actor's own at the time of the call (since <deploy date>), plus a small allowlisted set of older actions. All other entries, including all denials and most history before that date, appear under "Personal / no brand". This does not mean they were unrelated to a brand. The `resource`/`resource_id` columns are the tool's declared label and the id it reported, and they are not always the same entity.

That last sentence matters for the screen. Do not render `resource #id` as a link or as an authoritative label.

## Tests to add (RED first)

1. **Same-owner ambiguity**: user owns brands A and B, and a post in B has id equal to A's id. Call `get_calendar` with `brand_id=A`. Expect `brand_id = A` (the old code gave B).
2. **`export_design`**: the export id collides with a design of another owned brand. Expect the design's brand.
3. **`attach_design_asset`**: the asset id equals the id of another owned brand. Expect the requested brand.
4. **`NotOwned`, ability-denied, `list_brands`, unfiltered `get_calendar`**: expect `brand_id` null.
5. **Two distinct verified brands in one call** (stub tool): expect null.
6. **Integrity**: a post owned by the user points at another user's brand. Expect null.
7. **Migration**: a row with `resource='brand'` and an asset id, a denied row, and a row for an action outside the allowlist all stay null. An allowlisted row with a matching owner is set.
8. **Instance reuse**: run the same tool instance twice with different brands. Each row gets its own brand.
