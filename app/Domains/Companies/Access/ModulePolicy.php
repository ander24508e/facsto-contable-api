<?php

namespace App\Domains\Companies\Access;

use App\Domains\Companies\Context\CompanyContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Base of the policies of a core module: each one asks only for the permissions of ITS module
 * («{module}.view», «{module}.create», «{module}.update»), never by role, and only for records of the active company.
 */
abstract class ModulePolicy
{
    /** Module key of config/permissions.php. */
    protected const MODULE = '';

    public function __construct(protected readonly CompanyContext $context, protected readonly CompanyAccess $access) {}

    public function viewAny(User $user): bool
    {
        return $this->allows($user, 'view');
    }

    public function view(User $user, Model $record): bool
    {
        return $this->allows($user, 'view') && $this->owns($record);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, 'create');
    }

    public function update(User $user, Model $record): bool
    {
        return $this->allows($user, 'update') && $this->owns($record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->update($user, $record);
    }

    protected function allows(User $user, string $action): bool
    {
        return $this->access->allows($user, $this->context->company(), static::MODULE.'.'.$action);
    }

    protected function owns(Model $record): bool
    {
        return (int) $record->getAttribute('company_id') === $this->context->company()->id;
    }
}
