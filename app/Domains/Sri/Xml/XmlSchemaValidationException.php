<?php

namespace App\Domains\Sri\Xml;

use LibXMLError;
use RuntimeException;

final class XmlSchemaValidationException extends RuntimeException
{
    /** @param list<array{line: int, column: int, message: string}> $errors */
    public function __construct(public readonly array $errors)
    {
        parent::__construct('The invoice XML does not match the official SRI schema.');
    }

    /** @param list<LibXMLError> $errors */
    public static function fromErrors(array $errors): self
    {
        return new self(array_values(array_map(static fn (LibXMLError $error): array => [
            'line' => $error->line,
            'column' => $error->column,
            'message' => trim($error->message),
        ], $errors)));
    }
}
