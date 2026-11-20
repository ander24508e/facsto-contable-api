<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Acta de recepción {{ $order->code() }}</title>
    <style>
        @page { margin: 14mm; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #111; }
        h1 { font-size: 15px; margin: 0; } h2 { font-size: 11px; margin: 12px 0 4px; border-bottom: 1px solid #999; padding-bottom: 2px; }
        .muted { color: #555; } table { width: 100%; border-collapse: collapse; } td, th { padding: 3px 2px; vertical-align: top; text-align: left; }
        .grid td { width: 33%; } .photo { width: 100%; max-height: 150px; } .hash { font-family: 'DejaVu Sans Mono', monospace; font-size: 7px; word-break: break-all; color: #555; }
        .sign { width: 220px; border-top: 1px solid #111; margin-top: 6px; padding-top: 3px; }
    </style>
</head>
<body>
    <table>
        <tr>
            <td>
                <h1>{{ $company->commercial_name ?: $company->legal_name }}</h1>
                <div class="muted">@if ($company->ruc) RUC {{ $company->ruc }} @endif {{ $company->main_address }}</div>
            </td>
            <td style="text-align: right">
                <h1>Acta de recepción</h1>
                <div>Orden {{ $order->code() }}</div>
                <div class="muted">{{ ($order->arrived_at ?? $order->created_at)?->timezone(config('app.timezone'))->format('d/m/Y H:i') }}</div>
            </td>
        </tr>
    </table>

    <h2>Cliente y vehículo</h2>
    <table>
        <tr>
            <td>{{ $order->contactName() }}@if ($order->customer?->identification)<br><span class="muted">{{ $order->customer->identification }}</span>@endif @if ($order->contactPhone())<br><span class="muted">{{ $order->contactPhone() }}</span>@endif</td>
            <td style="text-align: right">Placa <strong>{{ $order->vehicle->plate }}</strong><br>
                <span class="muted">{{ collect([$order->vehicle->brand?->name, $order->vehicle->model?->name, $order->vehicle->type?->name, $order->vehicle->color])->filter()->implode(' · ') }}</span></td>
        </tr>
    </table>
    <p>Servicios: {{ $order->lines->pluck('description')->implode(', ') }}@if ($order->washer) · Lavador: {{ $order->washer->name }}@endif</p>

    @if (! empty($order->checklist))
        <h2>Checklist</h2>
        <table>
            @foreach ($order->checklist as $answer)
                <tr><td style="width: 14px">{{ ($answer['checked'] ?? false) ? '✓' : '✗' }}</td><td>{{ $answer['label'] ?? '' }}@if (! empty($answer['note'])) · <span class="muted">{{ $answer['note'] }}</span>@endif</td></tr>
            @endforeach
        </table>
    @endif
    @if ($order->notes)
        <p><strong>Observaciones:</strong> {{ $order->notes }}</p>
    @endif

    @if ($photos->isNotEmpty())
        <h2>Fotos ({{ $photos->count() }})</h2>
        <table class="grid">
            @foreach ($photos->chunk(3) as $row)
                <tr>
                    @foreach ($row as $photo)
                        <td>
                            @if ($photo['src'])<img class="photo" src="{{ $photo['src'] }}" alt="">@else<span class="muted">Archivo vencido</span>@endif
                            <div class="muted">{{ $photo['item']->taken_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }} · {{ $photo['item']->uploader?->name }}</div>
                            <div class="hash">SHA-256 {{ $photo['item']->sha256 }}</div>
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    @endif

    @if ($videos->isNotEmpty())
        <h2>Videos ({{ $videos->count() }})</h2>
        <table>
            @foreach ($videos as $video)
                <tr><td>{{ $video->taken_at?->timezone(config('app.timezone'))->format('d/m/Y H:i') }} · {{ number_format($video->size_bytes / 1048576, 1) }} MB</td><td class="hash">SHA-256 {{ $video->sha256 }}</td></tr>
            @endforeach
        </table>
    @endif

    <h2>Conformidad del cliente</h2>
    <p>El cliente declara que el estado del vehículo al recibirlo es el que muestran el checklist y las evidencias de esta acta.</p>
    @if ($signature && $signature['src'])
        <img src="{{ $signature['src'] }}" alt="" style="height: 70px">
    @endif
    <div class="sign">Firma del cliente</div>
    <p class="muted">Las huellas SHA-256 permiten comprobar que los archivos no fueron alterados. Este documento no es un comprobante electrónico.</p>
</body>
</html>
