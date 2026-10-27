<?php

namespace Oilstone\SystemStatus;

use Closure;
use Oilstone\SystemStatus\Contracts\Monitor;
use Oilstone\SystemStatus\Exceptions\UnknownCategory;
use Throwable;

class SystemStatus
{
    /**
     * Definitions are stored as given and only built into monitors when a check runs,
     * so registering monitors costs nothing on requests that never check them.
     *
     * @var array<string, list<mixed>>
     */
    protected array $monitors = [];

    public function __construct(protected MonitorFactory $factory = new MonitorFactory())
    {
    }

    /**
     * @param Monitor|Closure|string|array $monitor See MonitorFactory::make()
     */
    public function add(Monitor|Closure|string|array $monitor, string $category = 'default'): static
    {
        $this->monitors[$category][] = $monitor;

        return $this;
    }

    /**
     * @param array<string, list<mixed>> $monitors Definitions keyed by category
     */
    public function addMany(array $monitors): static
    {
        foreach ($monitors as $category => $definitions) {
            foreach ($definitions as $definition) {
                $this->add($definition, (string) $category);
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

    protected function run(mixed $definition, string $category): Result
    {
        try {
            $monitor = $this->factory->make($definition);
        } catch (Throwable $e) {
            return new Result($category, $this->factory->nameFor($definition), Rating::Failed, 0.0, $e);
        }

        $probe = new Probe();
        $error = null;
        $started = hrtime(true);

        try {
            $monitor->check($probe);
        } catch (Throwable $e) {
            $error = $e;
        }

        $duration = (hrtime(true) - $started) / 1e6;

        try {
            $rating = $error === null ? Rating::worst($probe->rating(), $monitor->rate($duration)) : Rating::Failed;
        } catch (Throwable $e) {
            [$rating, $error] = [Rating::Failed, $e];
        }

        return new Result($category, $monitor->name(), $rating, $duration, $error, $probe->notes(), $probe->metadata());
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
