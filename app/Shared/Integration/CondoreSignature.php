<?php

namespace App\Shared\Integration;

/**
 * Firma HMAC de las llamadas entre productos Condore (FACSTO ↔ Carwash). Es la misma copia en los dos productos.
 *
 * Cadena firmada: "{timestamp}\n{METHOD}\n{ruta con query}\n{usuario que actúa o vacío}\n{sha256 del cuerpo}".
 * Cabeceras: X-Condore-Client, X-Condore-Timestamp, X-Condore-User (opcional) y
 * X-Condore-Signature = "sha256=" + HMAC-SHA256(cadena, secreto). Ventana anti-replay de 5 minutos.
 */
final class CondoreSignature
{
    public const HEADER_CLIENT = 'X-Condore-Client';

    public const HEADER_TIMESTAMP = 'X-Condore-Timestamp';

    public const HEADER_USER = 'X-Condore-User';

    public const HEADER_SIGNATURE = 'X-Condore-Signature';

    public const WINDOW_SECONDS = 300;

    public static function sign(string $secret, int $timestamp, string $method, string $pathWithQuery, ?string $actingUser, string $body): string
    {
        $payload = implode("\n", [$timestamp, strtoupper($method), $pathWithQuery, $actingUser ?? '', hash('sha256', $body)]);

        return 'sha256='.hash_hmac('sha256', $payload, $secret);
    }

    public static function verify(string $secret, string $timestamp, string $method, string $pathWithQuery, ?string $actingUser, string $body, string $signature, ?int $now = null): bool
    {
        if ($secret === '' || ! ctype_digit($timestamp) || abs(($now ?? time()) - (int) $timestamp) > self::WINDOW_SECONDS) {
            return false;
        }

        return hash_equals(self::sign($secret, (int) $timestamp, $method, $pathWithQuery, $actingUser, $body), $signature);
    }
}
