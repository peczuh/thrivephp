<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	/**
	 * An immutable PDF capture, independent of the browser session. Printing happens
	 * when pdf() is called; reading or saving this object never prints again.
	 */
	final class PDF
	{
		/** Create a capture from decoded PDF bytes, not base64 text or a file path. */
		public function __construct(private string $data)
		{
		}

		/** Return the decoded binary PDF bytes, including after the browser has quit. */
		public function data(): string
		{
			return $this->data;
		}

		/**
		 * Write the captured PDF bytes to $path, overwriting an existing file. The parent
		 * directory must exist. Failed or incomplete writes throw; returns this capture.
		 * Example: $browser->pdf()->save('page.pdf').
		 */
		public function save(string $path): self
		{
			$written = @file_put_contents($path, $this->data);
			if ($written === false || $written !== strlen($this->data)):
				throw new \RuntimeException('Could not write the complete PDF to: ' . $path);
			endif;
			return $this;
		}
	}
