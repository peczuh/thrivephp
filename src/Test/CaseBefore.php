<?php

declare(strict_types=1);

namespace ThriveData\ThrivePHP\Test;

/** Setup before each case, executed base class first. */
#[\Attribute(\Attribute::TARGET_METHOD)]
final class CaseBefore {}
