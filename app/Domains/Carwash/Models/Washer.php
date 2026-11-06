<?php

namespace App\Domains\Carwash\Models;

use App\Domains\Carwash\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Washing staff: they do not log in, they are assigned to orders. */
#[Fillable(['company_id', 'name', 'phone', 'is_active'])]
class Washer extends Model
{
    use HasPublicId;

    protected $table = 'carwash_washers';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
