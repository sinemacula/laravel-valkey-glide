<?php

namespace Tests\Unit\Webhooks\DTO;

use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\CoversClass;
use Verifast\Foundation\Testing\DtoTestCase;
use Verifast\Transport\Webhooks\DTO\WebhookEvent;
use Verifast\Transport\Webhooks\Enums\WebhookEventStatus;

/**
 * Webhook event test case.
 *
 * @author      Ben Carey <ben.carey@verifast.com>
 * @copyright   2026 Verifast, Inc.
 *
 * @internal
 */
#[CoversClass(WebhookEvent::class)]
final class WebhookEventTest extends DtoTestCase
{
    /**
     * Data provider for round-trip tests.
     *
     * @return iterable<array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function dataProvider(): iterable
    {
        $timestamp = CarbonImmutable::parse('2025-01-01T12:00:00Z');

        yield 'full hydrated event' => [
            [
                'provider'    => 'transunion',
                'event'       => 'credit.report.ready',
                'external_id' => 'evt_123',
                'payload'     => ['foo' => 'bar'],
                'status'      => WebhookEventStatus::PROCESSED,
                'received_at' => $timestamp,
            ],
            [
                'provider'    => 'transunion',
                'event'       => 'credit.report.ready',
                'external_id' => 'evt_123',
                'payload'     => ['foo' => 'bar'],
                'status'      => WebhookEventStatus::PROCESSED->name,
                'received_at' => $timestamp->toIso8601String(),
            ],
        ];

        yield 'missing optional fields' => [
            [
                'provider'    => 'stripe',
                'status'      => WebhookEventStatus::PROCESSING,
                'received_at' => $timestamp,
            ],
            [
                'provider'    => 'stripe',
                'event'       => null,
                'external_id' => null,
                'payload'     => [],
                'status'      => WebhookEventStatus::PROCESSING->name,
                'received_at' => $timestamp->toIso8601String(),
            ],
        ];
    }

    /**
     * Explicit getter checks.
     *
     * @return void
     */
    public function testGettersReturnExpectedValues(): void
    {
        $timestamp = CarbonImmutable::parse('2025-01-01T12:00:00Z');

        $dto = WebhookEvent::fromArray([
            'provider'    => 'stripe',
            'event'       => 'payment.succeeded',
            'external_id' => 'evt_999',
            'payload'     => ['amount' => 100],
            'status'      => WebhookEventStatus::PROCESSED,
            'received_at' => $timestamp,
        ]);

        self::assertSame('stripe', $dto->provider());
        self::assertSame('payment.succeeded', $dto->event());
        self::assertSame('evt_999', $dto->externalId());
        self::assertSame(['amount' => 100], $dto->payload());
        self::assertSame(WebhookEventStatus::PROCESSED, $dto->status());
        self::assertSame($timestamp, $dto->receivedAt());
    }

    /**
     * Return the fully-qualified class name of the DTO under test.
     *
     * @return class-string
     */
    protected function dtoClass(): string
    {
        return WebhookEvent::class;
    }
}
