<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	use ThriveData\ThrivePHP\Test;

	/** @internal Shared author-operation recording; nested calls execute their ordinary bodies. */
	final class Step
	{
		public static function recorder(): ?Test
		{
			$test = class_exists(Test::class) ? Test::active() : null;
			return $test !== null && $test->canRecordStep() ? $test : null;
		}

		/** Re-enter the declared method under the recorder's exception-safe nesting guard. */
		public static function run(Test $test, object $receiver, string $method, array $arguments): mixed
		{
			$target = match (true) {
				$receiver instanceof Element, $receiver instanceof ElementSet, $receiver instanceof ShadowRoot => $receiver->description(),
				$receiver instanceof Window => 'window ' . $receiver->handle(),
				default => 'browser',
			};
			$values = [];
			foreach ((new \ReflectionMethod($receiver, $method))->getParameters() as $index => $parameter):
				if (array_key_exists($index, $arguments)):
					$values[$parameter->getName()] = self::value($parameter->isVariadic() ? array_slice($arguments, $index) : $arguments[$index]);
				endif;
			endforeach;
			return $test->recordStep($method, $target, $values, fn() => $receiver->$method(...$arguments));
		}

		/** JSON descriptions preserve supplied values without traversing remote object internals. */
		public static function value(mixed $value, int $depth = 0): mixed
		{
			if ($depth > 64):
				return '[recursive or excessively nested value]';
			endif;
			if (is_float($value) && !is_finite($value)):
				return ['type' => 'float', 'value' => is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF')];
			endif;
			if ($value instanceof Element || $value instanceof ShadowRoot):
				return ['target' => $value->description()];
			endif;
			if ($value instanceof Window):
				return ['window' => $value->handle()];
			endif;
			if (is_array($value)):
				return array_map(fn($item) => self::value($item, $depth + 1), $value);
			endif;
			if ($value instanceof \stdClass):
				return (object)array_map(fn($item) => self::value($item, $depth + 1), get_object_vars($value));
			endif;
			return is_object($value) ? ['class' => get_class($value)] : (is_resource($value) ? get_resource_type($value) : $value);
		}
	}
