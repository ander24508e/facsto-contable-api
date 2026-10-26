<?php

namespace Tests\Feature\Companies;

use App\Domains\Companies\Actions\CreateCompanyAction;
use App\Domains\Companies\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class CompanyTestCase extends TestCase
{
    use RefreshDatabase;

    protected function createCompany(User $owner, string $ruc): Company
    {
        return app(CreateCompanyAction::class)->execute($owner, [
            'slug' => 'empresa-'.$ruc,
            'ruc' => $ruc,
            'legal_name' => 'Empresa '.$ruc,
            'main_address' => 'Quito, Ecuador',
        ]);
    }
}
