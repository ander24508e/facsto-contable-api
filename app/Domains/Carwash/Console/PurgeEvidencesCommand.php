<?php

namespace App\Domains\Carwash\Console;

use App\Domains\Carwash\Models\WorkOrderEvidence;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Lavadora: borra los archivos de las evidencias de recepción vencidas (90 días) cuya orden no está «En reclamo». El
 * registro queda (fecha, quién la subió y su huella SHA-256) sin el archivo. Corre a diario (ModulesServiceProvider).
 */
final class PurgeEvidencesCommand extends Command
{
    protected $signature = 'carwash:purge-evidences';

    protected $description = 'Borra los archivos vencidos de las evidencias de recepción de la Lavadora';

    public function handle(): int
    {
        $purged = 0;
        WorkOrderEvidence::query()
            ->whereNotNull('expires_at')->where('expires_at', '<', now())
            ->whereHas('workOrder', fn ($order) => $order->where('evidence_hold', false))
            ->chunkById(200, function ($items) use (&$purged): void {
                foreach ($items as $item) {
                    Storage::disk('private')->delete($item->path);
                    $item->forceFill(['expires_at' => null])->save();
                    $purged++;
                }
            });
        $this->info("Evidencias vencidas borradas: {$purged}");

        return self::SUCCESS;
    }
}
