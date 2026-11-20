<?php

namespace App\Shared\Realtime;

use App\Domains\Companies\Models\Company;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Regla 31: announces that something changed so the other users' screens refetch it without F5.
 *  - Core resources go on the company channel «company.{companyPublicId}» (every active member listens).
 *  - A vertical module's resources go on «company.{companyPublicId}.module.{module}» (only with the module on and
 *    one of its permissions), so the core never knows a module's resources.
 *  - Something about one person (their permissions, their access) goes on «user.{userPublicId}».
 * Resources are plural kebab names the web listens to: «items», «stock», «sales», «carwash-orders»…
 * Actions: created, updated, deleted, or a verb of the resource (closed, issued…).
 *
 * A notice leaves only when the transaction commits (a rolled-back change announces nothing) and once per request
 * for the same resource and action (a sale of 20 lines announces its stock once per item). Scoped: one per request
 * or job.
 */
final class Realtime
{
    /** @var array<string, true> */
    private array $sent = [];

    /** @var array<string, array<int, ?string>> */
    private array $publicIds = [];

    public function changed(Company $company, string $resource, ?string $publicId = null, string $action = 'updated', DateTimeInterface|string|null $version = null, ?string $module = null): void
    {
        $this->announce($company->public_id, $resource, $publicId, $action, $version, $module);
    }

    /** A model of the company: its public id and its last change as the version. */
    public function model(Company $company, string $resource, Model $model, string $action = 'updated', ?string $module = null): void
    {
        $this->changed($company, $resource, $model->getAttribute('public_id'), $action, $model->getAttribute('updated_at'), $module);
    }

    /** From a row that only knows its company id (models with AnnouncesChanges). */
    public function forCompanyId(int $companyId, string $resource, ?string $publicId = null, string $action = 'updated', DateTimeInterface|string|null $version = null, ?string $module = null): void
    {
        $companyPublicId = $this->publicIdOf(Company::class, $companyId);
        if ($companyPublicId !== null) {
            $this->announce($companyPublicId, $resource, $publicId, $action, $version, $module);
        }
    }

    /** Something about one person: their roles, permissions or access changed. */
    public function user(User $user, string $resource, string $action = 'updated'): void
    {
        $this->dispatch("user.{$user->public_id}", $resource, $user->public_id, $action, null);
    }

    /**
     * The public id of a row by its internal id (a stock row names its item), remembered for the request.
     *
     * @param  class-string<Model>  $model
     */
    public function publicIdOf(string $model, ?int $id): ?string
    {
        if ($id === null) {
            return null;
        }
        if (! array_key_exists($id, $this->publicIds[$model] ?? [])) {
            $this->publicIds[$model][$id] = $model::query()->whereKey($id)->value('public_id');
        }

        return $this->publicIds[$model][$id];
    }

    private function announce(string $companyPublicId, string $resource, ?string $publicId, string $action, DateTimeInterface|string|null $version, ?string $module): void
    {
        $channel = $module === null ? "company.{$companyPublicId}" : "company.{$companyPublicId}.module.{$module}";
        $this->dispatch($channel, $resource, $publicId, $action, $version instanceof DateTimeInterface ? $version->format(DATE_ATOM) : $version);
    }

    private function dispatch(string $channel, string $resource, ?string $publicId, string $action, ?string $version): void
    {
        $key = "{$channel}|{$resource}|{$publicId}|{$action}";
        DB::afterCommit(function () use ($key, $channel, $resource, $publicId, $action, $version): void {
            if (isset($this->sent[$key])) {
                return;
            }
            $this->sent[$key] = true;
            event(new ResourceChanged($channel, $resource, $publicId, $action, $version));
        });
    }
}
