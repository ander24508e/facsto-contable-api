<?php

namespace App\Domains\Ride\Services;

use App\Domains\Ride\DTOs\RideData;
use Dompdf\Dompdf;
use Dompdf\Options;

/** Renders the RIDE to PDF. No remote resources are ever fetched while rendering. */
final class RidePdfGenerator
{
    public function render(RideData $ride): string
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setTempDir(sys_get_temp_dir());
        $options->setChroot([resource_path('views/ride')]);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml(view('ride.invoice', ['ride' => $ride])->render(), 'UTF-8');
        $dompdf->setPaper('A4');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
