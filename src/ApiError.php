<?php

namespace Cmdb;

final class ApiError extends \RuntimeException
{
    public function __construct(public int $status, string $message, public array $fields = [])
    {
        parent::__construct($message);
    }
}
