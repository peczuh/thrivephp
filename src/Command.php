<?
	namespace ThriveData\ThrivePHP;

	class Command
	{
		public function __construct(
			public string $name,
			public $callback,
			public ?string $options=null
		) {}
	}
?>