<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Catalog\Models\Tax;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\TaxResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class TaxController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Tax::class);
        $query = Tax::query()->where('is_active', true)
            ->whereDate('valid_from', '<=', today())
            ->where(fn ($builder) => $builder->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
            ->orderBy('tax_type')->orderBy('percentage');
        if ($type = $request->query('tax_type')) {
            $query->where('tax_type', $type);
        }

        return TaxResource::collection($query->paginate(min(max((int) $request->query('per_page', 50), 1), 100)));
    }
}
