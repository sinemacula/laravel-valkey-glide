<?php

declare(strict_types = 1);

namespace Tests\Unit\Exceptions;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Exceptions\ConnectionException;

/**
 * @internal
 */
#[CoversClass(ConnectionException::class)]
final class ConnectionExceptionTest extends TestCase
{
    /**
     * Verify the domain exception extends RuntimeException.
     *
     * @return void
     */
    public function testItExtendsRuntimeException(): void
    {
        $exception = new ConnectionException('Connection failed.');

        self::assertInstanceOf(\RuntimeException::class, $exception);
        self::assertSame('Connection failed.', $exception->getMessage());
    }
}
