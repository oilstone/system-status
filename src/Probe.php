<?php

namespace Oilstone\SystemStatus;

use Oilstone\SystemStatus\Exceptions\MonitorFailed;

/**
 * Handed to a monitor's check so it can report more than pass/fail: a worse
 * rating than its response time alone would earn, notes and metadata.
 */
final class Probe
{
    private Rating $rating = Rating::Optimal;

    /** @var list<string> */
    private array $notes = [];

    /** @var array<string, mixed> */
    private array $meta = [];

    /**
     * Ratings only ever get worse; a later, better rating is ignored.
     */
    public function rate(Rating $rating, ?string $note = null): self
    {
        $this->rating = Rating::worst($this->rating, $rating);

        if ($note !== null) {
            $this->notes[] = $note;
        }

        return $this;
    }

    public function degrade(string $note): self
    {
        return $this->rate(Rating::Degraded, $note);
    }

    public function note(string $note): self
    {
        $this->notes[] = $note;

        return $this;
    }

    public function meta(string $key, mixed $value): self
    {
        $this->meta[$key] = $value;

        return $this;
    }

    public function fail(string $message): never
    {
        throw new MonitorFailed($message);
    }

    public function rating(): Rating
    {
        return $this->rating;
    }

    /**
     * @return list<string>
     */
    public function notes(): array
    {
        return $this->notes;
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->meta;
    }
}
