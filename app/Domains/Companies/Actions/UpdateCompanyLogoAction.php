<?php

namespace App\Domains\Companies\Actions;

use App\Domains\Companies\Models\Company;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class UpdateCompanyLogoAction
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function execute(Company $company, UploadedFile $logo, User $actor): Company
    {
        $extension = $logo->extension();
        $path = 'companies/'.$company->public_id.'/branding/'.Str::uuid().'.'.$extension;
        $previousPath = $company->logo_path;

        Storage::disk('private')->putFileAs(dirname($path), $logo, basename($path));

        try {
            DB::transaction(function () use ($company, $actor, $path, $previousPath): void {
                $company->forceFill(['logo_path' => $path])->save();
                $this->audit->record($actor, $company, 'company.logo_updated', $company,
                    ['hasLogo' => $previousPath !== null], ['hasLogo' => true]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }

        if ($previousPath !== null) {
            Storage::disk('private')->delete($previousPath);
        }

        return $company->refresh();
    }
}
