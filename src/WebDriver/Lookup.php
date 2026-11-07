<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	/** @internal A selector and its original search scope, retained for assertion retries. */
	class Lookup
	{
		/** A findAll() member is reselected by its original zero-based position. */
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

		/** Describe the query in assertion and timeout diagnostics. */
		public function description(): string
		{
			return 'Selector ' . var_export($this->selector, true) . ' using ' . var_export($this->using, true);
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

		/** Retry missing matches, and stale references only when the scope can be recovered. */
		public function retryable(\RuntimeException $error): bool
		{
			return (bool)preg_match('/^WebDriver HTTP [45][0-9]{2} no such element: /', $error->getMessage())
				|| ($this->canRefresh() && (bool)preg_match('/^WebDriver HTTP [45][0-9]{2} (stale element reference|detached shadow root|no such shadow root): /', $error->getMessage()));
		}

		/** Retain the same selector/scope for a positional member of a search result. */
		public function at(int $index): self
		{
			$lookup = clone $this;
			$lookup->index = $index;
			return $lookup;
		}

		/** Repeat the lookup; null means a positional member or its ancestor no longer exists. */
		public function element(bool $refresh = true): ?Element
		{
			$reference = $this->query($this->index !== null, $refresh);
			if ($this->index !== null):
				$reference = $reference[$this->index] ?? null;
			endif;
			return $reference === null ? null : Element::fromReference($this->window, $this->frames, $reference, $this);
		}

		/** Repeat a collection search, preserving the query even for an empty result. */
		public function all(bool $refresh = true): ElementSet
		{
			return ElementSet::fromReferences($this->window, $this->frames, $this->query(true, $refresh) ?? [], $this);
		}

		/** Evaluate a presence/visibility condition; positive success returns a node, inverse success null. */
		public function find(bool $visible, bool $wait, bool $invert, bool $assert, ?float $timeout, bool $verify = false): ?Element
		{
			$browser = $this->window->browser();
			$element = null;
			$lastError = null;
			$actual = 'no matches';
			$passed = $browser->evaluate(function () use ($visible, $wait, $invert, &$element, &$lastError, &$actual) {
				// An unrestorable frame is a context error, not an unmet selector condition.
				$this->activate();
				try {
					if ($invert):
						$elements = $this->all($wait);
						if (!$visible):
							$actual = $elements->count() . ' matches';
							return $elements->count() === 0;
						endif;
						foreach ($elements as $index => $match):
							if ($match->displayed()):
								$actual = 'visible match #' . ($index + 1);
								return false;
							endif;
						endforeach;
						$actual = 'none';
						return true;
					endif;
					$match = $this->element($wait);
					$actual = $match === null ? 'no matches' : 'a match';
					if ($match === null):
						return false;
					endif;
					if ($visible && !$match->displayed()):
						$actual .= ' is hidden';
						return false;
					endif;
					$element = $match;
					return true;
				} catch (\RuntimeException $error) {
					if (!$this->retryable($error) || (!$wait && !str_contains($error->getMessage(), ' no such element: '))):
						throw $error;
					endif;
					$lastError = $error;
					$actual = $error->getMessage();
					return false;
				}
			}, $wait, $timeout);
			$expected = $invert ? ($visible ? 'no visible matches' : 'no matches') : ($visible ? 'a visible first match' : 'a match');
			$message = $this->description() . ': expected ' . $expected . ', got ' . $actual . '.';
			if ($assert || $verify):
				$browser->reportAssertion($passed, $message, $verify, ['expected' => $expected, 'actual' => $actual]);
			endif;
			if (!$passed && !$verify):
				if (!$wait && $lastError !== null):
					throw $lastError;
				endif;
				throw new \RuntimeException(($wait ? 'WebDriver wait timed out. ' : 'WebDriver find failed. ') . $message, 0, $lastError);
			endif;
			return $element;
		}

		/** Issue a query in the captured context, optionally re-finding ancestor elements/hosts. */
		private function query(bool $multiple, bool $refresh): ?array
		{
			$this->activate();
			$scope = $this->scope;
			if ($refresh && $scope !== null):
				try {
					$scope = $scope->resolve();
				} catch (\RuntimeException $error) {
					if (!preg_match('/^WebDriver HTTP [45][0-9]{2} (no such element|no such shadow root): /', $error->getMessage())):
						throw $error;
					endif;
					// An absent ancestor means the scoped search has no matches either.
					return null;
				}
				if ($scope === null):
					return null;
				endif;
			endif;
			$path = $scope === null ? '' : ($scope instanceof Element ? '/element/' : '/shadow/') . rawurlencode($scope->id());
			return $this->window->request('POST', $path . ($multiple ? '/elements' : '/element'), [
				'using' => $this->using, 'value' => $this->selector,
			], $this->frames);
		}
	}
