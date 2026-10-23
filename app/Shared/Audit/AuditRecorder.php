<?php

namespace App\Shared\Audit;

use App\Domains\Companies\Models\Company;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class AuditRecorder
{
    public function __construct(private readonly Request $request) {}

    public function record(User $actor, Company $company, string $action, Model $resource, ?array $before, array $after): void
    {
        DB::table('audit_logs')->insert([
            'company_id' => $company->id,
            'actor_id' => $actor->id,
            'action' => $action,
            'resource_type' => $resource::class,
            'resource_public_id' => $resource->getAttribute('public_id'),
            'before' => $before === null ? null : json_encode($before, JSON_THROW_ON_ERROR),
            'after' => json_encode($after, JSON_THROW_ON_ERROR),
            'ip_address' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 1024),
            'created_at' => now(),
        ]);
    }
}
