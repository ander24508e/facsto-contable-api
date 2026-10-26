<?php

return [

    'invoice' => [
        'xml_version' => env('SRI_INVOICE_XML_VERSION', '1.1.0'),
        'xsd_path' => resource_path('sri/xsd/invoice/1.1.0/factura.xsd'),
    ],

    'signature' => [
        'standard' => 'XAdES-BES',
        'schema_version' => '1.3.2',
        'encoding' => 'UTF-8',
        'type' => 'ENVELOPED',
        'algorithm' => 'RSA-SHA1',
    ],

    'software_provider' => [
        'ruc' => env('SRI_SOFTWARE_PROVIDER_RUC'),
        'name' => env('SRI_SOFTWARE_PROVIDER_NAME', 'Condore Software'),
        /*
         * Registro del proveedor ante el SRI. F05 puede firmar con el RUC configurado,
         * pero F06 nunca transmite mientras sea false.
         */
        'registered' => (bool) env('SRI_SOFTWARE_PROVIDER_REGISTERED', false),
    ],

    'storage' => [
        'disk' => 'private',
        'base_path' => 'sri',
    ],

    /*
     * Interruptor global de seguridad.
     * F06 jamás transmite si está false.
     */
    'transport_enabled' => env('SRI_TRANSPORT_ENABLED', false),
];
