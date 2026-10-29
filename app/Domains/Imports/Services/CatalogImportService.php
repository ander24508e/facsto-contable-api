<?php

namespace App\Domains\Imports\Services;

use App\Domains\Catalog\Actions\CreateItemAction;
use App\Domains\Catalog\Actions\SyncItemTaxesAction;
use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Domains\Customers\Actions\CreateCustomerAction;
use App\Domains\Customers\Enums\IdentificationType;
use App\Domains\Customers\Services\CustomerIdentificationService;
use App\Domains\Taxes\Enums\TaxType;
use App\Domains\Taxes\Models\CompanyTax;
use App\Models\User;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Customers or items from another system in two steps: a preview validates every row with the same rules
 * as the screens (identification check digits, unique codes, an IVA rate the company has) and is kept for
 * 30 minutes; the commit creates only the valid rows, through the same actions as the screens.
 */
final class CatalogImportService
{
    public const TYPES = ['customers', 'items'];

    private const MAX_ROWS = 1000;

    private const IDENTIFICATION_TYPES = [
        'RUC' => IdentificationType::RUC, 'CEDULA' => IdentificationType::CEDULA, 'PASAPORTE' => IdentificationType::PASSPORT,
        'PASSPORT' => IdentificationType::PASSPORT, 'EXTERIOR' => IdentificationType::FOREIGN_IDENTIFICATION,
    ];

    public function __construct(
        private readonly CompanyContext $context,
        private readonly CustomerIdentificationService $identifications,
        private readonly CreateCustomerAction $createCustomer,
        private readonly CreateItemAction $createItem,
        private readonly SyncItemTaxesAction $syncTaxes,
    ) {}

    /** @return array{id: string, type: string, rows: list<array{row: int, data: array<string, mixed>, errors: list<string>}>, valid: int, invalid: int} */
    public function preview(string $type, UploadedFile $file): array
    {
        $company = $this->context->company();
        $rows = $this->validate($company, $type, $this->read($file));
        $id = (string) Str::uuid7();
        Cache::put($this->key($company, $id), ['type' => $type, 'rows' => $rows], now()->addMinutes(30));

        return $this->summary($id, $type, $rows);
    }

    /** @return array{created: int, skipped: int, errors: list<array{row: int, errors: list<string>}>} */
    public function commit(string $id, User $actor): array
    {
        $company = $this->context->company();
        $stored = Cache::pull($this->key($company, $id));
        if (! is_array($stored)) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'La vista previa venció. Vuelve a subir el archivo.', 410);
        }
        // Validated again: the company may have changed in the meantime.
        $rows = $this->validate($company, $stored['type'], array_map(fn (array $row): array => $row['data'], $stored['rows']));
        $created = 0;
        $errors = [];
        foreach ($rows as $row) {
            if ($row['errors'] !== []) {
                $errors[] = ['row' => $row['row'], 'errors' => $row['errors']];

                continue;
            }
            try {
                DB::transaction(fn () => $stored['type'] === 'customers' ? $this->createCustomer($row['data'], $actor) : $this->createItem($company, $row['data'], $actor));
                $created++;
            } catch (ApiException $exception) {
                $errors[] = ['row' => $row['row'], 'errors' => [$exception->getMessage()]];
            }
        }

        return ['created' => $created, 'skipped' => count($rows) - $created, 'errors' => $errors];
    }

    /** @return list<array<string, string>> rows keyed by the normalized header */
    private function read(UploadedFile $file): array
    {
        $contents = (string) file_get_contents($file->getRealPath());
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents);
        if (! mb_check_encoding($contents, 'UTF-8')) {
            $contents = mb_convert_encoding($contents, 'UTF-8', 'Windows-1252');
        }
        $lines = preg_split('/\r\n|\n|\r/', trim($contents)) ?: [];
        if (count($lines) < 2) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'El archivo necesita una fila de encabezados y al menos una fila de datos.', 422);
        }
        if (count($lines) - 1 > self::MAX_ROWS) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'El archivo supera las '.self::MAX_ROWS.' filas.', 422);
        }
        $delimiter = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : ',';
        $headers = array_map(fn (string $header): string => Str::of($header)->ascii()->lower()->trim()->replace([' ', '-'], '_')->toString(), str_getcsv($lines[0], $delimiter, '"', ''));

        $rows = [];
        foreach (array_slice($lines, 1) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line, $delimiter, '"', '');
            $rows[] = array_combine($headers, array_map(fn (?string $value): string => trim((string) $value), array_pad(array_slice($values, 0, count($headers)), count($headers), '')));
        }

        return $rows;
    }

    private function validate(Company $company, string $type, array $rows): array
    {
        return $type === 'customers' ? $this->validateCustomers($company, $rows) : $this->validateItems($company, $rows);
    }

    private function validateCustomers(Company $company, array $rows): array
    {
        $existing = $company->customers()->pluck('identification')->flip();
        $seen = [];
        $result = [];
        foreach ($rows as $index => $raw) {
            $errors = [];
            $identification = strtoupper((string) ($raw['identificacion'] ?? $raw['identification'] ?? ''));
            $typeName = strtoupper(Str::ascii((string) ($raw['tipo_identificacion'] ?? $raw['tipo'] ?? '')));
            $type = self::IDENTIFICATION_TYPES[$typeName] ?? ($typeName === '' ? $this->identifications->detect($identification) : null);
            if ($type === null) {
                $errors[] = 'Tipo de identificación no reconocido (RUC, CEDULA, PASAPORTE o EXTERIOR).';
            } elseif (! $this->identifications->isValid($type, $identification)) {
                $errors[] = 'La identificación no es válida para su tipo.';
            }
            if (isset($existing[$identification]) || isset($seen[$identification])) {
                $errors[] = 'La identificación ya existe.';
            }
            $legalName = (string) ($raw['razon_social'] ?? $raw['nombre'] ?? '');
            if ($legalName === '' || mb_strlen($legalName) > 180) {
                $errors[] = 'La razón social es obligatoria (máximo 180 caracteres).';
            }
            $email = (string) ($raw['correo'] ?? $raw['email'] ?? '');
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[] = 'El correo no es válido.';
            }
            $seen[$identification] = true;
            $result[] = ['row' => $index + 2, 'errors' => $errors, 'data' => [
                'tipo_identificacion' => $type?->value ?? $typeName, 'identificacion' => $identification, 'razon_social' => $legalName,
                'nombre_comercial' => (string) ($raw['nombre_comercial'] ?? ''), 'correo' => $email,
                'telefono' => (string) ($raw['telefono'] ?? ''), 'direccion' => (string) ($raw['direccion'] ?? ''),
            ]];
        }

        return $result;
    }

    private function validateItems(Company $company, array $rows): array
    {
        $existing = $company->items()->pluck('internal_code')->map(fn ($code) => mb_strtolower($code))->flip();
        $ivaRates = $company->taxes()->with('definition')->get()
            ->filter(fn (CompanyTax $tax): bool => $tax->definition->tax_type === TaxType::IVA && $tax->isUsableOn(today()));
        $seen = [];
        $result = [];
        foreach ($rows as $index => $raw) {
            $errors = [];
            $code = (string) ($raw['codigo'] ?? '');
            if ($code === '' || mb_strlen($code) > 25) {
                $errors[] = 'El código es obligatorio (máximo 25 caracteres).';
            } elseif (isset($existing[mb_strtolower($code)]) || isset($seen[mb_strtolower($code)])) {
                $errors[] = 'El código ya existe.';
            }
            $name = (string) ($raw['nombre'] ?? '');
            if ($name === '' || mb_strlen($name) > 180) {
                $errors[] = 'El nombre es obligatorio (máximo 180 caracteres).';
            }
            $price = str_replace(',', '.', (string) ($raw['precio'] ?? ''));
            if (preg_match('/^\d{1,12}(\.\d{1,6})?$/', $price) !== 1) {
                $errors[] = 'El precio debe ser un número positivo.';
            }
            $kind = strtoupper(Str::ascii((string) ($raw['tipo'] ?? 'PRODUCTO')));
            if (! in_array($kind, ['PRODUCTO', 'SERVICIO', 'PRODUCT', 'SERVICE'], true)) {
                $errors[] = 'El tipo debe ser PRODUCTO o SERVICIO.';
            }
            $tax = $this->ivaRate($ivaRates, (string) ($raw['iva'] ?? ''));
            if (! $tax) {
                $errors[] = 'La tarifa de IVA no está activa en la empresa (0, 5, 15, NO_OBJETO o EXENTO).';
            }
            $seen[mb_strtolower($code)] = true;
            $result[] = ['row' => $index + 2, 'errors' => $errors, 'data' => [
                'tipo' => in_array($kind, ['SERVICIO', 'SERVICE'], true) ? 'SERVICIO' : 'PRODUCTO', 'codigo' => $code,
                'codigo_auxiliar' => (string) ($raw['codigo_auxiliar'] ?? ''), 'nombre' => $name,
                'unidad' => (string) (($raw['unidad'] ?? '') ?: 'UND'), 'precio' => $price,
                'precio_incluye_iva' => in_array(mb_strtolower(Str::ascii((string) ($raw['precio_incluye_iva'] ?? ''))), ['si', 'yes', '1', 'true'], true) ? 'SI' : 'NO',
                'iva' => (string) ($raw['iva'] ?? ''), 'iva_public_id' => $tax?->public_id, 'categoria' => (string) ($raw['categoria'] ?? ''),
            ]];
        }

        return $result;
    }

    private function ivaRate($rates, string $value): ?CompanyTax
    {
        $value = strtoupper(str_replace(['%', ' '], '', Str::ascii($value)));
        $code = match ($value) {
            'NOOBJETO', 'NO_OBJETO' => '6',
            'EXENTO' => '7',
            default => null,
        };

        return $rates->first(fn (CompanyTax $tax): bool => $code !== null
            ? $tax->definition->sri_percentage_code === $code
            : (is_numeric($value) && ! in_array($tax->definition->sri_percentage_code, ['6', '7'], true) && bccomp((string) $tax->definition->fixed_percentage, $value, 2) === 0));
    }

    private function createCustomer(array $data, User $actor): void
    {
        $this->createCustomer->execute([
            'identification_type' => $data['tipo_identificacion'], 'identification' => $data['identificacion'], 'legal_name' => $data['razon_social'],
            'commercial_name' => $data['nombre_comercial'] ?: null, 'email' => $data['correo'] ?: null,
            'phone' => $data['telefono'] ?: null, 'address' => $data['direccion'] ?: null,
        ], $actor);
    }

    private function createItem(Company $company, array $data, User $actor): void
    {
        $category = $data['categoria'] === '' ? null : $company->categories()->firstOrCreate(['name' => mb_substr($data['categoria'], 0, 120)], ['is_active' => true]);
        $item = $this->createItem->execute([
            'type' => $data['tipo'] === 'SERVICIO' ? 'SERVICE' : 'PRODUCT', 'internal_code' => $data['codigo'],
            'auxiliary_code' => $data['codigo_auxiliar'] ?: null, 'name' => $data['nombre'], 'unit' => mb_substr($data['unidad'], 0, 32),
            'sale_price' => $data['precio'], 'price_includes_tax' => $data['precio_incluye_iva'] === 'SI',
            'category_public_id' => $category?->public_id,
        ], $actor);
        $this->syncTaxes->execute($item, [$data['iva_public_id']], $actor);
    }

    private function summary(string $id, string $type, array $rows): array
    {
        $invalid = count(array_filter($rows, fn (array $row): bool => $row['errors'] !== []));

        return ['id' => $id, 'type' => $type, 'rows' => $rows, 'valid' => count($rows) - $invalid, 'invalid' => $invalid];
    }

    private function key(Company $company, string $id): string
    {
        return "imports:{$company->id}:{$id}";
    }
}
