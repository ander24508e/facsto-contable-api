<?php

namespace App\Http\Requests\Companies;

use App\Domains\Companies\Enums\CompanyRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateCompanyMemberRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['role' => ['required', Rule::enum(CompanyRole::class)]];
    }
}
