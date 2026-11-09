<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	/** @internal A search in a captured window/frame, optionally scoped to a parent or shadow root. */
	class Lookup
	{
		/** A findAll() member is selected by its zero-based position. */
		private ?int $index = null;

		/** Null scope searches the document; other scopes retain their own ancestor lookups. */
		public function __construct(
			private Window $window,
			private array $frames,
			private string $selector,
			private string $using,
			private Element|ShadowRoot|null $scope = null,
		) {
		}

		/** Describe the search without contacting the driver. */
		public function description(): string
		{
			return 'Selector ' . var_export($this->selector, true) . ' using ' . var_export($this->using, true)
				. ($this->index === null ? '' : ' at index ' . $this->index);
		}

		/** Restore the captured window/frame before entering retryable element operations. */
		public function activate(): void
		{
			$this->window->browser()->activate($this->window, $this->frames);
		}

		/** Whether every ancestor scope can be re-found after replacement. */
		public function canRefresh(): bool
		{
			return $this->scope === null || $this->scope->canRefresh();
		}

		/** Missing matches can appear later; stale scopes need a recoverable search. */
		public function retryable(\RuntimeException $error): bool
		{
			return (bool)preg_match('/^WebDriver HTTP [45][0-9]{2} no such element: /', $error->getMessage())
				|| ($this->canRefresh() && (bool)preg_match('/^WebDriver HTTP [45][0-9]{2} (stale element reference|detached shadow root|no such shadow root): /', $error->getMessage()));
		}

		/** Retain the selector/scope for a positional member of a search result. */
		public function at(int $index): self
		{
			if ($index < 0) throw new \OutOfBoundsException('Element index is out of range: ' . $index);
			$lookup = clone $this;
			$lookup->index = $index;
			return $lookup;
		}

		/** Construct a target without searching. */
		public function target(): Element
		{
			return new Element($this->window, null, $this->frames, $this);
		}

		/** Construct a collection search without materializing its members. */
		public function collection(): ElementSet
		{
			return ElementSet::fromLookup($this->window->browser(), $this);
		}

		/** Search once and return an exact node. The caller owns any polling budget. */
		public function element(): Element
		{
			$reference = $this->query($this->index !== null);
			if ($this->index !== null) $reference = $reference[$this->index] ?? null;
			if ($reference === null) throw new \RuntimeException('WebDriver HTTP 404 no such element: ' . $this->description());
			return Element::fromReference($this->window, $this->frames, $reference);
		}

		/** Materialize one snapshot of exact nodes, including an empty result. */
		public function all(): ElementSet
		{
			return ElementSet::fromReferences($this->window, $this->frames, $this->query(true) ?? []);
		}

		/** Issue one query in the captured context, resolving ancestor elements/hosts first. */
		private function query(bool $multiple): ?array
		{
			$this->activate();
			$scope = $this->scope;
			if ($scope !== null):
				try {
					$scope = $scope->resolve(wait: false);
				} catch (\RuntimeException $error) {
					if (!preg_match('/^WebDriver HTTP [45][0-9]{2} (no such element|no such shadow root): /', $error->getMessage())) throw $error;
					// An absent ancestor means the scoped search has no matches either.
					return null;
				}
			endif;
			$path = $scope === null ? '' : ($scope instanceof Element ? '/element/' : '/shadow/') . rawurlencode($scope->id());
			return $this->window->request('POST', $path . ($multiple ? '/elements' : '/element'), [
				'using' => $this->using, 'value' => $this->selector,
			], $this->frames);
		}
	}
