<?php

namespace App\Http\Controllers\Api\V1\Carwash;

use App\Domains\Carwash\Actions\SaveNamedEntryAction;
use App\Domains\Carwash\Models\Washer;
use App\Http\Resources\Api\V1\Carwash\NamedEntryResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Washing staff; they do not log in, they are assigned to orders. */
final class WasherController extends CarwashController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return NamedEntryResource::collection(Washer::query()
            ->where('company_id', $this->companyId())
            ->when(! $request->boolean('includeInactive'), fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get());
    }

    public function store(Request $request, SaveNamedEntryAction $save): JsonResponse
    {
        return (new NamedEntryResource($save->execute(new Washer, $this->validated($request, true), $request->user())))->response()->setStatusCode(201);
    }

    public function update(Request $request, string $washer, SaveNamedEntryAction $save): NamedEntryResource
    {
        return new NamedEntryResource($save->execute($this->owned(Washer::class, $washer), $this->validated($request, false), $request->user()));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'name' => [$creating ? 'required' : 'sometimes', 'string', 'max:120'],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9+ ]{7,20}$/'],
            'isActive' => ['sometimes', 'boolean'],
        ]);
        $attributes = [];
        foreach (['name' => 'name', 'phone' => 'phone', 'isActive' => 'is_active'] as $input => $column) {
            if (array_key_exists($input, $data)) {
                $attributes[$column] = $data[$input];
            }
        }

        return $attributes;
    }
}
