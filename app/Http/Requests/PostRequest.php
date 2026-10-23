<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // la propiedad de la marca/publicacion se comprueba en el controlador (Policies)
    }

    public function rules(): array
    {
        return [
            'text' => ['nullable', 'string', 'max:70000'],
            // Hora local de la marca ("2026-11-02T10:00"); la zona la aplica PostService.
            'scheduled_at' => ['nullable', 'string', 'max:40'],
            'schedule' => ['required', 'boolean'],
            'media' => ['nullable', 'array', 'max:35'],
            'media.*' => ['integer'],
            'targets' => ['nullable', 'array', 'max:40'],
            'targets.*.account_id' => ['required', 'integer'],
            'targets.*.content_type' => ['required', 'string', 'max:40'],
            'targets.*.options' => ['nullable', 'array'],
            'targets.*.options.share_to_feed' => ['nullable', 'boolean'],
            'targets.*.options.link' => ['nullable', 'url', 'max:2000'],
            // YouTube (T-26) y TikTok (T-27).
            'targets.*.options.title' => ['nullable', 'string', 'max:100'],
            'targets.*.options.privacy' => ['nullable', 'in:public,unlisted,private'],
            'targets.*.options.shorts_hashtag' => ['nullable', 'boolean'],
            'targets.*.options.privacy_level' => ['nullable', 'in:PUBLIC_TO_EVERYONE,MUTUAL_FOLLOW_FRIENDS,FOLLOWER_OF_CREATOR,SELF_ONLY'],
            'targets.*.options.disable_comment' => ['nullable', 'boolean'],
        ];
    }
}
