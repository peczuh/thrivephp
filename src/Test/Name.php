<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

/** @internal Literal suite, case and action identifiers, distinct from descriptive report labels. */
final class Name
{
    /** Reject unsafe components rather than sanitizing different names into the same identifier. */
    public static function validate(string $name, string $kind): void
    {
        if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_-]{0,254}\z/', $name)
            || preg_match('/\A(?:CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])\z/i', $name)) {
            throw new \InvalidArgumentException('Invalid ' . $kind . ' name ' . var_export($name, true)
                . ': use 1 to 255 ASCII letters, digits, underscores or hyphens, starting with a letter or underscore; filesystem-reserved names are not allowed.');
        }
    }
}
