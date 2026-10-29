<?php

namespace App\Domains\Delivery\Mail;

use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The authorized XML and its RIDE, sent to the customer email frozen in the document. */
final class AuthorizedInvoiceMail extends Mailable
{
    public function __construct(
        public readonly string $issuerName,
        public readonly string $fullNumber,
        public readonly string $accessKey,
        private readonly string $authorizedXml,
        private readonly string $ridePdf,
        public readonly string $documentLabel = 'Factura electrónica',
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->documentLabel.' '.$this->fullNumber.' - '.$this->issuerName);
    }

    public function content(): Content
    {
        return new Content(htmlString: '<p>Adjuntamos la '.e(mb_strtolower($this->documentLabel)).' <strong>'.e($this->fullNumber).'</strong> emitida por '
            .e($this->issuerName).', autorizada por el SRI.</p><p>Clave de acceso: '.e($this->accessKey).'</p>');
    }

    /** @return list<Attachment> */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->authorizedXml, $this->accessKey.'.xml')->withMime('application/xml'),
            Attachment::fromData(fn (): string => $this->ridePdf, $this->accessKey.'.pdf')->withMime('application/pdf'),
        ];
    }
}
