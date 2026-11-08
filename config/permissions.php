<?php

/*
 * Catálogo de permisos del núcleo de FACSTO, uno por módulo del menú. La pantalla Roles muestra una tarjeta por
 * módulo con sus permisos; la clave que se guarda es «módulo.acción» (sales.create).
 *
 * Reglas:
 *  - Cada módulo declara sólo SUS acciones, sin punto: la clave la arma el catálogo con el nombre del módulo, así un
 *    módulo no puede declarar, cambiar ni mostrar permisos de otro.
 *  - Los módulos verticales (Lavadora…) declaran su bloque en config/modules.php y aparecen en Roles sólo si la
 *    empresa los tiene contratados.
 *  - Dashboard y la configuración del sistema (Mi Empresa, Sistema, Integraciones, SRI de la empresa) no son
 *    módulos de roles: el Dashboard lo ven todos y la configuración sólo el rol fijo Administrador.
 */
return [
    'modules' => [
        'cash' => [
            'name' => 'Caja',
            'permissions' => [
                'view' => 'Ver cajas, sesiones y reportes',
                'operate' => 'Abrir y cerrar caja y registrar movimientos',
            ],
        ],
        'sales' => [
            'name' => 'Ventas',
            'permissions' => [
                'view' => 'Ver ventas y comprobantes',
                'create' => 'Crear, facturar y anular ventas',
                'profit' => 'Ver la utilidad de la venta',
            ],
        ],
        'proformas' => [
            'name' => 'Proformas',
            'permissions' => [
                'view' => 'Ver proformas',
                'create' => 'Crear, editar y convertir proformas',
            ],
        ],
        'investments' => [
            'name' => 'Inversiones',
            'permissions' => [
                'view' => 'Ver compras',
                'create' => 'Registrar compras',
            ],
        ],
        'retentions' => [
            'name' => 'Retenciones',
            'permissions' => [
                'view' => 'Ver retenciones',
                'create' => 'Emitir retenciones',
            ],
        ],
        'receivables' => [
            'name' => 'Cuentas por cobrar',
            'permissions' => [
                'view' => 'Ver cuentas por cobrar',
                'create' => 'Registrar cobros',
            ],
        ],
        'payables' => [
            'name' => 'Cuentas por pagar',
            'permissions' => [
                'view' => 'Ver cuentas por pagar',
                'create' => 'Registrar pagos',
            ],
        ],
        'customers' => [
            'name' => 'Clientes',
            'permissions' => [
                'view' => 'Ver clientes',
                'create' => 'Crear e importar clientes',
                'update' => 'Editar, activar y desactivar clientes',
            ],
        ],
        'suppliers' => [
            'name' => 'Proveedores',
            'permissions' => [
                'view' => 'Ver proveedores',
                'create' => 'Crear, editar, activar y desactivar proveedores',
            ],
        ],
        'users' => [
            'name' => 'Usuarios y roles',
            'permissions' => [
                'view' => 'Ver usuarios y roles',
                'manage' => 'Crear y editar usuarios y roles',
            ],
        ],
        'warehouses' => [
            'name' => 'Bodegas',
            'permissions' => [
                'view' => 'Ver bodegas',
                'create' => 'Crear, editar, activar y desactivar bodegas',
            ],
        ],
        'categories' => [
            'name' => 'Categorías',
            'permissions' => [
                'view' => 'Ver categorías',
                'create' => 'Crear categorías',
                'update' => 'Editar, activar y desactivar categorías',
            ],
        ],
        'brands' => [
            'name' => 'Marcas',
            'permissions' => [
                'view' => 'Ver marcas',
                'create' => 'Crear marcas',
                'update' => 'Editar, activar y desactivar marcas',
            ],
        ],
        'inventory' => [
            'name' => 'Inventario',
            'permissions' => [
                'view' => 'Ver productos y unidades',
                'create' => 'Crear e importar productos y unidades',
                'update' => 'Editar, activar y desactivar productos',
            ],
        ],
        'sri' => [
            'name' => 'SRI',
            'permissions' => [
                'view' => 'Ver reglas, tarifas de IVA y formas de pago',
                'manage' => 'Elegir las tarifas de IVA que aplica la empresa',
            ],
        ],
    ],
];
