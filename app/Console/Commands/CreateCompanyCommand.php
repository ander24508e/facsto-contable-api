<?php

namespace App\Console\Commands;

use App\Domains\Companies\Actions\ProvisionCompanyAction;
use App\Domains\Companies\DTOs\ProvisionAdministratorData;
use App\Domains\Companies\DTOs\ProvisionCompanyData;
use App\Shared\Http\ApiException;
use Illuminate\Console\Command;

/**
 * Temporary provisioning entry point until Condore Platform sends signed
 * activation events (F10). Only Condore staff run it on the server.
 */
final class CreateCompanyCommand extends Command
{
    protected $signature = 'facsto:empresa:crear
        {slug : Identificador y futuro subdominio, p. ej. lavadora-endara}
        {--ruc= : RUC de 13 dígitos}
        {--direccion= : Dirección matriz}
        {--razon-social= : Razón social (opcional, se completa en la bienvenida)}
        {--correo= : Correo de la empresa}
        {--telefono= : Teléfono de la empresa}
        {--operador-nombre= : Nombre del administrador operador de Condore}
        {--operador-correo= : Correo del administrador operador de Condore}
        {--cliente-nombre= : Nombre del administrador del cliente}
        {--cliente-correo= : Correo del administrador del cliente}';

    protected $description = 'Activa una empresa suscrita con su estructura de emisión y sus dos administradores.';

    public function handle(ProvisionCompanyAction $provision): int
    {
        $clientEmail = $this->option('cliente-correo') ?: $this->ask('Correo del administrador del cliente (vacío para omitir)');

        try {
            $result = $provision->execute(new ProvisionCompanyData(
                slug: $this->argument('slug'),
                ruc: $this->option('ruc') ?: (string) $this->ask('RUC'),
                mainAddress: $this->option('direccion') ?: (string) $this->ask('Dirección matriz'),
                legalName: $this->option('razon-social') ?: null,
                email: $this->option('correo') ?: null,
                phone: $this->option('telefono') ?: null,
                operator: new ProvisionAdministratorData(
                    $this->option('operador-nombre') ?: (string) $this->ask('Nombre del operador de Condore'),
                    $this->option('operador-correo') ?: (string) $this->ask('Correo del operador de Condore'),
                ),
                clientAdmin: $clientEmail ? new ProvisionAdministratorData(
                    $this->option('cliente-nombre') ?: (string) $this->ask('Nombre del administrador del cliente'),
                    $clientEmail,
                ) : null,
            ));
        } catch (ApiException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Empresa {$result['company']->slug} activada.");
        $this->warn('Entrega estas contraseñas por un canal seguro; no se vuelven a mostrar.');
        $this->table(['Correo', 'Contraseña temporal'], collect($result['credentials'])
            ->map(fn (string $password, string $email): array => [$email, $password])
            ->values()
            ->all());

        return self::SUCCESS;
    }
}
