<?php

namespace App\Http\Requests\Catalog;

use App\Domains\Catalog\Enums\ItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', Rule::enum(ItemType::class)],
            'internalCode' => ['sometimes', 'string', 'max:50'],
            'auxiliaryCode' => ['sometimes', 'nullable', 'string', 'max:50'],
            'name' => ['sometimes', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string'],
            'unit' => ['sometimes', 'string', 'max:32'],
            'salePrice' => ['sometimes', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter(['internal_code' => $this->input('internalCode'), 'auxiliary_code' => $this->input('auxiliaryCode'), 'sale_price' => $this->input('salePrice')], fn ($value) => $value !== null));
    }
}
