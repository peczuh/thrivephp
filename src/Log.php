<?
	namespace ThriveData\ThrivePHP;
	
	class Log
	{
		static function init()
		{
			error_reporting(E_ALL);
			
			set_exception_handler('\ThriveData\ThrivePHP\Log::exception');
			set_error_handler('\ThriveData\ThrivePHP\Log::error');
			register_shutdown_function('\ThriveData\ThrivePHP\Log::shutdown');
		}

		static function debug($message, array $backtrace)
		{
			error_log(sprintf("DEBUG: %s\n%s", $message, self::backtrace_format($backtrace)));
		}
		
		static function backtrace_format(array $backtrace)
		{
			$fmt = '';
			foreach($backtrace as $idx => $frame):
				$fmt .= sprintf("#%s | %s:%s | %s%s%s\n", $idx, $frame['file'], $frame['line'], $frame['class'] ?? '', $frame['type'] ?? '', $frame['function']);
			endforeach;
			return $fmt;
		}

		static function info($message)
		{
		}

		static function warning($message)
		{
		}

		static function fail($message)
		{
		}

		static function message($message, $severity)
		{
		}
		
		static function email($subject, $message)
		{
		}
		
		static function exception($e, ?bool $display=null)
		{
			$display = ini_get('display_errors');
			$log = ini_get('log_errors');
			$email = Settings::fetch('log.email.send');
			
			if ($log):
				error_log($e);
			endif;
			
			if ($display):
				if (php_sapi_name() == 'cli'):
					print($e);
				else:
					printf('<pre style="margin: 0; padding: 15px; color: hsl(354, 61%%, 43%%); background: hsl(355, 70%%, 91%%); border: 1px solid hsl(354, 71%%, 81%%); font-family: monospace;">%s</pre>', $e);
				endif;
			endif;
			
			if ($email):
				$subject = $e->getMessage();
				$message = $e;
				self::email($subject, $message);
			endif;
		}

		static function error($severity=null, $message=null, $file=null, $line=null, $context=null)
		{
			// the error was suppressed with @-operator
			if (error_reporting() === 0):
				return false;
			endif;
			
			switch($severity):
				case E_ERROR:               throw new ErrorException ($message, 0, $severity, $file, $line);
				case E_WARNING:             throw new WarningException ($message, 0, $severity, $file, $line);
				case E_PARSE:               throw new ParseException ($message, 0, $severity, $file, $line);
				case E_NOTICE:              throw new NoticeException ($message, 0, $severity, $file, $line);
				case E_CORE_ERROR:          throw new CoreErrorException ($message, 0, $severity, $file, $line);
				case E_CORE_WARNING:        throw new CoreWarningException ($message, 0, $severity, $file, $line);
				case E_COMPILE_ERROR:       throw new CompileErrorException ($message, 0, $severity, $file, $line);
				case E_COMPILE_WARNING:     throw new CoreWarningException ($message, 0, $severity, $file, $line);
				case E_USER_ERROR:          throw new UserErrorException ($message, 0, $severity, $file, $line);
				case E_USER_WARNING:        throw new UserWarningException ($message, 0, $severity, $file, $line);
				case E_USER_NOTICE:         throw new UserNoticeException ($message, 0, $severity, $file, $line);
				case E_STRICT:              throw new StrictException ($message, 0, $severity, $file, $line);
				case E_RECOVERABLE_ERROR:   throw new RecoverableErrorException ($message, 0, $severity, $file, $line);
				case E_DEPRECATED:          throw new DeprecatedException ($message, 0, $severity, $file, $line);
				case E_USER_DEPRECATED:     throw new UserDeprecatedException ($message, 0, $severity, $file, $line);
			endswitch;
			
			throw new \ErrorException($message, 0, $severity, $file, $line);
		}

		static function shutdown()
		{
			$error = error_get_last();
			if ($error !== null):
				throw new ErrorException($error['message'], 0, $error['type'], $error['file'], $error['line']);
			endif;
		}
		
		static function dump($data, $indent=1, $first=true, $html=null)
		{
			if (is_null($html)):
				if (php_sapi_name() == 'cli'):
					$html = false;
				else:
					$html = true;
				endif;
			endif;
			
			$retval = '';
			$prefix = \str_repeat("    ", $indent);
			if (\is_numeric($data)) $retval .= "Number: $data";
			elseif (\is_string($data)) $retval .= "String: '$data'";
			elseif (\is_null($data)) $retval .= "NULL";
			elseif ($data===true) $retval .= "TRUE";
			elseif ($data===false) $retval .= "FALSE";
			elseif (is_array($data)) {
				$retval .= "Array (".count($data).')';
				$indent++;
				foreach($data AS $key => $value) {
					$retval .= sprintf("\n%s[%s] = ", $prefix, $key);
					$retval .= self::dump($value, $indent, false);
				}
			}
			elseif (is_object($data)) {
				$retval .= "Object (".get_class($data).")";
				$indent++;
				foreach((array)$data AS $key => $value) {
					$retval .= sprintf("\n%s%s \u{2192} ", $prefix, $key);
					$retval .= self::dump($value, $indent, false);
				}
			}
			if ($first) $retval .= "\n";
			if ($first && $html) printf('<pre><div style="border: 1px solid red;">%s</div></pre>', $retval);
			if ($first && !$html) printf('%s', $retval);
			return $retval;
		}
	}

	class ErrorException                extends \ErrorException {}
	class WarningException              extends ErrorException {}
	class ParseException                extends ErrorException {}
	class NoticeException               extends ErrorException {}
	class CoreErrorException            extends ErrorException {}
	class CoreWarningException          extends ErrorException {}
	class CompileErrorException         extends ErrorException {}
	class CompileWarningException       extends ErrorException {}
	class UserErrorException            extends ErrorException {}
	class UserWarningException          extends ErrorException {}
	class UserNoticeException           extends ErrorException {}
	class StrictException               extends ErrorException {}
	class RecoverableErrorException     extends ErrorException {}
	class DeprecatedException           extends ErrorException {}
	class UserDeprecatedException       extends ErrorException {}
