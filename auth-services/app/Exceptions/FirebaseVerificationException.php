<?php

namespace App\Exceptions;

use RuntimeException;

class FirebaseVerificationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 422
    ) {
        parent::__construct($message, $statusCode);
    }
}

