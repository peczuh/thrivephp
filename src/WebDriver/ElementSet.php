<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	/**
	 * Ordered search snapshots. Elements remain live remote references
	 * and may become stale. A waiting count assertion refreshes membership on success.
	 * Other operations keep the snapshot. Waiting assertions on individual members
	 * re-find their original position in the query, so reordered results follow position.
	 * Empty sets are truthy PHP objects: use count() or first() in wait conditions.
	 * @implements \IteratorAggregate<int, Element>
	 */
	class ElementSet implements \IteratorAggregate, \Countable
	{
		/** @var Element[] */
		private array $elements;
		/** Search owner for polling and reporting, including searches with no matches. */
		private ?Browser $browser = null;
		/** Retained collection query; explicitly constructed snapshots have none. */
		private ?Lookup $lookup = null;

		/** Explicit snapshots retain the first element's browser, if any. */
		public function __construct(Element ...$elements)
		{
			$this->elements = array_values($elements);
			$this->browser = $this->first()?->window()->browser();
		}

		/** @internal Bind search results to their owner even when there are no element references. */
		public static function fromReferences(Window $window, array $frames, array $references, ?Lookup $lookup = null): self
		{
			$elements = [];
			foreach (array_values($references) as $index => $reference):
				$elements[] = Element::fromReference($window, $frames, $reference, $lookup?->at($index));
			endforeach;
			$set = new self(...$elements);
			$set->browser = $window->browser();
			$set->lookup = $lookup;
			return $set;
		}

		public function count(): int
		{
			return count($this->elements);
		}

		/**
		 * Assert the count and return this set. By default, repeat the retained search every
		 * 100 ms and adopt membership on success. $wait=false checks the snapshot once;
		 * snapshots without a lookup require $wait=false.
		 * $timeout is positive, finite milliseconds, null for the browser default, and
		 * ignored without waiting. Report once after success or expiry. Driver errors propagate.
		 * Report to the active Test, including empty explicit snapshots. $verify records
		 * mismatches and continues. Outside Test::run(), failures always throw.
		 */
		public function countEquals(int $expected, bool $wait = true, ?float $timeout = null, bool $verify = false): self
		{
			if ($wait && $this->lookup === null):
				throw new \LogicException('Cannot wait for an ElementSet count without a retained search.');
			endif;
			$actual = (string)$this->count();
			$condition = function () use ($expected, $wait, &$actual) {
				if ($wait):
					$this->lookup->activate();
				endif;
				try {
					$set = $wait ? $this->lookup->all() : $this;
					$actual = (string)$set->count();
					if ($set->count() !== $expected):
						return false;
					endif;
					$this->elements = $set->elements;
					return true;
				} catch (\RuntimeException $error) {
					if (!$wait || !$this->lookup->retryable($error)):
						throw $error;
					endif;
					$actual = $error->getMessage();
					return false;
				}
			};
			$passed = $this->browser === null ? $condition() : $this->browser->evaluate($condition, $wait, $timeout);
			$message = 'Element count: expected ' . $expected . ', got ' . $actual . '.';
			if ($this->browser !== null):
				$this->browser->reportAssertion($passed, $message, $verify);
			elseif (($test = \ThriveData\ThrivePHP\Test::active()) !== null):
				if ($verify):
					$test->verify($passed, $message);
				else:
					$test->assert($passed, $message);
				endif;
			elseif (!$passed):
				throw new \RuntimeException('WebDriver assertion failed: ' . $message);
			endif;
			return $this;
		}

		public function first(): ?Element
		{
			return $this->elements[0] ?? null;
		}

		/** Return a zero-based member; out-of-range indices throw. */
		public function get(int $index): Element
		{
			if (!isset($this->elements[$index])):
				throw new \OutOfBoundsException('Element index is out of range: ' . $index);
			endif;
			return $this->elements[$index];
		}

		/** Return the members in document order. @return Element[] */
		public function all(): array
		{
			return $this->elements;
		}

		public function getIterator(): \Traversable
		{
			yield from $this->elements;
		}
	}
