<?php

declare(strict_types = 1);

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SineMacula\Valkey\Support\CredentialResolver;

/**
 * CredentialResolver test case.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 *
 * @internal
 */
#[CoversClass(CredentialResolver::class)]
final class CredentialResolverTest extends TestCase
{
    /**
     * Verify resolve returns null when no password or IAM config is present.
     *
     * @return void
     */
    #[Test]
    public function resolveReturnsNullWhenNoCredentialsConfigured(): void
    {
        self::assertNull(CredentialResolver::resolve([]));
    }

    /**
     * Verify resolve returns null when password is an empty string.
     *
     * @return void
     */
    #[Test]
    public function resolveReturnsNullWhenPasswordIsEmptyString(): void
    {
        self::assertNull(CredentialResolver::resolve(['password' => '']));
    }

    /**
     * Verify resolve returns null when password is a non-string value.
     *
     * @return void
     */
    #[Test]
    public function resolveReturnsNullWhenPasswordIsNonString(): void
    {
        self::assertNull(CredentialResolver::resolve(['password' => 42]));
    }

    /**
     * Verify resolve returns password-only credentials when no username is set.
     *
     * @return void
     */
    #[Test]
    public function resolveReturnsPasswordOnlyCredentialsWhenUsernameAbsent(): void
    {
        $credentials = CredentialResolver::resolve(['password' => 'secret']);

        self::assertSame(['password' => 'secret'], $credentials);
    }

    /**
     * Verify resolve includes username when both username and password are set.
     *
     * @return void
     */
    #[Test]
    public function resolveIncludesUsernameWhenBothCredentialsProvided(): void
    {
        $credentials = CredentialResolver::resolve([
            'username' => 'cache-user',
            'password' => 'secret',
        ]);

        self::assertSame([
            'password' => 'secret',
            'username' => 'cache-user',
        ], $credentials);
    }

    /**
     * Verify resolve omits username when it is an empty string.
     *
     * @return void
     */
    #[Test]
    public function resolveOmitsUsernameWhenItIsEmptyString(): void
    {
        $credentials = CredentialResolver::resolve([
            'username' => '',
            'password' => 'secret',
        ]);

        self::assertSame(['password' => 'secret'], $credentials);
        self::assertArrayNotHasKey('username', $credentials);
    }

    /**
     * Verify resolve omits username when it is a non-string value.
     *
     * @return void
     */
    #[Test]
    public function resolveOmitsUsernameWhenItIsNonString(): void
    {
        $credentials = CredentialResolver::resolve([
            'username' => 42,
            'password' => 'secret',
        ]);

        self::assertSame(['password' => 'secret'], $credentials);
        self::assertArrayNotHasKey('username', $credentials);
    }

    /**
     * Verify resolve returns IAM credentials when a complete IAM block is
     * present.
     *
     * @return void
     */
    #[Test]
    public function resolveReturnsIamCredentialsWhenCompleteIamConfigPresent(): void
    {
        $credentials = CredentialResolver::resolve([
            'iam' => [
                'username'         => 'iam-user',
                'cluster_name'     => 'cluster-prod',
                'region'           => 'eu-west-2',
                'refresh_interval' => 120,
            ],
        ]);

        self::assertSame([
            'username'  => 'iam-user',
            'iamConfig' => [
                'clusterName'            => 'cluster-prod',
                'region'                 => 'eu-west-2',
                'service'                => 'Elasticache',
                'refreshIntervalSeconds' => 120,
            ],
        ], $credentials);
    }

    /**
     * Verify IAM credentials take precedence over password credentials.
     *
     * @return void
     */
    #[Test]
    public function resolveReturnsIamCredentialsOverPasswordWhenBothPresent(): void
    {
        $credentials = CredentialResolver::resolve([
            'password' => 'secret',
            'iam'      => [
                'username'     => 'iam-user',
                'cluster_name' => 'cluster-a',
                'region'       => 'eu-west-1',
            ],
        ]);

        self::assertArrayHasKey('iamConfig', $credentials);
        self::assertArrayNotHasKey('password', $credentials);
    }

    /**
     * Verify resolve returns null when IAM config is missing the username
     * field.
     *
     * @return void
     */
    #[Test]
    public function resolveReturnsNullWhenIamUsernameMissing(): void
    {
        $credentials = CredentialResolver::resolve([
            'iam' => [
                'cluster_name' => 'cluster-a',
                'region'       => 'eu-west-1',
            ],
        ]);

        self::assertNull($credentials);
    }

    /**
     * Verify resolve returns null when IAM username is empty.
     *
     * @return void
     */
    #[Test]
    public function resolveReturnsNullWhenIamUsernameIsEmpty(): void
    {
        $credentials = CredentialResolver::resolve([
            'iam' => [
                'username'     => '',
                'cluster_name' => 'cluster-a',
                'region'       => 'eu-west-1',
            ],
        ]);

        self::assertNull($credentials);
    }

    /**
     * Verify resolve returns null when IAM cluster_name is empty.
     *
     * @return void
     */
    #[Test]
    public function resolveReturnsNullWhenIamClusterNameIsEmpty(): void
    {
        $credentials = CredentialResolver::resolve([
            'iam' => [
                'username'     => 'iam-user',
                'cluster_name' => '',
                'region'       => 'eu-west-1',
            ],
        ]);

        self::assertNull($credentials);
    }

    /**
     * Verify resolve returns null when IAM region is empty.
     *
     * @return void
     */
    #[Test]
    public function resolveReturnsNullWhenIamRegionIsEmpty(): void
    {
        $credentials = CredentialResolver::resolve([
            'iam' => [
                'username'     => 'iam-user',
                'cluster_name' => 'cluster-a',
                'region'       => '',
            ],
        ]);

        self::assertNull($credentials);
    }

    /**
     * Verify resolve returns null when IAM config is a scalar rather than
     * an array.
     *
     * @return void
     */
    #[Test]
    public function resolveReturnsNullWhenIamConfigIsScalar(): void
    {
        $credentials = CredentialResolver::resolve(['iam' => 'not-an-array']);

        self::assertNull($credentials);
    }

    /**
     * Verify a configured non-zero IAM refresh interval is preserved verbatim.
     *
     * @return void
     */
    #[Test]
    public function resolvePreservesNonZeroIamRefreshInterval(): void
    {
        $credentials = CredentialResolver::resolve([
            'iam' => [
                'username'         => 'iam-user',
                'cluster_name'     => 'cluster-x',
                'region'           => 'eu-west-3',
                'refresh_interval' => 45,
            ],
        ]);

        self::assertIsArray($credentials);
        self::assertSame(45, $credentials['iamConfig']['refreshIntervalSeconds']);
    }

    /**
     * Verify a zero IAM refresh interval is replaced with the package
     * default of 300.
     *
     * @return void
     */
    #[Test]
    public function resolveReplacesZeroIamRefreshIntervalWithDefault(): void
    {
        $credentials = CredentialResolver::resolve([
            'iam' => [
                'username'         => 'iam-user',
                'cluster_name'     => 'cluster-x',
                'region'           => 'eu-west-3',
                'refresh_interval' => 0,
            ],
        ]);

        self::assertIsArray($credentials);
        self::assertSame(300, $credentials['iamConfig']['refreshIntervalSeconds']);
    }

    /**
     * Verify a null IAM refresh interval falls back to the package default
     * of 300.
     *
     * @return void
     */
    #[Test]
    public function resolveReplacesNullIamRefreshIntervalWithDefault(): void
    {
        $credentials = CredentialResolver::resolve([
            'iam' => [
                'username'     => 'iam-user',
                'cluster_name' => 'cluster-x',
                'region'       => 'eu-west-3',
            ],
        ]);

        self::assertIsArray($credentials);
        self::assertSame(300, $credentials['iamConfig']['refreshIntervalSeconds']);
    }

    /**
     * Verify IAM credentials always include the fixed Elasticache service
     * value.
     *
     * @return void
     */
    #[Test]
    public function resolveIamCredentialsAlwaysIncludeElasticacheService(): void
    {
        $credentials = CredentialResolver::resolve([
            'iam' => [
                'username'     => 'iam-user',
                'cluster_name' => 'cluster-a',
                'region'       => 'us-east-1',
            ],
        ]);

        self::assertIsArray($credentials);
        self::assertSame('Elasticache', $credentials['iamConfig']['service']);
    }

    /**
     * Provide config shapes where hasIam must return true.
     *
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function iamPresentProvider(): iterable
    {
        yield 'complete iam block' => [
            [
                'iam' => [
                    'username'     => 'iam-user',
                    'cluster_name' => 'cluster-a',
                    'region'       => 'eu-west-1',
                ],
            ],
        ];

        yield 'empty iam array' => [['iam' => []]];
    }

    /**
     * Verify hasIam returns true when the iam key holds an array.
     *
     * @param  array<string, mixed>  $config
     * @return void
     */
    #[DataProvider('iamPresentProvider')]
    #[Test]
    public function hasIamReturnsTrueWhenIamKeyIsArray(array $config): void
    {
        self::assertTrue(CredentialResolver::hasIam($config));
    }

    /**
     * Provide config shapes where hasIam must return false.
     *
     * @return iterable<string, array{0: array<string, mixed>}>
     */
    public static function iamAbsentProvider(): iterable
    {
        yield 'no iam key' => [[]];
        yield 'iam is a string' => [['iam' => 'not-array']];
        yield 'iam is an integer' => [['iam' => 1]];
        yield 'iam is null' => [['iam' => null]];
    }

    /**
     * Verify hasIam returns false when the iam key is absent or not an array.
     *
     * @param  array<string, mixed>  $config
     * @return void
     */
    #[DataProvider('iamAbsentProvider')]
    #[Test]
    public function hasIamReturnsFalseWhenIamKeyIsAbsentOrNotArray(array $config): void
    {
        self::assertFalse(CredentialResolver::hasIam($config));
    }
}
