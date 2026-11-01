<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Catalog\Models\FiscalPaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FiscalPaymentMethodResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Payment methods: the SRI's table 24 and CREDITO, fixed and read only. */
final class FiscalPaymentMethodController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = FiscalPaymentMethod::query()->valid()->orderBy('is_credit')->orderBy('name');

        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($builder) => $builder
                ->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($search).'%'])
                ->orWhereRaw('LOWER(code) LIKE ?', ['%'.mb_strtolower($search).'%']));
        }

        return FiscalPaymentMethodResource::collection(
            $query->paginate(min(max((int) $request->query('per_page', 15), 1), 100)),
        );
    }
}
