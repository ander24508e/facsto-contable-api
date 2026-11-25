<?php

/*
 * Una instalación (un despliegue en Dokploy) = un cliente. Al desplegar, el sistema deja lista su empresa y la cuenta
 * de soporte de Condore con el correo y la contraseña de estas variables, sin entrar a Docker. La contraseña de
 * Dokploy manda: si se cambia ahí, el próximo despliegue la actualiza. Sin correo o sin contraseña no se crea nada.
 */
return [
    /*
     * «pruebas»: la instalación de pruebas nunca envía nada al SRI (aunque SRI_TRANSPORT_ENABLED diga true) y la web
     * muestra una franja fija que lo avisa. Cualquier otro valor (por defecto «produccion»): instalación de un cliente.
     */
    'mode' => env('INSTANCE_MODE', 'produccion'),

    // Identificador de la empresa de esta instalación (minúsculas, números y guiones).
    'company_slug' => env('INSTANCE_COMPANY_SLUG', 'principal'),

    'support' => [
        'name' => env('SUPPORT_ADMIN_NAME', 'Soporte Condore'),
        'email' => env('SUPPORT_ADMIN_EMAIL'),
        'password' => env('SUPPORT_ADMIN_PASSWORD'),
    ],
];
