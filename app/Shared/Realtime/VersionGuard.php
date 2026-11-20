<?php

namespace App\Shared\Realtime;

use App\Shared\Http\ApiErrorCode;
use App\Shared\Http\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Regla 31 (formularios protegidos): an edit form sends `version`, the `updatedAt` of the record it loaded. If another
 * person saved it since, the save is refused (409 RECORD_CHANGED) instead of silently overwriting their change; the
 * form keeps what was typed and shows the current data. Without `version` (older clients, integrations) nothing changes.
 */
final class VersionGuard
{
    public function assertCurrent(Model $model, mixed $version): void
    {
        if (! is_string($version) || $version === '') {
            return;
        }
        try {
            $sent = CarbonImmutable::parse($version)->getTimestamp();
        } catch (Throwable) {
            throw new ApiException(ApiErrorCode::VALIDATION_FAILED, 'La versión del registro no es válida.', 422, ['errors' => ['version' => ['La versión del registro no es válida.']]]);
        }
        $current = $model->getAttribute('updated_at');
        if ($current !== null && $current->getTimestamp() !== $sent) {
            throw new ApiException(ApiErrorCode::RECORD_CHANGED, 'Otra persona modificó este registro. Revisa sus datos actuales antes de guardar.', 409, ['updatedAt' => $current->toIso8601String()]);
        }
    }
}
