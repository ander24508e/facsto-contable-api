<?php

namespace App\Http\Requests\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

abstract class SaleDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'customerPublicId' => ['sometimes', 'nullable', 'uuid'],
            'cashSessionPublicId' => ['sometimes', 'nullable', 'uuid'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'tipAmount' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,14}(\.\d{1,2})?$/'],
            'lines' => ['sometimes', 'array', 'max:100'],
            'lines.*.itemPublicId' => ['required', 'uuid'],
            'lines.*.quantity' => ['required', 'string', 'regex:/^\d{1,9}(\.\d{1,6})?$/'],
            'lines.*.discountAmount' => ['nullable', 'string', 'regex:/^\d{1,14}(\.\d{1,2})?$/'],
            'paymentMethods' => ['sometimes', 'array', 'max:10'],
            'paymentMethods.*.fiscalPaymentMethodPublicId' => ['required', 'uuid'],
            'paymentMethods.*.amount' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'paymentMethods.*.reference' => ['nullable', 'string', 'max:120'],
            'paymentMethods.*.term' => ['nullable', 'string', 'regex:/^\d{1,10}(\.\d{1,2})?$/'],
            'paymentMethods.*.installments' => ['sometimes', 'nullable', 'array', 'max:120'],
            'paymentMethods.*.downPayment' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'paymentMethods.*.installmentFrequency' => ['sometimes', 'nullable', 'in:DAILY,BIWEEKLY,MONTHLY,SEMIANNUAL,ANNUAL'],
            'paymentMethods.*.creditNotes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'paymentMethods.*.installments.*.dueDate' => ['required', 'date_format:Y-m-d'],
            'paymentMethods.*.installments.*.amount' => ['required', 'string', 'regex:/^\d{1,16}(\.\d{1,2})?$/'],
            'paymentMethods.*.timeUnit' => ['nullable', 'required_with:paymentMethods.*.term', Rule::in(['DAY', 'MONTH'])],
        ];
    }
}
