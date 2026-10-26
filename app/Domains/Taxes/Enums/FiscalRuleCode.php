<?php

namespace App\Domains\Taxes\Enums;

/** Reglas normativas globales: las publica FACSTO/Condore y ninguna empresa las modifica. */
enum FiscalRuleCode: string
{
    /** Ficha técnica 9.10: sobre este total la factura debe identificar al comprador. */
    case FINAL_CONSUMER_MAX_TOTAL = 'FINAL_CONSUMER_MAX_TOTAL';

    /** Ficha técnica tabla 21: la propina no supera este porcentaje del subtotal. */
    case MAX_TIP_PERCENTAGE = 'MAX_TIP_PERCENTAGE';
}
