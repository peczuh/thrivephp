<?
	namespace ThriveData\ThrivePHP;
	
	class Settings
	{
		/** JSON objects remain objects internally so empty objects and lists merge differently. */
		private static ?\stdClass $data = null;
				
		static function init(string ...$files)
		{
			if (count($files) == 0):
				$files[] = PATH_ROOT.'/settings.json';
				$files[] = PATH_ROOT.'/local/settings.json';
			endif;
				
			foreach($files as $f):
				self::load(file: $f, silent: true);
			endforeach;
		}
		
		static function load(string $file, bool $silent)
		{
			if (is_readable($file)):
				$data = json_decode(json: file_get_contents($file), flags: JSON_THROW_ON_ERROR);
				if (!$data instanceof \stdClass):
					throw new SettingsException('settings file must contain a JSON object');
				endif;
				self::$data = self::merge(self::$data ?? new \stdClass(), $data);
			elseif (!$silent):
				throw new SettingsException('file not found');
			endif;
		}
		
		/** Replace a dot-separated value in memory; an empty path replaces the whole settings object. */
		static function set(string $path, mixed $value): void
		{
			$value = self::normalize($value);
			if ($path === ''):
				if ($value === []) $value = new \stdClass();
				if (!$value instanceof \stdClass) throw new SettingsException('settings root must be an object');
				self::$data = $value;
				return;
			endif;
			self::$data ??= new \stdClass();
			$node = &self::$data;
			foreach (explode('.', $path) as $key):
				// A named or sparse key turns a list into an object, matching JSON encoding.
				if (is_array($node) && !array_key_exists($key, $node) && $key !== (string)count($node)) $node = (object)$node;
				if (is_array($node)):
					$node = &$node[$key];
				else:
					if (!$node instanceof \stdClass) $node = new \stdClass();
					$node = &$node->$key;
				endif;
			endforeach;
			$node = $value;
		}

		/** Return an independent, JSON-type-preserving snapshot for another process. */
		static function export(): \stdClass
		{
			return self::normalize(self::$data ?? new \stdClass());
		}

		/** Return a value as PHP arrays/scalars, or null for a missing or null setting. */
		static function fetch($path)
		{
			$node = self::$data;
			foreach (explode('.', $path) as $key):
				$node = $node instanceof \stdClass ? ($node->$key ?? null) : (is_array($node) ? ($node[$key] ?? null) : null);
			endforeach;
			return self::arrays($node);
		}
		
		static function get($path)
		{
			return self::fetch($path) ?? throw new SettingsException('config keypath does not exist [keypath='.$path.']');
		}

		/** Only two objects merge; every other later value replaces its predecessor. */
		private static function merge(\stdClass $earlier, \stdClass $later): \stdClass
		{
			foreach ($later as $key => $value):
				$earlier->$key = $value instanceof \stdClass && ($earlier->$key ?? null) instanceof \stdClass
					? self::merge($earlier->$key, $value) : $value;
			endforeach;
			return $earlier;
		}

		/** Copy supplied objects and interpret associative PHP arrays as settings objects. */
		private static function normalize(mixed $value): mixed
		{
			if ($value instanceof \stdClass || (is_array($value) && !array_is_list($value))):
				$object = new \stdClass();
				foreach ($value as $key => $item) $object->{(string)$key} = self::normalize($item);
				return $object;
			endif;
			return is_array($value) ? array_map(self::normalize(...), $value) : $value;
		}

		/** Preserve array-valued reads without exposing the mutable internal objects. */
		private static function arrays(mixed $value): mixed
		{
			return $value instanceof \stdClass || is_array($value) ? array_map(self::arrays(...), (array)$value) : $value;
		}
	}

	class SettingsException extends Exception {}

?>
