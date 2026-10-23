<?php

namespace App\Domains\FiscalDocuments\Services;

use App\Domains\Companies\Fiscal\Enums\EmissionType;
use App\Domains\Companies\Fiscal\Enums\SriEnvironment;
use App\Domains\Companies\Fiscal\Services\EcuadorTaxIdentifierValidator;
use App\Domains\FiscalDocuments\Enums\DocumentType;
use DateTimeInterface;
use InvalidArgumentException;

final class AccessKeyGenerator
{
    public function __construct(
        private readonly SriDocumentTypeMapper $documentTypeMapper,
        private readonly EcuadorTaxIdentifierValidator $rucValidator,
        private readonly Modulo11Service $modulo11,
    ) {}

    public function generate(
        DateTimeInterface $issueDate,
        DocumentType $documentType,
        string $ruc,
        SriEnvironment $environment,
        string $establishmentCode,
        string $emissionPointCode,
        string $sequential,
        string $numericCode,
        EmissionType $emissionType,
    ): string {
        if (! $this->rucValidator->isValidRuc($ruc)) {
            throw new InvalidArgumentException('El RUC no es válido.');
        }
        if (! preg_match('/^\d{3}$/', $establishmentCode) || ! preg_match('/^\d{3}$/', $emissionPointCode)
            || ! preg_match('/^\d{9}$/', $sequential) || ! preg_match('/^\d{8}$/', $numericCode)) {
            throw new InvalidArgumentException('Los componentes de la clave de acceso tienen formato inválido.');
        }

        $environmentCode = match ($environment) {
            SriEnvironment::TEST => '1', SriEnvironment::PRODUCTION => '2'
        };
        $emissionCode = match ($emissionType) {
            EmissionType::NORMAL => '1'
        };
        $base = $issueDate->format('dmY')
            .$this->documentTypeMapper->code($documentType)
            .$ruc.$environmentCode.$establishmentCode.$emissionPointCode.$sequential.$numericCode.$emissionCode;
        if (strlen($base) !== 48) {
            throw new InvalidArgumentException('La base de clave de acceso debe tener 48 dígitos.');
        }

        return $base.$this->modulo11->checkDigit($base);
    }
}
