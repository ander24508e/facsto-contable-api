<?php

namespace App\Domains\Sales\Actions;

use App\Domains\Sales\DTOs\SaleDraftData;
use App\Domains\Sales\Models\Sale;
use App\Domains\Sales\Services\SaleDraftWriter;
use App\Models\User;

final class CreateSaleDraftAction
{
    public function __construct(private readonly SaleDraftWriter $writer) {}

    public function execute(SaleDraftData $data, User $actor): Sale
    {
        return $this->writer->create($data->toArray(), $actor);
    }
}
