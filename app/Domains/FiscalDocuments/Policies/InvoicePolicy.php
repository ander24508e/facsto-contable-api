<?php

namespace App\Domains\FiscalDocuments\Policies;

use App\Domains\Companies\Access\ModulePolicy;
use App\Domains\FiscalDocuments\Enums\DocumentType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Comprobantes electrónicos. Los de una venta (factura, nota de crédito) son de Ventas: se ven con «sales.view» y se
 * emiten o procesan con «sales.create». Una retención es de Retenciones («retentions.view» / «retentions.create»).
 */
final class InvoicePolicy extends ModulePolicy
{
    protected const MODULE = 'sales';

    public function view(User $user, Model $record): bool
    {
        return $this->allowsFor($user, $record, 'view') && $this->owns($record);
    }

    public function process(User $user, Model $record): bool
    {
        return $this->allowsFor($user, $record, 'create') && $this->owns($record);
    }

    private function allowsFor(User $user, Model $record, string $action): bool
    {
        $module = $record->getAttribute('document_type') === DocumentType::RETENTION ? 'retentions' : 'sales';

        return $this->access->allows($user, $this->context->company(), "{$module}.{$action}");
    }
}
