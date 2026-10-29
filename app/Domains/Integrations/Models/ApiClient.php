<?php

namespace App\Domains\Integrations\Models;

use App\Domains\Companies\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** A module (Taller, Lavadora…) that invoices through FACSTO with its own token. */
#[Fillable(['company_id', 'name', 'module', 'token_hash', 'token_prefix', 'webhook_url', 'webhook_secret', 'is_active', 'last_used_at', 'created_by'])]
#[Hidden(['token_hash', 'webhook_secret'])]
class ApiClient extends Model
{
    public const TOKEN_PREFIX = 'fct_';

    protected static function booted(): void
    {
        static::creating(fn (self $client) => $client->public_id ??= (string) Str::uuid7());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /** @return array{0: string, 1: string} the plain token (shown once) and its SHA-256 */
    public static function newToken(): array
    {
        $token = self::TOKEN_PREFIX.Str::random(48);

        return [$token, hash('sha256', $token)];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function webhookDeliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    protected function casts(): array
    {
        return ['webhook_secret' => 'encrypted', 'is_active' => 'boolean', 'last_used_at' => 'immutable_datetime'];
    }
}
