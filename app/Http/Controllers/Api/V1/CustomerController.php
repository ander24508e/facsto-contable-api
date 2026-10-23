<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Customers\Actions\ActivateCustomerAction;
use App\Domains\Customers\Actions\CreateCustomerAction;
use App\Domains\Customers\Actions\DeactivateCustomerAction;
use App\Domains\Customers\Actions\UpdateCustomerAction;
use App\Domains\Customers\Models\Customer;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customers\StoreCustomerRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Http\Resources\Api\V1\CustomerResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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

    private function attributes(array $data): array
    {
        foreach (['identificationType' => 'identification_type', 'legalName' => 'legal_name', 'commercialName' => 'commercial_name'] as $source => $target) {
            if (array_key_exists($source, $data)) {
                $data[$target] = $data[$source];
            }
            unset($data[$source]);
        }

        return $data;
    }
}
