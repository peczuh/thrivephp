<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	/** @internal cURL owns HTTP framing/TLS; the caller services BiDi between transfer turns. */
	final class HttpRequest
	{
		/** Run one JSON request with a total seconds budget, servicing the optional dispatcher between turns. */
		public static function run(string $endpoint, string $method, string $path, ?array $body, float $timeout, ?callable $service = null): mixed
		{
			$deadline = hrtime(true) / 1e9 + $timeout;
			$handle = curl_init($endpoint . $path);
			$multi = curl_multi_init();
			$options = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', 'Connection: close', 'Expect:'],
				CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT_MS => max(1, (int)ceil($timeout * 1000)),
				CURLOPT_CONNECTTIMEOUT_MS => max(1, (int)ceil($timeout * 1000)),
				CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
				CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
			// Owned drivers use loopback; proxy environment variables must not redirect those sessions.
			if (in_array(parse_url($endpoint, PHP_URL_HOST), ['127.0.0.1', '[::1]', 'localhost'], true)) $options[CURLOPT_PROXY] = '';
			if ($body !== null) $options[CURLOPT_POSTFIELDS] = json_encode((object)$body, JSON_THROW_ON_ERROR);
			try {
				curl_setopt_array($handle, $options);
				if (curl_multi_add_handle($multi, $handle) !== CURLM_OK) throw new \RuntimeException('Could not start WebDriver HTTP transfer.');
				do {
					if ($service !== null) $service();
					if (curl_multi_exec($multi, $running) !== CURLM_OK) throw new \RuntimeException('WebDriver HTTP multi transfer failed.');
					if (!$running) break;
					$remaining = $deadline - hrtime(true) / 1e9;
					if ($remaining <= 0) throw new \RuntimeException("WebDriver HTTP $method $path failed, timed out, or returned an incomplete body.");
					// PHP cannot add our WebSocket to curl_multi_wait(). See doc/webdriver-bidi.md.
					$wait = min($remaining, $service === null ? 0.1 : 0.01);
					$before = hrtime(true);
					$ready = curl_multi_select($multi, $wait);
					if ($ready < 0) throw new \RuntimeException('WebDriver HTTP readiness wait failed.');
					// No cURL descriptors can produce an immediate zero; avoid a CPU spin.
					if ($ready === 0 && hrtime(true) - $before < 100000) usleep((int)ceil($wait * 1e6));
				} while ($running);
				$completion = curl_multi_info_read($multi);
				if (!$completion || $completion['result'] !== CURLE_OK):
					if (curl_getinfo($handle, CURLINFO_RESPONSE_CODE) === 0) throw new \RuntimeException("WebDriver HTTP $method $path failed to connect or receive headers: " . curl_error($handle));
					throw new \RuntimeException("WebDriver HTTP $method $path failed, timed out, or returned an incomplete body: " . curl_error($handle));
				endif;
				$response = curl_multi_getcontent($handle);
				$status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
			} finally {
				curl_multi_remove_handle($multi, $handle);
			}
			try {
				$result = json_decode($response, true, 512, JSON_THROW_ON_ERROR);
			} catch (\JsonException $error) {
				throw new \RuntimeException("WebDriver HTTP $status returned invalid JSON.", 0, $error);
			}
			if (!is_array($result) || !array_key_exists('value', $result)) throw new \RuntimeException("WebDriver HTTP $status response is missing value.");
			$value = $result['value'];
			if ($status < 200 || $status >= 300) throw new \RuntimeException("WebDriver HTTP $status " . ($value['error'] ?? 'request failed') . ': ' . ($value['message'] ?? 'No error message.'));
			return $value;
		}
	}
