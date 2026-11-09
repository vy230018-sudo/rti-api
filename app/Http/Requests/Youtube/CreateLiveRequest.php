<?php

namespace App\Http\Requests\Youtube;

use Illuminate\Foundation\Http\FormRequest;

class CreateLiveRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            'privacyStatus' => ['required', 'in:public,private,unlisted'],
            'scheduledStartTime' => ['required', 'date', 'after:now'],
        ];
    }
}
