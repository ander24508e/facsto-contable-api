<?php

namespace App\Domains\Sri\Soap;

use App\Domains\Sri\DTOs\SriAuthorizationResult;
use App\Domains\Sri\DTOs\SriMessageData;
use App\Domains\Sri\DTOs\SriReceptionResult;
use App\Domains\Sri\Enums\SriAuthorizationStatus;
use App\Domains\Sri\Enums\SriReceptionStatus;
use Carbon\CarbonImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Throwable;

/**
 * Reads the SOAP answers of the offline web services. Namespaces vary between SRI deployments,
 * so elements are matched by local name. authorized_at always comes from fechaAutorizacion.
 */
final class SriResponseParser
{
    public function reception(string $body): SriReceptionResult
    {
        $xpath = $this->load($body);
        $answer = $this->first($xpath, "//*[local-name()='RespuestaRecepcionComprobante']");
        $status = SriReceptionStatus::tryFrom(mb_strtoupper($this->text($xpath, $answer, 'estado') ?? ''));
        if ($answer === null || $status === null) {
            throw new SriResponseException('RECEPTION_RESPONSE_INVALID');
        }

        return new SriReceptionResult($status, $this->messages($xpath, $answer));
    }

    public function authorization(string $body, string $accessKey): SriAuthorizationResult
    {
        $xpath = $this->load($body);
        $answer = $this->first($xpath, "//*[local-name()='RespuestaAutorizacionComprobante']");
        if ($answer === null) {
            throw new SriResponseException('AUTHORIZATION_RESPONSE_INVALID');
        }
        $consulted = $this->text($xpath, $answer, 'claveAccesoConsultada');
        if ($consulted !== null && $consulted !== $accessKey) {
            throw new SriResponseException('AUTHORIZATION_ACCESS_KEY_MISMATCH');
        }

        $authorizations = [];
        foreach ($xpath->query("./*[local-name()='autorizaciones']/*[local-name()='autorizacion']", $answer) ?: [] as $node) {
            if ($node instanceof DOMElement) {
                $authorizations[] = $node;
            }
        }
        // No authorization yet: the SRI is still processing the received document.
        if ($authorizations === []) {
            return new SriAuthorizationResult(SriAuthorizationStatus::PENDING, $accessKey, null, null, null, null, null, []);
        }

        // The SRI may list a history; an AUTORIZADO entry wins, then an in-process one.
        $chosen = null;
        foreach ([SriAuthorizationStatus::AUTHORIZED, SriAuthorizationStatus::PENDING, SriAuthorizationStatus::NOT_AUTHORIZED] as $wanted) {
            foreach ($authorizations as $node) {
                if (SriAuthorizationStatus::fromSri($this->text($xpath, $node, 'estado') ?? '') === $wanted) {
                    $chosen ??= $node;
                }
            }
            if ($chosen !== null) {
                break;
            }
        }
        $status = SriAuthorizationStatus::fromSri($this->text($xpath, $chosen, 'estado') ?? '');
        $messages = $this->messages($xpath, $chosen);
        if ($status !== SriAuthorizationStatus::AUTHORIZED) {
            return new SriAuthorizationResult($status, $accessKey, null, null, $this->text($xpath, $chosen, 'ambiente'), null, null, $messages);
        }

        $number = $this->text($xpath, $chosen, 'numeroAutorizacion');
        $authorizedAt = $this->date($this->text($xpath, $chosen, 'fechaAutorizacion'));
        $comprobante = $this->text($xpath, $chosen, 'comprobante', trim: false);
        if ($number === null || preg_match('/^\d{10,49}$/', $number) !== 1 || $authorizedAt === null || blank($comprobante)) {
            throw new SriResponseException('AUTHORIZATION_DATA_INCOMPLETE');
        }

        return new SriAuthorizationResult(
            $status,
            $accessKey,
            $number,
            $authorizedAt,
            $this->text($xpath, $chosen, 'ambiente'),
            '<?xml version="1.0" encoding="UTF-8"?>'."\n".$chosen->ownerDocument->saveXML($chosen),
            trim((string) $comprobante),
            $messages,
        );
    }

    /** @return list<SriMessageData> */
    private function messages(DOMXPath $xpath, DOMElement $context): array
    {
        $messages = [];
        foreach ($xpath->query(".//*[local-name()='mensaje'][*[local-name()='identificador'] or *[local-name()='tipo']]", $context) ?: [] as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }
            $messages[] = new SriMessageData(
                $this->text($xpath, $node, 'identificador'),
                mb_substr($this->text($xpath, $node, 'mensaje') ?? 'Mensaje del SRI sin texto.', 0, 500),
                $this->text($xpath, $node, 'informacionAdicional'),
                ($type = $this->text($xpath, $node, 'tipo')) === null ? null : mb_strtoupper($type),
            );
        }

        return $messages;
    }

    private function date(?string $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }
        try {
            return preg_match('#^\d{2}/\d{2}/\d{4}#', $value) === 1
                ? CarbonImmutable::createFromFormat('d/m/Y H:i:s', $value, 'America/Guayaquil') ?: null
                : CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private function load(string $body): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $loaded = $body !== '' && $document->loadXML($body, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $loaded || $document->doctype !== null) {
            throw new SriResponseException('RESPONSE_NOT_XML');
        }
        $xpath = new DOMXPath($document);
        if ($this->first($xpath, "//*[local-name()='Fault']") !== null) {
            throw new SriResponseException('SOAP_FAULT');
        }

        return $xpath;
    }

    private function first(DOMXPath $xpath, string $query, ?DOMElement $context = null): ?DOMElement
    {
        $node = ($xpath->query($query, $context) ?: null)?->item(0);

        return $node instanceof DOMElement ? $node : null;
    }

    private function text(DOMXPath $xpath, ?DOMElement $context, string $child, bool $trim = true): ?string
    {
        if ($context === null) {
            return null;
        }
        $node = $this->first($xpath, "./*[local-name()='{$child}']", $context);
        if ($node === null) {
            return null;
        }
        $value = $trim ? trim($node->textContent) : $node->textContent;

        return $value === '' ? null : $value;
    }
}
