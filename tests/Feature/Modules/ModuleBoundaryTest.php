<?php

namespace Tests\Feature\Modules;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * The core never depends on a module: only the module's own folders (and the modules provider) may
 * reference App\Domains\{Module}. Removing or adding a module never touches Ventas, Caja, SRI…
 */
final class ModuleBoundaryTest extends TestCase
{
    private const MODULES = ['Carwash'];

    public function test_core_code_does_not_reference_modules(): void
    {
        $violations = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $path = str_replace('\\', '/', $file->getPathname());
            if (! str_ends_with($path, '.php')) {
                continue;
            }
            foreach (self::MODULES as $module) {
                if ($this->belongsTo($path, $module) || str_ends_with($path, 'Providers/ModulesServiceProvider.php')) {
                    continue;
                }
                if (str_contains((string) file_get_contents($path), "App\\Domains\\{$module}\\")) {
                    $violations[] = $path;
                }
            }
        }

        $this->assertSame([], $violations, 'El núcleo no debe depender de un módulo.');
    }

    private function belongsTo(string $path, string $module): bool
    {
        foreach (["/Domains/{$module}/", "/Controllers/Api/V1/{$module}/", "/Resources/Api/V1/{$module}/"] as $folder) {
            if (str_contains($path, $folder)) {
                return true;
            }
        }

        return false;
    }
}
