<?php

namespace App\Crawling;

use RuntimeException;

final class CrawlBudget
{
    private int $attempts = 0;
    private readonly float $deadline;

    public function __construct(private readonly int $maxAttempts, int $maxDurationSeconds)
    {
        $this->deadline = microtime(true) + $maxDurationSeconds;
    }

    public function consumeFetch(): void
    {
        $this->assertTime();
        if (++$this->attempts > $this->maxAttempts) throw new RuntimeException('Crawl fetch attempt limit reached.');
    }

    public function assertTime(): void
    {
        if (microtime(true) >= $this->deadline) throw new RuntimeException('Crawl duration limit reached.');
    }

    public function remainingSeconds(): int
    {
        $this->assertTime();
        return max(1, (int) ceil($this->deadline - microtime(true)));
    }

    public function attempts(): int { return $this->attempts; }
}
