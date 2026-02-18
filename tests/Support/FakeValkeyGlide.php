<?php

declare(strict_types = 1);

namespace Tests\Support;

final class FakeValkeyGlide extends \ValkeyGlide
{
    /** @var array<string, list<array<array-key, mixed>>> */
    public array $calls = [];

    /** @var array<string, list<mixed>> */
    private array $responses = [];

    /** @var array<string, list<array<int, mixed>>> */
    private array $subscriptionMessages = [
        'subscribe'  => [],
        'psubscribe' => [],
    ];

    /**
     * @param  string  $name
     * @param  array<int, mixed>  $arguments
     * @return mixed
     */
    public function __call(string $name, array $arguments): mixed
    {
        $this->recordCall($name, $arguments);

        return $this->popResponse($name, null);
    }

    /**
     * @param  string  $method
     * @param  mixed  ...$responses
     * @return void
     */
    public function queueResponse(string $method, mixed ...$responses): void
    {
        foreach ($responses as $response) {
            $this->responses[$method][] = $response;
        }
    }

    /**
     * @param  string  $method
     * @param  array<int, mixed>  $payload
     * @return void
     */
    public function queueSubscriptionMessage(string $method, array $payload): void
    {
        $this->subscriptionMessages[$method][] = $payload;
    }

    /**
     * @param  ?string  $host
     * @param  ?int  $port
     * @param  ?float  $timeout
     * @param  ?string  $persistent_id
     * @param  ?int  $retry_interval
     * @param  ?float  $read_timeout
     * @param  mixed  $addresses
     * @param  ?bool  $use_tls
     * @param  mixed  $credentials
     * @param  ?int  $read_from
     * @param  ?int  $request_timeout
     * @param  mixed  $reconnect_strategy
     * @param  ?int  $database_id
     * @param  ?string  $client_name
     * @param  ?string  $client_az
     * @param  mixed  $advanced_config
     * @param  ?bool  $lazy_connect
     * @param  mixed  $context
     * @return bool
     */
    public function connect(
        ?string $host = null,
        ?int $port = null,
        ?float $timeout = null,
        ?string $persistent_id = null,
        ?int $retry_interval = null,
        ?float $read_timeout = null,
        mixed $addresses = null,
        ?bool $use_tls = null,
        mixed $credentials = null,
        ?int $read_from = null,
        ?int $request_timeout = null,
        mixed $reconnect_strategy = null,
        ?int $database_id = null,
        ?string $client_name = null,
        ?string $client_az = null,
        mixed $advanced_config = null,
        ?bool $lazy_connect = null,
        mixed $context = null,
    ): bool {
        $this->recordCall('connect', [
            'host'               => $host,
            'port'               => $port,
            'timeout'            => $timeout,
            'persistent_id'      => $persistent_id,
            'retry_interval'     => $retry_interval,
            'read_timeout'       => $read_timeout,
            'addresses'          => $addresses,
            'use_tls'            => $use_tls,
            'credentials'        => $credentials,
            'read_from'          => $read_from,
            'request_timeout'    => $request_timeout,
            'reconnect_strategy' => $reconnect_strategy,
            'database_id'        => $database_id,
            'client_name'        => $client_name,
            'client_az'          => $client_az,
            'advanced_config'    => $advanced_config,
            'lazy_connect'       => $lazy_connect,
            'context'            => $context,
        ]);

        return (bool) $this->popResponse('connect', true);
    }

    /**
     * @param  int  $db
     * @return bool|\ValkeyGlide
     */
    public function select(int $db): bool|\ValkeyGlide
    {
        $this->recordCall('select', [$db]);

        $response = $this->popResponse('select', true);

        if (is_bool($response) || $response instanceof \ValkeyGlide) {
            return $response;
        }

        return true;
    }

    /**
     * @param  string  $opt
     * @param  mixed  ...$args
     * @return mixed
     */
    public function client(string $opt, mixed ...$args): mixed
    {
        $this->recordCall('client', [$opt, ...$args]);

        return $this->popResponse('client', true);
    }

    /**
     * @param  mixed  ...$arguments
     * @return bool
     */
    public function setOption(mixed ...$arguments): bool
    {
        $this->recordCall('setOption', $arguments);

        return (bool) $this->popResponse('setOption', true);
    }

    /**
     * @return bool
     */
    public function close(): bool
    {
        $this->recordCall('close', []);

        return (bool) $this->popResponse('close', true);
    }

    /**
     * @param  string  $command
     * @param  mixed  ...$arguments
     * @return mixed
     */
    public function rawcommand(string $command, mixed ...$arguments): mixed
    {
        $this->recordCall('rawcommand', [$command, ...$arguments]);

        return $this->popResponse('rawcommand', null);
    }

    /**
     * @param  string  $key
     * @return mixed
     */
    public function get(string $key): mixed
    {
        $this->recordCall('get', [$key]);

        return $this->popResponse('get', false);
    }

    /**
     * @param  string  $key
     * @param  mixed  $value
     * @param  mixed  $options
     * @return bool|string|\ValkeyGlide
     */
    public function set(string $key, mixed $value, mixed $options = null): bool|string|\ValkeyGlide
    {
        $this->recordCall('set', [$key, $value, $options]);

        $response = $this->popResponse('set', true);

        if (is_bool($response) || is_string($response) || $response instanceof \ValkeyGlide) {
            return $response;
        }

        return true;
    }

    /**
     * @param  array<array-key, mixed>  $channels
     * @param  mixed  $callback
     * @return bool
     */
    public function subscribe(array $channels, mixed $callback): bool
    {
        if (!is_callable($callback)) {
            throw new \InvalidArgumentException('Subscribe callback must be callable.');
        }

        $this->recordCall('subscribe', [$channels]);

        foreach ($this->subscriptionMessages['subscribe'] as $payload) {
            $callback(...$payload);
        }

        return (bool) $this->popResponse('subscribe', true);
    }

    /**
     * @param  array<array-key, mixed>  $patterns
     * @param  mixed  $callback
     * @return bool
     */
    public function psubscribe(array $patterns, mixed $callback): bool
    {
        if (!is_callable($callback)) {
            throw new \InvalidArgumentException('Psubscribe callback must be callable.');
        }

        $this->recordCall('psubscribe', [$patterns]);

        foreach ($this->subscriptionMessages['psubscribe'] as $payload) {
            $callback(...$payload);
        }

        return (bool) $this->popResponse('psubscribe', true);
    }

    /**
     * @param  string  $method
     * @param  array<array-key, mixed>  $arguments
     * @return void
     */
    private function recordCall(string $method, array $arguments): void
    {
        $this->calls[$method][] = $arguments;
    }

    /**
     * @param  string  $method
     * @param  mixed  $default
     * @return mixed
     */
    private function popResponse(string $method, mixed $default): mixed
    {
        if (!array_key_exists($method, $this->responses) || $this->responses[$method] === []) {
            return $default;
        }

        $response = array_shift($this->responses[$method]);

        if ($response instanceof \Throwable) {
            throw $response;
        }

        return $response;
    }
}
