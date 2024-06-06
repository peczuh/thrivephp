<?

	namespace ThriveData\ThrivePHP;
	
	/**
	 * Defines the HTTP request to match and a callback to call if matched. Routes are registered with `Router::register()`.
	 *
	 * Example:
	 * ```php
	 * new Route(url: '{^/user/([0-9]+)/account$}', callback: '\ui\pages\user\account::routed');
	 * new Route(url: '{^/api/v1/user/([0-9]+)/details$}', callback: '\api\v1\user\details::routed');
	 * ```
	 */
	class Route
	{
		/**
		 * Creates a new Route.
		 *
		 * @param string $url A regex to compare against the HTTP request URL. It is recommened to use the regex deliminators `{` and `}` as opposed to the usual `/` and `/` to avoid having to escape every forward slash.
		 * @param string $callback The method or function to call when the route is matched by `Router`.
		 * @param string $method A regex to compare against the HTTP method (e.g. GET, POST, etc.). By default matches any method.
		 * @param string $host A regex to compare against the HTTP host. By default matches any host.
		 * @param string $id A unique ID for identifying the exact Route among all registered routes. Probably only useful for debugging purposes.
		 */
		public function __construct(
			public string $url,
			public string $callback,
			public string $method='{^(.*)$}',
			public string $host='{^(.*)$}',
			public ?string $id=null,
		) {}
	}