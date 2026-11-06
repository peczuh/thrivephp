<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

/** Suite cleanup, executed child class first even after partial setup. */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class SuiteAfter {}
