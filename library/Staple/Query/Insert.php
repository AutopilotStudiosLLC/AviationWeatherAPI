<?php
/** 
 * A class for creating SQL INSERT statements.
 * 
 * @author Ironpilot
 * @copyright Copyright (c) 2011, STAPLE CODE
 * 
 * This file is part of the STAPLE Framework.
 * 
 * The STAPLE Framework is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by the 
 * Free Software Foundation, either version 3 of the License, or (at your option)
 * any later version.
 * 
 * The STAPLE Framework is distributed in the hope that it will be useful, 
 * but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY
 * or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU Lesser General Public License for 
 * more details.
 * 
 * You should have received a copy of the GNU Lesser General Public License
 * along with the STAPLE Framework.  If not, see <http://www.gnu.org/licenses/>.
 */
namespace Staple\Query;

use ArrayAccess;
use DateTime;
use Exception;
use PDO;
use Staple\Error;
use Staple\Exception\ConfigurationException;
use Staple\Exception\QueryException;
use Staple\Traits\Factory;

class Insert
{
	use Factory;

	const LOW = "LOW_PRIORITY";
	const DELAYED = "DELAYED";
	const HIGH = "HIGH_PRIORITY";
	
	/**
	 * The database object. A database object is required to properly escape input.
	 * @var IConnection
	 */
	protected IConnection $connection;
	/**
	 * The data to insert. May be a Select Statement Object or an array of DataSets
	 * @var DataSet | Select | array
	 */
	protected Select|DataSet|array $data;
	/**
	 * The Priority parameter of the SQL statement
	 * @var string|null
	 */
	protected ?string $priority = null;
	/**
	 * A boolean value used to set the IGNORE parameter
	 * @var boolean
	 */
	protected bool $ignore = false;
	/**
	 * Table to update.
	 * @var string
	 */
	protected string $table;
	/**
	 * The schema name.
	 * @var string
	 */
	protected string $schema;
	
	/**
	 * Boolean flag for ON DUPLICATE KEY UPDATE
	 * @var boolean
	 */
	protected bool $updateOnDuplicate = false;
	/**
	 * The columns to update on a duplicate key.
	 * @var array[string]
	 */
	protected array $updateColumns = array();
	/**
	 * An array of column names to use in the insert statement.
	 * @var array
	 */
	protected array $columns;

	/**
	 * Set the flag for query parameterization
	 * @var bool
	 */
	protected bool $parameterized = true;

	/**
	 * @param string|null $table
	 * @param array|null $data
	 * @param IConnection|null $db
	 * @param string|null $priority
	 * @param bool $parameterized
	 * @throws QueryException
	 */
	public function __construct(string $table = null, array $data = null, IConnection $db = null, string $priority = null, bool $parameterized = null)
	{
		$this->data = new DataSet();
		
		//Process Database connection
		if($db instanceof IConnection)
		{
			$this->setConnection($db);
		}
		else
		{
			try {
				$db = Connection::get();
				$this->setConnection($db);
			}
			catch (ConfigurationException $e)
			{
				throw new QueryException('Unable to find a database connection.', Error::DB_ERROR, $e);
			}
		}
		if(!($this->connection instanceof IConnection))
		{
			throw new QueryException('Unable to create database object', Error::DB_ERROR);
		}

		//Set the dataSet connection
		$this->data->setConnection($db);
		
		//Set Table
		if(isset($table))
		{
			$this->setTable($table);
		}
		
		//Set Data
		if(isset($data))
		{
			$this->setData($data);
		}
		
		//Set Priority
		if(isset($priority))
		{
			$this->setPriority($priority);
		}

		//Set Priority
		if(isset($parameterized))
		{
			$this->setParameterized($parameterized);
		}
	}
	
	/**
	 * Execute the build function and return the result when converting to a string.
	 */
	public function __toString()
	{
		try {
			$msg = $this->build();
		}
		catch (Exception $e)
		{
			$msg = $e->getMessage();
		}
		return $msg;
	}
	
	/**
	 * @see Staple_Query::build()
	 * @param bool $parameterized
	 * @return string
	 * @throws QueryException
	 * @throws ConfigurationException
	 */
	function build(bool $parameterized = null): string
	{
		if(isset($parameterized))
			$this->setParameterized($parameterized);

		//Statement Start
		$stmt = "INSERT ";
		
		//Flags
		if(isset($this->priority))
		{
			$stmt .= $this->priority.' ';
		}
		if($this->ignore === TRUE)
		{
			if($this->connection->getDriver() === Connection::DRIVER_SQLITE)
			{
				$stmt .= 'OR ';
			}
			$stmt .= 'IGNORE ';
		}
		
		//Table
		$stmt .= "\nINTO ";
		if(isset($this->schema))
		{
			$stmt .= $this->schema.'.';
		}
		elseif(!empty($this->connection->getSchema()))
		{
			$stmt .= $this->connection->getSchema().'.';
		}
		$stmt .= $this->table.' ';

		//Column List
		if(isset($this->columns))
		{
			$stmt .= '('.implode(', ', $this->getColumns()).') ';
		}

		//Data
		if($this->data instanceof DataSet)
		{
			$stmt .= $this->data->getInsertString();
		}
		elseif($this->data instanceof Select)
		{
			$stmt .= "\n".$this->data;
		}
		
		//Duplicate Updates
		if($this->updateOnDuplicate === true)
		{
			$first = true;
			$stmt .= "\nON DUPLICATE KEY UPDATE ";
			foreach($this->updateColumns as $ucol)
			{
				if($first === true)
				{
					$first = false;
				}
				else
				{
					$stmt .= ', ';
				}
				$stmt .= " $ucol=VALUES($ucol)";
			}
		}
		
		return $stmt;
	}
	
	/**
	 * Executes the query.
	 * @throws QueryException
	 * @throws ConfigurationException
	 * @return IStatement | bool
	 */
	public function execute(): bool|IStatement
	{
		//Make sure we have a connection object
		if(!($this->connection instanceof Connection))
		{
			try
			{
				$this->setConnection(Connection::get());
			}
			catch (Exception $e)
			{
				throw new QueryException('No Database Connection', Error::DB_ERROR);
			}
		}

		if($this->parameterized !== true)
		{
			//Run raw query string.
			return $this->connection->query($this->build());
		}
		else
		{
			$statement = $this->connection->prepare($this->build(true));
			if($statement !== false && $statement !== null)
			{
				$this->bindParametersToStatement($statement);
				if($statement->execute() === true)
					return $statement;
				else
					throw new QueryException(json_encode($statement->errorInfo()));
			}
			else
				throw new QueryException('Failed to prepare statement for execution.');
		}
	}

	/**
	 * @return array|string[]
	 * @throws QueryException
	 */
	public function getParams(): array
	{
		if($this->data instanceof DataSet)
		{
			$dataSet = $this->data;

			$data = array_filter($this->data->getData(), function($key) use($dataSet) {
				return !$dataSet->isLiteral($key);
			}, ARRAY_FILTER_USE_KEY );

			return $data;
		}
		elseif($this->data instanceof Select)
		{
			return $this->data->getParams();
		}
		else
		{
			throw new QueryException('Insert Data is stored as a non-supported format.');
		}
	}

	/**
	 * Bind the query parameters to the supplied statement object.
	 * @param IStatement $statement
	 * @throws QueryException
	 */
	private function bindParametersToStatement(IStatement &$statement): void
	{
		foreach($this->getParams() as $name=>$value)
		{
			$varType = gettype($value);

			//Bind other types.
			switch($varType)
			{
				case "boolean":
					$type = PDO::PARAM_BOOL;
					break;
				case "integer":
					$type = PDO::PARAM_INT;
					break;
				case "double":
					break;
				case "string":
					$type = PDO::PARAM_STR;
					break;
				case "array":
					$value = implode(',', $value);
					$type = PDO::PARAM_STR;
					break;
				case "object":
					try
					{
						if ($value instanceof DateTime)
						{
							$value = $value->format('Y-m-d H:i:s');
						}
						else
						{
							$value = (string)$value;
						}
					}
					catch(Exception $e)
					{
						throw new QueryException('Could not convert object to string for query.', 0, $e);
					}
					$type = PDO::PARAM_STR;
					break;
				case "resource":
				case "resource (closed)":
					throw new QueryException('Cannot supply a resource to a query.');
				case "NULL":
					$type = PDO::PARAM_NULL;
					break;
				default:
					throw new QueryException('Unable to determine parameter type.');
			}

			//Check for a large value
			if($type === PDO::PARAM_STR && strlen($value) > 4000)
				$type = PDO::PARAM_LOB;

			$statement->bindValue($name, $value, $type);
		}
	}
	
	
	/**
	 * Adds or replaces data in the insert dataset.
	 * @param array $data
	 * @throws QueryException
	 * @return $this
	 */	
	public function addData(array $data): static
	{
		if($this->data instanceof Select)
		{
			throw new QueryException('Cannot add data to an INSERT ... SELECT statement.', Error::DB_ERROR);
		}
		$this->data->addData($data);
		return $this;
	}
	
	/**
	 * Adds or replaces a specific column value. Alias is set Data Column
	 * @param string $column
	 * @param mixed $data
	 * @return $this
	 *@throws QueryException
	 * @see self::setDataColumn
	 */
	public function addDataColumn(string $column, mixed $data): static
	{
		return $this->setDataColumn($column, $data);
	}
	
	/**
	 * Adds a literal value to the dataset without conversion.
	 * @param string $column
	 * @param string $value
	 * @return $this
	 * @throws QueryException
	 */
	public function addLiteralColumn(string $column, string $value): static
	{
		return $this->setDataColumn($column, $value, true);
	}
	
	//----------------------------------------------GETTERS AND SETTERS----------------------------------------------
	
	/**
	 * @return IConnection
	 */
	public function getConnection(): IConnection
	{
		return $this->connection;
	}
	
	/**
	 * @return DataSet | Select $data
	 */
	public function getData(): DataSet|Select
	{
		return $this->data;
	}
	
	/**
	 * @return string $priority
	 */
	public function getPriority(): string
	{
		return $this->priority;
	}

	/**
	 * @return bool $ignore
	 */
	public function getIgnore(): bool
	{
		return $this->ignore;
	}

	/**
	 * @return Query | Union | string $table
	 */
	public function getTable(): Query|Union|string
	{
		return $this->table;
	}

	/**
	 * @return bool $updateOnDuplicate
	 */
	public function getUpdateOnDuplicate(): bool
	{
		return $this->updateOnDuplicate;
	}

	/**
	 * @return array $updateColumns
	 */
	public function getUpdateColumns(): array
	{
		return $this->updateColumns;
	}

	/**
	 * Return the column array
	 * @return array
	 */
	public function getColumns(): array
	{
		return $this->columns;
	}

	/**
	 * Set the array of columns
	 * @param array $columns
	 * @return Insert
	 */
	public function setColumns(array $columns): static
	{
		$this->columns = $columns;
		return $this;
	}

	/**
	 * Get the schema string.
	 * @return string
	 */
	public function getSchema(): string
	{
		return $this->schema;
	}

	/**
	 * Set the schema string
	 * @param string $schema
	 * @return $this
	 */
	public function setSchema(string $schema): static
	{
		$this->schema = $schema;

		return $this;
	}

	/**
	 * @return bool
	 */
	public function isParameterized(): bool
	{
		return $this->parameterized;
	}

	/**
	 * @param bool $parameterized
	 * @return Insert
	 */
	public function setParameterized(bool $parameterized): Insert
	{
		$this->parameterized = $parameterized;
		return $this;
	}
	
	/**
	 * @param IConnection $connection
	 * @return $this
	 */
	public function setConnection(IConnection $connection): static
	{
		$this->connection = $connection;
		return $this;
	}
	
	/**
	 * Sets the $data
	 * @param array | DataSet | Select $data
	 * @return $this
	 *@throws QueryException
	 */
	public function setData(DataSet|array|Select $data): static
	{
		if($data instanceof Select || $data instanceof DataSet)
		{
			$this->data = $data;
		}
		elseif(is_array($data))
		{
			$this->data = new DataSet($data);
			$this->data->setConnection($this->getConnection());
		}
		else
		{
			throw new QueryException('Data must be an instance of \Staple\Query\DataSet, an instance of \Staple\Query\Select or an array', Error::APPLICATION_ERROR);
		}
		return $this;
	}
	
	/**
	 * Sets the specified value for a specific column.
	 * @param string $column
	 * @param mixed $data
	 * @param bool $literal
	 * @throws QueryException
	 * @return $this
	 */
	public function setDataColumn($column,$data,$literal = false)
	{
		if($this->data instanceof Select)
		{
			throw new QueryException('Cannot add data to an INSERT ... SELECT statement.', Error::DB_ERROR);
		}
		if($literal === true)
		{
			$this->data->addLiteralColumn($column, $data);
		}
		else
		{
			$this->data[$column] = $data;
		}
		return $this;
	}

	/**
	 * @param string $priority
	 * @return $this
	 */
	public function setPriority($priority)
	{
		switch($priority)
		{
			case self::DELAYED:
				$this->priority = self::DELAYED;
				break;
			case self::HIGH:
				$this->priority = self::HIGH;
				break;
			case self::LOW:
				$this->priority = self::LOW;
				break;
			default: $this->priority = NULL;
		}
		return $this;
	}

	/**
	 * @param boolean $ignore
	 * @return $this
	 */
	public function setIgnore($ignore)
	{
		$this->ignore = (bool)$ignore;
		return $this;
	}

	/**
	 * @param string $table
	 * @return $this
	 */
	public function setTable($table)
	{
		$this->table = $table;
		return $this;
	}

	/**
	 * @param bool $updateOnDuplicate
	 * @return $this
	 */
	public function setUpdateOnDuplicate($updateOnDuplicate)
	{
		$this->updateOnDuplicate = (bool)$updateOnDuplicate;
		return $this;
	}

	/**
	 * @param array[string] $updateColumns
	 * @return $this
	 */
	public function setUpdateColumns(array $updateColumns)
	{
		$this->updateColumns = $updateColumns;
		return $this;
	}
	
	/**
	 * Setup On Duplicate Key Update Syntax
	 * @param bool $bool
	 * @return $this
	 */
	public function onDuplicateKeyUpdate($bool = true)
	{
		$this->setUpdateOnDuplicate((bool)$bool);
		return $this;
	}

	/**
	 * Returns the last ID inserted into the database.
	 * @return string
	 */
	public function getInsertId()
	{
		return $this->getConnection()->lastInsertId();
	}
}