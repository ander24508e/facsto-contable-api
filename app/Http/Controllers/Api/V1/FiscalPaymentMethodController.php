<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Catalog\Models\FiscalPaymentMethod;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\FiscalPaymentMethodResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class FiscalPaymentMethodController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return FiscalPaymentMethodResource::collection(FiscalPaymentMethod::query()
            ->where('is_active', true)
            ->whereDate('valid_from', '<=', today())
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhereDate('valid_until', '>=', today()))
            ->orderBy('name')
            ->paginate(50));
    }
}
