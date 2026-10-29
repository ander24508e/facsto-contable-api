{{-- RIDE de factura (ficha técnica, anexo 2). Sólo datos congelados del comprobante y de la autorización del SRI. --}}
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<style>
    @page { margin: 22px 24px; }
    body { font-family: "DejaVu Sans", sans-serif; font-size: 8.5px; color: #1f2933; }
    table { border-collapse: collapse; width: 100%; }
    .box { border: 1px solid #52606d; border-radius: 6px; padding: 8px; }
    .title { font-size: 13px; font-weight: bold; letter-spacing: .5px; }
    .muted { color: #52606d; }
    .label { font-weight: bold; }
    .grid td { border: 1px solid #9aa5b1; padding: 3px 4px; vertical-align: top; }
    .grid th { border: 1px solid #52606d; background: #e4e7eb; padding: 4px; font-size: 8px; }
    .num { text-align: right; white-space: nowrap; }
    .key { font-family: "DejaVu Sans Mono", monospace; font-size: 8px; word-break: break-all; }
    .spacer { height: 8px; }
</style>
</head>
<body>
<table>
    <tr>
        <td style="width: 50%; padding-right: 6px; vertical-align: top;">
            <div class="box" style="min-height: 190px;">
                <div class="title">{{ $ride->issuer['commercialName'] ?: $ride->issuer['legalName'] }}</div>
                <div class="spacer"></div>
                <div><span class="label">Razón social:</span> {{ $ride->issuer['legalName'] }}</div>
                <div><span class="label">Dirección matriz:</span> {{ $ride->issuer['mainAddress'] }}</div>
                <div><span class="label">Dirección sucursal:</span> {{ $ride->issuer['establishmentAddress'] }}</div>
                @if ($ride->issuer['specialTaxpayerNumber'])
                    <div><span class="label">Contribuyente especial Nro.:</span> {{ $ride->issuer['specialTaxpayerNumber'] }}</div>
                @endif
                <div><span class="label">Obligado a llevar contabilidad:</span> {{ $ride->issuer['accountingRequired'] }}</div>
                @if ($ride->issuer['withholdingAgentNumber'])
                    <div><span class="label">Agente de retención, resolución Nro.:</span> {{ $ride->issuer['withholdingAgentNumber'] }}</div>
                @endif
                @if ($ride->issuer['artisanQualificationNumber'] ?? null)
                    <div><span class="label">Artesano calificado Nro.:</span> {{ $ride->issuer['artisanQualificationNumber'] }}</div>
                @endif
                @if ($ride->issuer['rimpe'])
                    <div class="label">{{ $ride->issuer['rimpe'] }}</div>
                @endif
            </div>
        </td>
        <td style="width: 50%; padding-left: 6px; vertical-align: top;">
            <div class="box" style="min-height: 190px;">
                <div><span class="label">R.U.C.:</span> {{ $ride->issuer['ruc'] }}</div>
                <div class="title" style="margin-top: 4px;">{{ $ride->documentLabel }}</div>
                <div><span class="label">No.</span> {{ $ride->fullNumber }}</div>
                <div class="spacer"></div>
                <div class="label">NÚMERO DE AUTORIZACIÓN</div>
                <div class="key">{{ $ride->authorizationNumber }}</div>
                <div><span class="label">Fecha y hora de autorización:</span> {{ $ride->authorizedAt }}</div>
                <div><span class="label">Ambiente:</span> {{ $ride->environment }}</div>
                <div><span class="label">Emisión:</span> {{ $ride->emissionType }}</div>
                <div class="spacer"></div>
                <div class="label">CLAVE DE ACCESO</div>
                <img src="data:image/svg+xml;base64,{{ base64_encode($ride->barcodeSvg) }}" style="width: 100%; height: 34px;" alt="">
                <div class="key">{{ $ride->accessKey }}</div>
            </div>
        </td>
    </tr>
</table>

<div class="spacer"></div>
<div class="box">
    <table>
        <tr>
            <td style="width: 60%;"><span class="label">Razón social / Nombres y apellidos:</span> {{ $ride->customer['name'] }}</td>
            <td><span class="label">Identificación ({{ $ride->customer['identificationType'] }}):</span> {{ $ride->customer['identification'] }}</td>
        </tr>
        <tr>
            <td><span class="label">Fecha de emisión:</span> {{ $ride->issueDate }}</td>
            <td><span class="label">Dirección:</span> {{ $ride->customer['address'] }}</td>
        </tr>
        @if ($ride->modifiedDocument)
            <tr>
                <td><span class="label">Comprobante que se modifica:</span> {{ $ride->modifiedDocument['type'] }} {{ $ride->modifiedDocument['number'] }}</td>
                <td><span class="label">Fecha de emisión (comprobante a modificar):</span> {{ $ride->modifiedDocument['date'] }}</td>
            </tr>
            <tr>
                <td colspan="2"><span class="label">Razón de modificación:</span> {{ $ride->modifiedDocument['reason'] }}</td>
            </tr>
        @endif
    </table>
</div>

<div class="spacer"></div>
<table class="grid">
    <thead>
        <tr>
            <th>Cód. principal</th>
            <th>Cód. auxiliar</th>
            <th>Cantidad</th>
            <th style="width: 40%;">Descripción</th>
            <th>Precio unitario</th>
            <th>Descuento</th>
            <th>Precio total</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($ride->lines as $line)
            <tr>
                <td>{{ $line['code'] }}</td>
                <td>{{ $line['auxiliaryCode'] }}</td>
                <td class="num">{{ $line['quantity'] }}</td>
                <td>{{ $line['description'] }}</td>
                <td class="num">{{ $line['unitPrice'] }}</td>
                <td class="num">{{ $line['discount'] }}</td>
                <td class="num">{{ $line['total'] }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<div class="spacer"></div>
<table>
    <tr>
        <td style="width: 58%; padding-right: 6px; vertical-align: top;">
            <div class="box">
                <div class="label">Información adicional</div>
                @foreach ($ride->additionalInformation as $name => $value)
                    <div><span class="label">{{ $name }}:</span> {{ $value }}</div>
                @endforeach
            </div>
            @if ($ride->payments)
            <div class="spacer"></div>
            <table class="grid">
                <thead><tr><th>Forma de pago</th><th>Valor</th><th>Plazo</th></tr></thead>
                <tbody>
                    @foreach ($ride->payments as $payment)
                        <tr>
                            <td>{{ $payment['code'] }} - {{ $payment['name'] }}</td>
                            <td class="num">{{ $payment['amount'] }}</td>
                            <td>{{ $payment['term'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            @endif
        </td>
        <td style="width: 42%; vertical-align: top;">
            <table class="grid">
                @foreach ($ride->totals as $row)
                    <tr><td class="label">{{ $row['label'] }}</td><td class="num">{{ $row['amount'] }}</td></tr>
                @endforeach
                <tr><th style="text-align: left;">VALOR TOTAL</th><th class="num">{{ $ride->total }}</th></tr>
            </table>
        </td>
    </tr>
</table>

<div class="spacer"></div>
<div class="muted">Representación impresa de documento electrónico (RIDE) generada por FACSTO.</div>
</body>
</html>
