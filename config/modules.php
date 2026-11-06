<?php

/*
 * Manifiesto de módulos verticales de FACSTO (Lavadora, Restaurante, Taller…).
 *
 * Todo el código vive en FACSTO; un módulo se enciende para una empresa sólo cuando tiene su derecho
 * (company_entitlements). Los derechos los envía Condore Platform por la Internal API firmada
 * (o la consola mientras Platform no exista). Ésta es la única lista de claves válidas: Platform
 * arma sus planes con estas mismas claves.
 *
 * - features: funciones vendibles; la primera es el módulo mismo, las demás requieren al módulo.
 * - permissions: acción → roles de FACSTO que la pueden ejecutar.
 * El núcleo (Ventas, Caja, Clientes, Catálogo, SRI) nunca depende de un módulo.
 */
return [
    'modules' => [
        'carwash' => [
            'name' => 'Lavadora',
            'features' => [
                'carwash' => 'Lavadora',
                'carwash.storefront' => 'Tienda web y reservas',
                'carwash.online_payments' => 'Pagos en línea',
            ],
            'permissions' => [
                'view' => ['OWNER', 'ADMIN', 'BILLER', 'VIEWER'],
                'operate' => ['OWNER', 'ADMIN', 'BILLER'],
                'manage' => ['OWNER', 'ADMIN'],
            ],
        ],
    ],

    // Condore Platform: única fuente de derechos. Firma con CondoreSignature, cliente "platform".
    'platform' => [
        'client_id' => 'platform',
        'secret' => (string) env('CONDORE_PLATFORM_SECRET', ''),
        // URL con la que el comando platform:push-entitlements se llama a sí mismo («Platform falso»).
        'self_url' => rtrim((string) env('CONDORE_PLATFORM_SELF_URL', 'http://127.0.0.1:8000'), '/'),
    ],
];
