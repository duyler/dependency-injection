<?php

declare(strict_types=1);

namespace Duyler\DependencyInjection;

use Duyler\DependencyInjection\Exception\EndlessException;
use Duyler\DependencyInjection\Exception\InterfaceMapNotFoundException;
use Duyler\DependencyInjection\Provider\ProviderInterface;
use ReflectionClass;
use ReflectionMethod;

class DependencyMapper
{
    private ReflectionStorage $reflectionStorage;
    private array $classMap = [];
    private array $dependencies = [];
    private array $providers = [];

    public function __construct(ReflectionStorage $reflectionStorage)
    {
        $this->reflectionStorage = $reflectionStorage;
    }

    public function bind(array $classMap): void
    {
        $this->classMap = $classMap + $this->classMap;
    }

    public function addProvider(string $id, ProviderInterface $provider): void
    {
        $this->providers[$id] = $provider;
    }

    public function getBind(string $interface): string
    {
        if (isset($this->classMap[$interface])) {
            return $this->classMap[$interface];
        }
        throw new InterfaceMapNotFoundException($interface);
    }

    public function resolve(string $className): array
    {
        $this->prepareDependencies($className);
        return $this->dependencies;
    }

    protected function prepareDependencies(string $className): void
    {
        if (isset($this->trees[$className])) {
            $this->dependencies = $this->trees[$className];
            return;
        }

        if (!$this->reflectionStorage->has($className)) {
            $this->reflectionStorage->set($className, new ReflectionClass($className));
        }

        if ($this->reflectionStorage->get($className)->isInterface()) {
            $this->prepareInterface($this->reflectionStorage->get($className), $className);
        }

        $constructor = $this->reflectionStorage->get($className)->getConstructor();

        if ($constructor !== null) {
            $this->buildDependencies($constructor, $className);
        }
    }

    protected function buildDependencies(ReflectionMethod $constructor, string $className): void
    {
        foreach ($constructor->getParameters() as $param) {

            $type = $param->getType();

            if ($type === null) {
                continue;
            }

            $paramClassName = $type->getName();

            if (class_exists($paramClassName) === false && interface_exists($paramClassName) === false) {
                continue;
            }
            
            $this->reflectionStorage->set($paramClassName, new ReflectionClass($paramClassName));

            $class = $this->reflectionStorage->get($paramClassName);

            $paramArgClassName = $param->getName();

            if (null !== $class) {

                if ($class->isInterface()) {

                    $this->prepareInterface($class, $className, $paramArgClassName);
                    continue;
                }

                $depClassName = $class->getName();

                $this->resolveDependency($className, $depClassName, $paramArgClassName);
            }
        }
    }

    protected function prepareInterface(ReflectionClass $interface, string $className, string $depArgName = ''): void
    {
        $depInterfaceName = $interface->getName();

        $this->classMap[$depInterfaceName] ??= $this->providers[$className]?->bind()[$depInterfaceName];

        if (!isset($this->classMap[$depInterfaceName])) {
            throw new InterfaceMapNotFoundException($depInterfaceName);
        }

        $depClassName = $this->classMap[$depInterfaceName];

        $this->resolveDependency($className, $depClassName, $depArgName);
    }

    protected function resolveDependency(string $className, string $depClassName, string $depArgName = ''): void
    {
        if (isset($this->dependencies[$depClassName][$className])) {
            throw new EndlessException($className, $depClassName);
        }

        $this->dependencies[$className][$depArgName] = $depClassName;
        $this->prepareDependencies($depClassName);
    }
}
