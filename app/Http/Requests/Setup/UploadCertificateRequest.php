<?php

namespace App\Http\Requests\Setup;

use Illuminate\Foundation\Http\FormRequest;

final class UploadCertificateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'certificate' => ['required', 'file', 'extensions:p12,pfx', 'max:10240'],
            'password' => ['required', 'string', 'max:4096'],
        ];
    }
}
