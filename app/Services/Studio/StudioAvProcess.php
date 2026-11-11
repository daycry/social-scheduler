<?php

namespace App\Services\Studio;

use App\Models\MediaAsset;
use App\Models\Studio\DesignExport;
use App\Models\Studio\DesignRevision;
use App\Models\Studio\StudioAsset;
use App\Models\Studio\StudioAvLease;
use App\Models\Studio\StudioAvWorkspace;
use App\Models\Studio\StudioFont;
use Illuminate\Support\Facades\Process;

class StudioAvProcess
{
    public function __construct(private readonly StudioAvState $state, private readonly StudioAvStorage $storage) {}

    public function execute(StudioAvLease $lease, ?float $deadline = null): array
    {
        $deadline = min($deadline ?? microtime(true) + 85, microtime(true) + 85);

        return $this->storage->locked($lease->workspace_id, function (StudioAvWorkspace $workspace) use ($lease, $deadline) {
            $export = $this->state->workingExport($lease);
            if (! in_array($lease->phase, ['plan', 'normalize', 'render', 'mix', 'assemble'], true)) {
                throw new StudioAvProcessFailed('invalid_request');
            }
            $document = DesignRevision::withoutUserScope()->where('user_id', $export->user_id)->where('design_id', $export->design_id)
                ->where('revision', $export->revision)->firstOrFail()->document;
            if (hash('sha256', json_encode($document, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION)) !== $export->render_state['document_hash']) {
                throw new StudioAvProcessFailed('invalid_request');
            }
            $record = StudioAvWorkspace::withoutUserScope()->whereKey($workspace->id)->firstOrFail(['id', 'source_metadata']);
            $metadata = $record->source_metadata ?? $this->metadata($export, $document);
            $input = ['document' => $document, 'assets' => $metadata['assets'], 'fonts' => $metadata['fonts'], 'format' => $export->format,
                'options' => ['pageIds' => $export->pages, 'scale' => (float) ($export->options['scale'] ?? 1), 'quality' => (float) ($export->options['quality'] ?? 0.94)],
                'audioSources' => [], 'segments' => []];
            if ($lease->phase === 'mix') {
                $input['audioSources'] = $this->state->checkpoints($export, ['normalize'])->pluck('result')->values()->all();
            } elseif ($lease->phase === 'assemble') {
                $results = $this->state->checkpoints($export, ['render', 'mix']);
                $plan = StudioAvWorkspace::withoutUserScope()->whereKey($workspace->id)->firstOrFail(['id', 'plan'])->plan;
                for ($index = 0; $index < $plan['segmentCount']; $index++) {
                    $input['segments'][] = ['video' => $results->get('render:'.$index)?->result, 'audio' => $results->get('mix:'.$index)?->result];
                }
            }
            $directory = $this->storage->manifest($workspace, $lease->id, $input);
            try {
                $this->state->workingExport($lease);
                $budget = (int) floor(($deadline - microtime(true)) * 1000);
                if ($budget < 100) {
                    throw new StudioAvProcessFailed('phase_timeout');
                }
                $process = Process::path(base_path())->timeout(min(92, (int) ceil($budget / 1000) + 6))
                    ->env(['STUDIO_NODE_BINARY' => config('studio.node', 'node'), 'STUDIO_AV_BUDGET_MS' => (string) $budget])
                    ->run([config('studio.python'), base_path('scripts/studio/av-runtime.py'), $directory, $lease->id, $lease->phase, (string) $lease->item]);
                $raw = $process->output();
                $output = strlen($raw) <= 8 * 1024 ** 2 ? json_decode($raw, true) : null;
                if (! $process->successful() || ($output['ok'] ?? null) !== true) {
                    $code = $output['error_code'] ?? '';
                    throw new StudioAvProcessFailed(in_array($code, ['phase_timeout', 'runtime_unavailable', 'missing_resource', 'plan_budget', 'output_budget', 'phase_cancelled'], true) ? $code : 'phase_failed');
                }
                $memory = $output['memory'] ?? [];
                if (($output['namespace'] ?? null) !== $lease->id || ($output['phase'] ?? null) !== $lease->phase || ($output['index'] ?? null) !== $lease->item
                    || ! is_array($output['result'] ?? null) || ! is_int($memory['limit'] ?? null) || $memory['limit'] < 1 || $memory['limit'] > 3 * 1024 ** 3
                    || ! in_array($memory['scope'] ?? null, ['job', 'cgroup'], true)
                    || ($memory['scope'] === 'job' && (($memory['kill_on_close'] ?? null) !== true || ($memory['breakaway'] ?? null) !== false))) {
                    throw new StudioAvProcessFailed('runtime_unavailable');
                }
                $this->state->workingExport($lease);
                if ($record->source_metadata === null) {
                    $record->update(['source_metadata' => $metadata]);
                }

                return $output['result'] + ['runtime_memory' => array_intersect_key($memory, array_flip(['scope', 'limit', 'peak_commit', 'kill_on_close', 'breakaway']))];
            } finally {
                $this->storage->removeManifest($workspace, $lease->id);
            }
        });
    }

    private function metadata(DesignExport $export, array $document): array
    {
        $ids = [];
        $walk = function (array $elements) use (&$walk, &$ids): void {
            foreach ($elements as $element) {
                if (isset($element['asset_id'])) {
                    $ids[$element['asset_id']] = true;
                }
                if ($element['type'] === 'group') {
                    $walk($element['elements']);
                }
            }
        };
        foreach ($document['pages'] as $page) {
            $walk($page['elements']);
        }
        foreach ($document['timeline']['audio_tracks'] ?? [] as $track) {
            $ids[$track['asset_id']] = true;
        }
        $links = StudioAsset::withoutUserScope()->where('user_id', $export->user_id)->where('brand_id', $export->brand_id)
            ->whereIn('media_asset_id', array_keys($ids))->with('media')->get();
        $assets = $links->map(fn ($link) => $link->media)->filter(fn ($asset) => $asset && (int) $asset->user_id === (int) $export->user_id && $asset->status === MediaAsset::STATUS_READY)
            ->sortBy('id')->map(fn ($asset) => $asset->only(['id', 'kind', 'mime', 'width', 'height', 'duration', 'audio_codec', 'size', 'fps', 'status']) + ['file' => 'assets/'.$asset->id.'.bin'])->values()->all();
        $fonts = StudioFont::withoutUserScope()->where('user_id', $export->user_id)->where('brand_id', $export->brand_id)->where('status', 'ready')
            ->whereIn('id', StudioFonts::idsFromDocument($document))->orderBy('id')->get()
            ->map(fn ($font) => $font->only(['id', 'family', 'format', 'mime', 'size', 'sha256', 'style', 'weight', 'status'])
                + ['file' => 'fonts/'.$font->id.'.woff2', 'css_family' => 'StudioFont_'.$font->id.'_'.substr($font->sha256, 0, 16)])->all();

        return ['assets' => $assets, 'fonts' => $fonts];
    }
}
