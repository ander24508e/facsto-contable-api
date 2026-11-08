<?php

namespace App\Domains\Carwash\Models;

use App\Domains\Companies\Models\Company;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** General opening hours and the length of the agenda slots; the capacity comes from the work areas. */
#[Fillable(['company_id', 'opening_time', 'closing_time', 'slot_minutes'])]
class CarwashSetting extends Model
{
    protected $table = 'carwash_settings';

    public static function for(Company $company): self
    {
        return self::query()->firstOrCreate(['company_id' => $company->id], [
            'opening_time' => '08:00', 'closing_time' => '18:00', 'slot_minutes' => 30,
        ]);
    }

    protected function casts(): array
    {
        return ['slot_minutes' => 'integer'];
    }
}
