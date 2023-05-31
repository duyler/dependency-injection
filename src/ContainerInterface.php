<?php

declare(strict_types=1);

namespace Duyler\DependencyInjection;

use Psr\Container\ContainerInterface as PsrContainerInterface;

interface ContainerInterface extends PsrContainerInterface
{
    public function make(string $className, string $provider = '', bool $singleton = true): mixed;
    public function bind(array $classMap): void;

    public function setProviders(array $providers): void;
}
