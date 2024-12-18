<?php

	namespace Stoic\Pdo;

	use Stoic\Log\Logger;
	use Stoic\Utilities\EnumBase;
	use Stoic\Utilities\ReturnHelper;
	use Stoic\Utilities\StringHelper;

	/**
	 * Exception thrown if a property isn't found during calls to BaseDbModel::fromArray().
	 *
	 * @package Stoic\Pdo
	 * @version 1.1.0
	 */
	class ClassPropertyNotFoundException extends \Exception { }

	/**
	 * Represents a database field type.
	 *
	 * @package Stoic\Pdo
	 * @version 1.1.0
	 */
	class BaseDbTypes extends EnumBase {
		const INTEGER = 0;
		const STRING = 1;
		const BOOLEAN = 2;
		const NILL = 3;
		const DATETIME = 4;


		/**
		 * Retrieves the PDO parameter type to be used with a column of this type.
		 *
		 * @return null|integer
		 */
		public function getDbType() : ?int {
			if ($this->getValue() === null) {
				return null;
			}

			switch ($this->getValue()) {
				case self::INTEGER:
					return \PDO::PARAM_INT;
				case self::STRING:
				case self::DATETIME:
					return \PDO::PARAM_STR;
				case self::BOOLEAN:
					return \PDO::PARAM_INT;
				case self::NILL:
					return \PDO::PARAM_NULL;
			}

			// @codeCoverageIgnoreStart
			return null;
			// @codeCoverageIgnoreEnd
		}
	}

	/**
	 * Represents the different types of queries that can be generated for a BaseDbModel.
	 *
	 * @package Stoic\Pdo
	 * @version 1.1.0
	 */
	class BaseDbQueryTypes extends EnumBase {
		const INVALID = 0;
		const DELETE = 1;
		const INSERT = 2;
		const SELECT = 3;
		const UPDATE = 4;
	}

	/**
	 * Represents the different flags that can be set on a BaseDbColumn object.
	 *
	 * @package Stoic\Pdo
	 * @version 1.4.0
	 */
	class BaseDbColumnFlags extends EnumBase {
		const IS_KEY         = 1;
		const SHOULD_INSERT  = 2;
		const SHOULD_UPDATE  = 4;
		const ALLOWS_NULLS   = 8;
		const AUTO_INCREMENT = 16;
	}

	/**
	 * Represents a single database field in a BaseDbModel.
	 *
	 * @package Stoic\Pdo
	 * @version 1.1.0
	 */
	class BaseDbField {
		/**
		 * Whether this field can be null when used in a query.
		 *
		 * @var bool
		 */
		public bool $allowsNulls;
		/**
		 * Name of the database column for this field.
		 *
		 * @var StringHelper
		 */
		public StringHelper $column;
		/**
		 * Whether the field receives an AUTO_INCREMENT value upon insertion.
		 *
		 * @var bool
		 */
		public bool $autoIncrement;
		/**
		 * The database type for this field.
		 *
		 * @var BaseDbTypes
		 */
		public BaseDbTypes $type;
		/**
		 * Whether this field is the key for the table.
		 *
		 * @var bool
		 */
		public bool $isKey;
		/**
		 * Whether to use this field during row creation.
		 *
		 * @var bool
		 */
		public bool $shouldInsert;
		/**
		 * Whether to use this field during row updates.
		 *
		 * @var bool
		 */
		public bool $shouldUpdate;


		/**
		 * Instantiates a BaseDbField object with the given values.
		 *
		 * @param string $column Name of the database column.
		 * @param integer $type Type of the database column.
		 * @param boolean $isKey Whether this is part of the table key.
		 * @param boolean $shouldInsert Whether this should be used during row creation.
		 * @param boolean $shouldUpdate Whether this should be used during row updates.
		 * @param boolean $allowsNulls Whether this should be allowed to be null.
		 * @param boolean $autoIncrement Whether this receives an AUTO_INCREMENT value after insertion.
		 */
		public function __construct(string $column, int $type, bool $isKey, bool $shouldInsert, bool $shouldUpdate, bool $allowsNulls = false, bool $autoIncrement = false) {
			$this->allowsNulls   = $allowsNulls;
			$this->column        = new StringHelper($column);
			$this->type          = new BaseDbTypes($type);
			$this->isKey         = $isKey;
			$this->shouldInsert  = $shouldInsert;
			$this->shouldUpdate  = $shouldUpdate;
			$this->autoIncrement = $autoIncrement;

			if ($this->column->isEmptyOrNullOrWhitespace()) {
				throw new \InvalidArgumentException("Cannot create a BaseDbField object with no column name");
			}

			if ($this->type->getValue() === null) {
				throw new \InvalidArgumentException("Cannot create a BaseDbField object with an invalid BaseDbTypes value");
			}

			return;
		}
	}

	/**
	 * Abstract base class that provides simplistic ORM functionality without much fuss/overhead.
	 *
	 * @package Stoic\Pdo
	 * @version 1.1.0
	 */
	abstract class BaseDbModel extends BaseDbClass implements \JsonSerializable {
		/**
		 * Internal storage for guessed driver type.
		 *
		 * @var null|PdoDrivers
		 */
		protected ?PdoDrivers $dbDriver = null;
		/**
		 * Optional collection of BaseDbField objects representing object properties.
		 *
		 * @var BaseDbField[]
		 */
		protected array $dbFields = [];
		/**
		 * Name of the database table this class will query against.
		 *
		 * @var null|StringHelper
		 */
		protected ?StringHelper $dbTable = null;


		/**
		 * Cached collection of object properties.
		 *
		 * @var array
		 */
		protected static array $properties = [];


		/**
		 * Array-based instantiation of base class objects.
		 *
		 * @param array $source Array to use as source for object properties.
		 * @param \PDO $db PDO connection resource for use by object.
		 * @param null|Logger $log Optional Logger instance for use by object, new instance created if not supplied.
		 * @param null|array $exclusions Optional array of properties to exclude when comparing property counts.
		 * @throws \InvalidArgumentException|ClassPropertyNotFoundException
		 * @return static
		 */
		public static function fromArray(array $source, \PDO $db, ?Logger $log = null, ?array $exclusions = null) : static {
			$className = get_called_class();

			if (count($source) < 1) {
				throw new \InvalidArgumentException("Cannot populate {$className} from empty source array");
			}

			static::persistClassProperties();
			$baseVars = static::$properties['BaseDbModel'];

			if ($exclusions !== null) {
				foreach ($exclusions as $ex) {
					$baseVars[$ex] = true;
				}
			}

			if ((count(static::$properties[$className]) - count($baseVars)) != count($source)) {
				throw new \InvalidArgumentException("Cannot populate {$className} from array, variable count mismatch (class: " . (count(static::$properties[$className]) - count($baseVars)) . ", source: " . count($source) . ")");
			}

			$ret = new $className($db, $log);

			foreach ($source as $key => $val) {
				$keyFound = false;
				$loweredKey = strtolower($key);

				foreach ($ret->dbFields as $prop => $field) {
					if ($field->column->compare($loweredKey, null, true) == 0) {
						$keyFound = true;

						$ret->setPropertyDbValue($prop, $field, $val);

						break;
					}
				}

				if ($keyFound === false) {
					foreach (array_keys(static::$properties[$className]) as $prop) {
						if (strtolower($prop) == $loweredKey) {
							$keyFound = true;

							$ret->{$prop} = $val;

							break;
						}
					}
				}

				if ($keyFound === false) {
					throw new ClassPropertyNotFoundException("Couldn't find match for {$key} index while populating {$className}");
				}
			}

			return $ret;
		}

		/**
		 * Static method to ensure class properties have been set up in the cache.
		 *
		 * @return void
		 */
		protected static function persistClassProperties() : void {
			if (array_key_exists('BaseDbModel', static::$properties) === false) {
				static::$properties['BaseDbModel'] = get_class_vars(self::class);
			}

			$className = get_called_class();

			if (array_key_exists($className, static::$properties) === false) {
				static::$properties[$className] = get_class_vars($className);
			}

			return;
		}


		/**
		 * Optional method to determine if a 'create' action can proceed.
		 *
		 * @return bool|ReturnHelper
		 */
		protected function __canCreate() : bool|ReturnHelper {
			return true;
		}

		/**
		 * Optional method to determine if a 'delete' action can proceed.
		 *
		 * @return bool|ReturnHelper
		 */
		protected function __canDelete() : bool|ReturnHelper {
			return true;
		}

		/**
		 * Optional method to determine if a 'read' action can proceed.
		 *
		 * @return bool|ReturnHelper
		 */
		protected function __canRead() : bool|ReturnHelper {
			return true;
		}

		/**
		 * Optional method to determine if an 'update' action can proceed.
		 *
		 * @return bool|ReturnHelper
		 */
		protected function __canUpdate() : bool|ReturnHelper {
			return true;
		}

		/**
		 * Retrieves the value of a set property.
		 *
		 * @param string $name Name of the property to attempt retrieving.
		 * @return mixed
		 */
		public function __get(string $name) : mixed {
			if (array_key_exists($name, $this->dbFields) !== false) {
				if ($this->dbFields[$name]->type->is(BaseDbTypes::STRING) && $this->{$name} instanceof StringHelper) {
					return $this->{$name}->data();
				} else if ($this->dbFields[$name]->type->is(BaseDbTypes::DATETIME) && $this->{$name} instanceof \DateTimeInterface) {
					return $this->{$name}->format('Y-m-d H:i:s');
				} else {
					return $this->{$name};
				}
			} else {
				$this->log->warning("Attempted to retrieve non-existent field: {field}", array('field' => $name));
			}

			return null;
		}

		/**
		 * Optional method to initialize an object after the constructor has finished.
		 *
		 * @return void
		 */
		protected function __initialize() : void {
			static::persistClassProperties();

			$this->dbDriver = ($this->db instanceof PdoHelper) ? $this->db->getDriver() : new PdoDrivers(PdoDrivers::PDO_UNKNOWN);
			$this->__setupModel();

			return;
		}

		/**
		 * Sets the value of an existing property.
		 *
		 * @param string $name Name of property to attempt setting.
		 * @param mixed $value Value to set property to if it exists.
		 * @throws \Exception
		 * @return void
		 */
		public function __set(string $name, mixed $value) : void {
			if (array_key_exists($name, $this->dbFields) !== false) {
				$field = $this->dbFields[$name];

				if ($value === null && $field->allowsNulls) {
					// @codeCoverageIgnoreStart
					$this->{$name} = $value;
					// @codeCoverageIgnoreEnd
				} else if ($field->type->is(BaseDbTypes::STRING) && !($this->{$name} instanceof EnumBase)) {
					$this->{$name} = new StringHelper($value);
				} else if ($field->type->is(BaseDbTypes::DATETIME) && !($value instanceof \DateTimeInterface)) {
					$this->{$name} = new \DateTimeImmutable($value ?? 'now');
				} else if ($this->{$name} instanceof EnumBase && !($value instanceof EnumBase)) {
					$enumClass = get_class($this->{$name});
					$this->{$name} = new $enumClass($value);
				} else {
					$this->{$name} = $value;
				}
			} else {
				$this->log->warning("Attempted to set non-existent field: {field} => {value}", array('field' => $name, 'value' => $value));
			}

			return;
		}

		/**
		 * Optional method to initialize a model after the constructor has finished.
		 *
		 * @return void
		 */
		protected function __setupModel() : void {
			return;
		}

		/**
		 * Attempts to create a new object in the database.
		 *
		 * @return ReturnHelper
		 */
		public function create() : ReturnHelper {
			$ret = new ReturnHelper();
			$ret->makeBad();

			if (!$this->canProceed('create', $this->__canCreate(), $ret)) {
				return $ret;
			}

			$autoIncField = null;
			$insertFields = array();
			$insertColumns = array();

			foreach ($this->dbFields as $property => $field) {
				if ($field->shouldInsert) {
					$insertFields[] = $this->getPropertyDbValue($property, $field);
					$insertColumns[$field->column->data()] = ":{$property}";
				}

				if ($field->autoIncrement === true) {
					$autoIncField = $property;
				}
			}

			if (count($insertFields) < 1) {
				$ret->addMessage("Can't perform generated 'create', no fields available for insertion");
				$this->logErrors($ret);

				return $ret;
			}

			try {
				$sql = "INSERT INTO {$this->getDbTableName()} (" . $this->getDbColumnPrefix() . implode($this->getDbColumnSuffix() . ',' . $this->getDbColumnPrefix(), array_keys($insertColumns)) . $this->getDbColumnSuffix() . ") VALUES (" . implode(', ', array_values($insertColumns)) . ")";
				$stmt = $this->db->prepare($sql);
				$paramOutput = [];

				foreach ($insertFields as $field) {
					$stmt->bindValue($field[0], $field[1], $field[2]);
					$paramOutput[$field[0]] = $field[1];
				}

				$this->log->info("Attempting to create " . $this->className . " automatically with...\n\tQuery: {SQL}\n\tParams: {PARAMS}", array('SQL' => $sql, 'PARAMS' => json_encode($paramOutput)));

				$stmt->execute();

				if ($autoIncField !== null) {
					$this->log->info("Attempting to set autoInc field: {$autoIncField}");
					$this->{$autoIncField} = $this->db->lastInsertId();
				}

				$ret->makeGood();
				$this->log->info("Successfully created " . $this->className);
				// @codeCoverageIgnoreStart
			} catch (\PDOException $ex) {
				$this->log->error("Failed to create " . $this->className . ": {ERROR}", array('ERROR' => $ex));
				$ret->addMessage("Failed to create {$this->className}:  {$ex->getMessage()}");
			}
			// @codeCoverageIgnoreEnd

			$this->logErrors($ret);

			return $ret;
		}

		/**
		 * Determines if a response from a proceed-check method should stop an automatic query from being executed.
		 *
		 * If a ReturnHelper is provided, all messages will be placed in error logs in the result of a failed check.
		 *
		 * @param string $operation Name of operation that is about to be performed.
		 * @param mixed $value Value from proceed-check, likely boolean or ReturnHelper.
		 * @param null|ReturnHelper $ret Optional ReturnHelper to append message onto.
		 * @return bool
		 */
		protected function canProceed(string $operation, mixed $value, ?ReturnHelper &$ret = null) : bool {
			if ($value instanceof ReturnHelper) {
				if ($value->isGood()) {
					return true;
				}

				if ($value->hasMessages()) {
					foreach ($value->getMessages() as $msg) {
						$this->log->error($msg);
						$ret?->addMessage($msg);
					}
				}

				return false;
			}

			if ($value === false) {
				$this->log->error("Unable to '{$operation}', {$this->className} returned false");

				return false;
			}

			if (count($this->dbFields) < 1) {
				$this->log->error("Can't perform generated '{$operation}' on {$this->className} without registered fields");
				$ret?->addMessage("Can't perform generated '{$operation}' on {$this->className} without registered fields");

				return false;
			}

			return true;
		}

		/**
		 * Attempts to delete an object in the database.
		 *
		 * @return ReturnHelper
		 */
		public function delete() : ReturnHelper {
			$ret = new ReturnHelper();
			$ret->makeBad();

			if (!$this->canProceed('delete', $this->__canDelete(), $ret)) {
				return $ret;
			}

			$primaries = array();
			$primaryStrings = array();

			foreach ($this->dbFields as $property => $field) {
				if ($field->isKey) {
					$primaries[] = $this->getPropertyDbValue($property, $field);
					$primaryStrings[] = "{$this->prepColumn($field->column)} = :{$property}";
				}
			}

			if (count($primaries) < 1) {
				$ret->addMessage("Can't perform generated 'delete', no fields available for query");
				$this->logErrors($ret);

				return $ret;
			}

			try {
				$sql = "DELETE FROM {$this->getDbTableName()} WHERE " . implode(' AND ', array_values($primaryStrings));
				$stmt = $this->db->prepare($sql);
				$paramOutput = [];

				foreach ($primaries as $field) {
					$stmt->bindValue($field[0], $field[1], $field[2]);
					$paramOutput[$field[0]] = $field[1];
				}

				$this->log->info("Attempting to run generated 'delete'..\n\tQuery: {SQL}\n\tParams: {PARAMS}", array('SQL' => $sql, 'PARAMS' => json_encode($paramOutput)));

				$stmt->execute();
				$ret->makeGood();
				$this->log->info("Successfully deleted {$this->className}");
				// @codeCoverageIgnoreStart
			} catch (\PDOException $ex) {
				$this->log->error("Failed to delete {$this->className} with error: {ERROR}", array('ERROR' => $ex));
				$ret->addMessage("Failed to delete {$this->className}: {$ex->getMessage()}");
			}
			// @codeCoverageIgnoreEnd

			$this->logErrors($ret);

			return $ret;
		}

		/**
		 * Attempts to generate a query string to be used elsewhere.  Optional parameter only affects SELECT queries.
		 *
		 * @param int|BaseDbQueryTypes $queryType Type of query to generate with class meta information.
		 * @param bool $includePrimaryWheres Determines if queries should also include the WHERE section with primary keys included, defaults to true.
		 * @return string
		 */
		public function generateClassQuery(int|BaseDbQueryTypes $queryType, bool $includePrimaryWheres = true) : string {
			$ret = '';
			$queryType = EnumBase::tryGetEnum($queryType, BaseDbQueryTypes::class);

			$insertColumns = [];
			$selectColumns = [];
			$updateColumns = [];
			$primaryStrings = [];

			foreach ($this->dbFields as $property => $field) {
				if ($field->shouldInsert) {
					$insertColumns[$field->column->data()] = ":{$property}";
				}

				if ($field->isKey) {
					$primaryStrings[] = "{$this->prepColumn($field->column)} = :{$property}";
				}

				if ($field->shouldUpdate) {
					$updateColumns[] = "{$this->prepColumn($field->column)} = :{$property}";
				}

				$selectColumns[] = $field->column;
			}

			switch ($queryType->getValue()) {
				case BaseDbQueryTypes::DELETE:
					$ret = "DELETE FROM {$this->getDbTableName()}";

					break;
				case BaseDbQueryTypes::INSERT:
					$ret = "INSERT INTO {$this->getDbTableName()} (" . $this->getDbColumnPrefix() . implode($this->getDbColumnSuffix() . ', ' . $this->getDbColumnPrefix(), array_keys($insertColumns)) . $this->getDbColumnSuffix() . ") VALUES (" . implode(', ', array_values($insertColumns)) . ")";

					break;
				case BaseDbQueryTypes::SELECT:
					$ret = "SELECT " . $this->getDbColumnPrefix() . implode($this->getDbColumnSuffix() . ', ' . $this->getDbColumnPrefix(), array_values($selectColumns)) . $this->getDbColumnSuffix() . " FROM {$this->getDbTableName()}";

					break;
				case BaseDbQueryTypes::UPDATE:
					$ret = "UPDATE {$this->getDbTableName()} SET " . implode(', ', array_values($updateColumns));

					break;
			}

			if (!$queryType->is(BaseDbQueryTypes::INSERT) && $includePrimaryWheres) {
				$ret .= " WHERE " .implode(' AND ', array_values($primaryStrings));
			}

			return $ret;
		}

		/**
		 * Returns the fully qualified class name of the current class.
		 *
		 * @return string
		 */
		public function getClassName() : string {
			return $this->className;
		}

		/**
		 * Retrieves the common column prefix character for the database driver, if available.
		 *
		 * @codeCoverageIgnore
		 * @return string
		 */
		protected function getDbColumnPrefix() : string {
			$ret = '';

			if (!$this->dbDriver->is(PdoDrivers::PDO_UNKNOWN)) {
				switch ($this->dbDriver->getValue()) {
					case PdoDrivers::PDO_MYSQL:
						$ret = '`';

						break;
					case PdoDrivers::PDO_MSSQL:
					case PdoDrivers::PDO_SQLSRV:
					case PdoDrivers::PDO_SYBASE:
						$ret = '[';

						break;
					case PdoDrivers::PDO_IBM:
					case PdoDrivers::PDO_ORACLE:
					case PdoDrivers::PDO_PGSQL:
					case PdoDrivers::PDO_SQLITE:
						$ret = '"';

						break;
					case PdoDrivers::PDO_4D:
					case PdoDrivers::PDO_CUBRID:
					case PdoDrivers::PDO_FIREBIRD:
					case PdoDrivers::PDO_FREETDS:
					case PdoDrivers::PDO_INFORMIX:
					case PdoDrivers::PDO_ODBC:
					case PdoDrivers::PDO_UNKNOWN:
					default:
						break;
				}
			}

			return $ret;
		}

		/**
		 * Returns the currently set collection of database columns/fields.
		 *
		 * @return BaseDbField[]
		 */
		public function getDbColumns() : array {
			return $this->dbFields;
		}

		/**
		 * Retrieves the common column suffix character for the database driver, if available.
		 *
		 * @codeCoverageIgnore
		 * @return string
		 */
		protected function getDbColumnSuffix() : string {
			$ret = '';

			if (!$this->dbDriver->is(PdoDrivers::PDO_UNKNOWN)) {
				switch ($this->dbDriver->getValue()) {
					case PdoDrivers::PDO_MYSQL:
						$ret = '`';

						break;
					case PdoDrivers::PDO_MSSQL:
					case PdoDrivers::PDO_SQLSRV:
					case PdoDrivers::PDO_SYBASE:
						$ret = ']';

						break;
					case PdoDrivers::PDO_IBM:
					case PdoDrivers::PDO_ORACLE:
					case PdoDrivers::PDO_PGSQL:
					case PdoDrivers::PDO_SQLITE:
						$ret = '"';

						break;
					case PdoDrivers::PDO_4D:
					case PdoDrivers::PDO_CUBRID:
					case PdoDrivers::PDO_FIREBIRD:
					case PdoDrivers::PDO_FREETDS:
					case PdoDrivers::PDO_INFORMIX:
					case PdoDrivers::PDO_ODBC:
					case PdoDrivers::PDO_UNKNOWN:
					default:
						break;
				}
			}

			return $ret;
		}

		/**
		 * Retrieves the configured table name for the model.
		 *
		 * @return string
		 */
		public function getDbTableName() : string {
			$tableName = $this->dbTable->data() ?? '';

			return $this->getDbColumnPrefix() . $tableName . $this->getDbColumnSuffix();
		}

		/**
		 * Attempts to retrieve the DB value for the given property.
		 *
		 * @param string $property Name of property on class to retrieve.
		 * @param BaseDbField $field Field information to use when formatting value.
		 * @return array
		 */
		protected function getPropertyDbValue(string $property, BaseDbField $field) : array {
			$ret = array(":{$property}", '', $field->type->getDbType());

			if ($field->allowsNulls && $this->{$property} === null) {
				// @codeCoverageIgnoreStart
				$ret[1] = null;
				$ret[2] = \PDO::PARAM_NULL;
				// @codeCoverageIgnoreEnd
			} else if ($this->{$property} instanceof \DateTimeInterface) {
				$ret[1] = $this->{$property}->format('Y-m-d H:i:s');
			} else if ($field->type->is(BaseDbTypes::STRING) && $this->{$property} instanceof StringHelper) {
				$ret[1] = $this->{$property}->data();
			} else if ($field->type->is(BaseDbTypes::BOOLEAN)) {
				$ret[1] = ($this->{$property}) ? 1 : 0;
			} else if ($field->type->is(BaseDbTypes::STRING) && $this->{$property} instanceof EnumBase) {
				// @codeCoverageIgnoreStart
				$ret[1] = $this->{$property}->getName();
				// @codeCoverageIgnoreEnd
			} else if ($field->type->is(BaseDbTypes::INTEGER) && $this->{$property} instanceof EnumBase) {
				$ret[1] = $this->{$property}->getValue();
			} else {
				$ret[1] = $this->{$property};
			}

			return $ret;
		}

		/**
		 * Returns the shortened class name of the current class.
		 *
		 * @return string
		 */
		public function getShortClassName() : string {
			return $this->shortClassName;
		}

		/**
		 * Ensures the registered db fields are serializable by json_encode().
		 *
		 * @return array
		 */
		public function jsonSerialize() : array {
			return $this->toSerializableArray();
		}

		/**
		 * Logs any errors from the given ReturnHelper.
		 *
		 * @param ReturnHelper $ret ReturnHelper to scan for errors.
		 * @return void
		 */
		protected function logErrors(ReturnHelper $ret) : void {
			$trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1);

			if ($ret->isBad() && $ret->hasMessages()) {
				$this->log->error("BaseDbModel error log originating from {FILE}:{LINE}", ['FILE' => $trace[0]['file'], 'LINE' => $trace[0]['line']]);

				foreach ($ret->getMessages() as $message) {
					$this->log->error($message);
				}
			}

			return;
		}

		/**
		 * Wraps the column name with the appropriate characters, if available.
		 *
		 * @param string $column Column to wrap, if possible.
		 * @return string
		 */
		protected function prepColumn(string $column) : string {
			return $this->getDbColumnPrefix() . $column . $this->getDbColumnSuffix();
		}

		/**
		 * Attempts to read an object from the database.
		 *
		 * @throws \Exception
		 * @return ReturnHelper
		 */
		public function read() : ReturnHelper {
			$ret = new ReturnHelper();
			$ret->makeBad();

			if (!$this->canProceed('read', $this->__canRead(), $ret)) {
				return $ret;
			}

			$columns = array();
			$primaries = array();
			$primaryStrings = array();

			foreach ($this->dbFields as $property => $field) {
				if ($field->isKey) {
					$primaries[] = $this->getPropertyDbValue($property, $field);
					$primaryStrings[] = "{$this->prepColumn($field->column)} = :{$property}";
				}

				$columns[] = $field->column;
			}

			if (count($primaries) < 1) {
				$ret->addMessage("Can't perform generated 'read', no fields available for query");
				$this->logErrors($ret);

				return $ret;
			}

			try {
				$sql = "SELECT " . $this->getDbColumnPrefix() . implode($this->getDbColumnSuffix() . ', ' . $this->getDbColumnPrefix(), array_values($columns)) . $this->getDbColumnSuffix() . " FROM {$this->getDbTableName()} WHERE " . implode(' AND ', array_values($primaryStrings));
				$stmt = $this->db->prepare($sql);
				$paramOutput = [];

				foreach ($primaries as $field) {
					$stmt->bindValue($field[0], $field[1], $field[2]);
					$paramOutput[$field[0]] = $field[1];
				}

				$this->log->info("Attempting to 'read' {$this->className}..\n\tQuery: {SQL}\n\tParams: {PARAMS}", array('SQL' => $sql, 'PARAMS' => json_encode($paramOutput)));

				$cmp = $stmt->execute();

				if (!$this->dbDriver->isIn(PdoDrivers::PDO_MSSQL, PdoDrivers::PDO_SQLSRV)) {
					$cmp = $stmt->rowCount() > 0;
				}

				if ($cmp) {
					$row = $stmt->fetch(\PDO::FETCH_ASSOC);

					if ($row === false) {
						$ret->addMessage("No results returned for generated 'read' query, read aborted");
					} else {
						foreach ($this->dbFields as $property => $field) {
							$this->setPropertyDbValue($property, $field, $row[$field->column->data()]);
						}

						$ret->makeGood();
					}
				} else {
					$ret->addMessage("No results found for generated 'read' query, read aborted");
				}
				// @codeCoverageIgnoreStart
			} catch (\PDOException $ex) {
				$this->log->error("Failed to read {$this->className} object with error: {ERROR}", array('ERROR' => $ex));
				$ret->addMessage("Failed to read {$this->className}: {$ex->getMessage()}");
			}
			// @codeCoverageIgnoreEnd

			$this->logErrors($ret);

			return $ret;
		}

		/**
		 * Attempts to set a database field in the current object.
		 *
		 * @param string $property Name of the class property this field corresponds to.
		 * @param string $column Name of the database column.
		 * @param int $type Type of the database column.
		 * @param int|bool $isKeyOrFlags Flag composite for entire column flags or boolean value determining whether this is part of the table key.
		 * @param bool $shouldInsert Whether this should be used during row creation.
		 * @param bool $shouldUpdate Whether this should be used during row updates.
		 * @param bool $allowsNulls Whether this should be allowed to be null.
		 * @param bool $autoIncrement Whether this receives an AUTO_INCREMENT value after insertion.
		 * @throws \InvalidArgumentException
		 * @return void
		 */
		protected function setColumn(string $property, string $column, int $type, int|bool $isKeyOrFlags, bool $shouldInsert = false, bool $shouldUpdate = false, bool $allowsNulls = false, bool $autoIncrement = false) : void {
			if (array_key_exists($property, $this->dbFields) !== false) {
				throw new \InvalidArgumentException("Cannot overwrite a field that has already been set");
			}

			if (is_int($isKeyOrFlags)) {
				$tmp           = $isKeyOrFlags;
				$isKeyOrFlags  = $tmp & BaseDbColumnFlags::IS_KEY;
				$shouldInsert  = $tmp & BaseDbColumnFlags::SHOULD_INSERT;
				$shouldUpdate  = $tmp & BaseDbColumnFlags::SHOULD_UPDATE;
				$allowsNulls   = $tmp & BaseDbColumnFlags::ALLOWS_NULLS;
				$autoIncrement = $tmp & BaseDbColumnFlags::AUTO_INCREMENT;
			}

			$this->dbFields[$property] = new BaseDbField($column, $type, $isKeyOrFlags, $shouldInsert, $shouldUpdate, $allowsNulls, $autoIncrement);

			return;
		}

		/**
		 * Sets the value of a class property.
		 *
		 * @param string $property Name of property on class to set.
		 * @param BaseDbField $field Field information to use when formatting value.
		 * @param mixed $value Value to set class property to.
		 * @throws \Exception
		 * @return void
		 */
		protected function setPropertyDbValue(string $property, BaseDbField $field, mixed $value) : void {
			if ($field->type->is(BaseDbTypes::DATETIME) && $value !== null) {
				$this->{$property} = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
			} else if ($field->type->is(BaseDbTypes::BOOLEAN)) {
				$this->{$property} = (bool)$value;
			} else if ($field->type->is(BaseDbTypes::STRING) && $this->{$property} instanceof EnumBase) {
				$enumClass = get_class($this->{$property});
				$this->{$property} = $enumClass::fromString($value);
			} else if ($field->type->is(BaseDbTypes::INTEGER) && $this->{$property} instanceof EnumBase) {
				$enumClass = get_class($this->{$property});
				$this->{$property} = new $enumClass(intval($value));
			} else {
				$this->{$property} = $value;
			}

			return;
		}

		/**
		 * Sets the database table name for this object.
		 *
		 * @param string $name Value of table name.
		 * @return void
		 */
		protected function setTableName(string $name) : void {
			$this->dbTable = new StringHelper($name);

			return;
		}

		/**
		 * Returns all registered db fields with their values in an array.
		 *
		 * @return array
		 */
		public function toArray() : array {
			$ret = [];

			if (count($this->dbFields) > 0) {
				foreach (array_keys($this->dbFields) as $prop) {
					$ret[$prop] = $this->{$prop};
				}
			}

			return $ret;
		}

		/**
		 * Returns all registered db fields with their serializable values in an array.
		 *
		 * @return array
		 */
		public function toSerializableArray() : array {
			$ret = [];

			if (count($this->dbFields) > 0) {
				foreach ($this->dbFields as $property => $field) {
					if ($field->type->is(BaseDbTypes::DATETIME) && $this->{$property} !== null) {
						$ret[$property] = $this->{$property}->format('Y-m-d H:i:s');
					} else if ($this->{$property} instanceof EnumBase) {
						$ret[$property] = $this->{$property}->getValue();
					} else {
						$ret[$property] = $this->{$property};
					}
				}
			}

			return $ret;
		}

		/**
		 * Attempts to update an object in the database.
		 *
		 * @return ReturnHelper
		 */
		public function update() : ReturnHelper {
			$ret = new ReturnHelper();
			$ret->makeBad();

			if (!$this->canProceed('update', $this->__canUpdate(), $ret)) {
				$this->log->error("Not allowed to update.");

				return $ret;
			}

			$primaries = array();
			$primaryStrings = array();
			$updateColumns = array();
			$updateColumnStrings = array();

			foreach ($this->dbFields as $property => $field) {
				$colProp = ":{$property}";

				if ($field->isKey) {
					$primaries[] = $this->getPropertyDbValue($property, $field);
					$primaryStrings[] = "{$this->prepColumn($field->column)} = {$colProp}";
				}

				if ($field->shouldUpdate) {
					$updateColumns[] = $this->getPropertyDbValue($property, $field);
					$updateColumnStrings[] = "{$this->prepColumn($field->column)} = {$colProp}";
				}
			}

			if (count($primaries) < 1) {
				$ret->addMessage("Can't perform generated 'update' on class without primary fields");
				$this->logErrors($ret);

				return $ret;
			}

			try {
				$sql = "UPDATE {$this->getDbTableName()} SET " . implode(', ', array_values($updateColumnStrings)) . " WHERE " .implode(' AND ', array_values($primaryStrings));
				$stmt = $this->db->prepare($sql);
				$paramOutput = [];

				foreach ($primaries as $field) {
					$stmt->bindValue($field[0], $field[1], $field[2]);
					$paramOutput[$field[0]] = $field[1];
				}

				foreach ($updateColumns as $field) {
					$stmt->bindValue($field[0], $field[1], $field[2]);
					$paramOutput[$field[0]] = $field[1];
				}

				$this->log->info("Attempting to 'update' {$this->className}..\n\tQuery: {SQL}\n\tParams: {PARAMS}", array('SQL' => $sql, 'PARAMS' => json_encode($paramOutput)));

				$stmt->execute();
				$ret->makeGood();
				$this->log->info("Successfully updated {$this->className}");
				// @codeCoverageIgnoreStart
			} catch (\PDOException $ex) {
				$this->log->error("Failed to update {$this->className} with error: {ERROR}", array('ERROR' => $ex));
				$ret->addMessage("Failed to update {$this->className} with error: {$ex->getMessage()}");
			}
			// @codeCoverageIgnoreEnd

			$this->logErrors($ret);

			return $ret;
		}
	}
