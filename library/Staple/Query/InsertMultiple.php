<?php
/**
 * A class for creating SQL Multi-INSERT statements.
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

use Exception;
use Staple\Error;
use Staple\Exception\ConfigurationException;
use Staple\Exception\QueryException;

class InsertMultiple extends Insert
{
	/**
	 * The data to insert. May be a Select Statement Object or an array of DataSets
	 * @var Select|DataSet|array
	 */
	protected Select|array|DataSet $data = [];
	
	/**
	 * Query to insert multiple rows
	 * @param string|null $table
	 * @param array $columns
	 * @param Connection $db
	 * @param string|null $priority
	 * @throws QueryException
	 */
	public function __construct(string $table = NULL, array $columns = NULL, Connection $db = NULL, string $priority = NULL)
	{
		parent::__construct($table, null, $db, $priority);
		
		//Set Data
		if(isset($columns))
		{
			$this->setColumns($columns);
		}
	}

	/**
	 * (non-PHPdoc)
	 * @param bool $parameterized
	 * @return string
	 * @throws QueryException
	 * @throws ConfigurationException
	 * @see Staple_Query_Insert::build()
	 */
	public function build(bool $parameterized = null): string
	{
		//Statement start
		$stmt = "INSERT ";
		
		//Flags
		if(isset($this->priority))
		{
			$stmt .= $this->priority.' ';
		}
		if($this->ignore === TRUE)
		{
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
		
		//Columns
		$stmt .= '('.implode(',', $this->columns).') ';
		
		//Data
		$stmt .= 'VALUES ';
		$rows = array();
		foreach($this->data as $dataset)
		{
			if($dataset instanceof DataSet)
			{
				$rows[] = trim($dataset->getInsertMultipleString());
			}
			else
			{
				//Return null string since we can't throw exceptions on __toString().
				return '';
			}
		}
		$stmt .= implode(', ', $rows);
		
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
					$stmt .= ',';
				}
				$stmt .= " $ucol=VALUES($ucol)";
			}
		}
		
		return $stmt;
	}
	
	/**
	 * Adds a data set to the object
	 * @param DataSet $row
	 * @throws QueryException
	 * @return $this
	 */
	public function addRow(DataSet $row): static
	{
		if(count($row->getColumns()) != count($this->columns))
		{
			throw new QueryException('DataSet row count does not match the count for this object');
		}
		else
		{
			$this->data[] = $row;
			return $this;
		}
	}
	
	//------------------------------------------------GETTERS AND SETTERS------------------------------------------------//
	
	/**
	 * @return array $columns
	 */
	public function getColumns(): array
	{
		return $this->columns;
	}
	/**
	 * @param array[string] $columns
	 * @return $this
	 */
	public function setColumns(array $columns): static
	{
		$this->columns = $columns;
		return $this;
	}
	
	/**
	 * Overides the original setter to verify that the dataset is inserted with proper specifications. 
	 * @param array[DataSet] $data
	 * @throws QueryException
	 * @return $this
	 */
	public function setData(DataSet|array|Select $data): static
	{
		//Check all the array values
		foreach ($data as $row)
		{
			if(!($row instanceof DataSet))
			{
				throw new QueryException('To set the data for this object, the submission must be an array of Staple_Query_DataSet objects.', Error::APPLICATION_ERROR);
			}
			else
			{
				//Sync the dataSet with the current query's connection.
				$row->setConnection($this->getConnection());
			}
		}
		$this->data = $data;
		return $this;
	}
}