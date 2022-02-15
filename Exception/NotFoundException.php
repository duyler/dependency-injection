<?php

namespace Konveyer\DependencyInjection\Exception;

use Exception;

class NotFoundException extends Exception
{
    public function __construct($className)
    {
        $message = 'Instance of ' . $className . ' not found.';
    
        parent::__construct($message);
    }
}
