<?php

namespace App\Exceptions;

use Exception;

/**
 * Domain/business rule violation. HTTP 422 in the API layer.
 */
class ValidationException extends Exception
{
    public function __construct(string $message, public readonly ?string $reason = null)
    {
        parent::__construct($message);
    }
}