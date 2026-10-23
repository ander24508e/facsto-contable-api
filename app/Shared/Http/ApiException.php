<?php

namespace App\Shared\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class ApiException extends RuntimeException
{
    public function __construct(
        public readonly ApiErrorCode $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $this->errorCode->value,
                'message' => $this->getMessage(),
                'details' => (object) $this->details,
            ],
        ], $this->status);
    }
}
