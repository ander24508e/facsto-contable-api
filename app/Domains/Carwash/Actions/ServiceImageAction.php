<?php

namespace App\Domains\Carwash\Actions;

use App\Domains\Carwash\Models\Service;
use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** The photo of a service, on the private disk like the items' photos; served only to the company's members. */
final class ServiceImageAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    public function store(Service $service, UploadedFile $image, User $actor): Service
    {
        $company = $this->context->company();
        $path = 'companies/'.$company->public_id.'/carwash/services/'.$service->public_id.'/'.Str::uuid().'.'.$image->extension();
        $previous = $service->image_path;
        Storage::disk('private')->putFileAs(dirname($path), $image, basename($path));
        try {
            $service->forceFill(['image_path' => $path])->save();
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }
        if ($previous !== null) {
            Storage::disk('private')->delete($previous);
        }
        $this->audit->record($actor, $company, 'carwash.service.image_updated', $service, ['hasImage' => $previous !== null], ['hasImage' => true]);

        return $service;
    }

    public function delete(Service $service, User $actor): Service
    {
        $previous = $service->image_path;
        if ($previous !== null) {
            $service->forceFill(['image_path' => null])->save();
            Storage::disk('private')->delete($previous);
            $this->audit->record($actor, $this->context->company(), 'carwash.service.image_updated', $service, ['hasImage' => true], ['hasImage' => false]);
        }

        return $service;
    }
}
