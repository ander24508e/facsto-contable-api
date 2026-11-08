<?php

namespace App\Http\Requests\Proformas;

use App\Http\Requests\Sales\SaleDraftRequest;

final class ConvertProformaRequest extends SaleDraftRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'cashSessionPublicId' => ['required', 'uuid'],
            'paymentMethods' => ['required', 'array', 'min:1', 'max:10'],
        ];
    }
}
