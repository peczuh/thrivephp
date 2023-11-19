<?

	namespace ThriveData\ThrivePHP;
	
	class Route
	{
		public function __construct(
			public string $url,
			public string $callback,
			public string $method='{^(.*)$}',
			public string $host='{^(.*)$}',
			public ?string $id=null,
		) {}
	}