<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	/** An accessible shadow root bound to its host's window and frame. */
	class ShadowRoot
	{
		/** W3C JSON shadow-root reference key. */
		public const REFERENCE = 'shadow-6066-11e4-a52e-4f735466cecf';

		/** @internal Obtain roots through Element::shadowRoot() or script results. */
		public function __construct(private Window $window, private string $id, private array $frames = [], private ?Element $host = null)
		{
			if ($id === ''):
				throw new \InvalidArgumentException('Shadow-root ID must not be empty.');
			endif;
		}

		/** The opaque remote ID, for logging or protocol integration. */
		public function id(): string
		{
			return $this->id;
		}

		/**
		 * Find within this root using Browser::find() conditions and millisecond timeouts.
		 * Waiting defaults to true and re-finds the host when it has a lookup;
		 * $wait=false checks once. Positive success returns an Element; inverted success
		 * null. Supported strategies depend on the driver.
		 */
		public function find(string $selector, string $using = 'css selector', bool $visible = false, bool $wait = true, bool $invert = false, bool $assert = false, ?float $timeout = null): ?Element
		{
			return (new Lookup($this->window, $this->frames, $selector, $using, $this))->find($visible, $wait, $invert, $assert, $timeout);
		}

		/** Return matching descendants in document order, possibly empty. */
		public function findAll(string $selector, string $using = 'css selector'): ElementSet
		{
			return (new Lookup($this->window, $this->frames, $selector, $using, $this))->all(false);
		}

		/** Return the W3C reference, optionally checking its destination context. */
		public function reference(?Window $window = null, ?array $frames = null): array
		{
			if (($window !== null && $window !== $this->window) || ($frames !== null && $frames !== $this->frames)):
				throw new \InvalidArgumentException('Shadow root belongs to another browser session or browsing context.');
			endif;
			return [self::REFERENCE => $this->id];
		}

		/** @internal Validate a protocol shadow-root reference before exposing it. */
		public static function fromReference(Window $window, array $frames, mixed $reference, ?Element $host = null): self
		{
			$id = is_array($reference) ? ($reference[self::REFERENCE] ?? null) : null;
			if (!is_string($id) || $id === ''):
				throw new \RuntimeException('WebDriver did not return a shadow-root ID.');
			endif;
			return new self($window, $id, $frames, $host);
		}

		/** @internal A root can be recovered only through a host with a complete retained lookup. */
		public function canRefresh(): bool
		{
			return $this->host !== null && $this->host->canRefresh();
		}

		/** @internal Reacquire the root from its host; script-returned roots keep their original reference. */
		public function resolve(): ?self
		{
			return $this->host === null ? $this : $this->host->resolve()?->shadowRoot();
		}
	}
