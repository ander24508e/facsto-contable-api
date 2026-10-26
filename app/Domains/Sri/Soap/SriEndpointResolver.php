<?php

namespace App\Domains\Sri\Soap;

use App\Domains\Companies\Fiscal\Enums\SriEnvironment;
use App\Domains\Sri\Enums\SriOperation;
use InvalidArgumentException;

/** The only place that knows SRI URLs. The environment always comes from the document snapshot. */
final class SriEndpointResolver
{
    public function resolve(string $environment, SriOperation $operation): string
    {
        $environment = SriEnvironment::tryFrom($environment)
            ?? throw new InvalidArgumentException('Unknown SRI environment.');
        $key = $operation === SriOperation::RECEPTION ? 'reception' : 'authorization';
        $url = config("sri.endpoints.{$environment->value}.{$key}");
        if (! is_string($url) || ! str_starts_with($url, 'https://')) {
            throw new InvalidArgumentException('The SRI endpoint is not configured.');
        }

        return $url;
    }
}
