<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Customers\Actions\ActivateCustomerAction;
use App\Domains\Customers\Actions\CreateCustomerAction;
use App\Domains\Customers\Actions\DeactivateCustomerAction;
use App\Domains\Customers\Actions\DeleteCustomerAction;
use App\Domains\Customers\Actions\EnsureFinalConsumerAction;
use App\Domains\Customers\Actions\MergeCustomersAction;
use App\Domains\Customers\Actions\UpdateCustomerAction;
use App\Domains\Customers\Models\Customer;
use App\Domains\Delivery\Enums\DeliveryStatus;
use App\Domains\Delivery\Models\DocumentDelivery;
use App\Domains\FiscalDocuments\Enums\DocumentStatus;
use App\Domains\FiscalDocuments\Enums\DocumentType;
use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\StoreCustomerRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Http\Resources\Api\V1\CustomerResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

final class CustomerController extends Controller
{
    public function index(Request $request, CompanyContext $context): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Customer::class);
        $query = $context->company()->customers()->orderBy('legal_name');
        if ($search = trim((string) $request->query('search'))) {
            $pattern = '%'.strtolower($search).'%';
            $query->where(fn ($builder) => $builder->whereRaw('LOWER(legal_name) LIKE ?', [$pattern])
                ->orWhereRaw('LOWER(commercial_name) LIKE ?', [$pattern])
                ->orWhereRaw('LOWER(identification) LIKE ?', [$pattern]));
        }
        if ($type = $request->query('identification_type')) {
            $query->where('identification_type', $type);
        }
        if ($request->query('status') === 'ACTIVE') {
            $query->where('is_active', true);
        }
        if ($request->query('status') === 'INACTIVE') {
            $query->where('is_active', false);
        }

        return CustomerResource::collection($query->paginate(min(max((int) $request->query('per_page', 15), 1), 100)));
    }

    public function store(StoreCustomerRequest $request, CreateCustomerAction $create): CustomerResource
    {
        $this->authorize('create', Customer::class);

        return new CustomerResource($create->execute($this->attributes($request->validated()), $request->user()));
    }

    public function show(Customer $customer): CustomerResource
    {
        $this->authorize('view', $customer);

        return new CustomerResource($customer);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer, UpdateCustomerAction $update): CustomerResource
    {
        $this->authorize('update', $customer);

        return new CustomerResource($update->execute($customer, $this->attributes($request->validated()), $request->user()));
    }

    public function activate(Request $request, Customer $customer, ActivateCustomerAction $activate): CustomerResource
    {
        $this->authorize('update', $customer);

        return new CustomerResource($activate->execute($customer, $request->user()));
    }

    public function deactivate(Request $request, Customer $customer, DeactivateCustomerAction $deactivate): CustomerResource
    {
        $this->authorize('update', $customer);

        return new CustomerResource($deactivate->execute($customer, $request->user()));
    }

    /** DELETE /customers/{customer} — only for a customer without sales or fiscal documents. */
    public function destroy(Request $request, Customer $customer, DeleteCustomerAction $delete): Response
    {
        $this->authorize('delete', $customer);
        $delete->execute($customer, $request->user());

        return response()->noContent();
    }

    /**
     * GET /customers/{customer}/fiscal-summary — what was invoiced and credited to the customer this year,
     * its latest documents and the deliveries that did not reach it (to fix the email).
     */
    public function fiscalSummary(Customer $customer): JsonResponse
    {
        $this->authorize('view', $customer);
        $documents = ElectronicDocument::query()->where('company_id', $customer->company_id)->where('customer_id', $customer->id);
        $year = (clone $documents)->where('status', DocumentStatus::AUTHORIZED->value)->whereYear('issue_date', now()->year);
        $sum = fn (DocumentType $type): string => (string) ((clone $year)->where('document_type', $type->value)->sum('total') ?: '0');

        return response()->json(['data' => [
            'year' => now()->year,
            'invoicedTotal' => bcadd($sum(DocumentType::INVOICE), '0', 2),
            'creditedTotal' => bcadd($sum(DocumentType::CREDIT_NOTE), '0', 2),
            'invoiceCount' => (clone $year)->where('document_type', DocumentType::INVOICE->value)->count(),
            'failedDeliveries' => DocumentDelivery::query()->whereIn('electronic_document_id', (clone $documents)->select('id'))
                ->whereIn('status', [DeliveryStatus::FAILED->value, DeliveryStatus::SKIPPED_NO_RECIPIENT->value])->count(),
            'documents' => (clone $documents)->with('sale')->latest('id')->limit(10)->get()->map(fn (ElectronicDocument $document): array => [
                'publicId' => $document->public_id, 'salePublicId' => $document->sale?->public_id, 'documentType' => $document->document_type->value,
                'fullNumber' => $document->full_number, 'issueDate' => $document->issue_date->toDateString(), 'status' => $document->status->value, 'total' => $document->total,
            ]),
        ]]);
    }

    public function finalConsumer(Request $request, EnsureFinalConsumerAction $ensure): CustomerResource
    {
        $this->authorize('create', Customer::class);

        return new CustomerResource($ensure->execute($request->user()));
    }

    /** Joins a duplicate (sourcePublicId) into this customer. */
    public function merge(Request $request, Customer $customer, MergeCustomersAction $merge): CustomerResource
    {
        $this->authorize('update', $customer);
        $data = $request->validate(['sourcePublicId' => ['required', 'uuid']]);

        return new CustomerResource($merge->execute($customer, $data['sourcePublicId'], $request->user()));
    }

    private function attributes(array $data): array
    {
        foreach (['identificationType' => 'identification_type', 'legalName' => 'legal_name', 'commercialName' => 'commercial_name', 'additionalEmails' => 'additional_emails'] as $source => $target) {
            if (array_key_exists($source, $data)) {
                $data[$target] = $data[$source];
            }
            unset($data[$source]);
        }

        return $data;
    }
}
