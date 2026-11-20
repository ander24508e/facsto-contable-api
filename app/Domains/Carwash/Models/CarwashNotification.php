<?php

namespace App\Domains\Carwash\Models;

use App\Domains\Carwash\Models\Concerns\HasPublicId;
use App\Shared\Realtime\AnnouncesChanges;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Aviso al cliente («vehículo listo» o recordatorio de cita) en la bandeja de la Lavadora. */
#[Fillable(['company_id', 'work_order_id', 'kind', 'channel', 'recipient', 'message', 'status', 'sent_at', 'sent_by'])]
class CarwashNotification extends Model
{
    use AnnouncesChanges;

    public function liveResource(): string
    {
        return 'carwash-notices';
    }

    /** The Lavadora's own channel: only with the module on and one of its permissions. */
    public function liveModule(): ?string
    {
        return 'carwash';
    }

    use HasPublicId;

    public const READY = 'READY';

    public const REMINDER = 'REMINDER';

    protected $table = 'carwash_notifications';

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    protected function casts(): array
    {
        return ['sent_at' => 'datetime'];
    }
}
