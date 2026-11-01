<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Sri\Services\SriTaxpayerDirectory;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /taxpayers/lookup?identification= — the SRI catastro data of a RUC or cédula, or null when unknown or down. */
final class TaxpayerLookupController extends Controller
{
    public function __invoke(Request $request, SriTaxpayerDirectory $taxpayers): JsonResponse
    {
        $identification = $request->validate(['identification' => ['required', 'string', 'regex:/^(\d{10}|\d{13})$/']])['identification'];

        return response()->json(['data' => $taxpayers->find($identification)]);
    }
}
