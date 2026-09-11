<?php

declare(strict_types=1);

namespace NixPHP\Auth\Exceptions;

class ForbiddenException extends \RuntimeException
{
    public function __construct(string $message = 'Permission denied.')
    {
        parent::__construct($message, 403);
    }

    /** Lets the framework error handler render 403 instead of a generic 500. */
    public function getStatusCode(): int
    {
        return 403;
    }
}
