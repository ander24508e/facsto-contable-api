<?php

namespace App\Domains\Cash\Models;

use App\Domains\Companies\Models\EmissionPoint;
use App\Domains\Inventory\Models\Warehouse;
use App\Domains\Sales\Models\Sale;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/** One session of a cash register (an emission point): its opening, the money in and out, and its closing. */
#[Fillable([
    'company_id', 'emission_point_id', 'warehouse_id', 'opened_by', 'opened_at', 'opening_mode', 'opening_amount', 'denominations', 'notes', 'status',
    'closed_by', 'closed_at', 'closing_mode', 'closing_amount', 'closing_denominations', 'expected_amount', 'difference', 'closing_notes',
])]
class CashSession extends Model
{
    public const OPEN = 'OPEN';

    public const CLOSED = 'CLOSED';

    protected static function booted(): void
    {
        static::creating(fn (self $session) => $session->public_id ??= (string) Str::uuid7());
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function emissionPoint(): BelongsTo
    {
        return $this->belongsTo(EmissionPoint::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * The money the drawer should hold now: opening + money in − money out (exact decimals).
     *
     * @return array{opening: string, in: string, out: string, expected: string}
     */
    public function balance(): array
    {
        $in = (string) $this->movements()->where('type', CashMovement::IN)->sum('amount');
        $out = (string) $this->movements()->where('type', CashMovement::OUT)->sum('amount');
        $opening = (string) $this->opening_amount;

        return [
            'opening' => bcadd($opening, '0', 2),
            'in' => bcadd($in, '0', 2),
            'out' => bcadd($out, '0', 2),
            'expected' => bcsub(bcadd($opening, $in, 2), $out, 2),
        ];
    }

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime', 'closed_at' => 'datetime', 'opening_amount' => 'decimal:2', 'denominations' => 'array',
            'closing_amount' => 'decimal:2', 'closing_denominations' => 'array', 'expected_amount' => 'decimal:2', 'difference' => 'decimal:2',
        ];
    }
}
