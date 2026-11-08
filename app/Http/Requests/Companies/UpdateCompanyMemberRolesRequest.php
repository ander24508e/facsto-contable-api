<?php

namespace App\Http\Requests\Companies;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCompanyMemberRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'rolePublicIds' => ['required', 'array', 'min:1', 'max:20'],
            'rolePublicIds.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
