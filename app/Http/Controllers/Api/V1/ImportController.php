<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Catalog\Models\Item;
use App\Domains\Customers\Models\Customer;
use App\Domains\Imports\Services\CatalogImportService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** POST /imports/preview (CSV) → POST /imports/{import}/commit: customers or items from another system. */
final class ImportController extends Controller
{
    public function preview(Request $request, CatalogImportService $imports): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(CatalogImportService::TYPES)],
            'file' => ['required', 'file', 'extensions:csv,txt', 'max:2048'],
        ]);
        $this->authorize('create', $data['type'] === 'customers' ? Customer::class : Item::class);

        return response()->json(['data' => $imports->preview($data['type'], $request->file('file'))]);
    }

    public function commit(Request $request, string $import, CatalogImportService $imports): JsonResponse
    {
        $data = $request->validate(['type' => ['required', Rule::in(CatalogImportService::TYPES)]]);
        $this->authorize('create', $data['type'] === 'customers' ? Customer::class : Item::class);

        return response()->json(['data' => $imports->commit($import, $request->user())]);
    }
}
