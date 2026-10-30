<?php

namespace Oilstone\SystemStatus;

use Closure;
use Oilstone\SystemStatus\Contracts\Monitor;
use Oilstone\SystemStatus\Exceptions\UnknownCategory;
use Oilstone\SystemStatus\Exceptions\UnknownMonitor;
use Throwable;

class SystemStatus
{
    /**
     * Definitions are stored as given and only built into monitors when a check runs,
     * so registering monitors costs nothing on requests that never check them. Keys are
     * the monitors' keys within their category, which make up their ids.
     *
     * @var array<string, array<int|string, mixed>>
     */
    protected array $monitors = [];

    public function __construct(protected MonitorFactory $factory = new MonitorFactory())
    {
    }

    /**
     * @param Monitor|Closure|string|array $monitor See MonitorFactory::make()
     * @param string|null $key Gives the monitor the id "{category}.{key}", so watches and the dashboard can refer
     *                         to it, and replaces any monitor with that key; numbered in order when null
     */
    public function add(Monitor|Closure|string|array $monitor, string $category = 'default', ?string $key = null): static
    {
        if ($key === null) {
            $this->monitors[$category][] = $monitor;
        } else {
            $this->monitors[$category][$key] = $monitor;
        }

        return $this;
    }

    /**
     * @param array<string, array<int|string, mixed>> $monitors Definitions keyed by category, and by key where they have one
     */
    public function addMany(array $monitors): static
    {
        foreach ($monitors as $category => $definitions) {
            foreach ($definitions as $key => $definition) {
                $this->add($definition, (string) $category, is_string($key) ? $key : null);
            }
        }

        return $this;
    }

    /**
     * @param class-string<Monitor>|Closure(array): Monitor $resolver
     */
    public function extend(string $type, string|Closure $resolver): static
    {
        $this->factory->extend($type, $resolver);

        return $this;
    }

    /**
     * @return list<string>
     */
    public function categories(): array
    {
        return array_keys($this->monitors);
    }

    public function has(string $category): bool
    {
        return isset($this->monitors[$category]);
    }

    public function factory(): MonitorFactory
    {
        return $this->factory;
    }

    /**
     * The definitions in each category, in the order check() runs them and keyed as added, without building them.
     *
     * @param list<string>|string|null $categories All categories when null
     * @return array<string, array<int|string, mixed>>
     *
     * @throws UnknownCategory
     */
    public function definitions(array|string|null $categories = null): array
    {
        $definitions = [];

        foreach ($this->resolveCategories($categories) as $category) {
            $definitions[$category] = $this->monitors[$category];
        }

        return $definitions;
    }

    /**
     * Whether a monitor has this id ("{category}.{key}").
     */
    public function hasMonitor(string $id): bool
    {
        return $this->locate($id) !== null;
    }

    /**
     * Builds a monitor from a definition, or from the id of a monitor added here.
     *
     * @param Monitor|Closure|string|array $monitor See MonitorFactory::make()
     */
    public function make(Monitor|Closure|string|array $monitor): Monitor
    {
        $location = is_string($monitor) ? $this->locate($monitor) : null;

        return $this->factory->make($location === null ? $monitor : $this->monitors[$location[0]][$location[1]]);
    }

    /**
     * Checks the monitor with this id on its own. Like check(), a failure becomes a failed result.
     *
     * @throws UnknownMonitor
     */
    public function checkMonitor(string $id): Result
    {
        [$category, $key] = $this->locate($id) ?? throw UnknownMonitor::make($id);

        return $this->run($this->monitors[$category][$key], $category);
    }

    /**
     * @param list<string>|string|null $categories All categories when null
     * @param Rating|null $stopAt Skip the remaining monitors once one is rated this badly
     *
     * @throws UnknownCategory
     */
    public function check(array|string|null $categories = null, ?Rating $stopAt = null): Report
    {
        $results = [];

        foreach ($this->resolveCategories($categories) as $category) {
            foreach ($this->monitors[$category] as $definition) {
                $results[] = $result = $this->run($definition, $category);

                if ($stopAt !== null && $result->rating->isAtLeast($stopAt)) {
                    return new Report($results);
                }
            }
        }

        return new Report($results);
    }

    /**
     * @param list<string>|string|null $categories All categories when null
     *
     * @throws UnknownCategory
     */
    public function passes(array|string|null $categories = null, Rating $failAt = Rating::Failed): bool
    {
        return $this->check($categories, $failAt)->passes($failAt);
    }

    /**
     * Builds, checks and rates one definition, turning any failure into a failed result.
     */
    public function run(mixed $definition, string $category): Result
    {
        try {
            $monitor = $this->factory->make($definition);
        } catch (Throwable $e) {
            return new Result($category, $this->factory->nameFor($definition), Rating::Failed, 0.0, $e);
        }

        return Probe::run($monitor, $category);
    }

    /**
     * The category and key of a monitor id. Categories may contain dots, so each is tried.
     *
     * @return array{0: string, 1: int|string}|null
     */
    protected function locate(string $id): ?array
    {
        foreach ($this->monitors as $category => $definitions) {
            $prefix = $category . '.';

            if (str_starts_with($id, $prefix) && array_key_exists($key = substr($id, strlen($prefix)), $definitions)) {
                return [(string) $category, $key];
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    protected function resolveCategories(array|string|null $categories): array
    {
        if ($categories === null) {
            return $this->categories();
        }

        $categories = array_values(array_unique(array_filter(
            array_map(fn (mixed $category) => trim((string) $category), (array) $categories),
            fn (string $category) => $category !== '',
        )));

        if ($categories === []) {
            throw UnknownCategory::none();
        }

        $unknown = array_values(array_diff($categories, $this->categories()));

        if ($unknown) {
            throw UnknownCategory::make($unknown);
        }

        return $categories;
    }
}
