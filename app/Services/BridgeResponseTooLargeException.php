<?php

namespace App\Services;

use RuntimeException;

class BridgeResponseTooLargeException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Bridge response exceeds the permitted size.');
    }
}
