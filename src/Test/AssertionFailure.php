<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

/** Stops a check after Test::assert() has recorded its failed result. */
final class AssertionFailure extends \RuntimeException
{
}
