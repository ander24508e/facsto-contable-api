<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DocumentArtifactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'publicId' => $this->public_id,
            'type' => $this->type->value,
            'version' => $this->version,
            'sha256' => $this->sha256,
            'sizeBytes' => $this->size_bytes,
            'mimeType' => $this->mime_type,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
