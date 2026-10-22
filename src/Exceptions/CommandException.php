<?php

declare(strict_types = 1);

namespace SineMacula\Valkey\Exceptions;

/**
 * Thrown when GLIDE reports that a command failed.
 *
 * The extension answers a failed command with false and, from 1.2, records its
 * error, which this exception carries as its message, unchanged. It is an
 * UnexpectedValueException, as the driver throws for a false reply it cannot
 * pass over, such as a failed scan or tag write, so code that catches those
 * failures catches the error GLIDE recorded for them too.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class CommandException extends \UnexpectedValueException {}
