<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	/**
	 * An immutable PNG capture, independent of the browser session. Capture happens
	 * when screenshot() is called; reading or saving this object never captures again.
	 */
	final class Screenshot
	{
		/** Create a capture from decoded PNG bytes, not base64 text or a file path. */
		public function __construct(private string $data)
		{
		}

		/** Return the decoded binary PNG bytes, including after the browser has quit. */
		public function data(): string
		{
			return $this->data;
		}

		/**
		 * Write the captured PNG bytes to $path, overwriting an existing file. The parent
		 * directory must exist. Failed or incomplete writes throw; returns this capture.
		 * Example: $browser->screenshot()->save('page.png').
		 */
		public function save(string $path): self
		{
			$written = @file_put_contents($path, $this->data);
			if ($written === false || $written !== strlen($this->data)):
				throw new \RuntimeException('Could not write the complete screenshot to: ' . $path);
			endif;
			return $this;
		}

		/** Return the captured image width in pixels, which may differ from CSS pixels. */
		public function width(): int
		{
			return $this->dimensions()[0];
		}

		/** Return the captured image height in pixels, which may differ from CSS pixels. */
		public function height(): int
		{
			return $this->dimensions()[1];
		}

		/** Read PNG dimensions without decoding pixels or requiring the GD extension. */
		private function dimensions(): array
		{
			$size = @getimagesizefromstring($this->data);
			if ($size === false || $size[2] !== IMAGETYPE_PNG):
				throw new \RuntimeException('Screenshot does not contain a valid PNG image header.');
			endif;
			return $size;
		}
	}
