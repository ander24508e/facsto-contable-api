<?php

namespace App\Domains\Carwash\Models;

use App\Domains\Carwash\Models\Concerns\HasPublicId;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Foto, video o firma de la recepción de una orden; el archivo vive en el disco privado y no se edita. */
#[Fillable(['company_id', 'work_order_id', 'kind', 'path', 'mime', 'size_bytes', 'sha256', 'note', 'taken_at', 'expires_at', 'uploaded_by'])]
class WorkOrderEvidence extends Model
{
    use HasPublicId;

    public const PHOTO = 'PHOTO';

    public const VIDEO = 'VIDEO';

    public const SIGNATURE = 'SIGNATURE';

    /** Días que se guardan los archivos, salvo una orden «En reclamo». */
    public const RETENTION_DAYS = 90;

    protected $table = 'carwash_work_order_evidences';

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    protected function casts(): array
    {
        return ['size_bytes' => 'integer', 'taken_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
