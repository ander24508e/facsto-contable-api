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
            'brandPublicId' => ['sometimes', 'nullable', 'uuid'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:64'],
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
        $mapped = [
            'internal_code' => $this->input('internalCode'),
            'auxiliary_code' => $this->input('auxiliaryCode'),
            'sale_price' => $this->input('salePrice'),
            'category_public_id' => $this->input('categoryPublicId'),
            'brand_public_id' => $this->input('brandPublicId'),
            'barcode' => $this->input('barcode') === null ? null : trim((string) $this->input('barcode')),
        ];
        $this->merge(array_filter($mapped, fn ($value, $key) => $value !== null || array_key_exists(match ($key) {
            'internal_code' => 'internalCode', 'auxiliary_code' => 'auxiliaryCode', 'sale_price' => 'salePrice',
            'category_public_id' => 'categoryPublicId', 'brand_public_id' => 'brandPublicId', default => 'barcode',
        }, $this->all()), ARRAY_FILTER_USE_BOTH));
    }
}
