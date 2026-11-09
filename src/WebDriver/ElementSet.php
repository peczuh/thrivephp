<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	/**
	 * A collection search, or an explicitly constructed fixed-membership snapshot.
	 * Searches count current matches and create positional targets with first()/get().
	 * all() and iteration materialize one snapshot of exact nodes, which may become stale.
	 * Empty sets are truthy objects; use count() or countEquals() to check membership.
	 * @implements \IteratorAggregate<int, Element>
	 */
	class ElementSet implements \IteratorAggregate, \Countable
	{
		/** @var Element[] Fixed members for explicitly constructed snapshots. */
		private array $elements;
		/** Search owner for polling and reporting, including searches with no matches. */
		private ?Browser $browser = null;
		/** Retained collection query; explicitly constructed snapshots have none. */
		private ?Lookup $lookup = null;

		/** Explicit snapshots retain their supplied members and the first member's browser, if any. */
		public function __construct(Element ...$elements)
		{
			$this->elements = array_values($elements);
			$this->browser = $this->first()?->window()->browser();
		}

		/** @internal Construct a lazy collection, including searches that currently match nothing. */
		public static function fromLookup(Browser $browser, Lookup $lookup): self
		{
			$set = new self();
			$set->browser = $browser;
			$set->lookup = $lookup;
			return $set;
		}

		/** @internal Materialize exact references with no retained member searches. */
		public static function fromReferences(Window $window, array $frames, array $references): self
		{
			$set = new self(...array_map(fn($reference) => Element::fromReference($window, $frames, $reference), array_values($references)));
			$set->browser = $window->browser();
			return $set;
		}

		/** Count current matches once, or fixed snapshot membership. Zero is a valid result. */
		public function count(): int
		{
			return count($this->all());
		}

		/** The retained query, or a description of an explicitly constructed snapshot. */
		public function description(): string
		{
			return $this->lookup?->description() ?? 'element set';
		}

		/**
		 * Query by default; $assert records once and returns this set. $is=false negates.
		 * Null $wait checks queries once and polls assertions; explicit snapshots require
		 * $wait=false. Timeout is positive, finite milliseconds, null for assertionTimeout.
		 * $stop=false continues failed assertions under an active Test; otherwise they throw.
		 */
		public function countEquals(int $expected, bool $is = true, bool $assert = false, bool $stop = true, ?bool $wait = null, ?float $timeout = null): bool|self
		{
			if ($assert && ($test = Step::recorder()) !== null) return Step::run($test, $this, __FUNCTION__, func_get_args());
			$wait ??= $assert;
			if ($wait && $this->lookup === null) throw new \LogicException('Cannot wait for an ElementSet count without a retained search.');
			$actual = null;
			$condition = function () use ($expected, $wait, $is, &$actual) {
				$this->lookup?->activate();
				try {
					$actual = $this->count();
					return ($actual === $expected) === $is;
				} catch (\RuntimeException $error) {
					if (!$wait || !$this->lookup->retryable($error)) throw $error;
					$actual = $error->getMessage();
					return false;
				}
			};
			$passed = $this->browser === null ? $condition() : $this->browser->evaluate($condition, $wait, $timeout, assertion: true);
			if (!$assert) return $passed;
			$message = 'Element count: expected ' . (!$is ? 'not ' : '') . $expected . ', got ' . $actual . '.';
			$comparison = ['expected' => $expected, 'actual' => $actual] + (!$is ? ['invert' => true] : []);
			if ($this->browser !== null):
				$this->browser->reportAssertion($passed, $message, !$stop, $comparison);
			elseif (class_exists(\ThriveData\ThrivePHP\Test::class) && ($test = \ThriveData\ThrivePHP\Test::active()) !== null):
				if ($stop):
					$test->assert($passed, $message, $comparison);
				else:
					$test->verify($passed, $message, $comparison);
				endif;
			elseif (!$passed):
				throw new \RuntimeException('WebDriver assertion failed: ' . $message);
			endif;
			return $this;
		}

		/** A first-position target for searches; null only for an empty explicit snapshot. */
		public function first(): ?Element
		{
			return $this->lookup === null ? ($this->elements[0] ?? null) : $this->get(0);
		}

		/** Describe a zero-based position, or return a fixed member. Negative/out-of-range snapshot indices throw. */
		public function get(int $index): Element
		{
			if ($this->lookup !== null) return $this->lookup->at($index)->target();
			if (!isset($this->elements[$index])) throw new \OutOfBoundsException('Element index is out of range: ' . $index);
			return $this->elements[$index];
		}

		/** Materialize current matches once, or return the explicit members. @return Element[] */
		public function all(): array
		{
			return $this->lookup === null ? $this->elements : $this->lookup->all()->all();
		}

		public function getIterator(): \Traversable
		{
			yield from $this->all();
		}
	}
