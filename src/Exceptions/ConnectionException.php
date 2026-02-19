<?php

// phpcs:disable PSR12.Files.DeclareStatement.SpaceFoundAfterDirective,PSR12.Files.DeclareStatement.SpaceFoundBeforeDirectiveValue
declare(strict_types = 1);
// phpcs:enable PSR12.Files.DeclareStatement.SpaceFoundAfterDirective,PSR12.Files.DeclareStatement.SpaceFoundBeforeDirectiveValue

namespace SineMacula\Valkey\Exceptions;

/**
 * Connection exception.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ConnectionException extends \RuntimeException {}
