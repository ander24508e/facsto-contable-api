<?php

namespace App\Domains\Carwash\Services;

use App\Domains\Carwash\Models\WorkOrder;
use App\Domains\Carwash\Models\WorkOrderEvidence;
use App\Domains\Companies\Models\Company;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

/**
 * Acta de recepción de la Lavadora en PDF: datos de la empresa, la orden, el cliente y el vehículo, el checklist, las
 * fotos (incrustadas, con su fecha y huella SHA-256), la lista de videos y la firma del cliente. Nunca descarga
 * recursos remotos: las imágenes van incrustadas desde el disco privado.
 */
final class ReceptionReport
{
    public function render(WorkOrder $order, Company $company): string
    {
        $order->loadMissing(['vehicle.brand', 'vehicle.model', 'vehicle.type', 'customer', 'washer', 'lines']);
        $evidences = WorkOrderEvidence::query()->with('uploader')->where('work_order_id', $order->id)->orderBy('id')->get();
        $disk = Storage::disk('private');
        $image = fn (WorkOrderEvidence $item): ?string => $disk->exists($item->path)
            ? 'data:'.$item->mime.';base64,'.base64_encode((string) $disk->get($item->path))
            : null;

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setTempDir(sys_get_temp_dir());
        $options->setChroot([resource_path('views/carwash')]);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('carwash.reception', [
            'order' => $order,
            'company' => $company,
            'photos' => $evidences->where('kind', WorkOrderEvidence::PHOTO)->map(fn ($item) => ['item' => $item, 'src' => $image($item)])->values(),
            'videos' => $evidences->where('kind', WorkOrderEvidence::VIDEO)->values(),
            'signature' => ($signature = $evidences->firstWhere('kind', WorkOrderEvidence::SIGNATURE)) ? ['item' => $signature, 'src' => $image($signature)] : null,
        ])->render(), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
