<?php

namespace App\Services\Studio;

use App\Models\Studio\Design;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/** One mutation/persistence path for authenticated web, API and MCP consumers. */
class StudioCaptions
{
    public function __construct(private readonly StudioService $studio, private readonly StudioExports $exports, private readonly StudioCaptionCodec $codec) {}

    public function get(User $user, Design $design, array $filters = []): array
    {
        $current = $this->studio->owned($user, $design);
        $data = Validator::make($filters, ['revision' => ['sometimes', 'integer', 'min:1'], 'page' => ['sometimes', 'integer', 'min:1', 'max:5001'], 'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'], 'query' => ['sometimes', 'string', 'max:120']])->validate();
        $revision = (int) ($data['revision'] ?? $current->revision);
        $document = $this->exports->revision($user, $current, $revision)->document;
        $captions = $document['timeline']['captions'] ?? null;
        $cues = $captions['cues'] ?? [];
        if (($data['query'] ?? '') !== '') {
            $cues = array_values(array_filter($cues, fn ($cue) => mb_stripos($cue['text'], $data['query']) !== false));
        }
        $total = count($cues);
        $page = (int) ($data['page'] ?? 1);
        $perPage = (int) ($data['per_page'] ?? 50);

        return ['design_id' => $current->id, 'revision' => $revision, 'has_captions' => $captions !== null, 'style' => $captions['style'] ?? null,
            'cues' => array_slice($cues, ($page - 1) * $perPage, $perPage), 'pagination' => ['current_page' => $page, 'per_page' => $perPage, 'total' => $total,
                'last_page' => max(1, (int) ceil($total / $perPage)), 'next_page' => $page * $perPage < $total ? $page + 1 : null]];
    }

    public function mutate(User $user, Design $design, array $data): array
    {
        $current = $this->studio->owned($user, $design);
        StudioCaptionData::keys($data, ['revision', 'action', 'activate_temporal', 'format', 'text', 'mode', 'captions', 'cue', 'ids', 'seconds', 'style'], 'operación');
        $data = Validator::make($data, ['revision' => ['required', 'integer', 'min:1'], 'action' => ['required', 'in:import,replace,upsert,delete,offset,style,clear'],
            'activate_temporal' => ['sometimes', 'boolean'], 'format' => ['required_if:action,import', 'in:srt,vtt'], 'text' => ['required_if:action,import', 'string'],
            'mode' => ['sometimes', 'in:replace,append'], 'seconds' => ['required_if:action,offset'], 'ids' => ['sometimes', 'array', 'max:5000'],
            'ids.*' => ['string', 'distinct'], 'captions' => ['required_if:action,replace', 'array'], 'cue' => ['required_if:action,upsert', 'array'], 'style' => ['required_if:action,style', 'array']])->validate();
        abort_if($current->revision !== (int) $data['revision'], 409, 'El diseño cambió en otra sesión. Recarga la revisión.');
        $document = $current->document;
        if ($document['schema_version'] !== 2) {
            if (($data['activate_temporal'] ?? false) !== true) {
                StudioCaptionData::fail('Activa explícitamente el modo temporal antes de añadir subtítulos.');
            }
            $document['schema_version'] = 2;
            $document['timeline'] = ['fps' => 30, 'audio_tracks' => []];
        }
        $captions = $document['timeline']['captions'] ?? ['style' => [], 'cues' => []];
        $warnings = [];
        switch ($data['action']) {
            case 'import':
                $parsed = $this->codec->parse($data['text'], $data['format']);
                $warnings = $parsed['warnings'];
                $append = ($data['mode'] ?? 'append') === 'append';
                $ids = app(StudioCaptionData::class)->reservedIds($document, $append);
                $index = 1;
                foreach ($parsed['cues'] as &$cue) {
                    while (isset($ids[$cue['id']])) {
                        $cue['id'] = 'import_'.$index++;
                    }
                    $ids[$cue['id']] = true;
                }
                unset($cue);
                if ($append) {
                    $captions['cues'] = array_merge($captions['cues'], $parsed['cues']);
                } else {
                    $captions['cues'] = $parsed['cues'];
                }
                break;
            case 'replace':
                $captions = $data['captions'];
                break;
            case 'upsert':
                $id = $data['cue']['id'] ?? null;
                $captions['cues'] = array_values(array_filter($captions['cues'], fn ($cue) => $cue['id'] !== $id));
                $captions['cues'][] = $data['cue'];
                break;
            case 'delete':
            case 'offset':
                $selected = $data['ids'] ?? array_column($captions['cues'], 'id');
                if (array_diff($selected, array_column($captions['cues'], 'id')) !== []) {
                    StudioCaptionData::fail('Hay cues seleccionados que no existen.');
                }
                if ($data['action'] === 'delete') {
                    $captions['cues'] = array_values(array_filter($captions['cues'], fn ($cue) => ! in_array($cue['id'], $selected, true)));
                } else {
                    $seconds = StudioCaptionData::number($data['seconds'], -86400, 86400, 'desplazamiento');
                    foreach ($captions['cues'] as &$cue) {
                        if (in_array($cue['id'], $selected, true)) {
                            $cue['start'] += $seconds;
                            $cue['end'] += $seconds;
                        }
                    }
                    unset($cue);
                }
                break;
            case 'style':
                $captions['style'] = $data['style'];
                break;
        }
        if ($data['action'] === 'clear') {
            unset($document['timeline']['captions']);
        } else {
            $document['timeline']['captions'] = $captions;
        }
        $saved = $this->studio->update($user, $current, ['name' => $current->name, 'document' => $document, 'revision' => $data['revision']]);

        return $this->get($user, $saved) + ['warnings' => $warnings];
    }

    public function export(User $user, Design $design, array $data): array
    {
        $current = $this->studio->owned($user, $design);
        $data = Validator::make($data, ['revision' => ['sometimes', 'integer', 'min:1'], 'format' => ['required', 'in:srt,vtt']])->validate();
        $revision = (int) ($data['revision'] ?? $current->revision);
        $document = $this->exports->revision($user, $current, $revision)->document;

        return ['revision' => $revision, 'format' => $data['format'], 'filename' => 'captions-'.$current->id.'-r'.$revision.'.'.$data['format'],
            'mime' => $data['format'] === 'vtt' ? 'text/vtt' : 'application/x-subrip', 'text' => $this->codec->encode($document['timeline']['captions']['cues'] ?? [], $data['format'])];
    }
}
