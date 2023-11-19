<?
	namespace ThriveData\ThrivePHP;

	class Router
	{
		static $method;
		static $host;
		static $url;
		static $routes = [];
		
		static function init()
		{
			self::$url = $_SERVER['PATH_INFO'];
			self::$method = $_SERVER['REQUEST_METHOD'];
			self::$host = $_SERVER['HTTP_HOST'];
			
			$file = Application::PATH_ROOT.'/routes.php';
			if (is_readable($file)):
				require($file);
			endif;
		}
		
		static function register(Route $route)
		{
			self::$routes[$route->host][$route->url][$route->method] = $route;
		}
		
		static function call(string $host, string $url, string $method)
		{
			foreach(self::$routes as $host_regex => $host_routes):
				if (preg_match($host_regex, $host, $host_matches)):
					foreach ($host_routes ?? [] as $url_regex => $method_routes):
						if (preg_match($url_regex, $url, $url_matches)):
							foreach ($method_routes ?? [] as $method_regex => $route):
								if (preg_match($method_regex, $method, $method_matches)):
									Log::debug(sprintf('routing %s', $route->url), debug_backtrace());
									try {
										return ($route->callback)(
											data: $_REQUEST,
											url: $url_matches, 
											method: $method_matches, 
											host: $host_matches, 
											route: $route
										);
									} catch (Throwable $e) {
										Log::exception($e);
									}
								endif;
							endforeach;
						endif;
					endforeach;
				endif;
			endforeach;
			
			Log::debug(sprintf('route not found (host=%s, url=%s)', $host, $url), debug_backtrace());
			http_response_code(404);
			print('404 not found');
		}
		
		static function start()
		{
			self::call(host: self::$host, url: self::$url, method: self::$method);
		}
	}
	
?>