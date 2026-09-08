<?php

namespace App\Exceptions;

use RuntimeException;

class OfferUnavailableException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The offer is no longer available.');
    }
}
