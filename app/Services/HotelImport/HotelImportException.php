<?php

namespace App\Services\HotelImport;

use RuntimeException;

/**
 * Raised for anything the administrator can fix by sending a different file or
 * a different mapping (bad format, empty sheet, too many rows). The controller
 * turns these into 422 responses with the message shown verbatim.
 */
class HotelImportException extends RuntimeException
{
    /**
     * @param  array<string, string|array<int, string>>  $errors
     */
    public function __construct(
        string $message,
        private readonly array $errors = [],
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<string, string|array<int, string>>  $errors
     */
    public static function withErrors(string $message, array $errors): self
    {
        return new self($message, $errors);
    }

    /**
     * @return array<string, string|array<int, string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
