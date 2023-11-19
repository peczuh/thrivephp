<?
	namespace ThriveData\ThrivePHP;
	
	class Session
	{
		static function start()
		{
			Log::debug(message: 'starting session', backtrace: debug_backtrace());
				
			if(!isset($_SESSION)):
				\session_start();
			endif;
			
			setcookie('crumb_device', $_COOKIE['crumb_device'] ?? self::token(), time()+60*60*24*365, '/');
			
			if (isset($_COOKIE['crumb_user'])):
				setcookie('crumb_user', $_COOKIE['crumb_user'], time()+60*60*24*7, '/');
			endif;
		}
		
		static function clear()
		{
			self::start();
			
			$_SESSION = array();
		}
		
		static function authenticate()
		{
			Log::debug('authenticating', debug_backtrace());
			
			self::start();
			
			if(!isset($_SESSION['session']['id'])):
				Log::debug('session is not set, redirecting to login', debug_backtrace());
				Response::redirect("/login?referrer={$_SERVER['REQUEST_URI']}");
			endif;
			
			try {
				$session = DB::query(<<<SQL
					UPDATE users_sessions SET expires_when = now()+make_interval(hours := 24) WHERE id=$1 AND expires_when > now() RETURNING *
					SQL, $_SESSION['session']['id']
				)->single();
				Log::debug(sprintf('updated user session | id=[%s]', $session->id), debug_backtrace());
			} catch (\Thrive\DatabaseNoResult $e) {
				Log::debug(
					sprintf('could not update user session | id=[%s] | message=[%s] ',
						$_SESSION['session']['id'],
						$e->getMessage()
						),
					debug_backtrace()
				);
				Response::redirect('/login');
			}
		}
		
		static function recent($class, $value)
		{
			self::start();
			
			if(!isset($_SESSION['recents'][$class]) || !is_array($_SESSION['recents'][$class])):
				$_SESSION['recents'][$class] = [];
			endif;
			array_unshift($_SESSION['recents'][$class], $value);
			$_SESSION['recents'][$class] = array_values(array_unique($_SESSION['recents'][$class]));
		}
		
		static function token($length=32)
		{
			$data = random_bytes(16);
			
			$data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
			$data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
			
			return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
		}

	}
?>
