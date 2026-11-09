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

		/** Describe a search within this root. Host-backed roots follow the host search on each operation. */
		public function find(string $selector, string $using = 'css selector'): Element
		{
			return (new Lookup($this->window, $this->frames, $selector, $using, $this))->target();
		}

		/** Describe a collection search within this root. */
		public function findAll(string $selector, string $using = 'css selector'): ElementSet
		{
			return (new Lookup($this->window, $this->frames, $selector, $using, $this))->collection();
		}

		/** Return the W3C reference, optionally checking its destination context. */
		public function reference(?Window $window = null, ?array $frames = null, bool $wait = true): array
		{
			if (($window !== null && $window !== $this->window) || ($frames !== null && $frames !== $this->frames)):
				throw new \InvalidArgumentException('Shadow root belongs to another browser session or browsing context.');
			endif;
			return [self::REFERENCE => $this->resolve($wait)->id];
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
		public function resolve(bool $wait = true, ?float $timeout = null): self
		{
			return $this->host === null ? $this : $this->host->resolve($wait, $timeout)->shadowRoot();
		}

		/** Describe the scope without resolving a host search. */
		public function description(): string
		{
			return $this->host === null ? 'shadow ' . $this->id : 'shadow of ' . $this->host->description();
		}
	}
