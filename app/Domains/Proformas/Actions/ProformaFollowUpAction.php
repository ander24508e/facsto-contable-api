<?php

namespace App\Domains\Proformas\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Domains\Companies\Models\Company;
use App\Domains\Inventory\Services\StockLedger;
use App\Domains\Proformas\Models\Proforma;
use App\Domains\Proformas\Models\ProformaLine;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Support\Facades\DB;

/**
 * Seguimiento de una proforma: aceptar, rechazar con su motivo (libera la reserva y lo que haya tomado del stock),
 * renovar una vencida (7 o 15 días más, si todavía hay stock libre) y separar las líneas que no se convierten en
 * una proforma nueva («convertir lo que hay»).
 */
final class ProformaFollowUpAction
{
    public function __construct(
        private readonly CompanyContext $context,
        private readonly StockLedger $stock,
        private readonly AuditRecorder $audit,
    ) {}

    public function accept(Proforma $proforma, User $actor): Proforma
    {
        return $this->locked($proforma, function (Proforma $locked) use ($actor): Proforma {
            $locked->forceFill(['status' => Proforma::ACCEPTED])->save();
            $this->audit->record($actor, $this->context->company(), 'proforma.accepted', $locked, null, ['number' => $locked->displayNumber()]);

            return $locked;
        });
    }

    public function reject(Proforma $proforma, string $reason, User $actor): Proforma
    {
        return $this->locked($proforma, function (Proforma $locked) use ($reason, $actor): Proforma {
            if ($locked->stock_committed) {
                $this->stock->releaseProforma($locked, $actor, 'Rechazo de la proforma');
            }
            $locked->forceFill(['status' => Proforma::REJECTED, 'rejection_reason' => trim($reason)])->save();
            $this->audit->record($actor, $this->context->company(), 'proforma.rejected', $locked, null, ['number' => $locked->displayNumber(), 'reason' => $locked->rejection_reason]);

            return $locked;
        });
    }

    /** Renews an offer for 7 or 15 more days from today; it reserves again, so the stock must still be free. */
    public function renew(Proforma $proforma, int $days, User $actor): Proforma
    {
        return $this->locked($proforma, function (Proforma $locked) use ($days, $actor): Proforma {
            $before = $locked->expiration_date->toDateString();
            $locked->forceFill(['expiration_date' => today()->addDays($days)])->save();
            $this->audit->record($actor, $this->context->company(), 'proforma.renewed', $locked, ['expirationDate' => $before], ['expirationDate' => $locked->expiration_date->toDateString()]);

            return $locked;
        });
    }

    /**
     * «Convertir lo que hay»: the lines of the other products move to a new pending proforma (no advance, no credit),
     * so this one keeps only what becomes a sale. Runs inside the conversion.
     *
     * @param  list<string>  $itemPublicIds  products that are converted
     */
    public function splitForConversion(Proforma $locked, array $itemPublicIds, User $actor): void
    {
        $lines = $locked->lines()->with('item')->get();
        $leaving = $lines->filter(fn (ProformaLine $line) => ! in_array($line->item->public_id, $itemPublicIds, true));
        if ($leaving->isEmpty()) {
            return;
        }
        if ($leaving->count() === $lines->count()) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'Elige al menos un producto para convertir.', 422, ['itemPublicIds' => ['Elige al menos un producto para convertir.']]);
        }
        if ($locked->stock_committed) {
            $this->stock->releaseProforma($locked, $actor, 'Conversión parcial de la proforma');
        }

        $company = $this->context->company();
        Company::query()->whereKey($company->id)->lockForUpdate()->firstOrFail();
        $rest = $locked->replicate(['public_id', 'number', 'converted_sale_id', 'advance_amount', 'advance_payment_method_id', 'on_credit', 'stock_committed', 'status', 'rejection_reason', 'converted_at']);
        $rest->fill(['number' => ((int) Proforma::query()->where('company_id', $company->id)->max('number')) + 1, 'status' => Proforma::PENDING, 'created_by' => $actor->id]);
        $rest->save();
        ProformaLine::query()->whereIn('id', $leaving->pluck('id'))->update(['proforma_id' => $rest->id]);

        foreach ([$locked, $rest] as $proforma) {
            $sums = ProformaLine::query()->where('proforma_id', $proforma->id)
                ->selectRaw('COALESCE(SUM(subtotal), 0) as subtotal, COALESCE(SUM(discount_amount), 0) as discount, COALESCE(SUM(tax_total), 0) as tax, COALESCE(SUM(total), 0) as total')->first();
            $proforma->forceFill([
                'subtotal' => $sums->subtotal, 'discount_total' => $sums->discount, 'tax_total' => $sums->tax, 'total' => $sums->total,
            ])->save();
        }
        if ($locked->refresh()->takesStock()) {
            $this->stock->commitProforma($locked, $actor);
        }
        $this->audit->record($actor, $company, 'proforma.split', $locked, null, ['number' => $locked->displayNumber(), 'rest' => $rest->displayNumber()]);
    }

    /** @param callable(Proforma): Proforma $change */
    private function locked(Proforma $proforma, callable $change): Proforma
    {
        $company = $this->context->company();

        return DB::transaction(function () use ($proforma, $change, $company): Proforma {
            $locked = Proforma::query()->where('company_id', $company->id)->whereKey($proforma->id)->lockForUpdate()->first()
                ?? throw new ApiException(ApiErrorCode::PROFORMA_NOT_FOUND, 'La proforma no existe en la empresa activa.', 404);
            if (! $locked->isOpen() || $locked->converted_sale_id !== null) {
                throw new ApiException(ApiErrorCode::PROFORMA_NOT_OPEN, 'La proforma ya fue rechazada o convertida.', 409);
            }

            return $change($locked)->refresh();
        });
    }
}
