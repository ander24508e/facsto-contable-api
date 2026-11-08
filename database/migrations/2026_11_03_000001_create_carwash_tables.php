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
        // Horario de atención general y largo de los turnos de la Agenda; la capacidad la dan las áreas de trabajo.
        Schema::create('carwash_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->unique()->constrained()->cascadeOnDelete();
            $table->time('opening_time')->default('08:00');
            $table->time('closing_time')->default('18:00');
            $table->unsignedSmallInteger('slot_minutes')->default(30);
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

        // Vehículo del cliente de FACSTO; la placa es única por empresa.
        Schema::create('carwash_vehicles', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id');
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

        // Orden de trabajo (OT-000001 por empresa). El cobro es la venta de FACSTO enlazada en sale_id:
        // su estado y su factura se leen de Ventas, no se copian.
        Schema::create('carwash_work_orders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('source', 20)->default('COUNTER');
            $table->foreignId('vehicle_id');
            $table->foreignId('customer_id');
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
            $table->foreignId('sale_id')->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['company_id', 'number']);
            $table->unique(['company_id', 'id']);
            $table->index(['company_id', 'status']);
            $table->index(['company_id', 'scheduled_at']);
            $table->index(['company_id', 'work_area_id', 'scheduled_at']);
            $table->foreign(['company_id', 'vehicle_id'])->references(['company_id', 'id'])->on('carwash_vehicles')->restrictOnDelete();
            $table->foreign(['company_id', 'customer_id'])->references(['company_id', 'id'])->on('customers')->restrictOnDelete();
            $table->foreign('washer_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign(['company_id', 'work_area_id'])->references(['company_id', 'id'])->on('carwash_work_areas')->restrictOnDelete();
            $table->foreign(['company_id', 'sale_id'])->references(['company_id', 'id'])->on('sales')->restrictOnDelete();
        });

        Schema::create('carwash_work_order_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('work_order_id');
            $table->foreignId('service_id');
            $table->foreignId('service_price_id');
            $table->foreignId('item_id');
            $table->string('description', 300);
            $table->decimal('quantity', 12, 6)->default(1);
            $table->unsignedSmallInteger('duration_minutes');
            $table->timestamps();
            $table->index('work_order_id');
            $table->foreign(['company_id', 'work_order_id'])->references(['company_id', 'id'])->on('carwash_work_orders')->cascadeOnDelete();
            $table->foreign(['company_id', 'service_id'])->references(['company_id', 'id'])->on('carwash_services')->restrictOnDelete();
            $table->foreign(['company_id', 'service_price_id'])->references(['company_id', 'id'])->on('carwash_service_prices')->restrictOnDelete();
            $table->foreign(['company_id', 'item_id'])->references(['company_id', 'id'])->on('items')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE carwash_settings ADD CONSTRAINT carwash_settings_hours_check CHECK (closing_time > opening_time AND slot_minutes BETWEEN 5 AND 240)');
            DB::statement("ALTER TABLE carwash_vehicles ADD CONSTRAINT carwash_vehicles_plate_check CHECK (plate ~ '^[A-Z0-9]{5,10}$')");
            DB::statement('ALTER TABLE carwash_vehicles ADD CONSTRAINT carwash_vehicles_year_check CHECK (year IS NULL OR year BETWEEN 1950 AND 2100)');
            DB::statement('ALTER TABLE carwash_services ADD CONSTRAINT carwash_services_duration_check CHECK (duration_minutes BETWEEN 5 AND 1440)');
            DB::statement('ALTER TABLE carwash_work_orders ADD CONSTRAINT carwash_work_orders_reservation_check CHECK ((work_area_id IS NULL) = (scheduled_end_at IS NULL) AND (scheduled_end_at IS NULL OR scheduled_end_at > scheduled_at))');
            DB::statement("ALTER TABLE carwash_work_orders ADD CONSTRAINT carwash_work_orders_status_check CHECK (status IN ('PENDING','ARRIVED','IN_PROGRESS','READY','DELIVERED','CANCELLED'))");
            DB::statement("ALTER TABLE carwash_work_orders ADD CONSTRAINT carwash_work_orders_source_check CHECK (source IN ('COUNTER','RESERVATION','ONLINE'))");
            DB::statement('ALTER TABLE carwash_work_order_lines ADD CONSTRAINT carwash_work_order_lines_quantity_check CHECK (quantity > 0)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('carwash_work_order_lines');
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
