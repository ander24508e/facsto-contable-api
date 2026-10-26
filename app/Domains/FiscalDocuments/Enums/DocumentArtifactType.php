<?php

namespace App\Domains\FiscalDocuments\Enums;

enum DocumentArtifactType: string
{
    case UNSIGNED_XML = 'UNSIGNED_XML';
    case SIGNED_XML = 'SIGNED_XML';
    case AUTHORIZED_XML = 'AUTHORIZED_XML';
    case RIDE = 'RIDE';
}
