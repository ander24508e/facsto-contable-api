<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Módulo Lavadora (config/modules.php, derecho «carwash»). Sus tablas llevan el prefijo carwash_ y apuntan
 * al núcleo (clientes, artículos, ventas) con llaves compuestas (company_id, id): un registro nunca puede
 * referir a otra empresa. El núcleo no conoce estas tablas.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Configuración de la Lavadora, una pestaña por submódulo. Cada ajuste tiene un valor por defecto que deja el
        // módulo como antes: si la empresa no lo toca, nada cambia.
        Schema::create('carwash_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            // Agenda: horario general, largo del turno, anticipación, tolerancia de llegada, días cerrados y cupo.
            $table->time('opening_time')->default('08:00');
            $table->time('closing_time')->default('18:00');
            $table->unsignedSmallInteger('slot_minutes')->default(30);
            $table->unsignedSmallInteger('min_advance_minutes')->default(0);
            $table->unsignedSmallInteger('max_advance_days')->default(60);
            $table->unsignedSmallInteger('arrival_tolerance_minutes')->nullable();
            $table->unsignedSmallInteger('vehicles_per_slot')->nullable();
            $table->json('closed_weekdays')->nullable();
            $table->json('closed_dates')->nullable();
            // Servicios: checklist de recepción y pasos opcionales (Llegó, Listo).
            $table->json('reception_checklist')->nullable();
            $table->boolean('require_checklist')->default(false);
            $table->boolean('use_arrived_step')->default(true);
            $table->boolean('use_ready_step')->default(true);
            // Órdenes: prefijo y número inicial, cobro manual o automático, entrega sin factura.
            $table->string('order_prefix', 6)->default('OT');
            $table->unsignedInteger('order_start_number')->default(1);
            $table->string('billing_mode', 10)->default('MANUAL');
            $table->boolean('allow_unbilled_delivery')->default(false);
            // Áreas: limpieza entre vehículos, uno por área, asignación automática.
            $table->unsignedSmallInteger('cleanup_minutes')->default(0);
            $table->boolean('one_vehicle_per_area')->default(false);
            $table->boolean('auto_assign_area')->default(false);
            // Vehículos: datos obligatorios y placa ecuatoriana.
            $table->boolean('require_color')->default(false);
            $table->boolean('require_brand_model')->default(false);
            $table->boolean('require_year')->default(false);
            $table->boolean('validate_ec_plate')->default(false);
            // Tarifas: precio por vehículo o único, recargos (artículos de Inventario) y redondeo hacia abajo.
            $table->string('pricing_mode', 12)->default('BY_VEHICLE');
            $table->foreignId('dirty_surcharge_item_id')->nullable();
            $table->foreignId('peak_surcharge_item_id')->nullable();
            $table->time('peak_from')->nullable();
            $table->time('peak_to')->nullable();
            $table->decimal('rounding_step', 4, 2)->nullable();
            // Trabajadores: comisión por defecto, asignación y máximo de órdenes a la vez.
            $table->string('commission_type', 8)->default('PERCENT');
            $table->decimal('commission_value', 10, 2)->default(0);
            $table->string('assignment_mode', 10)->default('MANUAL');
            $table->unsignedSmallInteger('max_orders_per_washer')->nullable();
            // Insumos: cuándo se descuentan y aviso de insumo bajo.
            $table->string('consume_supplies_on', 10)->default('READY');
            $table->boolean('supply_alert')->default(true);
            // Clientes: avisos (se envían cuando estén el correo y WhatsApp) y fidelización (cada N lavados, uno gratis).
            $table->boolean('notify_ready')->default(false);
            $table->boolean('notify_reminder')->default(false);
            $table->unsignedSmallInteger('reminder_hours')->default(24);
            $table->unsignedSmallInteger('loyalty_every')->nullable();
            // Asistente inicial: se muestra hasta que la empresa lo termina.
            $table->timestamp('setup_completed_at')->nullable();
            $table->timestamps();
        });

        // Tipos (definen la tarifa), marcas y modelos de vehículo.
        foreach (['carwash_vehicle_types', 'carwash_vehicle_brands'] as $name) {
            Schema::create($name, function (Blueprint $table): void {
                $table->id();
                $table->uuid('public_id')->unique();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('name', 80);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
                $table->unique(['company_id', 'name']);
                $table->unique(['company_id', 'id']);
            });
        }

        // Especificación: marca + modelo + tipo. El vehículo que la usa toma de ella su tipo (y su tarifa).
        Schema::create('carwash_vehicle_models', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('vehicle_brand_id');
            $table->foreignId('vehicle_type_id');
            $table->string('name', 80);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['vehicle_brand_id', 'name', 'vehicle_type_id']);
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'vehicle_brand_id'])->references(['company_id', 'id'])->on('carwash_vehicle_brands')->cascadeOnDelete();
            $table->foreign(['company_id', 'vehicle_type_id'])->references(['company_id', 'id'])->on('carwash_vehicle_types')->restrictOnDelete();
        });

        // El vehículo (identificado por su placa, única por empresa). El cliente va en cada orden; `customer_id` solo
        // recuerda el último que lo trajo, para sugerirlo en Nueva agenda.
        Schema::create('carwash_vehicles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable();
            $table->string('plate', 10);
            $table->foreignId('vehicle_type_id');
            $table->foreignId('vehicle_brand_id')->nullable();
            $table->foreignId('vehicle_model_id')->nullable();
            $table->string('color', 40)->nullable();
            $table->unsignedSmallInteger('year')->nullable();
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'plate']);
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'customer_id']);
            $table->foreign(['company_id', 'customer_id'])->references(['company_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['company_id', 'vehicle_type_id'])->references(['company_id', 'id'])->on('carwash_vehicle_types')->restrictOnDelete();
            $table->foreign(['company_id', 'vehicle_brand_id'])->references(['company_id', 'id'])->on('carwash_vehicle_brands')->restrictOnDelete();
            $table->foreign(['company_id', 'vehicle_model_id'])->references(['company_id', 'id'])->on('carwash_vehicle_models')->restrictOnDelete();
        });

        // Tarifas: cada artículo de Inventario (servicio o producto) de una categoría activada en la Lavadora tiene
        // aquí su ficha. Nombre, categoría, IVA, foto y estado se leen del artículo; la Lavadora guarda la duración
        // base y, en los servicios, sus precios por vehículo.
        Schema::create('carwash_services', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_id');
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            // Tipo de área que necesita (Lavado, Secado…): al reservar sólo sirven las áreas que lo hacen.
            $table->foreignId('area_type_id')->nullable();
            // Comisión propia del servicio; vacía: la de Configuración › Trabajadores.
            $table->string('commission_type', 8)->nullable();
            $table->decimal('commission_value', 10, 2)->nullable();
            $table->timestamps();
            $table->unique(['company_id', 'item_id']);
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'item_id'])->references(['company_id', 'id'])->on('items')->cascadeOnDelete();
            $table->foreign(['company_id', 'area_type_id'])->references(['company_id', 'id'])->on('carwash_area_types')->restrictOnDelete();
        });

        // Precio por vehículo: servicio × especificación (marca + modelo + tipo). Su precio e IVA viven en un artículo
        // tipo Servicio de Inventario que el módulo crea y mantiene (items.managed_by = carwash): es lo que se factura.
        Schema::create('carwash_service_prices', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id');
            $table->foreignId('vehicle_model_id');
            $table->foreignId('item_id');
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->string('description', 300)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['service_id', 'vehicle_model_id']);
            $table->unique(['company_id', 'id']);
            $table->foreign(['company_id', 'service_id'])->references(['company_id', 'id'])->on('carwash_services')->cascadeOnDelete();
            $table->foreign(['company_id', 'vehicle_model_id'])->references(['company_id', 'id'])->on('carwash_vehicle_models')->restrictOnDelete();
            $table->foreign(['company_id', 'item_id'])->references(['company_id', 'id'])->on('items')->restrictOnDelete();
        });

        // Orden de trabajo (OT-000001 por empresa): un vehículo con sus servicios y productos. El cobro es la venta de
        // FACSTO enlazada en sale_id: su estado y su factura se leen de Ventas, no se copian.
        Schema::create('carwash_work_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            // Prefijo con el que nació (Configuración › Órdenes); cambiarlo no renombra las órdenes anteriores.
            $table->string('code_prefix', 6)->default('OT');
            $table->string('source', 20)->default('COUNTER');
            $table->foreignId('vehicle_id');
            // El cliente de Clientes (con cédula o RUC) o, sin él, sólo el contacto: nombre y teléfono.
            $table->foreignId('customer_id')->nullable();
            $table->string('contact_name', 180)->nullable();
            $table->string('contact_phone', 40)->nullable();
            // Trabajador: un usuario de la empresa cuyo rol tiene «Puede ser asignado como trabajador».
            $table->foreignId('washer_id')->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->timestamp('scheduled_at')->nullable();
            // Reserva: el área queda ocupada desde scheduled_at hasta scheduled_end_at (la duración de sus servicios).
            $table->foreignId('work_area_id')->nullable();
            $table->timestamp('scheduled_end_at')->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 300)->nullable();
            $table->string('notes', 1000)->nullable();
            // Checklist de recepción respondido al llegar: [{label, checked, note}].
            $table->json('checklist')->nullable();
            // Suciedad extrema (recargo) y lavado gratis por fidelización.
            $table->boolean('dirty')->default(false);
            $table->boolean('loyalty_free')->default(false);
            // «En reclamo»: sus evidencias de recepción no vencen.
            $table->boolean('evidence_hold')->default(false);
            $table->foreignId('sale_id')->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'scheduled_at']);
            $table->index(['company_id', 'work_area_id', 'scheduled_at']);
            $table->foreign(['company_id', 'customer_id'])->references(['company_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign(['company_id', 'vehicle_id'])->references(['company_id', 'id'])->on('carwash_vehicles')->restrictOnDelete();
            $table->foreign('washer_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['company_id', 'work_area_id'])->references(['company_id', 'id'])->on('carwash_work_areas')->restrictOnDelete();
            $table->foreign(['company_id', 'sale_id'])->references(['company_id', 'id'])->on('sales')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE carwash_settings ADD CONSTRAINT carwash_settings_hours_check CHECK (closing_time <> opening_time AND slot_minutes BETWEEN 5 AND 240)');
            DB::statement("ALTER TABLE carwash_settings ADD CONSTRAINT carwash_settings_modes_check CHECK (billing_mode IN ('MANUAL','AUTO') AND pricing_mode IN ('BY_VEHICLE','SINGLE') AND commission_type IN ('PERCENT','FIXED') AND assignment_mode IN ('MANUAL','ROTATION') AND consume_supplies_on IN ('READY','DELIVERED'))");
            DB::statement("ALTER TABLE carwash_services ADD CONSTRAINT carwash_services_commission_check CHECK (commission_type IS NULL OR commission_type IN ('PERCENT','FIXED'))");
            DB::statement("ALTER TABLE carwash_vehicles ADD CONSTRAINT carwash_vehicles_plate_check CHECK (plate ~ '^[A-Z0-9]{5,10}$')");
            DB::statement('ALTER TABLE carwash_vehicles ADD CONSTRAINT carwash_vehicles_year_check CHECK (year IS NULL OR year BETWEEN 1950 AND 2100)');
            DB::statement('ALTER TABLE carwash_services ADD CONSTRAINT carwash_services_duration_check CHECK (duration_minutes BETWEEN 5 AND 1440)');
            DB::statement('ALTER TABLE carwash_work_orders ADD CONSTRAINT carwash_work_orders_reservation_check CHECK ((work_area_id IS NULL) = (scheduled_end_at IS NULL) AND (scheduled_end_at IS NULL OR scheduled_end_at > scheduled_at))');
            DB::statement("ALTER TABLE carwash_work_orders ADD CONSTRAINT carwash_work_orders_status_check CHECK (status IN ('PENDING','ARRIVED','IN_PROGRESS','READY','DELIVERED','CANCELLED'))");
            DB::statement("ALTER TABLE carwash_work_orders ADD CONSTRAINT carwash_work_orders_source_check CHECK (source IN ('COUNTER','RESERVATION','ONLINE'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('carwash_work_orders');
        Schema::dropIfExists('carwash_service_prices');
        Schema::dropIfExists('carwash_services');
        Schema::dropIfExists('carwash_vehicles');
        Schema::dropIfExists('carwash_vehicle_models');
        Schema::dropIfExists('carwash_vehicle_brands');
        Schema::dropIfExists('carwash_vehicle_types');
        Schema::dropIfExists('carwash_settings');
    }
};
