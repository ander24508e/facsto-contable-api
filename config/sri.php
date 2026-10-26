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

    /*
     * F05: firmador XAdES-BES (tools/xades-signer). La contraseña del P12 sólo viaja por stdin.
     */
    'signer' => [
        'java_binary' => env('SRI_SIGNER_JAVA', 'java'),
        'jar' => env('SRI_SIGNER_JAR', '/opt/facsto/xades-signer.jar'),
        'timeout' => (int) env('SRI_SIGNER_TIMEOUT', 60),
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
    'transport_enabled' => (bool) env('SRI_TRANSPORT_ENABLED', false),

    /*
     * F06: servicios web offline. El servidor sale siempre del ambiente congelado en el
     * comprobante (electronic_documents.sri_environment); nunca del frontend ni de un Job.
     */
    'endpoints' => [
        'TEST' => [
            'reception' => 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline',
            'authorization' => 'https://celcer.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline',
        ],
        'PRODUCTION' => [
            'reception' => 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/RecepcionComprobantesOffline',
            'authorization' => 'https://cel.sri.gob.ec/comprobantes-electronicos-ws/AutorizacionComprobantesOffline',
        ],
    ],

    'http' => [
        'connect_timeout' => (int) env('SRI_CONNECT_TIMEOUT', 10),
        'timeout' => (int) env('SRI_TIMEOUT', 30),
    ],

    'queues' => [
        'submit' => 'sri-submit',
        'authorization' => 'sri-authorization',
        'delivery' => 'delivery',
    ],

    'retry' => [
        // Recepción: reintentos técnicos (red, HTTP 5xx) con espera exponencial, siempre con la misma clave.
        'submit_tries' => 3,
        'submit_backoff' => [30, 120, 600],
        // Autorización: consultas mientras el SRI responde "en proceso".
        'authorization_delay' => (int) env('SRI_AUTHORIZATION_DELAY', 30),
        'authorization_max_polls' => (int) env('SRI_AUTHORIZATION_MAX_POLLS', 10),
        // El scheduler retoma los comprobantes sin respuesta pasados estos minutos.
        'stale_after_minutes' => (int) env('SRI_STALE_AFTER_MINUTES', 10),
    ],

    /*
     * F07: entrega del comprobante autorizado. Sin SMTP configurado el estado queda
     * SKIPPED_SMTP_NOT_CONFIGURED; jamás se simula un envío.
     */
    'delivery' => [
        'email_enabled' => (bool) env('DELIVERY_EMAIL_ENABLED', false),
    ],
];
