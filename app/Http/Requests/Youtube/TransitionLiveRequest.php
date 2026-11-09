<?php

namespace App\Http\Requests\Youtube;

use Illuminate\Foundation\Http\FormRequest;

class TransitionLiveRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return ['broadcast_id' => ['required', 'string', 'exists:youtube_live_streams,broadcast_id']];
    }
}
