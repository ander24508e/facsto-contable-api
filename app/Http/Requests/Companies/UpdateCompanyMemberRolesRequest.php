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
            'rolePublicIds' => ['required', 'array', 'size:1'],
            'rolePublicIds.*' => ['required', 'uuid', 'distinct'],
        ];
    }
}
