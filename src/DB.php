<?
	namespace ThriveData\ThrivePHP;
	
	/**
	 * PostgreSQL database interface.
	 */
	class DB
	{
		/**
		 * Queries the database.
		 */
		static function query($query, ...$params)
		{
			$c = new DatabaseConnection();
			return $c->query($query, ...$params);
		}
		
		static function send($query, ...$params)
		{
			$c = new DatabaseConnection();
			return $c->send($query, ...$params);
		}
		
		/**
		 * Updates `$table` with `$data` that matches `$condition`.
		 */
		static function update($table, $data, $condition)
		{
			$c = new DatabaseConnection();
			return $c->update($table, $data, $condition);
		}
		
		/**
		 * Inserts `$data` into `$table`.
		 */
		static function insert($table, $data)
		{
			$c = new DatabaseConnection();
			return $c->insert($table, $data);
		}
	}
	
	/**
	 * Connection to the database.
	 */
	class DatabaseConnection
	{
		private $connection;
		
		/**
		 * Start and setup connection to the database.
		 *
		 * The database connection parameters are retrieved from `settings.json` and the key `pgsql.connections.default`. (See `Settings` class.)
		 *
		 * The database GUC `intervalstyle` is set to `iso_8601` to be more compatible with date parsing.
		 *
		 * Some custom GUC parameters are set which are accessible by database queries:
		 * - 
		 */
		public function __construct($dsn = null)
		{
			if(is_null($dsn)):
				$dsn = Settings::get('pgsql.connections.default');
			endif;
			
			$this->connection = pg_connect($dsn);
			
			$settings = [
				'intervalstyle' => 'iso_8601',
			];
			
			if (isset($_SESSION['user']['id'])):
				pg_query_params($this->connection, "SELECT set_config('user.id', $1, false)", [$_SESSION['user']['id']]);
				$settings['app.state']['user']['id'] = $_SESSION['user']['id'];
			endif;
			if (isset($_COOKIE['timezone'])):
				pg_query_params($this->connection, "SELECT set_config('user.device_timezone', $1, false)", [$_COOKIE['timezone']]);
				$settings['app.state']['device']['timezone'] = $_COOKIE['timezone'];
			endif;
			if (isset($_SERVER['REMOTE_ADDR'])):
				pg_query_params($this->connection, "SELECT set_config('user.device_addr', $1, false)", [$_SERVER['REMOTE_ADDR']]);
				$settings['app.state']['device']['ipaddr'] = $_SERVER['REMOTE_ADDR'];
			endif;
			if (isset($_COOKIE['crumb_device'])):
				$settings['app.state']['device']['crumb'] = $_COOKIE['crumb_device'];
			endif;
			if (isset($_COOKIE['crumb_user'])):
				$settings['app.state']['user']['crumb'] = $_COOKIE['crumb_user'];
			endif;
			if (isset($_SERVER['HTTP_USER_AGENT'])):
				$settings['app.state']['device']['browser'] = $_SERVER['HTTP_USER_AGENT'];
			endif;
			pg_query_params(
				$this->connection,
				"SELECT set_config(t2.key, t2.value, false) FROM (VALUES($1::json)) AS t1(settings), json_each_text(t1.settings) AS t2",
				[json_encode($settings)]
			);
		}
		
		public function query($query, ...$params)
		{
			return self::send($query, ...$params);
		}
		
		public function send($query, ...$params)
		{
			if (pg_connection_busy($this->connection)):
				throw new DatabaseException('connection is busy');
			endif;
			
			$result = pg_get_result($this->connection);
			if ($result !== false):
				throw new DatabaseException('results on connection');
			endif;
			
			$status = pg_send_query_params($this->connection, $query, $params);
			if (!$status):
				throw new DatabaseException('Could not send query.');
			endif;
			
			$result = false;
			while ($check = pg_get_result($this->connection)):
				$result = $check;
			endwhile;
			
			if ($result === false):
				throw new DatabaseException('Could not get query result.');
			endif;
			
			$error = pg_result_error($result);
			
			if ($error):
				$fields = [
					'severity' => pg_result_error_field($result, PGSQL_DIAG_SEVERITY),
					'sqlstate' => pg_result_error_field($result, PGSQL_DIAG_SQLSTATE),
					'message' => pg_result_error_field($result, PGSQL_DIAG_MESSAGE_PRIMARY),
					'detail' => pg_result_error_field($result, PGSQL_DIAG_MESSAGE_DETAIL),
					'hint' => pg_result_error_field($result, PGSQL_DIAG_MESSAGE_HINT),
					'position' => pg_result_error_field($result, PGSQL_DIAG_STATEMENT_POSITION),
					'context' => pg_result_error_field($result, PGSQL_DIAG_CONTEXT),
					'schema' => pg_result_error_field($result, PGSQL_DIAG_SCHEMA_NAME),
					'table' => pg_result_error_field($result, PGSQL_DIAG_TABLE_NAME),
					'column' => pg_result_error_field($result, PGSQL_DIAG_COLUMN_NAME),
					'datatype' => pg_result_error_field($result, PGSQL_DIAG_DATATYPE_NAME),
					'constraint' => pg_result_error_field($result, PGSQL_DIAG_CONSTRAINT_NAME),
				];
				$fields['condition'] = DatabaseCondition::$codes[$fields['sqlstate']] ?? $fields['sqlstate'];
				
				$cancel = pg_cancel_query($this->connection);
				if ($cancel === false):
					throw new DatabaseException('Could not cancel query after error.');
				endif;
				
				// this prevents "Cannot set connection to blocking mode"
				$close = pg_close($this->connection);
				if ($close == false):
					throw new DatabaseException('Could not close connection after error.');
				endif;
				
				switch ($fields['condition']):
					case 'data_exception':
						throw new DatabaseDataException(...$fields); break;
					case 'datatype_mismatch':   // 42804
						throw new DatabaseDatatypeMismatch(...$fields); break;
					case 'not_null_violation':   // 23502
						throw new DatabaseNotNullViolation(...$fields); break;
					case 'undefined_column':
						throw new DatabaseUndefinedColumn(...$fields); break;
					case 'unique_violation':
						throw new DatabaseUniqueViolation(...$fields); break;
					case 'check_violation':
						throw new DatabaseCheckViolation(...$fields); break;
					case 'exclusion_violation':
						throw new DatabaseExclusionViolation(...$fields); break;
					case 'invalid_text_representation':
						throw new DatabaseInvalidTextRepresentation(...$fields); break;
					default:
						throw new DatabaseServerException(...$fields);
				endswitch;
			endif;
			
			return new DatabaseResult($result);
		}

		public function update($table, $data, $condition)
		{
			return pg_update($this->connection, $table, $data, $condition);
		}
		
		public function insert($table, $data)
		{
			foreach($data as &$d):
				if (is_bool($d)):
					if ($d === true):
						$d = 't';
					elseif ($d === false):
						$d = 'f';
					endif;
				elseif ($d instanceof DateTime):
					$d = $d->format(DateTime::ISO8601);
				endif;
			endforeach;
			
			for($i=1; $i <= count($data); $i++) { $placeholders[] = '$'.$i; }
			
			$query = 
				sprintf(
					'INSERT INTO %s (%s) VALUES (%s) RETURNING *',
					$table,
					implode(',', array_keys($data)),
					implode(',', $placeholders)
				);
			return $this->query($query, ...array_values($data))->single();
		}
	}
	
	class DatabaseResult
	{
		private $result;
		private $types;
		
		public function __construct($result)
		{
			$this->result = $result;
			
			// get column types
			for($i=0; $i < pg_num_fields($result); $i++):
				$this->types[pg_field_name($result, $i)] = pg_field_type($result, $i);
			endfor;
		}
		
		public function rows()
		{
			return pg_num_rows($this->result);
		}
		
		public function seek($offset = 0)
		{
			return pg_result_seek($this->result, $offset);
		}
		
		public function fetch($convert=true, $columns='object', $json='object')
		{
			switch ($columns):
				case 'object':
					$row = pg_fetch_object($this->result);
					break;
				case 'array':
					$row = pg_fetch_assoc($this->result);
					break;
			endswitch;
			
			switch ($json):
				case 'object':
					$json_assoc = false;
					break;
				case 'array':
					$json_assoc = true;
					break;
			endswitch;
			
			if (!$row) return $row;
			
			// return value based on column data type
			if ($convert):
				foreach($row as $col => &$value):
					switch($this->types[$col]):
						case 'bool':
							if($value == 't'):
								$value = true;
							elseif($value == 'f'):
								$value = false;
							endif;
							break;
						case 'timestamp':
						case 'timestamptz':
						case 'date':
							if($value):
								$value = new DatabaseDateTime($value);
							endif;
							break;
						case 'interval':
							if($value):
								$value = new DatabaseDateInterval($value);
							endif;
							break;
						case 'json':
						case 'jsonb':
							if (!is_null($value)):
								$value = json_decode($value, $json_assoc);
							endif;
							break;
					endswitch;
				endforeach;
			endif;
			
			return $row;
		}
		
		public function single($convert=true, $columns='object', $json='object')
		{
			if ($this->rows() == 1):
				return $this->fetch(convert: $convert, columns: $columns, json: $json);
			elseif ($this->rows() == 0):
				throw new DatabaseNoResult('no result');
			elseif ($this->rows() > 1):
				throw new DatabaseManyResults('result is not single row');
			else:
				throw new DatabaseException('unknown exception');
			endif;
		}
		
		public function all($convert=true, $struct='object')
		{
			$all = [];
			$this->seek();
			while ($r = $this->fetch($convert, $struct)):
				$all[] = $r;
			endwhile;
			
			return $all;
		}
	}
	

	class DatabaseDateTime extends \DateTime
	{
		public function __toString()
		{
			return $this->format('c');
		}
	}
	
	class DatabaseDateInterval extends \DateInterval
	{
		public function __construct($spec)
		{
			if(preg_match('/P(?:([0-9]+)Y)?(?:([0-9]+)M)?(?:([0-9]+)D)?(?:T(?:([0-9]+)H)?(?:([0-9]+)M)?(?:([0-9]+)(?:\.([0-9]+))?S)?)?/', $spec, $matches)):
				$this->y = (float)($matches[1] ?? 0);   // years
				$this->m = (float)($matches[2] ?? 0);   // months
				$this->d = (float)($matches[3] ?? 0);   // days
				$this->h = (float)($matches[4] ?? 0);   // hours
				$this->i = (float)($matches[5] ?? 0);   // minutes
				$this->s = (float)($matches[6] ?? 0);   // seconds
				$this->f = (float)('0.'.($matches[7] ?? 0));   // microseconds
			endif;
		}

		public function truncate($part=null, $precision=0)
		{
			//print '<pre>'.Log::dump($this).'</pre>'; die;
			$td = $this->s/60/60/24 + $this->i/60/24 + $this->h/24 + $this->d;
			$th = $this->s/60/60 + $this->i/60 + $this->h;
			$ti = $this->s/60 + $this->i;
			$y = $this->y;
			$m = $this->m;
			switch($part) {
				case 'd':
					$d = $td;
					$h = 0;
					$i = 0;
					$s = 0;
					break;
				case 'h':
					$d = $this->d;
					$h = $th;
					$i = 0;
					$s = 0;
					break;
				case 'i':
					$d = $this->d;
					$h = $this->h;
					$i = $ti;
					$s = 0;
					break;
				default:
					$d = $this->d;
					$h = $this->h;
					$i = $this->i;
					$s = $this->s;
			}			
			$str = '';
			//$str = ($this->invert ? '-' : '');
			if($y > 0) $str .= round($y, $precision).'y ';
			if($m > 0) $str .= round($m, $precision).'m ';
			if($d > 0) $str .= round($d, $precision).'d ';
			if($h > 0) $str .= round($h, $precision).'h ';
			if($i > 0) $str .= round($i, $precision).'m ';
			if($s > 0) $str .= round($s, $precision).'s ';
			return $str;
		}

		public function __toString()
		{
			return $this->truncate();
		}
	}
	
	class DatabaseCondition
	{
		static $codes = [
			// data exception
			'22000' => 'data_exception',
			'2202E' => 'array_subscript_error',
			'22021' => 'division_by_zero',
			'22007' => 'invalid_datetime_format',
			'22007' => 'invalid_escape_character',
			'22004' => 'null_value_not_allowed',
			'22003' => 'numeric value_out_of_range',
			'22032' => 'invalid_json_text',
			
			// integrity constraint violations
			'23000' => 'integrity_constraint_violation',
			'23502' => 'not_null_violation',
			'23503' => 'foreign_key_violation',
			'23505' => 'unique_violation',
			'23514' => 'check_violation',
			'23P01' => 'exclusion_violation',
			'22P02' => 'invalid_text_representation',
			
			// syntax errors or access rule violations
			'42000' => 'syntax_error_or_access_rule_violation',
			'42601' => 'syntax_error',
			'42501' => 'insufficient_privilege',
			'42703' => 'undefined_column',
			'42804' => 'datatype_mismatch',
			'42P01' => 'undefined_table',
		];
	}
	
	class DatabaseException extends ContextException {}
	
	class DatabaseClientException extends DatabaseException {}
	class DatabaseNoResult extends DatabaseClientException {}
	class DatabaseManyResults extends DatabaseClientException {}
	
	class DatabaseServerException extends DatabaseException
	{
		public function __construct(
			public $severity,
			public $sqlstate,
			public $message,
			public $detail,
			public $hint,
			public $position,
			public $context,
			public $schema,
			public $table,
			public $column,
			public $datatype,
			public $constraint,
			public $condition,
			?\throwable $previous=null,
		) {
			parent::__construct(message: $message, previous: $previous);
		}
		
		public function __toString()
		{
			return
				sprintf("%s: %s\n", get_called_class(), $this->message).
				sprintf("%s: %s\n", $this->severity, $this->condition).
				($this->detail ? sprintf("DETAIL: %s\n", $this->detail) : null).
				($this->hint ? sprintf("HINT: %s\n", $this->hint) : null).
				($this->context ? sprintf("CONTEXT: %s\n", $this->context) : null).
				($this->schema ? sprintf("SCHEMA: %s\n", $this->schema) : null).
				($this->table ? sprintf("TABLE: %s\n", $this->table) : null).
				($this->column ? sprintf("COLUMN: %s\n", $this->column) : null).
				($this->constraint ? sprintf("CONSTRAINT: %s\n", $this->constraint) : null).
				"Stack trace:\n".$this->getTraceAsString()."\n"
				;
		}
	}
	
	class DatabaseDataException extends DatabaseServerException {}
	class DatabaseNotNullViolation extends DatabaseServerException {}
	class DatabaseDataTypeMismatch extends DatabaseServerException {}
	class DatabaseUndefinedColumn extends DatabaseServerException {}
	class DatabaseUniqueViolation extends DatabaseServerException {}
	class DatabaseCheckViolation extends DatabaseServerException {}
	class DatabaseExclusionViolation extends DatabaseServerException {}
	class DatabaseInvalidTextRepresentation extends DatabaseServerException {}

	
?>