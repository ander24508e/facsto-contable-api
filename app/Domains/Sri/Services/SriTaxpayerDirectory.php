<?php

namespace App\Domains\Sri\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The SRI's public RUC registry (catastro): legal name and status of a RUC while it is typed. It is a
 * convenience only: a slow, changed or offline service answers null and the user types the name.
 * A cédula is looked up through its natural-person RUC (cédula + 001); a cédula alone is not public.
 */
final class SriTaxpayerDirectory
{
    /** @return array{ruc: string, legalName: string, status: ?string, withholdingAgent: bool, keepsAccounting: bool}|null */
    public function find(string $identification): ?array
    {
        if (! config('sri.taxpayer_lookup.enabled')) {
            return null;
        }
        $ruc = strlen($identification) === 10 ? $identification.'001' : $identification;
        if (! preg_match('/^\d{13}$/', $ruc)) {
            return null;
        }

        return Cache::remember("sri:taxpayer:{$ruc}", now()->addDay(), fn (): ?array => $this->fetch($ruc)) ?: null;
    }

    private function fetch(string $ruc): ?array
    {
        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(5)
                ->get((string) config('sri.taxpayer_lookup.url'), ['ruc' => $ruc]);
        } catch (Throwable) {
            return null;
        }
        $body = $response->successful() ? $response->json() : null;
        $row = is_array($body) && array_is_list($body) ? ($body[0] ?? null) : $body;
        if (! is_array($row) || trim((string) ($row['razonSocial'] ?? '')) === '') {
            return null;
        }

        return [
            'ruc' => $ruc,
            'legalName' => mb_substr(trim((string) $row['razonSocial']), 0, 300),
            'status' => isset($row['estadoContribuyenteRuc']) ? (string) $row['estadoContribuyenteRuc'] : null,
            'withholdingAgent' => strtoupper((string) ($row['agenteRetencion'] ?? '')) === 'SI',
            'keepsAccounting' => strtoupper((string) ($row['obligadoLlevarContabilidad'] ?? '')) === 'SI',
        ];
    }
}
