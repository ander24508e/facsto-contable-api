<?php

namespace App\Domains\Certificates\Models;

use App\Domains\Certificates\Enums\CertificateStatus;
use App\Domains\Companies\Models\Company;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

#[Fillable(['company_id', 'storage_path', 'password_ciphertext', 'issued_to', 'subject_identification', 'subject_ruc', 'identification_source', 'identification_candidates', 'issuer', 'serial_number', 'valid_from', 'valid_until', 'status', 'validation_error_code', 'validated_at', 'last_checked_at', 'is_active'])]
class DigitalCertificate extends Model
{
    protected static function booted(): void
    {
        static::creating(fn (self $certificate) => $certificate->public_id ??= (string) Str::uuid7());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected function casts(): array
    {
        return [
            'status' => CertificateStatus::class,
            'valid_from' => 'immutable_datetime',
            'valid_until' => 'immutable_datetime',
            'validated_at' => 'immutable_datetime',
            'last_checked_at' => 'immutable_datetime',
            'is_active' => 'boolean',
            'identification_candidates' => 'array',
        ];
    }
}
