<?php

declare(strict_types = 1);

namespace Tests\Fakes;

use Carbon\CarbonInterval;
use Illuminate\Support\Collection;
use Laravel\Pulse\Contracts\Storage;

/**
 * Pulse storage that keeps every batch of items stored, in order, and answers
 * every query as empty.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
final class PulseStorageFake implements Storage
{
    /** @var list<array<int, mixed>> The batches of items stored, in order. */
    public array $stored = [];

    /**
     * Keep the items.
     *
     * @param  \Illuminate\Support\Collection<int, \Laravel\Pulse\Entry|\Laravel\Pulse\Value>  $items
     * @return void
     */
    #[\Override]
    public function store(Collection $items): void
    {
        $this->stored[] = $items->all();
    }

    /**
     * Trim nothing.
     *
     * @return void
     */
    #[\Override]
    public function trim(): void
    {
        // Nothing is kept beyond the test.
    }

    /**
     * Purge nothing.
     *
     * @param  list<string>|null  $types
     * @return void
     */
    #[\Override]
    public function purge(?array $types = null): void
    {
        // Nothing is kept beyond the test.
    }

    /**
     * Answer with no values.
     *
     * @param  string  $type
     * @param  list<string>|null  $keys
     * @return \Illuminate\Support\Collection<string, object{timestamp: int, key: string, value: string}>
     */
    #[\Override]
    public function values(string $type, ?array $keys = null): Collection
    {
        return new Collection;
    }

    /**
     * Answer with no graph.
     *
     * @param  list<string>  $types
     * @param  'avg'|'count'|'max'|'min'|'sum'  $aggregate
     * @param  \Carbon\CarbonInterval  $interval
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<string, int|null>>>
     */
    #[\Override]
    public function graph(array $types, string $aggregate, CarbonInterval $interval): Collection
    {
        return new Collection;
    }

    /**
     * Answer with no aggregates.
     *
     * @param  string  $type
     * @param  'avg'|'count'|'max'|'min'|'sum'|list<'avg'|'count'|'max'|'min'|'sum'>  $aggregates
     * @param  \Carbon\CarbonInterval  $interval
     * @param  string|null  $orderBy
     * @param  string  $direction
     * @param  int  $limit
     * @return \Illuminate\Support\Collection<int, object{key: string, min?: int, max?: int, sum?: int, avg?: int, count?: int}>
     */
    #[\Override]
    public function aggregate(string $type, array|string $aggregates, CarbonInterval $interval, ?string $orderBy = null, string $direction = 'desc', int $limit = 101): Collection
    {
        return new Collection;
    }

    /**
     * Answer with no aggregates.
     *
     * @param  list<string>|string  $types
     * @param  'avg'|'count'|'max'|'min'|'sum'  $aggregate
     * @param  \Carbon\CarbonInterval  $interval
     * @param  string|null  $orderBy
     * @param  string  $direction
     * @param  int  $limit
     * @return \Illuminate\Support\Collection<int, object>
     */
    #[\Override]
    public function aggregateTypes(
        array|string $types,
        string $aggregate,
        CarbonInterval $interval,
        ?string $orderBy = null,
        string $direction = 'desc',
        int $limit = 101,
    ): Collection {
        return new Collection;
    }

    /**
     * Answer with a total of nothing.
     *
     * @param  list<string>|string  $types
     * @param  'avg'|'count'|'max'|'min'|'sum'  $aggregate
     * @param  \Carbon\CarbonInterval  $interval
     * @return float
     */
    #[\Override]
    public function aggregateTotal(array|string $types, string $aggregate, CarbonInterval $interval): float
    {
        return 0.0;
    }
}
