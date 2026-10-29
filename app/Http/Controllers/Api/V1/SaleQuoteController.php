<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\SaleQuoteService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\QuoteSaleRequest;
use Illuminate\Http\JsonResponse;

/** POST /sales/quote — calculates a sale without saving it, with the same math the invoice uses. */
final class SaleQuoteController extends Controller
{
    public function __invoke(QuoteSaleRequest $request, SaleQuoteService $quotes): JsonResponse
    {
        $this->authorize('create', Sale::class);

        return response()->json(['data' => $quotes->quote($request->validated())]);
    }
}
