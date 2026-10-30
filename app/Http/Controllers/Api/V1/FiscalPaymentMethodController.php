<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Catalog\Models\FiscalPaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FiscalPaymentMethodResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Request;

final class FiscalPaymentMethodController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = FiscalPaymentMethod::query()
            ->where('is_active', true)
            ->whereDate('valid_from', '<=', today())
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
            ->orderBy('name');

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
