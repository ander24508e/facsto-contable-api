<?php

namespace Database\Seeders;

use App\Domains\Withholdings\Models\SriWithholdingCode;
use Illuminate\Database\Seeder;

/**
 * Códigos de retención del comprobante 07: Renta (tabla de conceptos del ATS) e IVA (ficha técnica, tabla 20).
 * El porcentaje es el sugerido: el SRI los cambia por resolución (la última reforma de Renta rige desde
 * marzo de 2026), así que se corrige aquí y se vuelve a ejecutar, y al emitir se puede ajustar.
 */
final class SriWithholdingCodeSeeder extends Seeder
{
    /** @var list<array{0: string, 1: string, 2: string, 3?: bool}> code, name, percentage, editable */
    private const RENTA = [
        ['303', 'Honorarios profesionales y demás pagos por servicios relacionados con el título profesional', '10.00'],
        ['303A', 'Servicios profesionales prestados por sociedades residentes', '5.00'],
        ['304', 'Servicios predomina el intelecto no relacionados con el título profesional', '10.00'],
        ['304A', 'Comisiones y demás pagos por servicios predomina intelecto no relacionados con el título profesional', '10.00'],
        ['304B', 'Pagos a notarios y registradores de la propiedad y mercantil por sus actividades ejercidas como tales', '10.00'],
        ['304C', 'Pagos a deportistas, entrenadores, árbitros, miembros del cuerpo técnico por sus actividades ejercidas como tales', '10.00'],
        ['304D', 'Pagos a artistas por sus actividades ejercidas como tales', '10.00'],
        ['304E', 'Honorarios y demás pagos por servicios de docencia', '10.00'],
        ['307', 'Servicios predomina la mano de obra', '3.00'],
        ['308', 'Utilización o aprovechamiento de la imagen o renombre', '10.00'],
        ['309', 'Servicios prestados por medios de comunicación y agencias de publicidad', '3.00'],
        ['310', 'Servicio de transporte privado de pasajeros o transporte público o privado de carga', '1.00'],
        ['311', 'Pagos a través de liquidación de compra (nivel cultural o rusticidad)', '3.00'],
        ['312', 'Transferencia de bienes muebles de naturaleza corporal', '2.00'],
        ['312A', 'Compra de bienes de origen agrícola, avícola, pecuario, apícola, cunícula, bioacuático, forestal y carnes en estado natural', '1.00'],
        ['319', 'Cuotas de arrendamiento mercantil (prestado por sociedades), inclusive la de opción de compra', '2.00'],
        ['320', 'Arrendamiento de bienes inmuebles', '10.00'],
        ['322', 'Seguros y reaseguros (primas y cesiones)', '2.00'],
        ['323', 'Rendimientos financieros pagados a naturales y sociedades (no a IFIs)', '3.00'],
        ['332', 'Otras compras de bienes y servicios no sujetas a retención (incluye RIMPE Negocios Populares)', '0.00'],
        ['332B', 'Compra de bienes inmuebles', '0.00'],
        ['332C', 'Transporte público de pasajeros', '0.00'],
        ['332G', 'Pagos con tarjeta de crédito', '0.00'],
        ['343', 'Otras retenciones aplicables el 1% (incluye RIMPE Emprendedores)', '1.00'],
        ['343A', 'Energía eléctrica', '2.00'],
        ['343B', 'Actividades de construcción de obra material inmueble, urbanización, lotización o actividades similares', '2.00'],
        ['344', 'Otras retenciones aplicables el 2%', '2.00'],
        ['344A', 'Pago local tarjeta de crédito / débito reportada por la emisora de tarjeta de crédito / entidades del sistema financiero', '2.00'],
        ['3440', 'Otras retenciones aplicables el 3%', '3.00'],
        ['345', 'Otras retenciones aplicables el 8%', '8.00'],
        ['346', 'Otras retenciones aplicables a otros porcentajes', '0.00', true],
    ];

    /** @var list<array{0: string, 1: string, 2: string}> */
    private const IVA = [
        ['9', 'Retención del 10% del IVA', '10.00'],
        ['10', 'Retención del 20% del IVA', '20.00'],
        ['1', 'Retención del 30% del IVA (bienes)', '30.00'],
        ['11', 'Retención del 50% del IVA', '50.00'],
        ['2', 'Retención del 70% del IVA (servicios)', '70.00'],
        ['3', 'Retención del 100% del IVA (honorarios, arriendos a personas naturales, liquidaciones de compra)', '100.00'],
        ['7', 'Retención en cero', '0.00'],
        ['8', 'No procede retención', '0.00'],
    ];

    public function run(): void
    {
        foreach (['RENTA' => self::RENTA, 'IVA' => self::IVA] as $tax => $codes) {
            foreach ($codes as $row) {
                SriWithholdingCode::query()->firstOrNew(['tax' => $tax, 'code' => $row[0]])
                    ->fill(['name' => $row[1], 'percentage' => $row[2], 'percentage_editable' => $row[3] ?? false, 'is_active' => true])
                    ->save();
            }
        }
    }
}
