<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	/**
	 * An immutable HTML capture, independent of the browser session. Capture happens
	 * when snapshot() is called; reading or saving this object never captures again.
	 * External resources remain references rather than bundled archive contents.
	 */
	final class Snapshot
	{
		/** Create a capture from serialized HTML, not a URL or file path. */
		public function __construct(private string $data)
		{
		}

		/** Return the captured HTML, including after the browser has quit. */
		public function data(): string
		{
			return $this->data;
		}

		/**
		 * Write the captured HTML to $path, overwriting an existing file. The parent
		 * directory must exist. Failed or incomplete writes throw; returns this capture.
		 * Example: $browser->snapshot()->save('page.html').
		 */
		public function save(string $path): self
		{
			$written = @file_put_contents($path, $this->data);
			if ($written === false || $written !== strlen($this->data)):
				throw new \RuntimeException('Could not write the complete snapshot to: ' . $path);
			endif;
			return $this;
		}
	}
