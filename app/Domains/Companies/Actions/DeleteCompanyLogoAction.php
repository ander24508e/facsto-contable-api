<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Models\Company;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class DeleteCompanyLogoAction
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function execute(Company $company, User $actor): Company
    {
        $path = $company->logo_path;
        if ($path === null) {
            return $company;
        }

        DB::transaction(function () use ($company, $actor): void {
            $company->forceFill(['logo_path' => null])->save();
            $this->audit->record($actor, $company, 'company.logo_removed', $company,
                ['hasLogo' => true], ['hasLogo' => false]);
        });

        Storage::disk('private')->delete($path);

        return $company->refresh();
    }
}
