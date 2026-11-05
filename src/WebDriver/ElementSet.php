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

		public function __construct(Element ...$elements)
		{
			$this->elements = array_values($elements);
		}

		public function count(): int
		{
			return count($this->elements);
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
