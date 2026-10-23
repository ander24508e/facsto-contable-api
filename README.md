# FACSTO API

Backend fiscal de FACSTO, construido como un monolito modular con Laravel.
Esta aplicación es la autoridad para las reglas fiscales y los datos de FACSTO.

## Responsabilidades

- Autenticación y usuarios propios de FACSTO.
- Empresas, aislamiento multiempresa y permisos.
- Clientes, catálogo, ventas y documentos fiscales.
- XML, certificados, firma, comunicación con el SRI y RIDE.
- API para FACSTO Web e integraciones externas.
- Aplicación local de entitlements recibidos de Condore Platform.

Condore Platform conserva la autoridad comercial sobre catálogo, planes, precios,
suscripciones y derechos. FACSTO no procesa pagos ni administra esa oferta comercial.
Los dos sistemas tendrán bases de datos privadas e independientes.

## Desarrollo local

Desde la carpeta raíz del proyecto, ejecuta `./scripts/dev-up.ps1` en PowerShell.
El script prepara el `.env` local y su clave antes de iniciar los contenedores.

La API queda disponible en `http://localhost:8000` y el endpoint de estado en
`http://localhost:8000/health`. PostgreSQL y Redis sólo se publican en loopback para
facilitar herramientas locales.

La API corre en Laravel con PostgreSQL, Redis y Horizon. Los certificados, XML y RIDE
se guardarán en el disco privado `storage/private`, fuera de `public/`.

Para crear la cuenta y las dos empresas de demostración en un entorno local, ejecuta
`docker compose exec api php artisan db:seed`. Usuario demo: `demo@facsto.local`,
contraseña local: `FacstoLocal2026!`. El seeder sólo corre con `APP_ENV=local` y usa
datos ficticios de desarrollo.

## Convenciones

Cada funcionalidad seguirá el flujo de migración, modelo, Action, Request, Policy,
Resource, Controller, ruta, contrato OpenAPI, prueba y conexión con FACSTO Web.
Las reglas fiscales viven en Laravel; Vue sólo presenta y envía datos.
