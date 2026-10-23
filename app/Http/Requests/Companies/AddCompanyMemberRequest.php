<?php

namespace App\Http\Requests\Companies;

use App\Domains\Companies\Enums\CompanyRole;
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
            'role' => ['required', Rule::enum(CompanyRole::class)],
        ];
    }
}
