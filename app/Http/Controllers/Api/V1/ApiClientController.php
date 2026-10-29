<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Integrations\Models\ApiClient;
use App\Http\Controllers\Controller;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Configuración → Integraciones: the tokens of the modules that invoice through FACSTO. */
final class ApiClientController extends Controller
{
    public function index(CompanyContext $context): JsonResponse
    {
        $company = $context->company();
        $this->authorize('update', $company);

        return response()->json(['data' => ApiClient::query()->where('company_id', $company->id)->latest('id')->get()->map(fn (ApiClient $client): array => $this->present($client))]);
    }

    /** The token and the webhook secret are shown only in this response. */
    public function store(Request $request, CompanyContext $context, AuditRecorder $audit): JsonResponse
    {
        $company = $context->company();
        $this->authorize('update', $company);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'module' => ['required', 'string', 'max:40', 'regex:/^[a-z0-9_-]+$/'],
            'webhookUrl' => ['nullable', 'url:https', 'max:500'],
        ]);
        [$token, $hash] = ApiClient::newToken();
        $secret = 'whsec_'.Str::random(40);
        $client = ApiClient::query()->create([
            'company_id' => $company->id, 'name' => $data['name'], 'module' => $data['module'],
            'token_hash' => $hash, 'token_prefix' => substr($token, 0, 12), 'webhook_url' => $data['webhookUrl'] ?? null,
            'webhook_secret' => $secret, 'is_active' => true, 'created_by' => $request->user()->id,
        ]);
        $audit->record($request->user(), $company, 'api_client.created', $client, null, ['name' => $client->name, 'module' => $client->module]);

        return response()->json(['data' => [...$this->present($client), 'token' => $token, 'webhookSecret' => $secret]], 201);
    }

    public function update(Request $request, string $apiClient, CompanyContext $context, AuditRecorder $audit): JsonResponse
    {
        $client = $this->find($apiClient, $context);
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'webhookUrl' => ['sometimes', 'nullable', 'url:https', 'max:500']]);
        if (array_key_exists('name', $data)) {
            $client->name = $data['name'];
        }
        if (array_key_exists('webhookUrl', $data)) {
            $client->webhook_url = $data['webhookUrl'];
        }
        $client->save();
        $audit->record($request->user(), $context->company(), 'api_client.updated', $client, null, ['name' => $client->name, 'webhookUrl' => $client->webhook_url]);

        return response()->json(['data' => $this->present($client)]);
    }

    /** Revoking is immediate and permanent: the module needs a new token. */
    public function destroy(Request $request, string $apiClient, CompanyContext $context, AuditRecorder $audit): JsonResponse
    {
        $client = $this->find($apiClient, $context);
        $client->forceFill(['is_active' => false])->save();
        $audit->record($request->user(), $context->company(), 'api_client.revoked', $client, null, ['name' => $client->name]);

        return response()->json(['data' => $this->present($client)]);
    }

    private function find(string $publicId, CompanyContext $context): ApiClient
    {
        $company = $context->company();
        $this->authorize('update', $company);

        return ApiClient::query()->where('company_id', $company->id)->where('public_id', $publicId)->firstOrFail();
    }

    private function present(ApiClient $client): array
    {
        return [
            'publicId' => $client->public_id, 'name' => $client->name, 'module' => $client->module,
            'tokenPrefix' => $client->token_prefix, 'webhookUrl' => $client->webhook_url, 'isActive' => $client->is_active,
            'lastUsedAt' => $client->last_used_at?->toIso8601String(), 'createdAt' => $client->created_at?->toIso8601String(),
        ];
    }
}
