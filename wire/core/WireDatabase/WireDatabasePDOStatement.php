<?php namespace ProcessWire;

/**
 * ProcessWire PDO Statement
 *
 * Serves as a wrapper to PHP’s PDOStatement class that keeps track of values
 * bound with bindValue() so that the statement can re-prepare itself on a new
 * connection (via the reprepare() method) if its own connection is lost. Once
 * re-prepared, all calls on this statement delegate to the replacement, so
 * existing references to the statement remain usable.
 *
 * In debug mode this class also logs queries with the bind parameters
 * populated into the SQL query string, purely for readability purposes. These
 * populated queries are not ever used for actual database queries, just for logs.
 *
 * Note that this class only tracks bindValue() and does not track bindParam(),
 * so statements using bindParam() or bindColumn() cannot be re-prepared.
 *
 * ProcessWire 3.x, Copyright 2026 by Ryan Cramer
 * https://processwire.com
 *
 */

class WireDatabasePDOStatement extends \PDOStatement {

	/**
	 * @var WireDatabasePDO
	 *
	 */
	protected $database = null;

	/**
	 * Values bound with bindValue() in format [ parameter => [ value, data_type ] ]
	 *
	 * Kept so the statement can be re-prepared on a new connection if its own is lost.
	 *
	 * @var array
	 *
	 */
	protected $boundValues = array();

	/**
	 * Replacement statement prepared on a new connection after this one’s connection was lost
	 *
	 * When present, all statement calls delegate to it.
	 *
	 * @var \PDOStatement|null
	 *
	 */
	protected $fallback = null;

	/**
	 * Debug params in format [ ":param_name" => "param value" ]
	 *
	 * @var array
	 *
	 */
	protected $debugParams = array();

	/**
	 * Debug params that require PCRE, in format [ "/:param_name\b/" => "param value" ]
	 *
	 * @var array
	 *
	 */
	protected $debugParamsPCRE = array();

	/**
	 * Quantity of debug params
	 *
	 * @var int
	 *
	 */
	protected $debugParamsQty = 0;

	/**
	 * Debug note
	 *
	 * @var string
	 *
	 */
	protected $debugNote = '';

	/**
	 * Debug mode?
	 *
	 * @var bool
	 *
	 */
	protected $debugMode = false;

	/**
	 * Construct
	 *
	 * PDO requires the PDOStatement constructor to be protected for some reason
	 *
	 * @param WireDatabasePDO $database
	 *
	 */
	protected function __construct(WireDatabasePDO $database) {
		$this->database = $database;
		$this->debugMode = $database->debugMode;
	}

	/**
	 * Set debug note
	 *
	 * @param string $note
	 *
	 */
	public function setDebugNote($note) {
		$this->debugNote = $note;
	}

	/**
	 * Set a named debug parameter
	 *
	 * @param string $parameter
	 * @param int|string|null $value
	 * @param int|null $data_type \PDO::PARAM_* type
	 *
	 */
	public function setDebugParam($parameter, $value, $data_type = null) {
		if($data_type === \PDO::PARAM_INT) {
			$value = (int) $value;
		} else if($data_type === \PDO::PARAM_NULL) {
			$value = 'NULL';
		} else {
			$value = $this->database->quote($value);
		}
		if($parameter[strlen($parameter)-1] !== 'X') {
			// user-specified param name: partial name collisions possible, so use boundary
			$this->debugParamsPCRE['/' . $parameter . '\b/'] = $value;
		} else {
			// auto-generated param name: already protected against partial name collisions
			$this->debugParams[$parameter] = $value;
		}
		$this->debugParamsQty++;
	}

	/**
	 * Bind a value for this statement
	 *
	 * @param string|int $parameter
	 * @param mixed $value
	 * @param int $data_type
	 * @return bool
	 *
	 */
	#[\ReturnTypeWillChange]
	public function bindValue($parameter, $value, $data_type = \PDO::PARAM_STR) {
		$this->boundValues[$parameter] = array($value, $data_type);
		if($this->fallback) {
			$result = $this->fallback->bindValue($parameter, $value, $data_type);
		} else {
			$result = parent::bindValue($parameter, $value, $data_type);
		}
		if($this->debugMode && is_string($parameter) && strpos($parameter, ':') === 0) {
			$this->setDebugParam($parameter, $value, $data_type);
		} else {
			// note we do not handle index/question-mark parameters for debugging
		}
		return $result;
	}

	/**
	 * Execute prepared statement
	 *
	 * @param array|null $input_parameters
	 * @return bool
	 * @throws \PDOException
	 *
	 */
	#[\ReturnTypeWillChange]
	public function execute($input_parameters = NULL) {
		if($this->fallback) {
			return $this->fallback->execute($input_parameters);
		} else if($this->debugMode) {
			return $this->executeDebug($input_parameters);
		} else {
			return parent::execute($input_parameters);
		}
	}

	/**
	 * Execute prepared statement when in debug mode only
	 *
	 * @param array|null $input_parameters
	 * @return bool
	 * @throws \PDOException
	 *
	 */
	public function executeDebug($input_parameters = NULL) {

		$timer = Debug::startTimer();
		$exception = null;

		try {
			$result = parent::execute($input_parameters);
		} catch(\PDOException $e) {
			$exception = $e;
			$result = false;
		}

		$timer = Debug::stopTimer($timer, 'ms');

		if(!$this->database) {
			if($exception) throw $exception;
			return $result;
		}

		if(is_array($input_parameters)) {
			foreach($input_parameters as $key => $value) {
				if(is_string($key)) $this->setDebugParam($key, $value);
			}
		}

		$debugNote = trim("$this->debugNote [$timer]");
		if($exception) $debugNote .= ' FAIL SQLSTATE[' . $exception->getCode() . ']';

		if($this->debugParamsQty) {
			$sql = $this->queryString;
			if(count($this->debugParams)) {
				$sql = strtr($sql, $this->debugParams);
			}
			if(count($this->debugParamsPCRE)) {
				$sql = preg_replace(
					array_keys($this->debugParamsPCRE),
					array_values($this->debugParamsPCRE),
					$sql
				);
			}
			$this->database->queryLog($sql, $debugNote);
		} else {
			$this->database->queryLog($this->queryString, $debugNote);
		}

		if($exception) throw $exception;

		return $result;
	}

	/**
	 * Re-prepare this statement on the given connection after its own connection was lost
	 *
	 * Prepares a replacement statement and replays all values bound with bindValue().
	 * Afterwards, all calls on this statement delegate to the replacement, so existing
	 * references to this statement remain usable, including for further bindValue()
	 * and execute() calls. Values given directly to execute($input_parameters) need no
	 * replay since the caller provides them on each execute() call.
	 *
	 * Note: parameters bound with bindParam() and columns bound with bindColumn() are
	 * not replayed, and iterating the statement directly with foreach does not reach
	 * the replacement statement.
	 *
	 * #pw-internal
	 *
	 * @param \PDO $pdo Connection to re-prepare this statement on
	 * @return bool
	 * @throws \PDOException if the prepare on the given connection fails
	 *
	 */
	public function reprepare(\PDO $pdo) {
		$fallback = $pdo->prepare($this->queryString);
		if(!$fallback) return false;
		foreach($this->boundValues as $parameter => $valueType) {
			$fallback->bindValue($parameter, $valueType[0], $valueType[1]);
		}
		$this->fallback = $fallback;
		return true;
	}

	/**
	 * Has this statement been re-prepared on a new connection?
	 *
	 * #pw-internal
	 *
	 * @return bool
	 *
	 */
	public function isReprepared() {
		return $this->fallback !== null;
	}

	/**
	 * @return mixed
	 *
	 */
	#[\ReturnTypeWillChange]
	public function fetch(...$args) {
		return $this->fallback ? $this->fallback->fetch(...$args) : parent::fetch(...$args);
	}

	/**
	 * @return array|false
	 *
	 */
	#[\ReturnTypeWillChange]
	public function fetchAll(...$args) {
		return $this->fallback ? $this->fallback->fetchAll(...$args) : parent::fetchAll(...$args);
	}

	/**
	 * @return mixed
	 *
	 */
	#[\ReturnTypeWillChange]
	public function fetchColumn(...$args) {
		return $this->fallback ? $this->fallback->fetchColumn(...$args) : parent::fetchColumn(...$args);
	}

	/**
	 * @return object|false
	 *
	 */
	#[\ReturnTypeWillChange]
	public function fetchObject(...$args) {
		return $this->fallback ? $this->fallback->fetchObject(...$args) : parent::fetchObject(...$args);
	}

	/**
	 * @return int
	 *
	 */
	#[\ReturnTypeWillChange]
	public function rowCount() {
		return $this->fallback ? $this->fallback->rowCount() : parent::rowCount();
	}

	/**
	 * @return int
	 *
	 */
	#[\ReturnTypeWillChange]
	public function columnCount() {
		return $this->fallback ? $this->fallback->columnCount() : parent::columnCount();
	}

	/**
	 * @return bool
	 *
	 */
	#[\ReturnTypeWillChange]
	public function closeCursor() {
		return $this->fallback ? $this->fallback->closeCursor() : parent::closeCursor();
	}

	/**
	 * @return string|null
	 *
	 */
	#[\ReturnTypeWillChange]
	public function errorCode() {
		return $this->fallback ? $this->fallback->errorCode() : parent::errorCode();
	}

	/**
	 * @return array
	 *
	 */
	#[\ReturnTypeWillChange]
	public function errorInfo() {
		return $this->fallback ? $this->fallback->errorInfo() : parent::errorInfo();
	}

	/**
	 * @return bool
	 *
	 */
	#[\ReturnTypeWillChange]
	public function setFetchMode(...$args) {
		return $this->fallback ? $this->fallback->setFetchMode(...$args) : parent::setFetchMode(...$args);
	}

	/**
	 * @return array|false
	 *
	 */
	#[\ReturnTypeWillChange]
	public function getColumnMeta($column) {
		return $this->fallback ? $this->fallback->getColumnMeta($column) : parent::getColumnMeta($column);
	}

	/**
	 * @return bool
	 *
	 */
	#[\ReturnTypeWillChange]
	public function nextRowset() {
		return $this->fallback ? $this->fallback->nextRowset() : parent::nextRowset();
	}

	/**
	 * @return mixed
	 *
	 */
	#[\ReturnTypeWillChange]
	public function getAttribute($attribute) {
		return $this->fallback ? $this->fallback->getAttribute($attribute) : parent::getAttribute($attribute);
	}

	/**
	 * @return bool
	 *
	 */
	#[\ReturnTypeWillChange]
	public function setAttribute($attribute, $value) {
		return $this->fallback ? $this->fallback->setAttribute($attribute, $value) : parent::setAttribute($attribute, $value);
	}

	/**
	 * @return bool|null
	 *
	 */
	#[\ReturnTypeWillChange]
	public function debugDumpParams() {
		return $this->fallback ? $this->fallback->debugDumpParams() : parent::debugDumpParams();
	}

}
