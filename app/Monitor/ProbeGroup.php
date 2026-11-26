<?php

namespace App\Monitor;

/**
 * Outcome of one family of probes (e.g. "external ICMP") within a cycle.
 * A stale result counts as not attempted: an old success proves nothing
 * about trouble that began after it.
 */
final class ProbeGroup
{
    /** @param array<string, float|null> $results target => RTT in ms, or null when it failed */
    public function __construct(public readonly array $results = [], public readonly bool $fresh = true) {}

    public static function none(): self
    {
        return new self([], false);
    }

    public function attempted(): int
    {
        return $this->fresh ? count($this->results) : 0;
    }

    public function succeeded(): int
    {
        return $this->fresh ? count(array_filter($this->results, fn ($r) => $r !== null)) : 0;
    }

    public function failed(): int
    {
        return $this->attempted() - $this->succeeded();
    }

    public function allSucceeded(): bool
    {
        return $this->attempted() > 0 && $this->failed() === 0;
    }

    public function allFailed(): bool
    {
        return $this->attempted() > 0 && $this->succeeded() === 0;
    }

    public function someFailed(): bool
    {
        return $this->failed() > 0 && $this->succeeded() > 0;
    }

    public function anySuccess(): bool
    {
        return $this->succeeded() > 0;
    }

    /** @return list<string> */
    public function failedTargets(): array
    {
        return $this->fresh ? array_keys(array_filter($this->results, fn ($r) => $r === null)) : [];
    }

    public function averageRtt(): ?float
    {
        $ok = $this->fresh ? array_filter($this->results, fn ($r) => $r !== null) : [];

        return $ok ? array_sum($ok) / count($ok) : null;
    }

    public function summary(): string
    {
        return $this->succeeded().'/'.$this->attempted();
    }
}
