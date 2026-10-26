<?php

namespace App\Domains\Delivery\Enums;

enum DeliveryStatus: string
{
    case PENDING = 'PENDING';
    case SENT = 'SENT';
    case FAILED = 'FAILED';
    /** No SMTP is configured: nothing was sent and FACSTO says so. */
    case SKIPPED_SMTP_NOT_CONFIGURED = 'SKIPPED_SMTP_NOT_CONFIGURED';
    case SKIPPED_NO_RECIPIENT = 'SKIPPED_NO_RECIPIENT';
}
