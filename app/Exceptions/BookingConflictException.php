<?php

namespace App\Exceptions;

use Exception;

/**
 * Thrown when a requested room/window collides with an active allocation.
 * HTTP 409 in the API layer.
 */
class BookingConflictException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?string $reason = 'ROOM_CONFLICT',
        ?Exception $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}