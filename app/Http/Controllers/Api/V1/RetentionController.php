<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\FiscalDocuments\Actions\ContinueInvoiceIssuanceAction;
use App\Domains\FiscalDocuments\Enums\DocumentType;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Purchases\Models\Purchase;
use App\Domains\Withholdings\Actions\CreateRetentionAction;
use App\Domains\Withholdings\Models\SriWithholdingCode;
use App\Domains\Withholdings\Services\WithholdingAgentStatus;
use App\Http\Controllers\Controller;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Retenciones (codDoc 07): the withholding codes, whether the company withholds, and the retentions issued
 * on the purchases of Inversiones. XML, RIDE and retries use the routes of /invoices/{document}.
 */
final class RetentionController extends Controller
{
    public function codes(Request $request): JsonResponse
    {
        $this->authorize('retentions.view');
        $tax = $request->validate(['tax' => ['sometimes', Rule::in([SriWithholdingCode::RENTA, SriWithholdingCode::IVA])]])['tax'] ?? null;
        $codes = SriWithholdingCode::query()->where('is_active', true)->when($tax, fn ($query) => $query->where('tax', $tax))->orderBy('tax')->orderBy('code')->get();

        return response()->json(['data' => $codes->map(fn (SriWithholdingCode $code): array => [
            'publicId' => $code->public_id, 'tax' => $code->tax, 'code' => $code->code, 'name' => $code->name,
            'percentage' => $code->percentage, 'percentageEditable' => $code->percentage_editable,
        ])->values()]);
    }

    /** Whether the company withholds, read from its own fiscal profile. */
    public function eligibility(CompanyContext $context, WithholdingAgentStatus $agent): JsonResponse
    {
        $this->authorize('retentions.view');

        return response()->json(['data' => $agent->of($context->company())]);
    }

    public function index(Request $request, CompanyContext $context): JsonResponse
    {
        $this->authorize('retentions.view');
        $query = $this->retentions($context)->with(['withholdings', 'purchase'])->orderByDesc('issue_date')->orderByDesc('id');
        if ($search = trim((string) $request->query('search'))) {
            $query->where(fn ($builder) => $builder->where('full_number', 'like', '%'.$search.'%')
                ->orWhere('customer_identification', 'like', $search.'%')
                ->orWhereRaw('LOWER(customer_name) LIKE ?', ['%'.mb_strtolower($search).'%']));
        }
        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }
        $page = $query->paginate(min(max((int) $request->query('per_page', 15), 1), 100));

        return response()->json([
            'data' => collect($page->items())->map(fn (ElectronicDocument $retention): array => self::present($retention)),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()],
        ]);
    }

    public function show(CompanyContext $context, string $retention): JsonResponse
    {
        $this->authorize('retentions.view');
        $model = $this->retentions($context)->where('public_id', $retention)->with(['withholdings', 'taxes', 'purchase'])->first()
            ?? throw new ApiException(ApiErrorCode::RETENTION_NOT_FOUND, 'La retención no existe en la empresa actual.', 404);

        return response()->json(['data' => self::present($model, true)]);
    }

    /** Numbers the retention of a purchase and continues it to XML → signature → SRI, like an invoice. */
    public function store(Request $request, CompanyContext $context, Purchase $purchase, CreateRetentionAction $create, ContinueInvoiceIssuanceAction $continue): JsonResponse
    {
        $this->authorize('retentions.create');
        if ($purchase->company_id !== $context->company()->id) {
            throw new ApiException(ApiErrorCode::PURCHASE_NOT_FOUND, 'La compra no existe en la empresa actual.', 404);
        }
        $data = $request->validate([
            'emissionPointPublicId' => ['required', 'uuid'],
            'supportCode' => ['required', Rule::in(CreateRetentionAction::SUPPORT_CODES)],
            'relatedParty' => ['sometimes', 'boolean'],
            'withholdings' => ['required', 'array', 'min:1', 'max:20'],
            'withholdings.*.codePublicId' => ['required', 'uuid'],
            'withholdings.*.taxableBase' => ['required', 'string', 'regex:/^\d{1,12}(\.\d{1,2})?$/'],
            'withholdings.*.percentage' => ['sometimes', 'nullable', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
        ]);

        $retention = $create->execute($purchase, $data, $request->user());
        try {
            $retention = $continue->execute($retention, $request->user());
        } catch (ApiException) {
            // Numbered and kept: its page shows why it stopped and offers the retry.
            $retention = $retention->refresh();
        }

        return response()->json(['data' => self::present($retention->load(['withholdings', 'taxes', 'purchase']), true)], 201);
    }

    /** @return array<string, mixed> */
    public static function present(ElectronicDocument $retention, bool $full = false): array
    {
        $withheld = $retention->withholdings->reduce(fn (string $sum, $row): string => bcadd($sum, (string) $row->amount, 2), '0.00');

        return [
            'publicId' => $retention->public_id,
            'fullNumber' => $retention->full_number,
            'status' => $retention->status->value,
            'issueDate' => $retention->issue_date->toDateString(),
            'fiscalPeriod' => $retention->fiscal_period,
            'supplier' => ['identification' => $retention->customer_identification, 'legalName' => $retention->customer_name, 'email' => $retention->customer_email],
            'purchase' => ['publicId' => $retention->purchase?->public_id, 'number' => $retention->purchase?->displayNumber()],
            'supportDocumentNumber' => $retention->support_document_number,
            'supportTotal' => $retention->total,
            'withheldTotal' => $withheld,
            'lastErrorCode' => $retention->last_error_code,
            ...($full ? [
                'accessKey' => $retention->access_key,
                'authorizationNumber' => $retention->authorization_number,
                'authorizedAt' => $retention->authorized_at?->toIso8601String(),
                'supportCode' => $retention->support_code,
                'supportDocumentDate' => $retention->support_document_date?->toDateString(),
                'supportAuthorizationNumber' => $retention->support_authorization_number,
                'relatedParty' => $retention->related_party,
                'subtotal' => $retention->total_without_taxes,
                'taxTotal' => $retention->tax_total,
                'withholdings' => $retention->withholdings->map(fn ($row): array => [
                    'tax' => $row->tax_code === '2' ? SriWithholdingCode::IVA : SriWithholdingCode::RENTA, 'code' => $row->withholding_code, 'name' => $row->name,
                    'taxableBase' => $row->taxable_base, 'percentage' => $row->percentage, 'amount' => $row->amount,
                ])->values(),
            ] : []),
        ];
    }

    private function retentions(CompanyContext $context)
    {
        return ElectronicDocument::query()->where('company_id', $context->company()->id)->where('document_type', DocumentType::RETENTION->value);
    }
}
