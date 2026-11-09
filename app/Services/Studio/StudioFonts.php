<?php

namespace App\Services\Studio;

use App\Models\Brand;
use App\Models\Studio\StudioFont;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Process\Exception\ProcessStartFailedException;

class StudioFonts
{
    public function listing(User $user, Brand $brand, array $filters = [], bool $api = false): array
    {
        $this->assertBrand($user, $brand);
        $filters = Validator::make($filters, ['query' => ['sometimes', 'nullable', 'string', 'max:120'],
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'archived' => ['sometimes', 'boolean']])->validate();
        $query = StudioFont::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->where('status', 'ready');
        ($filters['archived'] ?? false) ? $query->whereNotNull('archived_at') : $query->whereNull('archived_at');
        if (filled($filters['query'] ?? null)) {
            $term = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($filters['query'])).'%';
            $query->where(fn ($q) => $q->whereRaw("name LIKE ? ESCAPE '!'", [$term])->orWhereRaw("family LIKE ? ESCAPE '!'", [$term]));
        }
        $page = $query->latest('id')->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);

        return ['fonts' => $page->getCollection()->map(fn ($font) => $this->present($font, $api))->values()->all(),
            'pagination' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(),
                'total' => $page->total(), 'next_page' => $page->hasMorePages() ? $page->currentPage() + 1 : null]];
    }

    public function upload(User $user, Brand $brand, UploadedFile $file, ?string $name = null): StudioFont
    {
        $this->assertBrand($user, $brand);
        $max = (int) config('studio.fonts.max_bytes', 8 * 1024 * 1024);
        Validator::make(['file' => $file, 'name' => $name], ['file' => ['required', 'file', 'max:'.(int) ceil($max / 1024)], 'name' => ['nullable', 'string', 'max:255']])->validate();
        $input = file_get_contents($file->getRealPath());
        if (! is_string($input) || strlen($input) < 12 || strlen($input) > $max
            || ! in_array(substr($input, 0, 4), ["\x00\x01\x00\x00", 'OTTO', 'true', 'wOFF', 'wOF2'], true)) {
            throw ValidationException::withMessages(['file' => ['El archivo no contiene una fuente TTF, OTF, WOFF o WOFF2 válida.']]);
        }
        $work = 'studio-font-probe/'.$user->id.'/'.Str::uuid();
        $local = Storage::disk('local');
        abort_unless($local->put($work.'/input.bin', $input), 503, 'No se pudo guardar temporalmente la tipografía.');
        try {
            try {
                $result = Process::timeout((int) config('studio.fonts.probe_timeout', 20))->path(base_path())->run([
                    (string) config('studio.python', 'python3'), '-I', base_path('scripts/studio/font_probe.py'),
                    $local->path($work.'/input.bin'), $local->path($work.'/font.woff2'),
                    (string) $max, (string) config('studio.fonts.max_decoded_bytes', 32 * 1024 * 1024),
                ]);
            } catch (ProcessTimedOutException|ProcessStartFailedException $e) {
                abort(503, 'El motor tipográfico no está disponible.');
            }
            $info = json_decode($result->output(), true);
            if (! $result->successful() || ! is_array($info) || ! ($info['ok'] ?? false)) {
                abort_if(! is_array($info) || ($info['error'] ?? null) === 'runtime_unavailable' || $result->exitCode() === 2, 503, 'El motor tipográfico no está disponible.');
                throw ValidationException::withMessages(['file' => ['La estructura tipográfica no es válida o supera sus límites.']]);
            }
            $bytes = $local->get($work.'/font.woff2');
            if (strlen($bytes) < 48 || strlen($bytes) > $max || substr($bytes, 0, 4) !== 'wOF2' || ! StudioDocument::validFont($info['family'] ?? null)
                || ! in_array($info['style'] ?? null, ['normal', 'italic'], true) || ! is_int($info['weight'] ?? null) || $info['weight'] < 100 || $info['weight'] > 900) {
                throw ValidationException::withMessages(['file' => ['El resultado tipográfico no es válido.']]);
            }
            $disk = (string) config('studio.fonts.disk', 'local');
            $path = 'studio-fonts/'.$user->id.'/'.$brand->id.'/'.Str::uuid().'.woff2';
            abort_unless(Storage::disk($disk)->put($path, $bytes), 503, 'No se pudo guardar la tipografía.');
            try {
                return StudioFont::create(['user_id' => $user->id, 'brand_id' => $brand->id, 'name' => filled($name) ? trim($name) : mb_substr($file->getClientOriginalName(), 0, 255),
                    'family' => $info['family'], 'style' => $info['style'], 'weight' => $info['weight'], 'format' => 'woff2', 'mime' => 'font/woff2',
                    'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'source_sha256' => hash('sha256', $input), 'disk' => $disk, 'path' => $path, 'status' => 'ready']);
            } catch (\Throwable $e) {
                Storage::disk($disk)->delete($path);
                throw $e;
            }
        } finally {
            $local->delete([$work.'/input.bin', $work.'/font.woff2']);
        }
    }

    public function owned(User $user, Brand $brand, int $id): StudioFont
    {
        $this->assertBrand($user, $brand);

        return StudioFont::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->where('status', 'ready')->findOrFail($id);
    }

    public function many(User $user, Brand $brand, array $ids): Collection
    {
        $this->assertBrand($user, $brand);
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return collect();
        }
        $fonts = StudioFont::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->where('status', 'ready')->whereIn('id', $ids)->get()->keyBy('id');
        abort_unless($fonts->count() === count($ids), 404);

        return $fonts;
    }

    public static function idsFromDocument(array $document): array
    {
        $ids = [];
        if (isset($document['timeline']['captions']['style']['font_id'])) {
            $ids[(int) $document['timeline']['captions']['style']['font_id']] = true;
        }
        $walk = function (array $elements) use (&$walk, &$ids): void {
            foreach ($elements as $element) {
                if (($element['type'] ?? null) === 'text' && isset($element['font_id'])) {
                    $ids[(int) $element['font_id']] = true;
                }
                if (($element['type'] ?? null) === 'chart' && isset($element['chart']['style']['font_id'])) {
                    $ids[(int) $element['chart']['style']['font_id']] = true;
                }
                if (($element['type'] ?? null) === 'group' && is_array($element['elements'] ?? null)) {
                    $walk($element['elements']);
                }
            }
        };
        foreach ($document['pages'] ?? [] as $page) {
            $walk($page['elements'] ?? []);
        }

        return array_keys($ids);
    }

    public function present(StudioFont $font, bool $api = false): array
    {
        return $font->only(['id', 'brand_id', 'name', 'family', 'style', 'weight', 'format', 'mime', 'size', 'sha256', 'status'])
            + ['archived_at' => $font->archived_at?->toIso8601String(), 'css_family' => 'StudioFont_'.$font->id.'_'.substr($font->sha256, 0, 16),
                'file_url' => route(($api ? 'api.' : '').'studio.fonts.file', ['brand' => $font->brand_id, 'font' => $font->id], false)];
    }

    public function bytes(StudioFont $font): string
    {
        $disk = Storage::disk($font->disk);
        abort_unless($font->status === 'ready' && str_starts_with($font->path, 'studio-fonts/'.$font->user_id.'/'.$font->brand_id.'/')
            && $disk->exists($font->path) && $disk->size($font->path) === $font->size && $font->size <= (int) config('studio.fonts.max_bytes', 8 * 1024 * 1024), 404);
        $bytes = $disk->get($font->path);
        abort_unless(hash_equals($font->sha256, hash('sha256', $bytes)), 404);

        return $bytes;
    }

    public function file(User $user, Brand $brand, int $id): StreamedResponse
    {
        $font = $this->owned($user, $brand, $id);
        $bytes = $this->bytes($font);

        return response()->stream(static function () use ($bytes): void {
            echo $bytes;
        }, 200, ['Content-Type' => 'font/woff2',
            'Content-Length' => (string) strlen($bytes), 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function neededFor(User $user, Brand $brand, array $document, bool $api = false): array
    {
        return $this->many($user, $brand, self::idsFromDocument($document))->map(fn ($font) => $this->present($font, $api))->values()->all();
    }

    public function archive(User $user, Brand $brand, int $id, bool $archived): StudioFont
    {
        $font = $this->owned($user, $brand, $id);
        $font->forceFill(['archived_at' => $archived ? now() : null])->save();

        return $font;
    }

    public function delete(User $user, Brand $brand, int $id): void
    {
        $this->assertBrand($user, $brand);
        DB::transaction(function () use ($user, $brand, $id): void {
            $font = StudioFont::withoutUserScope()->where('user_id', $user->id)->where('brand_id', $brand->id)->lockForUpdate()->findOrFail($id);
            abort_if(DB::table('studio_font_usages')->where('font_id', $id)->exists()
                || DB::table('studio_brand_kit_fonts')->where('font_id', $id)->exists(), 409, 'Esta tipografía está en uso en un kit o en el historial de un diseño.');
            $font->delete();
            Storage::disk($font->disk)->delete($font->path);
        });
    }

    private function assertBrand(User $user, Brand $brand): void
    {
        abort_unless((int) $brand->user_id === (int) $user->id, 404);
    }
}
