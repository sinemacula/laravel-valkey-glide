<?php

declare(strict_types = 1);

namespace Tests\Fakes;

/**
 * Cluster client fake that answers `CLUSTER SLOTS` and routed `SCAN` pages from
 * a model of its primaries, the slots each serves and its keys.
 *
 * The model can change between calls, as a failover or a slot migration would
 * change the cluster. A node's `SCAN` cursor is the offset of its next key, and
 * a `SCAN` sent to an address the model does not list answers false, as GLIDE
 * answers a route to a node it has no connection for. A `SCAN` sent to a random
 * node reaches the model's primary when it has only one, as GLIDE's would, and
 * a primary added at `:port` is reported with an empty host, as a node that has
 * never met another reports itself.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 *
 * @inheritable
 */
class ClusterKeyspaceFake extends \ValkeyGlideCluster
{
    /** @var array<string, array{slots: list<array{0: int, 1: int}>, keys: list<string>}> Primaries by address. */
    private array $primaries = [];

    /** @var (\Closure(self): void)|null A change applied to the model when the next SCAN arrives, before it is answered. */
    private ?\Closure $onNextScan = null;

    /** @var array<string, mixed> Replies that replace the model's, by command. */
    private array $replies = [];

    /** @var array<string, \Throwable> Exceptions thrown instead of a reply, by command. */
    private array $exceptions = [];

    /** @var list<array{0: mixed, 1: string, 2: list<mixed>}> The rawcommand() calls received: the route, the command, its arguments. */
    private array $calls = [];

    /** @var list<list<string>> The SCAN calls received: the address routed to, then the arguments. */
    private array $scans = [];

    /** @var array<string, list<array<int|string, mixed>>> The arguments of every other command received, by lowercase name. */
    private array $commands = [];

    /**
     * Record any other command, answering it with 0.
     *
     * @param  string  $name
     * @param  array<int|string, mixed>  $arguments
     * @return mixed
     */
    #[\Override]
    public function __call(string $name, array $arguments): mixed
    {
        $this->commands[strtolower($name)][] = $arguments;

        return 0;
    }

    /**
     * Record that the client was closed.
     *
     * @return bool
     */
    #[\Override]
    public function close(): bool
    {
        $this->commands['close'][] = [];

        return true;
    }

    /**
     * Return the arguments of each call of another command, by its name.
     *
     * @param  string  $name
     * @return list<array<int|string, mixed>>
     */
    public function callsFor(string $name): array
    {
        return $this->commands[strtolower($name)] ?? [];
    }

    /**
     * Add a primary, or replace one, at the given address.
     *
     * @param  string  $address
     * @param  list<array{0: int, 1: int}>  $slots
     * @param  list<string>  $keys
     * @return self
     */
    public function withPrimary(string $address, array $slots, array $keys = []): self
    {
        $this->primaries[$address] = ['slots' => $slots, 'keys' => $keys];

        return $this;
    }

    /**
     * Remove the primary at the given address, as a failover demotes it.
     *
     * @param  string  $address
     * @return self
     */
    public function withoutPrimary(string $address): self
    {
        unset($this->primaries[$address]);

        return $this;
    }

    /**
     * Answer a command, `CLUSTER SLOTS` or `SCAN`, with the given reply rather
     * than the model's.
     *
     * @param  string  $command
     * @param  mixed  $reply
     * @return self
     */
    public function willAnswer(string $command, mixed $reply): self
    {
        $this->replies[$command] = $reply;

        return $this;
    }

    /**
     * Apply a change to the model when the next SCAN arrives, before it is
     * answered, as a topology change between a page's CLUSTER SLOTS and its
     * SCAN would.
     *
     * @param  \Closure(self): void  $change
     * @return self
     */
    public function whenNextScanned(\Closure $change): self
    {
        $this->onNextScan = $change;

        return $this;
    }

    /**
     * Throw the given exception for a command, `CLUSTER SLOTS` or `SCAN`.
     *
     * @param  string  $command
     * @param  \Throwable  $exception
     * @return self
     */
    public function willThrow(string $command, \Throwable $exception): self
    {
        $this->exceptions[$command] = $exception;

        return $this;
    }

    /**
     * Return the rawcommand() calls received, each as its route then its
     * arguments.
     *
     * @return list<array{0: mixed, 1: string, 2: list<mixed>}>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    /**
     * Return the SCAN calls received, each as the address it was routed to,
     * then its arguments after the command.
     *
     * @return list<list<string>>
     */
    public function scans(): array
    {
        return $this->scans;
    }

    /**
     * Answer `CLUSTER SLOTS` or a routed `SCAN` from the model.
     *
     * @param  mixed  $route
     * @param  string  $command
     * @param  mixed  ...$args
     * @return mixed
     *
     * @throws \Throwable
     */
    #[\Override]
    public function rawcommand(mixed $route, string $command, mixed ...$args): mixed
    {
        $args          = array_values($args);
        $name          = $command === 'CLUSTER' && ($args[0] ?? null) === 'SLOTS' ? 'CLUSTER SLOTS' : $command;
        $this->calls[] = [$route, $command, $args];

        if ($name === 'SCAN') {
            $this->receiveScan($route, $args);
        }

        if (array_key_exists($name, $this->exceptions)) {
            throw $this->exceptions[$name];
        }

        if (array_key_exists($name, $this->replies)) {
            return $this->replies[$name];
        }

        return match ($name) {
            'CLUSTER SLOTS' => $this->slotsReply(),
            'SCAN'          => $this->scanReply($this->address($route), $args),
            default         => self::refuse($command),
        };
    }

    /**
     * Record a SCAN and apply the change awaiting it, if any.
     *
     * @param  mixed  $route
     * @param  list<mixed>  $args
     * @return void
     */
    private function receiveScan(mixed $route, array $args): void
    {
        $this->scans[] = [$this->address($route), ...array_map(static fn (mixed $arg): string => is_string($arg) ? $arg : '', $args)];
        $change        = $this->onNextScan;

        $this->onNextScan = null;

        if ($change === null) {
            return;
        }

        $change($this);
    }

    /**
     * Refuse a raw command the model does not answer.
     *
     * @param  string  $command
     * @return never
     *
     * @throws \LogicException
     */
    private static function refuse(string $command): never
    {
        throw new \LogicException(sprintf('Unexpected rawcommand [%s].', $command));
    }

    /**
     * Build the `CLUSTER SLOTS` reply of the model, as GLIDE returns it: each
     * range with its primary's host, port, id and metadata, by first slot.
     *
     * @return list<array<int, mixed>>
     */
    private function slotsReply(): array
    {
        $ranges = [];

        foreach ($this->primaries as $address => $primary) {
            $separator     = (int) strrpos($address, ':');
            [$host, $port] = [substr($address, 0, $separator), substr($address, $separator + 1)];

            foreach ($primary['slots'] as [$start, $end]) {
                $ranges[] = [$start, $end, [$host, (int) $port, sha1($address), []]];
            }
        }

        usort($ranges, static fn (array $first, array $second): int => $first[0] <=> $second[0]);

        return $ranges;
    }

    /**
     * Answer a page of a primary's keys matching the MATCH pattern, COUNT at a
     * time (ten by default), from the offset the cursor names, or false for an
     * address the model does not list.
     *
     * @param  string  $address
     * @param  list<mixed>  $args
     * @return array{0: string, 1: list<string>}|false
     */
    private function scanReply(string $address, array $args): array|false
    {
        if (!isset($this->primaries[$address])) {
            return false;
        }

        $arguments = array_map(static fn (mixed $arg): string => is_string($arg) ? $arg : '', $args);
        $options   = [];

        foreach (array_chunk(array_slice($arguments, 1), 2) as $option) {
            $options[$option[0]] = $option[1] ?? '';
        }

        $offset = (int) ($arguments[0] ?? '0');
        $count  = (int) ($options['COUNT'] ?? '10');
        $keys   = $this->primaries[$address]['keys'];
        $next   = $offset + $count < count($keys) ? (string) ($offset + $count) : '0';
        $match  = $options['MATCH'] ?? '*';

        return [$next, array_values(array_filter(array_slice($keys, $offset, $count), static fn (string $key): bool => fnmatch($match, $key)))];
    }

    /**
     * Read the address a route reaches: the address a route by address names,
     * the model's only primary for a random node, or an empty string.
     *
     * @param  mixed  $route
     * @return string
     */
    private function address(mixed $route): string
    {
        if ($route === 'randomNode' && count($this->primaries) === 1) {
            return array_key_first($this->primaries);
        }

        if (!is_array($route) || ($route['type'] ?? null) !== 'routeByAddress' || !is_string($route['host'] ?? null) || !is_int($route['port'] ?? null)) {
            return '';
        }

        return $route['host'] . ':' . $route['port'];
    }
}
