<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Support;

/**
 * Resolve password, ACL, and IAM credentials into the GLIDE credentials shape.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CredentialResolver
{
    /** @var int Default IAM refresh interval in seconds. */
    private const int DEFAULT_IAM_REFRESH_INTERVAL = 300;

    /**
     * Resolve GLIDE credentials from the given connection config.
     *
     * Returns IAM credentials when an IAM block is present, password
     * credentials when a password string is set, or null when neither applies.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     *
     * @throws \InvalidArgumentException
     */
    public static function resolve(#[\SensitiveParameter] array $config): ?array
    {
        $iamCredentials = self::iamCredentials($config);

        if ($iamCredentials !== null) {
            return $iamCredentials;
        }

        $password = $config['password'] ?? null;

        if (!is_string($password) || $password === '') {
            return null;
        }

        $username    = $config['username'] ?? null;
        $credentials = ['password' => $password];

        if (is_string($username) && $username !== '') {
            $credentials['username'] = $username;
        }

        return $credentials;
    }

    /**
     * Determine whether IAM auth config is present in the given config array.
     *
     * A null or empty iam value counts as absent, so IAM can be toggled per
     * environment.
     *
     * @param  array<string, mixed>  $config
     * @return bool
     */
    public static function hasIam(#[\SensitiveParameter] array $config): bool
    {
        return isset($config['iam']) && is_array($config['iam']) && $config['iam'] !== [];
    }

    /**
     * Build IAM credentials in GLIDE's expected shape.
     *
     * Returns null when IAM config is absent.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     *
     * @throws \InvalidArgumentException
     */
    private static function iamCredentials(#[\SensitiveParameter] array $config): ?array
    {
        if (!self::hasIam($config)) {
            return null;
        }

        $iam = $config['iam'];

        $required = [
            'username'     => Cast::toNonEmptyString($iam['username'] ?? null),
            'cluster_name' => Cast::toNonEmptyString($iam['cluster_name'] ?? null),
            'region'       => Cast::toNonEmptyString($iam['region'] ?? null),
        ];

        $missing = array_keys(array_filter($required, is_null(...)));

        if ($missing !== []) {
            throw new \InvalidArgumentException(sprintf('Incomplete IAM configuration; missing or empty iam keys [%s].', implode(', ', $missing)));
        }

        return [
            'username'  => $required['username'],
            'iamConfig' => [
                'clusterName'            => $required['cluster_name'],
                'region'                 => $required['region'],
                'service'                => 'Elasticache',
                'refreshIntervalSeconds' => self::iamRefreshInterval($iam['refresh_interval'] ?? null),
            ],
        ];
    }

    /**
     * Resolve the IAM refresh interval in seconds, applying the default.
     *
     * Falls back to the default when the value is absent, non-numeric, or zero.
     *
     * @param  mixed  $value
     * @return int
     */
    private static function iamRefreshInterval(mixed $value): int
    {
        $refreshInterval = Cast::toNonNegativeInt($value);

        if ($refreshInterval === null || $refreshInterval === 0) {
            return self::DEFAULT_IAM_REFRESH_INTERVAL;
        }

        return $refreshInterval;
    }
}
