<?php

namespace App\Http\Requests;

use App\Models\InboxCommentNote;
use Illuminate\Foundation\Http\FormRequest;

/** Alta de una nota interna de un comentario del inbox (C-04d): texto plano, hasta 2.000 caracteres. */
class InboxNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:'.InboxCommentNote::BODY_MAX]];
    }

    public function attributes(): array
    {
        return ['body' => 'nota'];
    }
}
