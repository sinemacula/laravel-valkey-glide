<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Exceptions;

/**
 * Thrown when a Valkey GLIDE connection cannot be established or
 * re-established.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ConnectionException extends \RuntimeException {}
