<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Cash\Actions\CloseCashSessionAction;
use App\Domains\Cash\Actions\OpenCashSessionAction;
use App\Domains\Cash\Actions\RegisterCashMovementAction;
use App\Domains\Cash\Models\CashMovement;
use App\Domains\Cash\Models\CashSession;
use App\Domains\Cash\Services\CashDenominations;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Sales\Models\Sale;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Cajas: the sessions of each cash register (opening, money in and out, closing) and their report.
 * Selling permissions apply: owner, admin and biller operate; every active member can look.
 */
final class CashSessionController extends Controller
{
    private const RELATIONS = ['emissionPoint.establishment', 'warehouse', 'opener', 'closer'];

    public function index(Request $request, CompanyContext $context): JsonResponse
    {
        $this->authorize('viewAny', Sale::class);
        $filters = $request->validate([
            'status' => ['sometimes', 'nullable', Rule::in([CashSession::OPEN, CashSession::CLOSED])],
            'emissionPoint' => ['sometimes', 'nullable', 'uuid'],
        ]);
        $query = CashSession::query()->where('company_id', $context->company()->id)->with(self::RELATIONS)->latest('opened_at')->latest('id');
        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }
        if ($point = $filters['emissionPoint'] ?? null) {
            $query->whereHas('emissionPoint', fn ($builder) => $builder->where('public_id', $point));
        }

        return response()->json(['data' => $query->limit(100)->get()->map($this->present(...))]);
    }

    /** The report of one session: its data, the balance and every movement in order. */
    public function show(CashSession $session, CompanyContext $context): JsonResponse
    {
        $this->authorize('viewAny', Sale::class);
        $this->ensureOwn($session, $context);
        $session->load([...self::RELATIONS, 'movements.creator']);

        return response()->json(['data' => [
            ...$this->present($session),
            'balance' => $session->balance(),
            'movements' => $session->movements->map(fn (CashMovement $movement): array => [
                'publicId' => $movement->public_id,
                'type' => $movement->type,
                'amount' => (string) $movement->amount,
                'concept' => $movement->concept,
                'createdBy' => $movement->creator->name,
                'createdAt' => $movement->created_at->toIso8601String(),
            ]),
        ]]);
    }

    public function store(Request $request, CompanyContext $context, OpenCashSessionAction $open): JsonResponse
    {
        $this->authorize('create', Sale::class);
        $data = $request->validate([
            'emissionPointPublicId' => ['required', 'uuid'],
            'warehousePublicId' => ['required', 'uuid'],
            ...$this->countRules(),
        ]);
        $session = $open->execute($request->user(), $context->company(), $data);

        return response()->json(['data' => $this->present($session->load(self::RELATIONS))], 201);
    }

    public function storeMovement(Request $request, CashSession $session, CompanyContext $context, RegisterCashMovementAction $register): JsonResponse
    {
        $this->authorize('create', Sale::class);
        $this->ensureOwn($session, $context);
        $data = $request->validate([
            'type' => ['required', Rule::in([CashMovement::IN, CashMovement::OUT])],
            'amount' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'concept' => ['required', 'string', 'max:120'],
        ]);
        $movement = $register->execute($request->user(), $context->company(), $session, $data);

        return response()->json(['data' => [
            'publicId' => $movement->public_id,
            'type' => $movement->type,
            'amount' => (string) $movement->amount,
            'concept' => $movement->concept,
            'createdBy' => $request->user()->name,
            'createdAt' => $movement->created_at->toIso8601String(),
        ]], 201);
    }

    public function close(Request $request, CashSession $session, CompanyContext $context, CloseCashSessionAction $close): JsonResponse
    {
        $this->authorize('create', Sale::class);
        $this->ensureOwn($session, $context);
        $data = $request->validate($this->countRules());
        $session = $close->execute($request->user(), $context->company(), $session, $data);

        return response()->json(['data' => [...$this->present($session->load(self::RELATIONS)), 'balance' => $session->balance()]]);
    }

    /** The denominations the counter offers, so the web and the API never disagree. */
    public function denominations(): JsonResponse
    {
        $this->authorize('viewAny', Sale::class);

        return response()->json(['data' => ['bills' => CashDenominations::BILLS, 'coins' => CashDenominations::COINS]]);
    }

    /** Opening and closing count the money the same way: a fixed amount or bills and coins. */
    private function countRules(): array
    {
        return [
            'mode' => ['required', Rule::in(['MANUAL', 'COUNTED'])],
            'amount' => ['required_if:mode,MANUAL', 'nullable', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'denominations' => ['required_if:mode,COUNTED', 'nullable', 'array:bills,coins'],
            'denominations.bills' => ['sometimes', 'array'],
            'denominations.bills.*' => ['integer', 'min:0', 'max:100000'],
            'denominations.coins' => ['sometimes', 'array'],
            'denominations.coins.*' => ['integer', 'min:0', 'max:100000'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    private function ensureOwn(CashSession $session, CompanyContext $context): void
    {
        if ($session->company_id !== $context->company()->id) {
            throw new ApiException(ApiErrorCode::CASH_SESSION_NOT_FOUND, 'La sesión de caja no existe en la empresa activa.', 404);
        }
    }

    private function present(CashSession $session): array
    {
        $point = $session->emissionPoint;

        return [
            'publicId' => $session->public_id,
            'status' => $session->status,
            'emissionPointPublicId' => $point->public_id,
            'emissionPointCode' => $point->establishment->code.'-'.$point->code,
            'emissionPointName' => $point->name,
            'establishmentName' => $point->establishment->name,
            'warehousePublicId' => $session->warehouse->public_id,
            'warehouseName' => $session->warehouse->name,
            'openedBy' => $session->opener->name,
            'openedAt' => $session->opened_at->toIso8601String(),
            'mode' => $session->opening_mode,
            'openingAmount' => (string) $session->opening_amount,
            'denominations' => $session->denominations,
            'notes' => $session->notes,
            'closedBy' => $session->closer?->name,
            'closedAt' => $session->closed_at?->toIso8601String(),
            'closingMode' => $session->closing_mode,
            'closingAmount' => $session->closing_amount !== null ? (string) $session->closing_amount : null,
            'closingDenominations' => $session->closing_denominations,
            'expectedAmount' => $session->expected_amount !== null ? (string) $session->expected_amount : null,
            'difference' => $session->difference !== null ? (string) $session->difference : null,
            'closingNotes' => $session->closing_notes,
        ];
    }
}
