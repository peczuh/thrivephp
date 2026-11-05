<?
	namespace ThriveData\ThrivePHP\Database;
	
	class DateTime extends \DateTime
	{
		public function __toString()
		{
			return $this->format('c');
		}
	}
?>
