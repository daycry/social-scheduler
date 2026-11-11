<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PostRequest extends FormRequest
{
    public const NOTES_MAX = 5000;

    public function authorize(): bool
    {
        return true; // la propiedad de la marca/publicacion se comprueba en el controlador (Policies)
    }

    public function rules(): array
    {
        return [
            'text' => ['nullable', 'string', 'max:70000'],
            // Notas internas (C-09): texto plano, nunca se publican. Omitidas = no se tocan.
            'notes' => ['nullable', 'string', 'max:'.self::NOTES_MAX],
            // Hora local de la marca ("2026-11-02T10:00"); la zona la aplica PostService.
            // Con `publish_now` la fecha no se envia: la pone el servidor (`now()`).
            'scheduled_at' => ['nullable', 'string', 'max:40', 'prohibited_if:publish_now,true,1'],
            // Campaña del post (C-03): omitida = no se toca; nula = quitarla. Que sea de la marca y del usuario lo valida PostService::plan().
            'campaign_id' => ['nullable', 'integer'],
            'schedule' => ['required', 'boolean'],
            // Aprobacion (T-19): el post nace como borrador pendiente de aprobar y no se programa hasta estar aprobado.
            'requires_approval' => ['nullable', 'boolean'],
            // «Publicar ahora»: programa con la fecha del servidor y encola tras el commit (PostService::publishNow, ADR-005).
            'publish_now' => ['nullable', 'boolean'],
            'media' => ['nullable', 'array', 'max:35'],
            'media.*' => ['integer'],
            'targets' => ['nullable', 'array', 'max:40'],
            'targets.*.account_id' => ['required', 'integer'],
            'targets.*.content_type' => ['required', 'string', 'max:40'],
            // Texto propio del destino (C-03): `null` = vinculado a la plantilla. El maximo real por red lo pone MediaValidator.
            'targets.*.text' => ['nullable', 'string', 'max:70000'],
            // Publicacion manual (T-19): el destino no se envia a la red; a la hora se avisa y el usuario lo marca como publicado.
            'targets.*.manual' => ['nullable', 'boolean'],
            // Primer comentario del destino (C-01b): el maximo real por red lo pone PostService::plan() con `media_specs`.
            'targets.*.first_comment' => ['nullable', 'string', 'max:10000'],
            // Media propia del destino (T-17): ausente o null = hereda la comun; lista = esos archivos en ese orden, con alt y portada propios.
            // Los limites por red y tipo los pone PostService (los mismos validadores que la media comun).
            'targets.*.media' => ['nullable', 'array', 'max:35'],
            'targets.*.media.*' => ['array:id,alt,cover_id'],
            'targets.*.media.*.id' => ['required', 'integer'],
            'targets.*.media.*.alt' => ['nullable', 'string', 'max:5000'],
            'targets.*.media.*.cover_id' => ['nullable', 'integer'],
            'targets.*.options' => ['nullable', 'array'],
            'targets.*.options.share_to_feed' => ['nullable', 'boolean'],
            'targets.*.options.collaborators' => ['nullable', 'array', 'max:3'],
            'targets.*.options.collaborators.*' => ['required', 'string', 'max:32'],
            'targets.*.options.is_ai_generated' => ['nullable', 'boolean'],
            'targets.*.options.link' => ['nullable', 'url', 'max:2000'],
            'targets.*.options.facebook_title' => ['nullable', 'string', 'max:255'],
            'targets.*.options.instagram_audio' => ['nullable', 'array:audio_id,audio_volume,video_volume'],
            'targets.*.options.instagram_audio.audio_id' => ['required_with:targets.*.options.instagram_audio', 'string', 'regex:/^[0-9]{1,40}$/D'],
            'targets.*.options.instagram_audio.audio_volume' => ['sometimes', 'integer', 'between:1,100'],
            'targets.*.options.instagram_audio.video_volume' => ['sometimes', 'integer', 'between:1,100'],
            // YouTube (T-26) y TikTok (T-27).
            'targets.*.options.title' => ['nullable', 'string', 'max:100'],
            'targets.*.options.privacy' => ['nullable', 'in:public,unlisted,private'],
            'targets.*.options.shorts_hashtag' => ['nullable', 'boolean'],
            'targets.*.options.privacy_level' => ['nullable', 'in:PUBLIC_TO_EVERYONE,MUTUAL_FOLLOW_FRIENDS,FOLLOWER_OF_CREATOR,SELF_ONLY'],
            'targets.*.options.disable_comment' => ['nullable', 'boolean'],
            'targets.*.options.auto_add_music' => ['nullable', 'boolean'],
            // YouTube (C-04, T-26): solo los campos confirmados en la documentacion de la Data API v3.
            'targets.*.options.category_id' => ['nullable', 'regex:/^\d{1,3}$/'],
            'targets.*.options.tags' => ['nullable', 'array', 'max:30'],
            'targets.*.options.tags.*' => ['nullable', 'string', 'max:100'],
            'targets.*.options.made_for_kids' => ['nullable', 'boolean'],
            'targets.*.options.notify_subscribers' => ['nullable', 'boolean'],
            'targets.*.options.contains_synthetic_media' => ['nullable', 'boolean'],
            // TikTok (C-04): solo actuan en publicacion directa; la regla por cuenta la aplica PostService.
            'targets.*.options.disable_duet' => ['nullable', 'boolean'],
            'targets.*.options.disable_stitch' => ['nullable', 'boolean'],
            'targets.*.options.brand_organic_toggle' => ['nullable', 'boolean'],
            'targets.*.options.brand_content_toggle' => ['nullable', 'boolean'],
            'targets.*.options.is_aigc' => ['nullable', 'boolean'],
            // T-18: ubicacion y etiquetas de Instagram, LinkedIn (visibilidad, comparticion) y Pinterest (tablero, titulo, enlace). El detalle por red
            // (formas, coordenadas, paginas sin CONNECTIONS, tablero obligatorio) lo valida PostService::optionErrors con el mismo texto en web, API y MCP.
            'targets.*.options.location_id' => ['nullable'],
            'targets.*.options.user_tags' => ['nullable', 'array', 'max:20'],
            'targets.*.options.user_tags.*' => ['nullable', 'array:username,x,y'],
            'targets.*.options.user_tags.*.username' => ['nullable', 'string', 'max:64'],
            'targets.*.options.user_tags.*.x' => ['nullable', 'numeric'],
            'targets.*.options.user_tags.*.y' => ['nullable', 'numeric'],
            'targets.*.options.linkedin_visibility' => ['nullable', 'string', 'max:20'],
            'targets.*.options.linkedin_disable_reshare' => ['nullable', 'boolean'],
            'targets.*.options.pinterest_board_id' => ['nullable'],
            'targets.*.options.pinterest_title' => ['nullable', 'string', 'max:1000'],
            'targets.*.options.pinterest_link' => ['nullable', 'string', 'max:5000'],
        ];
    }
}
