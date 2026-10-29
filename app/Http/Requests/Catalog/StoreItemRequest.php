<?php

namespace App\Http\Requests\Catalog;

use App\Domains\Catalog\Enums\ItemType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ItemType::class)],
            'internalCode' => ['required', 'string', 'max:25'],
            'auxiliaryCode' => ['nullable', 'string', 'max:25'],
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string'],
            'unit' => ['required', 'string', 'max:32'],
            'salePrice' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'priceIncludesTax' => ['sometimes', 'boolean'],
            'categoryPublicId' => ['nullable', 'uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['internal_code' => $this->input('internalCode'), 'auxiliary_code' => $this->input('auxiliaryCode'), 'sale_price' => $this->input('salePrice'), 'category_public_id' => $this->input('categoryPublicId')]);
    }
}
