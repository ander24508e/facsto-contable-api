<?php

namespace App\Domains\Carwash\Actions;

use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;
use App\Shared\Audit\AuditRecorder;
use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Creates or edits a named entry of the Lavadora (vehicle type, brand, model, service, area). The name is
 * trimmed and never repeats, ignoring case, within its scope: the company, or the brand for models.
 */
final class SaveNamedEntryAction
{
    public function __construct(private readonly CompanyContext $context, private readonly AuditRecorder $audit) {}

    /**
     * @template TModel of Model
     *
     * @param  TModel  $entry  new (unsaved) or existing
     * @param  array<string, mixed>  $data  columns
     * @param  array{0: string, 1: int}|null  $scope  uniqueness column and value; the company by default
     * @return TModel
     */
    public function execute(Model $entry, array $data, User $actor, ?array $scope = null): Model
    {
        $company = $this->context->company();
        [$scopeColumn, $scopeValue] = $scope ?? ['company_id', $company->id];
        if (array_key_exists('name', $data)) {
            $data['name'] = trim((string) preg_replace('/\s+/u', ' ', (string) $data['name']));
        }

        return DB::transaction(function () use ($entry, $data, $actor, $company, $scopeColumn, $scopeValue): Model {
            if (isset($data['name'])) {
                $duplicate = $entry->newQuery()
                    ->where($scopeColumn, $scopeValue)
                    ->whereRaw('lower(name) = ?', [mb_strtolower($data['name'])])
                    ->when($entry->exists, fn ($query) => $query->whereKeyNot($entry->getKey()))
                    ->exists();
                if ($duplicate) {
                    throw new ApiException(ApiErrorCode::CARWASH_DUPLICATE_NAME, 'Ya existe un registro con ese nombre.', 409, ['errors' => ['name' => ['Ya existe un registro con ese nombre.']]]);
                }
            }

            $before = $entry->exists ? $entry->only(array_keys($data)) : null;
            $isNew = ! $entry->exists;
            $entry->fill([...$data, 'company_id' => $company->id])->save();
            $this->audit->record($actor, $company, 'carwash.'.class_basename($entry).($isNew ? '.created' : '.updated'), $entry, $before, $entry->only(array_keys($data)));
            // A new record comes back as the database keeps it (defaults such as is_active included).
            if ($isNew) {
                $entry->refresh();
            }

            return $entry;
        });
    }
}
