<?php

declare(strict_types = 1);

namespace Tests\Unit\Connections;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideBatch;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use Tests\Fakes\ValkeyGlideClusterFake;
use Tests\Fakes\ValkeyGlideFake;

/**
 * Valkey GLIDE connection test case for the float arguments GLIDE would write
 * with six significant digits: values, members, fields and score bounds sent as
 * strings, and inexact increments sent through the raw command.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
final class FloatPrecisionValkeyGlideConnectionTest extends TestCase
{
    /**
     * Verify float values, members, fields and score bounds reach GLIDE as the
     * strings phpredis sends, with the keys prefixed.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function floatValuesAreSentAsPhpRedisSendsThem(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);

        $connection->command('set', ['k', 19.999999]);
        $connection->command('hset', ['h', 'f', 1.23456789]);
        $connection->command('rpush', ['l', 1.23456789]);
        $connection->command('zrangebyscore', ['z', 1.23456789, '+inf']);

        self::assertSame([['app:k', '19.999999', null]], $client->callsFor('set'));
        self::assertSame([['app:h', 'f', '1.23456789']], $client->callsFor('hset'));
        self::assertSame([['app:l', '1.23456789']], $client->callsFor('rpush'));
        self::assertSame([['app:z', '1.23456789', '+inf']], $client->callsFor('zrangebyscore'));
    }

    /**
     * Verify an INCRBYFLOAT or HINCRBYFLOAT whose increment GLIDE would write
     * with other digits is sent through the raw command, with the increment as
     * phpredis sends it, routed to the key's slot on a cluster, and returns the
     * new value.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function inexactIncrementsAreSentThroughTheRawCommand(): void
    {
        foreach ([new ValkeyGlideFake, new ValkeyGlideClusterFake] as $client) {
            $client->willReturn('rawcommand', 1.23456789);

            $connection = new ValkeyGlideConnection($client, null, ['prefix' => 'app:']);
            $route      = static fn (string $key): array => $client instanceof ValkeyGlideClusterFake ? [['type' => 'primarySlotKey', 'key' => $key]] : [];

            self::assertSame(1.23456789, $connection->command('incrbyfloat', ['k', 1.23456789]));
            self::assertSame(1.23456789, $connection->command('hIncrByFloat', ['h', 'f', '19.999999']));
            self::assertSame([], $client->callsFor('incrbyfloat'));
            self::assertSame([], $client->callsFor('hincrbyfloat'));
            self::assertSame(
                [
                    [...$route('app:k'), 'INCRBYFLOAT', 'app:k', '1.2345678899999999'],
                    [...$route('app:h'), 'HINCRBYFLOAT', 'app:h', 'f', '19.999998999999999'],
                ],
                $client->callsFor('rawcommand'),
            );
        }
    }

    /**
     * Verify an increment GLIDE writes as phpredis does is left to GLIDE.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function exactIncrementsAreLeftToGlide(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('incrbyfloat', 2.5);

        $connection = new ValkeyGlideConnection($client);

        self::assertSame(2.5, $connection->command('incrbyfloat', ['k', 1.5]));
        self::assertSame(2.5, $connection->command('incrbyfloat', ['k', '1.5']));
        self::assertSame([['k', 1.5], ['k', '1.5']], $client->callsFor('incrbyfloat'));
        self::assertSame([], $client->callsFor('rawcommand'));
    }

    /**
     * Verify a raw increment in a pipeline runs in call order, after what was
     * buffered before it, and its result takes its place in the list.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function inexactIncrementInAPipelineRunsInCallOrder(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('rpush', 1);
        $client->willReturn('rawcommand', 1.23456789);

        $connection = new ValkeyGlideConnection($client);

        $results = $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
            $pipe->rpush('l', 1.23456789);
            $pipe->incrbyfloat('k', 1.23456789);
        });

        self::assertSame([1, 1.23456789], $results);
        self::assertSame(['pipeline', 'rpush', 'exec', 'rawcommand'], $client->callOrder());
        self::assertSame([['l', '1.23456789']], $client->callsFor('rpush'));
        self::assertSame([['INCRBYFLOAT', 'k', '1.2345678899999999']], $client->callsFor('rawcommand'));
    }

    /**
     * Verify an increment GLIDE would not write as the same float is refused in
     * a transaction, which is discarded, as the raw command would run outside
     * the MULTI block.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function inexactIncrementInATransactionIsRefused(): void
    {
        $client     = new ValkeyGlideFake;
        $connection = new ValkeyGlideConnection($client);

        try {
            $connection->transaction(static function (ValkeyGlideBatch $tx): void {
                $tx->set('k', 1.23456789);
                $tx->hincrbyfloat('h', 'f', 1.23456789);
            });
            self::fail('Expected the increment to be refused.');
        } catch (\LogicException $exception) {
            self::assertStringStartsWith('[HINCRBYFLOAT] cannot run inside a transaction', $exception->getMessage());
        }

        self::assertSame(['multi', 'set', 'discard'], $client->callOrder());
        self::assertSame([['k', '1.23456789', null]], $client->callsFor('set'));
        self::assertFalse($client->isBatching());
    }

    /**
     * Verify an increment GLIDE writes as the same float is buffered in a
     * transaction, as only the raw command could send phpredis' digits.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function incrementGlideWritesAsTheSameFloatIsBufferedInATransaction(): void
    {
        $client = new ValkeyGlideFake;
        $client->willReturn('incrbyfloat', 0.1);

        $connection = new ValkeyGlideConnection($client);

        self::assertSame([0.1], $connection->transaction(static fn (ValkeyGlideBatch $tx): ValkeyGlideBatch => $tx->incrbyfloat('k', 0.1)));
        self::assertSame(['multi', 'incrbyfloat', 'exec'], $client->callOrder());
        self::assertSame([['k', 0.1]], $client->callsFor('incrbyfloat'));
    }
}
