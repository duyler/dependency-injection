<?php

declare(strict_types=1);

namespace Duyler\DependencyInjection;

use Duyler\DependencyInjection\Exception\CircularReferenceException;
use Duyler\DependencyInjection\Exception\InterfaceMapNotFoundException;
use Duyler\DependencyInjection\Provider\ProviderInterface;
use Duyler\DependencyInjection\Storage\ProviderArgumentsStorage;
use Duyler\DependencyInjection\Storage\ProviderStorage;
use Duyler\DependencyInjection\Storage\ReflectionStorage;
use Duyler\DependencyInjection\Storage\ServiceStorage;
use ReflectionClass;
use ReflectionMethod;

class DependencyMapper
{
    private array $classMap = [];
    private array $dependencies = [];

    public function __construct(
        private readonly ReflectionStorage $reflectionStorage,
        private readonly ServiceStorage $serviceStorage,
        private readonly ProviderStorage $providerStorage,
        private readonly ProviderArgumentsStorage $argumentsStorage,
        private readonly ContainerService $containerService,
    ) {}

    public function bind(array $classMap): void
    {
        $this->classMap = $classMap + $this->classMap;
    }

    public function getClassMap(): array
    {
        return $this->classMap;
    }

    public function getBind(string $interface): string
    {
        if ($this->providerStorage->has($interface)) {
            $provider = $this->providerStorage->get($interface);
            $this->classMap = $provider->bind() + $this->classMap;
            if (isset($this->classMap[$interface])) {
                $this->providerStorage->add($this->classMap[$interface], $provider);
            }
        }

        return $this->classMap[$interface] ?? throw new InterfaceMapNotFoundException($interface);
    }

    public function resolve(string $className): array
    {
        $this->dependencies = [];
        $this->prepareDependencies($className);

        return $this->dependencies;
    }

    private function prepareDependencies(string $className): void
    {
        if (!$this->reflectionStorage->has($className)) {
            $this->reflectionStorage->set($className, new ReflectionClass($className));
        }

        $constructor = $this->reflectionStorage->get($className)->getConstructor();

        if (null !== $constructor && false === $this->serviceStorage->has($className)) {
            if ($this->providerStorage->has($className)) {
                $provider = $this->providerStorage->get($className);
                $arguments = $this->prepareProviderArguments($provider, $className);
                if (count($constructor->getParameters()) === count($arguments)) {
                    $this->dependencies[$className] = [];
                    return;
                }
            }
            $this->buildDependencies($constructor, $className);
        }
    }

    private function buildDependencies(ReflectionMethod $constructor, string $className): void
    {
        foreach ($constructor->getParameters() as $param) {
            $type = $param->getType();

            if (null === $type) {
                continue;
            }

            $paramClassName = $type->getName();

            if (false === class_exists($paramClassName)
                && false === interface_exists($paramClassName)
                || enum_exists($paramClassName)
            ) {
                continue;
            }

            $this->reflectionStorage->set($paramClassName, new ReflectionClass($paramClassName));

            $class = $this->reflectionStorage->get($paramClassName);

            $paramArgClassName = $param->getName();

            if ($this->providerStorage->has($className)) {
                $provider = $this->providerStorage->get($className);

                if (array_key_exists($paramArgClassName, $this->prepareProviderArguments($provider, $className))) {
                    continue;
                }
            }

            if ($class->isInterface()) {
                $this->prepareInterface($class, $className, $paramArgClassName);
                continue;
            }

            $depClassName = $class->getName();

            $this->resolveDependency($className, $depClassName, $paramArgClassName);
        }
    }

    private function prepareProviderArguments(ProviderInterface $provider, string $className): array
    {
        $arguments = $provider->getArguments($this->containerService);
        $this->argumentsStorage->set($className, $arguments);
        return $arguments;
    }

    /**
     * @throws InterfaceMapNotFoundException
     */
    private function prepareInterface(ReflectionClass $interface, string $className, string $depArgName): void
    {
        $depInterfaceName = $interface->getName();

        if ($this->providerStorage->has($className)) {
            $provider = $this->providerStorage->get($className);
            $this->classMap[$depInterfaceName] ??= $provider->bind()[$depInterfaceName] ?? null;
        }

        if ($this->providerStorage->has($depInterfaceName)) {
            $provider = $this->providerStorage->get($depInterfaceName);
            $this->classMap[$depInterfaceName] ??= $provider->bind()[$depInterfaceName] ?? null;
        }

        if (!isset($this->classMap[$depInterfaceName])) {
            throw new InterfaceMapNotFoundException($depInterfaceName, $className);
        }

        $depClassName = $this->classMap[$depInterfaceName];

        $this->resolveDependency($className, $depClassName, $depArgName);
    }

    /**
     * @throws CircularReferenceException
     */
    private function resolveDependency(string $className, string $depClassName, string $depArgName): void
    {
        if (isset($this->dependencies[$depClassName][$className])) {
            throw new CircularReferenceException($className, $depClassName);
        }

        $this->dependencies[$className][$depArgName] = $depClassName;
        $this->prepareDependencies($depClassName);
    }
}
