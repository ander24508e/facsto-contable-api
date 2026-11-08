<?php

namespace App\Http\Requests\Companies;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AddCompanyMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'userPublicId' => ['required', 'uuid', Rule::exists('users', 'public_id')],
            'rolePublicIds' => ['required', 'array', 'min:1', 'max:20'],
            'rolePublicIds.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
