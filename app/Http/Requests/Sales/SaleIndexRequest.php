<?php

namespace App\Http\Requests\Sales;

use App\Domains\Sales\Enums\SaleStatus;
use App\Domains\Sales\Services\SaleStageFilter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaleIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'string', 'max:120'],
            'status' => ['sometimes', 'nullable', Rule::enum(SaleStatus::class)],
            'stage' => ['sometimes', 'nullable', Rule::in(array_keys(SaleStageFilter::STAGES))],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
