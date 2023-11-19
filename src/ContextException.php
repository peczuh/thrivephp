<?
	namespace ThriveData\ThrivePHP;
	
	class ContextException extends \Exception
	{
		protected array $context;
		
		public function __construct(string $message = "", int $code = 0, ?\Throwable $previous = null, ?array $context = [])
		{
			$this->context = $context;
			parent::__construct($message, $code, $previous);
		}
		
		public function getContext()
		{
			return $this->context;
		}
	}
?>
