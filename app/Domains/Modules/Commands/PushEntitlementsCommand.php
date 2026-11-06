<?php

namespace App\Domains\Modules\Commands;

use App\Domains\Companies\Models\Company;
use App\Domains\Modules\Models\CompanyEntitlementState;
use App\Shared\Integration\CondoreSignature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * «Platform falso»: sends exactly the signed message Condore Platform will send, through HTTP, so the
 * whole contract is exercised before Platform exists. Features listed are ENABLED; the rest go off.
 *   php artisan platform:push-entitlements demo carwash
 *   php artisan platform:push-entitlements demo carwash --read-only=carwash
 */
final class PushEntitlementsCommand extends Command
{
    protected $signature = 'platform:push-entitlements {company : slug o RUC} {features?* : funciones habilitadas} {--read-only=* : funciones en solo lectura} {--entitlement-version= : versión (por defecto la siguiente)}';

    protected $description = 'Envía a esta instancia un mensaje de derechos firmado como Condore Platform';

    public function handle(): int
    {
        $key = (string) $this->argument('company');
        $company = Company::query()->where('slug', $key)->orWhere('ruc', $key)->first();
        if (! $company) {
            $this->error('No existe una empresa con ese slug o RUC.');

            return self::FAILURE;
        }
        $secret = (string) config('modules.platform.secret');
        if ($secret === '') {
            $this->error('Falta CONDORE_PLATFORM_SECRET.');

            return self::FAILURE;
        }

        $version = (int) ($this->option('entitlement-version') ?: ((int) CompanyEntitlementState::query()->where('company_id', $company->id)->value('version') + 1));
        $entitlements = [
            ...array_map(fn (string $feature) => ['feature' => $feature, 'status' => 'ENABLED'], (array) $this->argument('features')),
            ...array_map(fn (string $feature) => ['feature' => $feature, 'status' => 'READ_ONLY'], (array) $this->option('read-only')),
        ];
        $body = (string) json_encode(['version' => $version, 'issuedAt' => now()->toIso8601String(), 'entitlements' => $entitlements], JSON_UNESCAPED_SLASHES);
        $path = "/internal/v1/platform/companies/{$company->public_id}/entitlements";
        $timestamp = time();

        $response = Http::withHeaders([
            CondoreSignature::HEADER_CLIENT => config('modules.platform.client_id'),
            CondoreSignature::HEADER_TIMESTAMP => (string) $timestamp,
            CondoreSignature::HEADER_SIGNATURE => CondoreSignature::sign($secret, $timestamp, 'PUT', $path, null, $body),
            'Accept' => 'application/json',
        ])->withBody($body, 'application/json')->put(config('modules.platform.self_url').$path);

        $this->line('HTTP '.$response->status());
        $this->line((string) json_encode($response->json(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return $response->successful() ? self::SUCCESS : self::FAILURE;
    }
}
