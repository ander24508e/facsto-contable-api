<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Sales\Actions\CancelSaleDraftAction;
use App\Domains\Sales\Actions\CreateSaleDraftAction;
use App\Domains\Sales\Actions\UpdateSaleDraftAction;
use App\Domains\Sales\DTOs\SaleDraftData;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\SaleInvoiceReadinessService;
use App\Domains\Sales\Services\SaleStageFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\CreateSaleDraftRequest;
use App\Http\Requests\Sales\SaleIndexRequest;
use App\Http\Requests\Sales\UpdateSaleDraftRequest;
use App\Http\Resources\Api\V1\SaleResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class SaleController extends Controller
{
    public function index(SaleIndexRequest $request, CompanyContext $context): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Sale::class);
        $filters = $request->validated();
        $query = $context->company()->sales()->with(['customer', 'creator', 'electronicDocument'])->latest();

        if ($search = trim((string) ($filters['search'] ?? ''))) {
            $pattern = '%'.mb_strtolower($search).'%';
            $query->where(fn ($builder) => $builder->whereRaw('LOWER(customer_name) LIKE ?', [$pattern])
                ->orWhereRaw('LOWER(customer_identification) LIKE ?', [$pattern]));
        }
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($stage = $filters['stage'] ?? null) {
            SaleStageFilter::apply($query, $stage);
        }
        if ($from = $filters['from'] ?? null) {
            $query->whereDate('created_at', '>=', $from);
        }
        if ($to = $filters['to'] ?? null) {
            $query->whereDate('created_at', '<=', $to);
        }

        return SaleResource::collection($query->paginate($filters['per_page'] ?? 15));
    }

    public function store(CreateSaleDraftRequest $request, CreateSaleDraftAction $create): JsonResponse
    {
        $this->authorize('create', Sale::class);

        return (new SaleResource($create->execute(SaleDraftData::fromValidated($request->validated()), $request->user())))
            ->response()->setStatusCode(201);
    }

    public function show(Sale $sale, CompanyContext $context, SaleInvoiceReadinessService $readiness): SaleResource
    {
        $this->authorize('view', $sale);

        $sale = $context->company()->sales()->with(['customer', 'creator', 'details.item', 'details.taxes', 'paymentMethods.fiscalPaymentMethod', 'electronicDocument'])
            ->whereKey($sale->id)->firstOrFail();

        return (new SaleResource($sale))->additional(['meta' => ['invoiceReadiness' => $readiness->summary($sale)]]);
    }

    public function update(UpdateSaleDraftRequest $request, Sale $sale, UpdateSaleDraftAction $update): SaleResource
    {
        $this->authorize('update', $sale);

        return new SaleResource($update->execute($sale, SaleDraftData::fromValidated($request->validated()), $request->user()));
    }

    public function cancel(Request $request, Sale $sale, CancelSaleDraftAction $cancel): SaleResource
    {
        $this->authorize('update', $sale);

        return new SaleResource($cancel->execute($sale, $request->user()));
    }
}
