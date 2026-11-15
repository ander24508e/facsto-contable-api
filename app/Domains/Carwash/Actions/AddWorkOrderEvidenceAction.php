<?php

namespace App\Domains\Carwash\Actions;

use App\Domains\Carwash\Enums\WorkOrderStatus;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Carwash\Models\WorkOrderEvidence;
use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * «Hacer la inspección»: guarda una foto, un video corto o la firma del cliente de la recepción en el disco privado
 * (`carwash/evidences/{empresa}/{orden}/`), con su huella SHA-256, quién la subió y cuándo se tomó. La foto llega ya
 * sellada con fecha, hora y placa (lo hace la web antes de subirla). Hasta 20 archivos y una firma por orden; una orden
 * cancelada o entregada hace más de un día ya no recibe evidencias.
 */
final class AddWorkOrderEvidenceAction
{
    public const MAX_FILES = 20;

    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    public function execute(WorkOrder $order, string $kind, UploadedFile $file, ?string $takenAt, ?string $note, User $actor): WorkOrderEvidence
    {
        if ($order->status === WorkOrderStatus::CANCELLED || ($order->delivered_at !== null && $order->delivered_at->lt(now()->subDay()))) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'La orden ya está cerrada; no recibe más evidencias.', 409);
        }
        $count = WorkOrderEvidence::query()->where('work_order_id', $order->id);
        if ((clone $count)->count() >= self::MAX_FILES) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'La orden ya tiene '.self::MAX_FILES.' evidencias.', 422, ['errors' => ['file' => ['Se alcanzó el máximo de evidencias.']]]);
        }
        if ($kind === WorkOrderEvidence::SIGNATURE && (clone $count)->where('kind', WorkOrderEvidence::SIGNATURE)->exists()) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'El cliente ya firmó esta recepción.', 409);
        }

        $contents = (string) file_get_contents($file->getRealPath());
        $extension = strtolower($file->guessExtension() ?: $file->getClientOriginalExtension() ?: 'bin');
        $path = "carwash/evidences/{$order->company_id}/{$order->public_id}/".Str::uuid7().'.'.$extension;
        Storage::disk('private')->put($path, $contents);

        $evidence = WorkOrderEvidence::query()->create([
            'company_id' => $order->company_id,
            'work_order_id' => $order->id,
            'kind' => $kind,
            'path' => $path,
            'mime' => (string) $file->getMimeType(),
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'note' => $note,
            'taken_at' => $takenAt ? CarbonImmutable::parse($takenAt)->setTimezone(config('app.timezone')) : now(),
            'expires_at' => now()->addDays(WorkOrderEvidence::RETENTION_DAYS),
            'uploaded_by' => $actor->id,
        ]);
        $this->audit->record($actor, $this->context->company(), 'carwash.work_order.evidence_added', $order, null, [
            'evidence' => $evidence->public_id, 'kind' => $kind, 'sha256' => $evidence->sha256,
        ]);

        return $evidence;
    }
}
