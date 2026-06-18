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
     * Returns IAM credentials when an IAM block is present and complete,
     * password credentials when a password string is set, or null when neither
     * applies.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
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
     * @param  array<string, mixed>  $config
     * @return bool
     */
    public static function hasIam(array $config): bool
    {
        return isset($config['iam']) && is_array($config['iam']);
    }

    /**
     * Build IAM credentials in GLIDE's expected shape.
     *
     * Returns null when IAM config is absent or when any required field is
     * empty after normalization.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>|null
     */
    private static function iamCredentials(#[\SensitiveParameter] array $config): ?array
    {
        if (!self::hasIam($config)) {
            return null;
        }

        $iam = $config['iam'];

        $username    = Cast::toNonEmptyString($iam['username'] ?? null)     ?? '';
        $clusterName = Cast::toNonEmptyString($iam['cluster_name'] ?? null) ?? '';
        $region      = Cast::toNonEmptyString($iam['region'] ?? null)       ?? '';

        if ($username === '' || $clusterName === '' || $region === '') {
            return null;
        }

        $refreshInterval = Cast::toNonNegativeInt($iam['refresh_interval'] ?? null);

        if ($refreshInterval === null || $refreshInterval === 0) {
            $refreshInterval = self::DEFAULT_IAM_REFRESH_INTERVAL;
        }

        return [
            'username'  => $username,
            'iamConfig' => [
                'clusterName'            => $clusterName,
                'region'                 => $region,
                'service'                => 'Elasticache',
                'refreshIntervalSeconds' => $refreshInterval,
            ],
        ];
    }
}
