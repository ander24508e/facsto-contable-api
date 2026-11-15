<?php

namespace App\Http\Controllers\Api\V1\Carwash;

use App\Domains\Carwash\Actions\AddWorkOrderEvidenceAction;
use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Carwash\Models\WorkOrderEvidence;
use App\Domains\Carwash\Services\ReceptionReport;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * Evidencias de la recepción (respaldo de rayones y golpes): fotos, videos cortos y la firma del cliente de una orden,
 * guardados en el disco privado del sistema. «Hacer la inspección» (`carwash.inspect`) las sube; «Ver evidencias»
 * (`carwash.evidence`) las ve y descarga el acta en PDF; «Administrar» marca la orden «En reclamo» (no vencen).
 */
final class EvidenceController extends CarwashController
{
    /** Photos up to 10 MB; videos (short, from the phone) up to 40 MB; the signature is a PNG drawn on screen. */
    private const RULES = [
        WorkOrderEvidence::PHOTO => ['mimetypes:image/jpeg,image/png,image/webp', 'max:10240'],
        WorkOrderEvidence::VIDEO => ['mimetypes:video/mp4,video/webm,video/quicktime', 'max:40960'],
        WorkOrderEvidence::SIGNATURE => ['mimetypes:image/png', 'max:1024'],
    ];

    public function index(string $workOrder): JsonResponse
    {
        $order = $this->owned(WorkOrder::class, $workOrder);
        $evidences = WorkOrderEvidence::query()->with('uploader')->where('work_order_id', $order->id)->orderBy('id')->get();

        return response()->json(['data' => [
            'inClaim' => (bool) $order->evidence_hold,
            'items' => $evidences->map($this->present(...))->values(),
        ]]);
    }

    public function store(Request $request, string $workOrder, AddWorkOrderEvidenceAction $add): JsonResponse
    {
        $kind = (string) $request->validate(['kind' => ['required', 'in:PHOTO,VIDEO,SIGNATURE']])['kind'];
        $data = $request->validate([
            'file' => ['required', 'file', ...self::RULES[$kind]],
            'takenAt' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:200'],
        ], [
            'file.mimetypes' => $kind === WorkOrderEvidence::VIDEO ? 'El video debe ser MP4, WEBM o MOV.' : 'La imagen debe ser JPG, PNG o WEBP.',
            'file.max' => $kind === WorkOrderEvidence::VIDEO ? 'El video supera los 40 MB.' : 'La imagen supera el tamaño permitido.',
        ]);
        $evidence = $add->execute($this->owned(WorkOrder::class, $workOrder), $kind, $data['file'], $data['takenAt'] ?? null, $data['note'] ?? null, $request->user());

        return response()->json(['data' => $this->present($evidence->load('uploader'))], 201);
    }

    /** The file itself, inline (the browser shows the photo or plays the video), only inside the company. */
    public function file(string $evidence): Response
    {
        $item = $this->owned(WorkOrderEvidence::class, $evidence);
        abort_unless(Storage::disk('private')->exists($item->path), 404, 'El archivo ya venció.');

        return Storage::disk('private')->response($item->path, null, [
            'Content-Type' => $item->mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** «En reclamo»: while it is on, the order's evidences never expire. */
    public function claim(Request $request, string $workOrder, AuditRecorder $audit): JsonResponse
    {
        $hold = (bool) $request->validate(['inClaim' => ['required', 'boolean']])['inClaim'];
        $order = $this->owned(WorkOrder::class, $workOrder);
        $order->forceFill(['evidence_hold' => $hold])->save();
        $audit->record($request->user(), $this->company(), 'carwash.work_order.claim', $order, null, ['inClaim' => $hold]);

        return response()->json(['data' => ['inClaim' => $hold]]);
    }

    /** Acta de recepción en PDF: the order, the checklist, the photos with their hash and the customer's signature. */
    public function report(string $workOrder, ReceptionReport $report): Response
    {
        $order = $this->owned(WorkOrder::class, $workOrder);

        return response($report->render($order, $this->company()), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="acta-'.$order->code().'.pdf"',
        ]);
    }

    /** @return array<string, mixed> */
    private function present(WorkOrderEvidence $item): array
    {
        return [
            'publicId' => $item->public_id,
            'kind' => $item->kind,
            'mime' => $item->mime,
            'sizeBytes' => $item->size_bytes,
            'sha256' => $item->sha256,
            'note' => $item->note,
            'takenAt' => $item->taken_at?->toIso8601String(),
            'expiresAt' => $item->expires_at?->toIso8601String(),
            'uploadedBy' => $item->uploader?->name,
            'available' => Storage::disk('private')->exists($item->path),
            // Relative to the API base (`apiUrl` in the web).
            'url' => '/carwash/evidences/'.$item->public_id.'/file',
        ];
    }
}
