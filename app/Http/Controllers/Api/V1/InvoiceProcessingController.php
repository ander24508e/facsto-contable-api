<?php

namespace App\Http\Controllers\Api\V1;

use App\Domains\FiscalDocuments\Models\ElectronicDocument;
use App\Domains\Sri\Actions\BuildInvoiceXmlAction;
use App\Domains\Sri\Actions\SignElectronicDocumentAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ElectronicDocumentResource;
use Illuminate\Http\Request;

final class InvoiceProcessingController extends Controller
{
    public function buildXml(ElectronicDocument $invoice, BuildInvoiceXmlAction $build, Request $request): ElectronicDocumentResource
    {
        $this->authorize('process', $invoice);

        return new ElectronicDocumentResource($build->execute($invoice, $request->user()));
    }

    public function sign(ElectronicDocument $invoice, SignElectronicDocumentAction $sign, Request $request): ElectronicDocumentResource
    {
        $this->authorize('process', $invoice);

        return new ElectronicDocumentResource($sign->execute($invoice, $request->user()));
    }
}
