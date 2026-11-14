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
            // «Convertir lo que hay»: only these products; the rest stays as a new proforma.
            'itemPublicIds' => ['sometimes', 'array', 'max:100'],
            'itemPublicIds.*' => ['uuid', 'distinct'],
        ];
    }
}
