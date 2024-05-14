<?
	namespace ThriveData\ThrivePHP;
	
	class Application
	{
		static function init()
		{
			if (!defined('PATH_ROOT')):
				self::setPathRoot();
			endif;
				
			Settings::init();
			Log::init();
		}
		
		static function setPathRoot(?string $path = null)
		{
			if (is_null($path)):
				if (defined('PATH_ROOT')):
					$path = PATH_ROOT;
				else:
					if (class_exists('\Composer\AutoLoad\ClassLoader')):
						$reflection = new \ReflectionClass(\Composer\Autoload\ClassLoader::class);
						$path = dirname($reflection->getFileName(), 3);
					endif;
				endif;
			endif;
			
			if (is_null($path))
				throw new RootPathUnknown('could not determine root directory from PATH_ROOT or Composer');
			
			if (!file_exists($path))
				throw new RootPathUknown('root path does not exist');
			
			if (!is_readable($path))
				throw new RootPathUnknown('root path is not readable');
			
			define('PATH_ROOT', $path);
				
			return $path;
		}
		
		static function pathLocal()
		{
			if (defined('PATH_LOCAL')):
				return PATH_LOCAL;
			endif;
			
			return self::$pathroot.'/local';
		}
		
		
		static function start()
		{
			if (php_sapi_name() == 'cli'):
				Commander::init();
				Commander::start();
			else:
				Router::init();
				Router::start();
			endif;
		}
	}
	
	class ApplicationException extends Exception {}
	class AutoloadException extends Exception {}
	class RootPathUnknown extends ApplicationException {}

?>