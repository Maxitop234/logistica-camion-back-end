<?php

namespace App\Services\Iot;

use RuntimeException;

/**
 * Error del flujo IoT con el código HTTP que debe recibir el sensor.
 */
class IotException extends RuntimeException
{
    public function __construct(string $message, public readonly int $estado)
    {
        parent::__construct($message);
    }
}
