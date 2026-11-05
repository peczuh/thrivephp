<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	/**
	 * Ordered, fixed-membership search results. Elements remain live remote references
	 * and may become stale; repeat the search to refresh membership. Empty sets are
	 * truthy PHP objects: use count() or first() in wait conditions.
	 * @implements \IteratorAggregate<int, Element>
	 */
	class ElementSet implements \IteratorAggregate, \Countable
	{
		/** @var Element[] */
		private array $elements;
		/** Search owner for reporting assertions, including searches with no matches. */
		private ?Browser $browser = null;

		/** Explicit snapshots use the first element's assertion recorder, if any. */
		public function __construct(Element ...$elements)
		{
			$this->elements = array_values($elements);
			$this->browser = $this->first()?->window()->browser();
		}

		/** @internal Bind search results to their owner even when there are no element references. */
		public static function fromReferences(Window $window, array $frames, array $references): self
		{
			$set = new self(...array_map(fn($reference) => Element::fromReference($window, $frames, $reference), $references));
			$set->browser = $window->browser();
			return $set;
		}

		public function count(): int
		{
			return count($this->elements);
		}

		/**
		 * Assert this snapshot's count once and return this set; the search is not repeated.
		 * Use Browser's assertion callback, or throw on failure when none was supplied.
		 */
		public function countEquals(int $expected): self
		{
			$actual = $this->count();
			$message = 'Element count: expected ' . $expected . ', got ' . $actual . '.';
			if ($this->browser !== null):
				$this->browser->reportAssertion($actual === $expected, $message);
			elseif ($actual !== $expected):
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
