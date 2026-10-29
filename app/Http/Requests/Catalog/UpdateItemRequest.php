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
            'internalCode' => ['sometimes', 'string', 'max:25'],
            'auxiliaryCode' => ['sometimes', 'nullable', 'string', 'max:25'],
            'name' => ['sometimes', 'string', 'max:60'],
            'description' => ['sometimes', 'nullable', 'string'],
            'unit' => ['sometimes', 'string', 'max:32'],
            'salePrice' => ['sometimes', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'priceIncludesTax' => ['sometimes', 'boolean'],
            'categoryPublicId' => ['sometimes', 'nullable', 'uuid'],
            'costPrice' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'additionalDetail' => ['sometimes', 'nullable', 'string', 'max:300'],
            'stock' => ['sometimes', 'array'],
            'stock.warehousePublicId' => ['required_with:stock', 'uuid'],
            'stock.quantity' => ['required_with:stock', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'priceTiers' => ['sometimes', 'array', 'max:10'],
            'priceTiers.*.minQuantity' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
            'priceTiers.*.price' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,6})?$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.max' => 'El nombre no puede superar los 60 caracteres.',
            'stock.required_if' => 'Indica la bodega y cuántas unidades del producto tienes.',
        ];
    }

    public function withValidator($validator): void
    {
        // Wholesale prices start from 2 units: one unit is the normal sale price.
        $validator->after(function ($validator): void {
            foreach ((array) $this->input('priceTiers', []) as $index => $tier) {
                if (is_array($tier) && is_numeric($tier['minQuantity'] ?? null) && (float) $tier['minQuantity'] <= 1) {
                    $validator->errors()->add("priceTiers.$index.minQuantity", 'El precio alterno empieza desde 2 unidades.');
                }
            }
        });
    }

    protected function prepareForValidation(): void
    {
        $this->merge(array_filter(['internal_code' => $this->input('internalCode'), 'auxiliary_code' => $this->input('auxiliaryCode'), 'sale_price' => $this->input('salePrice'), 'category_public_id' => $this->input('categoryPublicId')], fn ($value) => $value !== null || array_key_exists('categoryPublicId', $this->all())));
    }
}
