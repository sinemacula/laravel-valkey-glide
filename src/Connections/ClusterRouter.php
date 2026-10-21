<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Connections;

use SineMacula\Valkey\Support\Cast;

/**
 * Resolve the route for a raw command sent to a GLIDE cluster client.
 *
 * `ValkeyGlideCluster::rawcommand()` takes a route before the command, which
 * Laravel's `executeRaw()` does not carry. The tables below are drawn from
 * `base_routing()` in GLIDE's `cluster_routing.rs`, but the route is always a
 * single one: GLIDE splits multi-key commands such as `MGET`, `DEL` and `MSET`
 * across shards only when it picks the route itself, and the extension has no
 * route that asks for that. A multi-key command goes whole to its first key's
 * primary, so all of its keys must share a slot. Subcommands GLIDE does not
 * list, which it routes by the subcommand's name as if it were a key, go to a
 * random node.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final readonly class ClusterRouter
{
    /** @var array<string, string> Commands GLIDE runs on every node or every primary, combining the replies. */
    private const array FAN_OUT_ROUTES = [
        'ACL DELUSER'          => 'allNodes',
        'ACL SAVE'             => 'allNodes',
        'ACL SETUSER'          => 'allNodes',
        'AUTH'                 => 'allNodes',
        'BGREWRITEAOF'         => 'allPrimaries',
        'BGSAVE'               => 'allPrimaries',
        'CLIENT PAUSE'         => 'allPrimaries',
        'CLIENT SETINFO'       => 'allNodes',
        'CLIENT SETNAME'       => 'allNodes',
        'CLIENT UNPAUSE'       => 'allPrimaries',
        'CONFIG RESETSTAT'     => 'allNodes',
        'CONFIG REWRITE'       => 'allNodes',
        'CONFIG SET'           => 'allNodes',
        'DBSIZE'               => 'allPrimaries',
        'DEBUG'                => 'allPrimaries',
        'FLUSHALL'             => 'allPrimaries',
        'FLUSHDB'              => 'allPrimaries',
        'FT._ALIASLIST'        => 'allPrimaries',
        'FT._LIST'             => 'allPrimaries',
        'FUNCTION DELETE'      => 'allPrimaries',
        'FUNCTION FLUSH'       => 'allPrimaries',
        'FUNCTION KILL'        => 'allNodes',
        'FUNCTION LOAD'        => 'allPrimaries',
        'FUNCTION RESTORE'     => 'allPrimaries',
        'FUNCTION STATS'       => 'allNodes',
        'INFO'                 => 'allPrimaries',
        'KEYS'                 => 'allPrimaries',
        'LATENCY DOCTOR'       => 'allPrimaries',
        'LATENCY GRAPH'        => 'allPrimaries',
        'LATENCY HISTOGRAM'    => 'allPrimaries',
        'LATENCY HISTORY'      => 'allPrimaries',
        'LATENCY LATEST'       => 'allPrimaries',
        'LATENCY RESET'        => 'allPrimaries',
        'MEMORY DOCTOR'        => 'allPrimaries',
        'MEMORY MALLOC-STATS'  => 'allPrimaries',
        'MEMORY PURGE'         => 'allPrimaries',
        'MEMORY STATS'         => 'allPrimaries',
        'PING'                 => 'allPrimaries',
        'PUBSUB CHANNELS'      => 'allNodes',
        'PUBSUB NUMPAT'        => 'allNodes',
        'PUBSUB NUMSUB'        => 'allNodes',
        'PUBSUB SHARDCHANNELS' => 'allNodes',
        'PUBSUB SHARDNUMSUB'   => 'allNodes',
        'RANDOMKEY'            => 'allPrimaries',
        'RESET'                => 'allNodes',
        'SAVE'                 => 'allPrimaries',
        'SCRIPT EXISTS'        => 'allPrimaries',
        'SCRIPT FLUSH'         => 'allNodes',
        'SCRIPT KILL'          => 'allNodes',
        'SCRIPT LOAD'          => 'allNodes',
        'SELECT'               => 'allNodes',
        'SLOWLOG GET'          => 'allNodes',
        'SLOWLOG LEN'          => 'allNodes',
        'SLOWLOG RESET'        => 'allNodes',
        'UNWATCH'              => 'allPrimaries',
        'WAIT'                 => 'allPrimaries',
        'WAITAOF'              => 'allPrimaries',
    ];

    /** @var list<string> Commands GLIDE sends to the primary that owns the slot number in their second argument. */
    private const array SLOT_COMMANDS = [
        'CLUSTER ADDSLOTS',
        'CLUSTER COUNTKEYSINSLOT',
        'CLUSTER DELSLOTS',
        'CLUSTER DELSLOTSRANGE',
        'CLUSTER GETKEYSINSLOT',
        'CLUSTER SETSLOT',
    ];

    /** @var array<string, int|null> Commands whose first key is not their first argument, by its index, or null when keyless. */
    private const array KEY_INDEXES = [
        'ACL'        => null,
        'BITOP'      => 1,
        'CLIENT'     => null,
        'CLUSTER'    => null,
        'COMMAND'    => null,
        'CONFIG'     => null,
        'ECHO'       => null,
        'FUNCTION'   => null,
        'JSON.DEBUG' => 1,
        'LATENCY'    => null,
        'LOLWUT'     => null,
        'MEMORY'     => 1,
        'MIGRATE'    => 2,
        'MODULE'     => null,
        'OBJECT'     => 1,
        'PFDEBUG'    => 1,
        'PUBSUB'     => null,
        'SCRIPT'     => null,
        'SENTINEL'   => null,
        'SLOWLOG'    => null,
        'XGROUP'     => 1,
        'XINFO'      => 1,
    ];

    /** @var array<string, int> Commands that declare their key count, by the count's index; the keys follow it. */
    private const array KEY_COUNT_INDEXES = [
        'BLMPOP'     => 1,
        'BZMPOP'     => 1,
        'EVAL'       => 1,
        'EVALSHA'    => 1,
        'EVALSHA_RO' => 1,
        'EVAL_RO'    => 1,
        'FCALL'      => 1,
        'FCALL_RO'   => 1,
        'LMPOP'      => 0,
        'SINTERCARD' => 0,
        'ZDIFF'      => 0,
        'ZINTER'     => 0,
        'ZINTERCARD' => 0,
        'ZMPOP'      => 0,
        'ZUNION'     => 0,
    ];

    /**
     * Resolve the cluster route for raw command parameters that lead with the
     * command name.
     *
     * A keyed command goes to the primary that owns its first key's slot, so
     * every key of a multi-key command must share that slot. GLIDE cannot parse
     * a null route, so a keyless command, or one whose key is missing or empty,
     * goes to `randomNode`. So does a key that is not valid UTF-8, as
     * `routeKey()` describes.
     *
     * A cursor `SCAN` is refused, since it walks one node's keys and each call
     * may land on a different node. So are the slot commands, such as `CLUSTER
     * COUNTKEYSINSLOT`, which inspect the receiving node's keys without a
     * `MOVED` redirect, since GLIDE's PHP routes cannot name a slot.
     *
     * @param  array<array-key, mixed>  $parameters
     * @return array{type: string, key: string}|string
     *
     * @throws \InvalidArgumentException
     */
    public function route(array $parameters): array|string
    {
        $arguments  = array_values($parameters);
        $command    = array_shift($arguments);
        $command    = is_string($command) ? strtoupper($command) : '';
        $subcommand = is_string($arguments[0] ?? null) ? $command . ' ' . strtoupper($arguments[0]) : $command;

        if ($command === 'SCAN') {
            throw new \InvalidArgumentException('A raw SCAN on a cluster connection covers a single node; call scan() with a ClusterScanCursor instead.');
        }

        if (in_array($subcommand, self::SLOT_COMMANDS, true)) {
            throw new \InvalidArgumentException(sprintf('A raw %s must reach the primary that owns its slot; call rawCommand() with a route to that primary.', $subcommand));
        }

        return self::FAN_OUT_ROUTES[$subcommand] ?? self::FAN_OUT_ROUTES[$command] ?? $this->resolveKeyRoute($command, $arguments);
    }

    /**
     * Resolve the route for a command keyed by the given value.
     *
     * GLIDE decodes a slot-key route's key as a UTF-8 string and rejects any
     * other bytes, so an empty key, a key that is not valid UTF-8, or a value
     * that is not a key goes to `randomNode`; GLIDE follows the node's `MOVED`
     * redirect to the key's owner.
     *
     * @param  mixed  $key
     * @return array{type: string, key: string}|string
     */
    public function routeKey(mixed $key): array|string
    {
        $key = is_scalar($key) || $key instanceof \Stringable ? (string) $key : '';

        return $key !== '' && preg_match('//u', $key) === 1 ? ['type' => 'primarySlotKey', 'key' => $key] : 'randomNode';
    }

    /**
     * Resolve the route of a command by its first key.
     *
     * @param  string  $command
     * @param  array<int, mixed>  $arguments
     * @return array{type: string, key: string}|string
     */
    private function resolveKeyRoute(string $command, array $arguments): array|string
    {
        $keyIndex = $this->resolveKeyIndex($command, $arguments);

        return $this->routeKey($keyIndex !== null ? ($arguments[$keyIndex] ?? null) : null);
    }

    /**
     * Resolve the index of a raw command's first key argument, if it has one.
     *
     * @param  string  $command
     * @param  array<int, mixed>  $arguments
     * @return int|null
     */
    private function resolveKeyIndex(string $command, array $arguments): ?int
    {
        return match (true) {
            array_key_exists($command, self::KEY_INDEXES)       => self::KEY_INDEXES[$command],
            array_key_exists($command, self::KEY_COUNT_INDEXES) => $this->resolveCountedKeyIndex(self::KEY_COUNT_INDEXES[$command], $arguments),
            $command === 'XREAD' || $command === 'XREADGROUP'   => $this->resolveStreamsKeyIndex($arguments),
            default                                             => 0,
        };
    }

    /**
     * Resolve the index of the first key that follows a key count, if the count
     * declares any keys.
     *
     * @param  int  $countIndex
     * @param  array<int, mixed>  $arguments
     * @return int|null
     */
    private function resolveCountedKeyIndex(int $countIndex, array $arguments): ?int
    {
        $keyCount = Cast::toNonNegativeInt($arguments[$countIndex] ?? null);

        return $keyCount !== null && $keyCount > 0 ? $countIndex + 1 : null;
    }

    /**
     * Resolve the index of the first stream key, which follows the STREAMS
     * token.
     *
     * @param  array<int, mixed>  $arguments
     * @return int|null
     */
    private function resolveStreamsKeyIndex(array $arguments): ?int
    {
        foreach ($arguments as $index => $argument) {
            if (is_string($argument) && strtoupper($argument) === 'STREAMS') {
                return $index + 1;
            }
        }

        return null;
    }
}
