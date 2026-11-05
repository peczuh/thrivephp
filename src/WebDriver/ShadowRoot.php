<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	/** An accessible shadow root bound to its host's window and frame. */
	class ShadowRoot
	{
		/** W3C JSON shadow-root reference key. */
		public const REFERENCE = 'shadow-6066-11e4-a52e-4f735466cecf';

		/** @internal Obtain roots through Element::shadowRoot() or script results. */
		public function __construct(private Window $window, private string $id, private array $frames = [])
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

		/** Find the first match within this root; supported strategies depend on the driver. */
		public function find(string $selector, string $using = 'css selector'): Element
		{
			return Element::fromReference($this->window, $this->frames, $this->request('/element', $selector, $using));
		}

		/** Return matching descendants in document order, possibly empty. */
		public function findAll(string $selector, string $using = 'css selector'): ElementSet
		{
			$references = $this->request('/elements', $selector, $using);
			return ElementSet::fromReferences($this->window, $this->frames, $references);
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
		public static function fromReference(Window $window, array $frames, mixed $reference): self
		{
			$id = is_array($reference) ? ($reference[self::REFERENCE] ?? null) : null;
			if (!is_string($id) || $id === ''):
				throw new \RuntimeException('WebDriver did not return a shadow-root ID.');
			endif;
			return new self($window, $id, $frames);
		}

		/** Search in the host's captured context, independently of subsequent frame selection. */
		private function request(string $path, string $selector, string $using): mixed
		{
			return $this->window->request('POST', '/shadow/' . rawurlencode($this->id) . $path, [
				'using' => $using, 'value' => $selector,
			], $this->frames);
		}
	}
