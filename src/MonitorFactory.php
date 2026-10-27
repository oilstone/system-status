<?php

namespace Oilstone\SystemStatus;

use Closure;
use Oilstone\SystemStatus\Contracts\Monitor;
use Oilstone\SystemStatus\Exceptions\InvalidMonitorConfiguration;
use Oilstone\SystemStatus\Exceptions\UnknownMonitorType;
use Oilstone\SystemStatus\Monitors\Cache;
use Oilstone\SystemStatus\Monitors\Callback;
use Oilstone\SystemStatus\Monitors\File;
use Oilstone\SystemStatus\Monitors\Http;
use Oilstone\SystemStatus\Monitors\OAuthToken;
use Oilstone\SystemStatus\Monitors\Pdo;
use ReflectionClass;

class MonitorFactory
{
    /**
     * @var array<string, class-string<Monitor>|Closure(array): Monitor>
     */
    protected array $types = [
        'cache' => Cache::class,
        'callback' => Callback::class,
        'file' => File::class,
        'http' => Http::class,
        'oauth_token' => OAuthToken::class,
        'pdo' => Pdo::class,
    ];

    /**
     * @param class-string<Monitor>|Closure(array): Monitor $resolver A class constructed with the
     *        definition's options, or a closure given the options that returns a monitor
     */
    public function extend(string $type, string|Closure $resolver): static
    {
        $this->types[$this->normaliseType($type)] = $resolver;

        return $this;
    }

    public function hasType(string $type): bool
    {
        return isset($this->types[$this->normaliseType($type)]);
    }

    /**
     * Definitions can be a monitor, a closure returning one, a monitor class name, or an
     * array of options with a "class" or "type" key.
     */
    public function make(mixed $definition): Monitor
    {
        if ($definition instanceof Monitor) {
            return $definition;
        }

        if ($definition instanceof Closure) {
            return $this->ensureMonitor($definition(), 'Closure definition');
        }

        if (is_string($definition)) {
            return $this->instantiate($definition, []);
        }

        if (is_array($definition) && isset($definition['class'])) {
            return $this->instantiate($definition['class'], $this->options($definition));
        }

        if (is_array($definition) && isset($definition['type'])) {
            return $this->resolveType((string) $definition['type'], $this->options($definition));
        }

        throw UnknownMonitorType::forDefinition($definition);
    }

    /**
     * Best available name for a definition that could not be built into a monitor.
     */
    public function nameFor(mixed $definition): string
    {
        if ($definition instanceof Monitor) {
            return $definition->name();
        }

        if (is_array($definition) && isset($definition['name'])) {
            return (string) $definition['name'];
        }

        $class = match (true) {
            is_string($definition) => $definition,
            is_array($definition) && isset($definition['class']) => (string) $definition['class'],
            is_array($definition) && isset($definition['type']) => $this->classForType((string) $definition['type']),
            default => null,
        };

        return $this->defaultNameOf($class)
            ?? (is_array($definition) ? ($definition['class'] ?? $definition['type'] ?? null) : $class)
            ?? 'Unnamed monitor';
    }

    protected function resolveType(string $type, array $options): Monitor
    {
        $resolver = $this->types[$this->normaliseType($type)] ?? throw UnknownMonitorType::forType($type);

        if ($resolver instanceof Closure) {
            return $this->ensureMonitor($resolver($options), sprintf('Resolver for type "%s"', $type));
        }

        return $this->instantiate($resolver, $options);
    }

    protected function instantiate(string $class, array $options): Monitor
    {
        if (! is_subclass_of($class, Monitor::class)) {
            throw UnknownMonitorType::forClass($class);
        }

        return new $class($options);
    }

    protected function ensureMonitor(mixed $monitor, string $source): Monitor
    {
        if (! $monitor instanceof Monitor) {
            throw InvalidMonitorConfiguration::invalid($source, 'it returned ' . get_debug_type($monitor) . ' rather than a monitor');
        }

        return $monitor;
    }

    protected function classForType(string $type): ?string
    {
        $resolver = $this->types[$this->normaliseType($type)] ?? null;

        return is_string($resolver) ? $resolver : null;
    }

    /**
     * The $name a monitor class declares, without constructing it.
     */
    protected function defaultNameOf(?string $class): ?string
    {
        if ($class === null || ! is_subclass_of($class, Monitor::class)) {
            return null;
        }

        $name = (new ReflectionClass($class))->getDefaultProperties()['name'] ?? null;

        return is_string($name) && $name !== '' ? $name : null;
    }

    protected function options(array $definition): array
    {
        unset($definition['class'], $definition['type']);

        return $definition;
    }

    protected function normaliseType(string $type): string
    {
        return strtolower(str_replace(['-', ' '], '_', $type));
    }
}
