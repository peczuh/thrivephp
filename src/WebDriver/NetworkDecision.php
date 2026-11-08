<?php
	namespace ThriveData\ThrivePHP\WebDriver;

	/** A network interception decision value. Returning it never performs nested browser I/O. */
	final class NetworkDecision
	{
		private function __construct(private string $method, private array $params = []) {}

		/** Continue unchanged, or replace supplied fields. Headers are ordered [name, value] string pairs; body is bytes. */
		public static function continueRequest(?string $url = null, ?string $method = null, ?array $headers = null, ?string $body = null): self
		{
			$params = [];
			if ($url !== null) $params['url'] = $url;
			if ($method !== null) $params['method'] = $method;
			if ($headers !== null) $params['headers'] = self::headers($headers);
			if ($body !== null) $params['body'] = ['type' => 'base64', 'value' => base64_encode($body)];
			return new self('network.continueRequest', $params);
		}

		/**
		 * Continue at responseStarted with the original network body. Null fields preserve
		 * server values; a supplied status must be 100 through 599. Headers are ordered
		 * [name, value] string pairs; [] requests an empty list. Browser/HTTP rules still apply.
		 */
		public static function continueResponse(?int $status = null, ?array $headers = null): self
		{
			$params = [];
			if ($status !== null):
				if ($status < 100 || $status > 599) throw new \InvalidArgumentException('Expected an HTTP status from 100 through 599.');
				$params['statusCode'] = $status;
			endif;
			if ($headers !== null) $params['headers'] = self::headers($headers);
			return new self('network.continueResponse', $params);
		}

		/** Supply a complete response at request/response interception. Headers preserve duplicates; body is bytes, including empty. */
		public static function respond(int $status = 200, array $headers = [], string $body = ''): self
		{
			if ($status < 100 || $status > 599) throw new \InvalidArgumentException('Expected an HTTP status from 100 through 599.');
			return new self('network.provideResponse', ['statusCode' => $status, 'headers' => self::headers($headers),
				'body' => ['type' => 'base64', 'value' => base64_encode($body)]]);
		}

		/** Fail at request/response interception with a browser network error; authentication requires cancelAuth(). */
		public static function fail(): self { return new self('network.failRequest'); }

		/** Answer an authRequired challenge with password credentials; the browser determines the authentication scheme. */
		public static function provideCredentials(string $username, string $password): self
		{
			return new self('network.continueWithAuth', ['action' => 'provideCredentials',
				'credentials' => ['type' => 'password', 'username' => $username, 'password' => $password]]);
		}

		/** Resume browser-default authentication, which may open a prompt or leave a navigation pending. */
		public static function defaultAuth(): self { return new self('network.continueWithAuth', ['action' => 'default']); }

		/** Cancel an authRequired challenge without providing credentials. */
		public static function cancelAuth(): self { return new self('network.continueWithAuth', ['action' => 'cancel']); }

		/** @internal Managed phase compatibility; response replacement and failure work in either non-auth phase. */
		public function supportsPhase(string $phase): bool
		{
			return match ($this->method) {
				'network.continueRequest' => $phase === 'beforeRequestSent',
				'network.continueResponse' => $phase === 'responseStarted',
				'network.continueWithAuth' => $phase === 'authRequired',
				'network.provideResponse', 'network.failRequest' => in_array($phase, ['beforeRequestSent', 'responseStarted'], true),
			};
		}

		/** @internal Bind a value to the owned request; callers cannot substitute another request ID. */
		public function command(string $request): array
		{
			return ['method' => $this->method, 'params' => ['request' => $request] + $this->params];
		}

		private static function headers(array $headers): array
		{
			$result = [];
			foreach ($headers as $header):
				if (!is_array($header) || !array_is_list($header) || count($header) !== 2 || !is_string($header[0]) || !is_string($header[1])) {
					throw new \InvalidArgumentException('Expected ordered [name, value] string header pairs.');
				}
				$value = preg_match('//u', $header[1]) === 1
					? ['type' => 'string', 'value' => $header[1]] : ['type' => 'base64', 'value' => base64_encode($header[1])];
				$result[] = ['name' => $header[0], 'value' => $value];
			endforeach;
			return $result;
		}
	}
