<?php

declare(strict_types = 1);

namespace Tests\External;

use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Events\CommandFailed;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Connections\ValkeyGlideBatch;
use SineMacula\Valkey\Connections\ValkeyGlideConnection;
use SineMacula\Valkey\Connectors\ValkeyGlideConnector;
use SineMacula\Valkey\Exceptions\CommandException;
use SineMacula\Valkey\Exceptions\ConnectionException;
use Tests\External\Concerns\ConnectsToTestServers;

/**
 * External checks of how the connection reports failed commands, which GLIDE
 * records for getLastError() from 1.2 and answers with a bare false before.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(ValkeyGlideConnection::class)]
#[Group('external')]
final class ValkeyGlideLastErrorExternalTest extends TestCase
{
    use ConnectsToTestServers;

    /** @var array{host: string, port: int} Local endpoint that refuses connections. */
    private const array REFUSING_ENDPOINT = ['host' => '127.0.0.1', 'port' => 1];

    /** @var string Message for a pipeline whose exec() failed. */
    private const string PIPELINE_FAILED_MESSAGE = 'The pipeline failed; some of its commands may have been applied.';

    /**
     * Ensure extension preconditions are met before each external test.
     *
     * @return void
     */
    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertExtensionIsLoaded();
    }

    /**
     * Verify a command the server refuses throws the error GLIDE records, and
     * leaves it recorded on the client, where getLastError() reads it.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function commandTheServerRefusesThrowsTheRecordedError(string $topology): void
    {
        $connection = $this->connectTo($topology, $this->uniqueName('last-error') . ':');

        $this->skipUnlessGlideRecordsErrors($connection);

        $connection->command('set', ['string', 'value']);

        try {
            $connection->command('lpush', ['string', 'member']);
            self::fail('Expected the recorded error to be thrown.');
        } catch (CommandException $exception) {
            self::assertStringContainsString('WRONGTYPE', $exception->getMessage());
            self::assertSame($exception->getMessage(), $connection->command('getLastError'));
        } finally {
            $connection->command('del', ['string']);
            $connection->disconnect();
        }
    }

    /**
     * Verify a command the server refuses throws the connection's own error on
     * a GLIDE that does not record errors, as 1.1.2 does not, when its reply is
     * never false, so GLIDE's false can only mean it failed.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function commandTheServerRefusesThrowsWhereGlideRecordsNoErrors(string $topology): void
    {
        $connection = $this->connectTo($topology, $this->uniqueName('last-error') . ':');

        if (method_exists($connection->client(), 'getLastError')) {
            $connection->disconnect();

            self::markTestSkipped('This GLIDE records command errors, which are thrown instead.');
        }

        $connection->command('set', ['string', 'value']);

        try {
            $connection->command('lpush', ['string', 'member']);
            self::fail('Expected the refused command to throw.');
        } catch (CommandException $exception) {
            self::assertStringStartsWith('[LPUSH] failed', $exception->getMessage());
        } finally {
            $connection->command('del', ['string']);
            $connection->disconnect();
        }
    }

    /**
     * Verify replies that are false, zero or missing are returned as replies,
     * even after a call made on the client directly left an error recorded.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function falseRepliesAreReturnedAsReplies(string $topology): void
    {
        $prefix     = $this->uniqueName('last-error') . ':';
        $connection = $this->connectTo($topology, $prefix);

        $connection->command('set', ['string', 'value']);
        $connection->command('rpush', ['list', 'member']);
        $connection->client()->get($prefix . 'list');

        try {
            self::assertSame(0, $connection->setnx('string', 'other'));
            self::assertNull($connection->get('missing'));
            self::assertFalse($connection->command('sismember', ['missing-set', 'member']));
            self::assertFalse($connection->command('hexists', ['missing-hash', 'field']));
            self::assertSame(0, $connection->command('exists', ['missing']));
        } finally {
            $connection->command('del', ['string']);
            $connection->command('del', ['list']);
            $connection->disconnect();
        }
    }

    /**
     * Verify a watched transaction aborted by a change to a watched key still
     * returns false, as GLIDE records no error for the abort.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function watchedTransactionAbortedByAChangeReturnsFalse(string $topology): void
    {
        $prefix     = $this->uniqueName('last-error') . ':';
        $connection = $this->connectTo($topology, $prefix);
        $other      = $this->connectTo($topology, $prefix);

        try {
            $connection->watch('{tx}key');
            $other->command('set', ['{tx}key', 'changed']);

            self::assertFalse($connection->transaction(static function (ValkeyGlideBatch $transaction): void {
                $transaction->set('{tx}key', 'mine');
            }));
            self::assertSame('changed', $other->get('{tx}key'));
        } finally {
            $other->command('del', ['{tx}key']);
            $other->disconnect();
            $connection->disconnect();
        }
    }

    /**
     * Verify a command that fails inside a transaction leaves false in its
     * result, as GLIDE records no error for it, while the others apply.
     *
     * @param  string  $topology
     * @return void
     *
     * @throws \Throwable
     */
    #[DataProvider('topologies')]
    #[Test]
    public function commandThatFailsInsideATransactionLeavesFalseInItsResult(string $topology): void
    {
        $connection = $this->connectTo($topology, $this->uniqueName('last-error') . ':');

        $connection->command('set', ['{tx}string', 'value']);

        try {
            $results = $connection->transaction(static function (ValkeyGlideBatch $transaction): void {
                $transaction->lpush('{tx}string', 'member');
                $transaction->set('{tx}other', 'applied');
            });

            self::assertSame([false, true], $results);
            self::assertSame('applied', $connection->get('{tx}other'));
        } finally {
            $connection->command('del', ['{tx}string', '{tx}other']);
            $connection->disconnect();
        }
    }

    /**
     * Verify a command that cannot reach the server throws the error GLIDE
     * records, dispatches a command failed event, and that get() throws it
     * rather than reading as a miss.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function commandThatCannotReachTheServerThrowsTheRecordedError(): void
    {
        $connection = (new ValkeyGlideConnector)->connect(self::REFUSING_ENDPOINT + ['lazy_connect' => true, 'timeout' => 0.5], []);

        $this->skipUnlessGlideRecordsErrors($connection);

        $dispatcher = new Dispatcher;
        $failed     = [];

        $dispatcher->listen(CommandFailed::class, static function (CommandFailed $event) use (&$failed): void {
            $failed[] = $event;
        });

        $connection->setEventDispatcher($dispatcher);

        try {
            $connection->command('set', ['key', 'value']);
            self::fail('Expected the recorded error to be thrown.');
        } catch (CommandException $exception) {
            self::assertStringContainsString('connect failed', $exception->getMessage());
            self::assertCount(1, $failed);
            self::assertSame($exception, $failed[0]->exception);
        }

        $this->expectException(CommandException::class);

        try {
            $connection->get('key');
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * Verify a pipeline that cannot reach the server throws, with the error
     * GLIDE records as the previous exception.
     *
     * @return void
     *
     * @throws \Throwable
     */
    #[Test]
    public function pipelineThatCannotReachTheServerCarriesTheRecordedError(): void
    {
        $connection = (new ValkeyGlideConnector)->connect(self::REFUSING_ENDPOINT + ['lazy_connect' => true, 'timeout' => 0.5], []);

        $this->skipUnlessGlideRecordsErrors($connection);

        try {
            $connection->pipeline(static function (ValkeyGlideBatch $pipe): void {
                $pipe->set('key', 'value');
            });
            self::fail('Expected the pipeline to throw.');
        } catch (ConnectionException $exception) {
            self::assertSame(self::PIPELINE_FAILED_MESSAGE, $exception->getMessage());
            self::assertInstanceOf(CommandException::class, $exception->getPrevious());
            self::assertStringContainsString('connect failed', $exception->getPrevious()->getMessage());
        } finally {
            $connection->disconnect();
        }
    }

    /**
     * Skip the test when the GLIDE in use records no command errors, as 1.1.2
     * does not, closing the connection first.
     *
     * @param  \SineMacula\Valkey\Connections\ValkeyGlideConnection  $connection
     * @return void
     *
     * @throws \Throwable
     */
    private function skipUnlessGlideRecordsErrors(ValkeyGlideConnection $connection): void
    {
        if (method_exists($connection->client(), 'getLastError')) {
            return;
        }

        $connection->disconnect();

        self::markTestSkipped('This GLIDE records no command errors; getLastError() arrives in 1.2.');
    }
}
