<?php

namespace App\Domains\Companies\Access;

use App\Domains\Companies\Models\Company;
use App\Domains\Modules\Enums\EntitlementStatus;
use App\Domains\Modules\Services\ModuleRegistry;
use LogicException;

/**
 * Todos los permisos que un rol puede tener, agrupados por módulo: los del núcleo (config/permissions.php) y los de
 * cada módulo vertical (config/modules.php). Cada módulo declara sólo sus acciones; la clave «módulo.acción» la arma
 * este catálogo, así un módulo nuevo agrega su propia tarjeta en Roles sin tocar ni mostrar permisos de otro.
 */
final class PermissionCatalog
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    /** @return array<string, array{name: string, vertical: bool, permissions: array<string, string>}> keyed by module */
    public function blocks(): array
    {
        $blocks = [];
        foreach ((array) config('permissions.modules', []) as $module => $definition) {
            $blocks[$module] = $this->block($module, $definition, false);
        }
        foreach ($this->modules->manifest() as $module => $definition) {
            if (isset($blocks[$module])) {
                throw new LogicException("El módulo {$module} ya existe en el núcleo: cada módulo tiene su propio bloque de permisos.");
            }
            $blocks[$module] = $this->block($module, $definition, true);
        }

        return $blocks;
    }

    /**
     * The blocks a role of this company shows: vertical modules only when the company has them.
     *
     * @return array<string, array{name: string, vertical: bool, permissions: array<string, string>}>
     */
    public function forCompany(Company $company): array
    {
        return array_filter(
            $this->blocks(),
            fn (array $block, string $module) => ! $block['vertical'] || $this->modules->status($company, $module) !== EntitlementStatus::DISABLED,
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /** @return list<string> */
    public function keys(?Company $company = null): array
    {
        $keys = [];
        foreach ($company ? $this->forCompany($company) : $this->blocks() as $block) {
            $keys = [...$keys, ...array_keys($block['permissions'])];
        }

        return $keys;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return array{name: string, vertical: bool, permissions: array<string, string>}
     */
    private function block(string $module, array $definition, bool $vertical): array
    {
        $permissions = [];
        foreach ((array) ($definition['permissions'] ?? []) as $action => $label) {
            if (! is_string($action) || ! is_string($label) || str_contains($action, '.')) {
                throw new LogicException("Cada permiso de {$module} es una acción propia (sin punto) con su descripción.");
            }
            $permissions["{$module}.{$action}"] = $label;
        }

        return ['name' => (string) $definition['name'], 'vertical' => $vertical, 'permissions' => $permissions];
    }
}
