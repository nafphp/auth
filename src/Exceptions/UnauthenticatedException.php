<?php

declare(strict_types=1);

namespace NixPHP\Auth\Exceptions;

class UnauthenticatedException extends \RuntimeException
{
    public function __construct(string $message = 'Authentication required.')
    {
        parent::__construct($message, 401);
    }

    /** Lets the framework error handler render 401 instead of a generic 500. */
    public function getStatusCode(): int
    {
        return 401;
    }
}
