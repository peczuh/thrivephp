<?
	namespace ThriveData\ThrivePHP;
	
	class Response
	{
		static $code = '200';
		static $headers = [];
		
		static function json()
		{
			self::$headers['Content-Type'] = 'application/json';
		}
		
		static function success(int $code=200, ?array $headers=[])
		{
			if ($code < 200 or $code > 299):
				throw ResponseException('response code is out of range');
			endif;
			
			self::$code = $code;
			self::$headers = $headers;
			
			http_response_code(self::$code);
		}
		
		static function redirect(string $url, ...$values)
		{
			Log::debug('redirecting to '.$url, debug_backtrace());
			header(sprintf('Location: %s', sprintf($url, ...$values)), true, 302);
			exit();
		}
		
		static function send()
		{
		}
	}
	
	class ResponseException extends Exception {}
?>