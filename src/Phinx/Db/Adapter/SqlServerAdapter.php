<?php
/**
 * Phinx
 *
 * (The MIT license)
 * Copyright (c) 2014 Rob Morgan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated * documentation files (the "Software"), to
 * deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or
 * sell copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 * 
 * @package    Phinx
 * @subpackage Phinx\Db\Adapter
 */
namespace Phinx\Db\Adapter;

use Phinx\Db\Table;
use Phinx\Db\Table\Column;
use Phinx\Db\Table\Index;
use Phinx\Db\Table\ForeignKey;
use Phinx\Migration\MigrationInterface;

/**
 * Phinx SqlServer Adapter.
 *
 * @author Rob Morgan <robbym@gmail.com>
 */
class SqlServerAdapter extends PdoAdapter implements AdapterInterface
{

	protected $schema = 'dbo';

    protected $signedColumnTypes = array('integer' => true, 'biginteger' => true, 'float' => true, 'decimal' => true);

    /**
     * {@inheritdoc}
     */
    public function connect()
    {
        if (null === $this->connection) {
            if (!class_exists('PDO') || !in_array('sqlsrv', \PDO::getAvailableDrivers(), true)) {
                // @codeCoverageIgnoreStart
                throw new \RuntimeException('You need to enable the PDO_SqlSrv extension for Phinx to run properly.');
                // @codeCoverageIgnoreEnd
            }
            
            $db = null;
            $options = $this->getOptions();
            
            // if port is specified use it, otherwise use the SqlServer default
            if (empty($options['port'])) {
                $dsn = 'sqlsrv:server=' . $options['host'] . ';database=' . $options['name'];
            } else {
                $dsn = 'sqlsrv:server=' . $options['host'] . ',' . $options['port'] . ';database=' . $options['name'];
            }
	        $dsn .= ';MultipleActiveResultSets=false';
            
            // charset support
            if (isset($options['charset'])) {
                $dsn .= ';charset=' . $options['charset'];
            }

            $driverOptions = array(\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION);

            // support arbitrary \PDO::SQLSRV_ATTR_* driver options and pass them to PDO
            // http://php.net/manual/en/ref.pdo-sqlsrv.php#pdo-sqlsrv.constants
            foreach ($options as $key => $option) {
                if (strpos($key, 'sqlsrv_attr_') === 0) {
                    $driverOptions[constant('\PDO::' . strtoupper($key))] = $option;
                }
            }

            try {
                $db = new \PDO($dsn, $options['user'], $options['pass'], $driverOptions);
            } catch (\PDOException $exception) {
                throw new \InvalidArgumentException(sprintf(
                    'There was a problem connecting to the database: %s',
                    $exception->getMessage()
                ));
            }

            $this->setConnection($db);
            
            // Create the schema table if it doesn't already exist
            if (!$this->hasSchemaTable()) {
                $this->createSchemaTable();
            }
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function disconnect()
    {
        $this->connection = null;
    }
    
    /**
     * {@inheritdoc}
     */
    public function hasTransactions()
    {
        return true;
    }
    
    /**
     * {@inheritdoc}
     */
    public function beginTransaction()
    {
        $this->execute('BEGIN TRANSACTION');
    }
    
    /**
     * {@inheritdoc}
     */
    public function commitTransaction()
    {
        $this->execute('COMMIT TRANSACTION');
    }
    
    /**
     * {@inheritdoc}
     */
    public function rollbackTransaction()
    {
        $this->execute('ROLLBACK TRANSACTION');
    }
    
    /**
     * {@inheritdoc}
     */
    public function quoteTableName($tableName)
    {
        return str_replace('.', '].[', $this->quoteColumnName($tableName));
    }
    
    /**
     * {@inheritdoc}
     */
    public function quoteColumnName($columnName)
    {
        return '[' . str_replace(']', '\]', $columnName) . ']';
    }

	/**
	 * {@inheritdoc}
	 */
	public function hasTable($tableName) {

		$result = $this->fetchRow(sprintf('SELECT count(*) as [count] FROM information_schema.tables WHERE table_name = \'%s\';', $tableName));

		return $result['count'] > 0;
	}

    /**
     * {@inheritdoc}
     */
    public function createTable(Table $table)
    {
        $this->startCommandTimer();

        $options = $table->getOptions();

        // Add the default primary key
        $columns = $table->getPendingColumns();
        if (!isset($options['id']) || (isset($options['id']) && $options['id'] === true)) {
            $column = new Column();
            $column->setName('id')
                   ->setType('integer')
                   ->setIdentity(true);
            
            array_unshift($columns, $column);
            $options['primary_key'] = 'id';

        } elseif (isset($options['id']) && is_string($options['id'])) {
            // Handle id => "field_name" to support AUTO_INCREMENT
            $column = new Column();
            $column->setName($options['id'])
                   ->setType('integer')
                   ->setIdentity(true);

            array_unshift($columns, $column);
            $options['primary_key'] = $options['id'];
        }

        $sql = 'CREATE TABLE ';
        $sql .= $this->quoteTableName($table->getName()) . ' (';
	    $sqlBuffer = array();
	    $columnsWithComments = [];
        foreach ($columns as $column) {
	        $sqlBuffer[] = $this->quoteColumnName($column->getName()) . ' ' . $this->getColumnSqlDefinition($column);

			// set column comments, if needed
	        if ($column->getComment()) {
		        $columnsWithComments[] = $column;
	        }
        }

	    // set the primary key(s)
	    if (isset($options['primary_key'])) {
		    $pkSql = sprintf('CONSTRAINT PK_%s PRIMARY KEY (', $table->getName());
		    if (is_string($options['primary_key'])) { // handle primary_key => 'id'
			    $pkSql .= $this->quoteColumnName($options['primary_key']);
		    } elseif (is_array($options['primary_key'])) { // handle primary_key => array('tag_id', 'resource_id')
			    // PHP 5.4 will allow access of $this, so we can call quoteColumnName() directly in the anonymous function,
			    // but for now just hard-code the adapter quotes
			    $pkSql .= implode(
				    ',',
				    array_map(
					    function ($v) {
						    return '[' . $v . ']';
					    },
					    $options['primary_key']
				    )
			    );
		    }
		    $pkSql .= ')';
		    $sqlBuffer[] = $pkSql;
	    }

	    // set the foreign keys
	    $foreignKeys = $table->getForeignKeys();
	    if (!empty($foreignKeys)) {
		    foreach ($foreignKeys as $foreignKey) {
			    $sqlBuffer[] = $this->getForeignKeySqlDefinition($foreignKey, $table->getName());
		    }
	    }

	    $sql .= implode(', ', $sqlBuffer);
	    $sql .= ');';

	    // process column comments
	    if (!empty($columnsWithComments)) {
		    foreach ($columnsWithComments as $column) {
			    $sql .= $this->getColumnCommentSqlDefinition($column, $table->getName());
		    }
	    }

	    // set the indexes
	    $indexes = $table->getIndexes();
	    if (!empty($indexes)) {
		    foreach ($indexes as $index) {
			    $sql .= $this->getIndexSqlDefinition($index, $table->getName());
		    }
	    }

        // execute the sql
        $this->writeCommand('createTable', array($table->getName()));
        $this->execute($sql);
        $this->endCommandTimer();
    }

	/**
	 * Gets the SqlServer Column Comment Defininition for a column object.
	 *
	 * @param Column $column    Column
	 * @param string $tableName Table name
	 *
	 * @return string
	 */
	protected function getColumnCommentSqlDefinition(Column $column, $tableName) {
		// passing 'null' is to remove column comment
		$currentComment = $this->getColumnComment($tableName, $column->getName());

		$comment = (strtoupper($column->getComment()) !== 'NULL') ? $this->getConnection()->quote($column->getComment()) : '\'\'';
		$command = $currentComment === false ? 'sp_addextendedproperty' : 'sp_updateextendedproperty';
		return sprintf(
			"EXECUTE %s N'MS_Description', N%s, N'SCHEMA', N'%s', N'TABLE', N'%s', N'COLUMN', N'%s';",
			$command,
			$comment,
			$this->schema,
			$tableName,
			$column->getName()
		);
	}

    /**
     * {@inheritdoc}
     */
    public function renameTable($tableName, $newTableName)
    {
        $this->startCommandTimer();
        $this->writeCommand('renameTable', array($tableName, $newTableName));
        $this->execute(sprintf('EXEC sp_rename \'%s\', \'%s\'', $tableName, $newTableName));
        $this->endCommandTimer();
    }
    
    /**
     * {@inheritdoc}
     */
    public function dropTable($tableName)
    {
        $this->startCommandTimer();
        $this->writeCommand('dropTable', array($tableName));
        $this->execute(sprintf('DROP TABLE %s', $this->quoteTableName($tableName)));
        $this->endCommandTimer();
    }

	public function getColumnComment($tableName, $columnName) {
		$sql = sprintf("SELECT cast(extended_properties.[value] as nvarchar(4000)) comment
  FROM sys.schemas
 INNER JOIN sys.tables
    ON schemas.schema_id = tables.schema_id
 INNER JOIN sys.columns
    ON tables.object_id = columns.object_id
 INNER JOIN sys.extended_properties
    ON tables.object_id = extended_properties.major_id
   AND columns.column_id = extended_properties.minor_id
   AND extended_properties.name = 'MS_Description'
   WHERE schemas.[name] = '%s' AND tables.[name] = '%s' AND columns.[name] = '%s'", $this->schema, $tableName, $columnName);
		$row = $this->fetchRow($sql);

		if ($row) {
			return $row['comment'];
		}

		return false;
	}

    /**
     * {@inheritdoc}
     */
    public function getColumns($tableName)
    {
        $columns = array();
	    $sql = sprintf(
		    "SELECT DISTINCT TABLE_SCHEMA AS [schema], TABLE_NAME as [table_name], COLUMN_NAME AS [name], DATA_TYPE AS [type],
			IS_NULLABLE AS [null], COLUMN_DEFAULT AS [default],
			CHARACTER_MAXIMUM_LENGTH AS [char_length],
			NUMERIC_PRECISION AS [precision],
			NUMERIC_SCALE AS [scale], ORDINAL_POSITION AS [ordinal_position],
			COLUMNPROPERTY(object_id(TABLE_NAME), COLUMN_NAME, 'IsIdentity') as [identity]
		FROM INFORMATION_SCHEMA.COLUMNS
		WHERE TABLE_NAME = '%s'
		ORDER BY ordinal_position",
		    $tableName
	    );
        $rows = $this->fetchAll($sql);
        foreach ($rows as $columnInfo) {
            $column = new Column();
            $column->setName($columnInfo['name'])
                   ->setType($this->getPhinxType($columnInfo['type']))
                   ->setNull($columnInfo['null'] != 'NO')
                   ->setDefault(preg_replace(array("/\('(.*)'\)/", "/\(\((.*)\)\)/"), '$1', $columnInfo['default']))
                   ->setIdentity($columnInfo['identity'] === '1')
	               ->setComment($this->getColumnComment($columnInfo['table_name'], $columnInfo['name']));

	        if (!empty($columnInfo['char_length'])) {
		        $column->setLimit($columnInfo['char_length']);
	        }

            $columns[] = $column;
        }

        return $columns;
    }
    
    /**
     * {@inheritdoc}
     */
	public function hasColumn($tableName, $columnName, $options = array()) {
		$sql = sprintf(
			"SELECT count(*) as [count]
			 FROM information_schema.columns
			 WHERE table_name = '%s' AND column_name = '%s'",
			$tableName,
			$columnName
		);
		$result = $this->fetchRow($sql);

		return $result['count'] > 0;
	}
    
    /**
     * {@inheritdoc}
     */
    public function addColumn(Table $table, Column $column)
    {
        $this->startCommandTimer();
        $sql = sprintf(
            'ALTER TABLE %s ADD %s %s',
            $this->quoteTableName($table->getName()),
            $this->quoteColumnName($column->getName()),
            $this->getColumnSqlDefinition($column)
        );

        $this->writeCommand('addColumn', array($table->getName(), $column->getName(), $column->getType()));
        $this->execute($sql);
        $this->endCommandTimer();
    }
    
    /**
     * {@inheritdoc}
     */
    public function renameColumn($tableName, $columnName, $newColumnName)
    {
	    $this->startCommandTimer();

	    if (!$this->hasColumn($tableName, $columnName)) {
		    throw new \InvalidArgumentException("The specified column does not exist: $columnName");
	    }
	    $this->writeCommand('renameColumn', array($tableName, $columnName, $newColumnName));
	    $this->execute(
	         sprintf(
		         "EXECUTE sp_rename N'%s.%s', N'%s', 'COLUMN' ",
		         $tableName,
		         $columnName,
		         $newColumnName
	         )
	    );
	    $this->endCommandTimer();
    }
    
    /**
     * {@inheritdoc}
     */
    public function changeColumn($tableName, $columnName, Column $newColumn)
    {
        $this->startCommandTimer();
        $this->writeCommand('changeColumn', array($tableName, $columnName, $newColumn->getType()));
	    if ($columnName != $newColumn->getName()) {
		    $this->renameColumn($tableName, $columnName, $newColumn->getName());
	    }
        $this->execute(
            sprintf(
                'ALTER TABLE %s ALTER COLUMN %s %s',
                $this->quoteTableName($tableName),
                $this->quoteColumnName($newColumn->getName()),
                $this->getColumnSqlDefinition($newColumn)
            )
        );
	    // change column comment if needed
	    if ($newColumn->getComment()) {
		    $sql = $this->getColumnCommentSqlDefinition($newColumn, $tableName);
		    $this->execute($sql);
	    }
        $this->endCommandTimer();
    }
    
    /**
     * {@inheritdoc}
     */
    public function dropColumn($tableName, $columnName)
    {
        $this->startCommandTimer();
        $this->writeCommand('dropColumn', array($tableName, $columnName));
        $this->execute(
            sprintf(
                'ALTER TABLE %s DROP COLUMN %s',
                $this->quoteTableName($tableName),
                $this->quoteColumnName($columnName)
            )
        );
        $this->endCommandTimer();
    }

	protected function getIndexColums($tableId, $indexId) {
		$sql = "SELECT AC.[name] AS [column_name]
FROM sys.[index_columns] IC
  INNER JOIN sys.[all_columns] AC ON IC.[column_id] = AC.[column_id]
WHERE AC.[object_id] = {$tableId} AND IC.[index_id] = {$indexId}  AND IC.[object_id] = {$tableId}
ORDER BY IC.[key_ordinal];";

		$rows = $this->fetchAll($sql);
		$columns = array();
		foreach($rows as $row) {
			$columns[] = strtolower($row['column_name']);
		}
		return $columns;
	}

    /**
     * Get an array of indexes from a particular table.
     *
     * @param string $tableName Table Name
     * @return array
     */
	public function getIndexes($tableName) {
		$indexes = array();
		$sql = "SELECT I.[name] AS [index_name], I.[index_id] as [index_id], T.[object_id] as [table_id]
FROM sys.[tables] AS T
  INNER JOIN sys.[indexes] I ON T.[object_id] = I.[object_id]
WHERE T.[is_ms_shipped] = 0 AND I.[type_desc] <> 'HEAP'  AND T.[name] = '{$tableName}'
ORDER BY T.[name], I.[index_id];";

		$rows = $this->fetchAll($sql);
		foreach ($rows as $row) {
			$columns = $this->getIndexColums($row['table_id'], $row['index_id']);
			$indexes[$row['index_name']] = array('columns' => $columns);
		}

		return $indexes;
	}
    
    /**
     * {@inheritdoc}
     */
    public function hasIndex($tableName, $columns)
    {
        if (is_string($columns)) {
            $columns = array($columns); // str to array
        }
        
        $columns = array_map('strtolower', $columns);
        $indexes = $this->getIndexes($tableName);
        
        foreach ($indexes as $index) {
            $a = array_diff($columns, $index['columns']);

            if (empty($a)) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * {@inheritdoc}
     */
    public function addIndex(Table $table, Index $index)
    {
        $this->startCommandTimer();
        $this->writeCommand('addIndex', array($table->getName(), $index->getColumns()));
	    $sql = $this->getIndexSqlDefinition($index, $table->getName());
        $this->execute($sql);
        $this->endCommandTimer();
    }
    
    /**
     * {@inheritdoc}
     */
    public function dropIndex($tableName, $columns)
    {
        $this->startCommandTimer();
        if (is_string($columns)) {
            $columns = array($columns); // str to array
        }
        
        $this->writeCommand('dropIndex', array($tableName, $columns));
        $indexes = $this->getIndexes($tableName);
        $columns = array_map('strtolower', $columns);

        foreach ($indexes as $indexName => $index) {
            $a = array_diff($columns, $index['columns']);
            if (empty($a)) {
                $this->execute(
                    sprintf(
                        'DROP INDEX %s ON %s',
                        $this->quoteColumnName($indexName),
                        $this->quoteTableName($tableName)
                    )
                );
                $this->endCommandTimer();
                return;
            }
        }
    }
    
    /**
     * {@inheritdoc}
     */
    public function dropIndexByName($tableName, $indexName)
    {
        $this->startCommandTimer();
        
        $this->writeCommand('dropIndexByName', array($tableName, $indexName));
        $indexes = $this->getIndexes($tableName);
        
        foreach ($indexes as $name => $index) {
            if ($name === $indexName) {
                $this->execute(
                    sprintf(
                        'DROP INDEX %s ON %s',
                        $this->quoteColumnName($indexName),
                        $this->quoteTableName($tableName)
                    )
                );
                $this->endCommandTimer();
                return;
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function hasForeignKey($tableName, $columns, $constraint = null)
    {
        if (is_string($columns)) {
            $columns = array($columns); // str to array
        }
        $foreignKeys = $this->getForeignKeys($tableName);
        if ($constraint) {
            if (isset($foreignKeys[$constraint])) {
                return !empty($foreignKeys[$constraint]);
            }
            return false;
        } else {
            foreach ($foreignKeys as $key) {
                $a = array_diff($columns, $key['columns']);
                if (empty($a)) {
                    return true;
                }
            }
            return false;
        }
    }

    /**
     * Get an array of foreign keys from a particular table.
     *
     * @param string $tableName Table Name
     * @return array
     */
	protected function getForeignKeys($tableName) {
		$foreignKeys = array();
		$rows = $this->fetchAll(sprintf(
			"SELECT
					tc.constraint_name,
					tc.table_name, kcu.column_name,
					ccu.table_name AS referenced_table_name,
					ccu.column_name AS referenced_column_name
				FROM
					information_schema.table_constraints AS tc
					JOIN information_schema.key_column_usage AS kcu ON tc.constraint_name = kcu.constraint_name
					JOIN information_schema.constraint_column_usage AS ccu ON ccu.constraint_name = tc.constraint_name
				WHERE constraint_type = 'FOREIGN KEY' AND tc.table_name = '%s'
				ORDER BY kcu.ordinal_position",
			$tableName
		));
		foreach ($rows as $row) {
			$foreignKeys[$row['constraint_name']]['table'] = $row['table_name'];
			$foreignKeys[$row['constraint_name']]['columns'][] = $row['column_name'];
			$foreignKeys[$row['constraint_name']]['referenced_table'] = $row['referenced_table_name'];
			$foreignKeys[$row['constraint_name']]['referenced_columns'][] = $row['referenced_column_name'];
		}

		return $foreignKeys;
	}

    /**
     * {@inheritdoc}
     */
    public function addForeignKey(Table $table, ForeignKey $foreignKey)
    {
        $this->startCommandTimer();
        $this->writeCommand('addForeignKey', array($table->getName(), $foreignKey->getColumns()));
        $this->execute(
            sprintf(
                'ALTER TABLE %s ADD %s',
                $this->quoteTableName($table->getName()),
                $this->getForeignKeySqlDefinition($foreignKey, $table->getName())
            )
        );
        $this->endCommandTimer();
    }

    /**
     * {@inheritdoc}
     */
    public function dropForeignKey($tableName, $columns, $constraint = null)
    {
        $this->startCommandTimer();
        if (is_string($columns)) {
            $columns = array($columns); // str to array
        }
        
        $this->writeCommand('dropForeignKey', array($tableName, $columns));
        
        if ($constraint) {
            $this->execute(
                sprintf(
                    'ALTER TABLE %s DROP CONSTRAINT %s',
                    $this->quoteTableName($tableName),
                    $constraint
                )
            );
            $this->endCommandTimer();
            return;
        } else {
            foreach ($columns as $column) {
                $rows = $this->fetchAll(sprintf(
                    "SELECT
					tc.constraint_name,
					tc.table_name, kcu.column_name,
					ccu.table_name AS referenced_table_name,
					ccu.column_name AS referenced_column_name
				FROM
					information_schema.table_constraints AS tc
					JOIN information_schema.key_column_usage AS kcu ON tc.constraint_name = kcu.constraint_name
					JOIN information_schema.constraint_column_usage AS ccu ON ccu.constraint_name = tc.constraint_name
				WHERE constraint_type = 'FOREIGN KEY' AND tc.table_name = '%s' and ccu.column_name='%s'
				ORDER BY kcu.ordinal_position",
                    $tableName,
                    $column
                ));
                foreach ($rows as $row) {
                    $this->dropForeignKey($tableName, $columns, $row['constraint_name']);
                }
            }
        }
        $this->endCommandTimer();
    }
    
    /**
     * {@inheritdoc}
     */
    public function getSqlType($type)
    {
        switch ($type) {
            case static::PHINX_TYPE_STRING:
                return array('name' => 'nvarchar', 'limit' => 255);
                break;
            case static::PHINX_TYPE_TEXT:
                return array('name' => 'ntext');
                break;
            case static::PHINX_TYPE_INTEGER:
                return array('name' => 'int');
                break;
            case static::PHINX_TYPE_BIG_INTEGER:
                return array('name' => 'bigint');
                break;
            case static::PHINX_TYPE_FLOAT:
                return array('name' => 'float');
                break;
            case static::PHINX_TYPE_DECIMAL:
                return array('name' => 'decimal');
                break;
            case static::PHINX_TYPE_DATETIME:
            case static::PHINX_TYPE_TIMESTAMP:
                return array('name' => 'datetime');
                break;
            case static::PHINX_TYPE_TIME:
                return array('name' => 'time');
                break;
            case static::PHINX_TYPE_DATE:
                return array('name' => 'date');
                break;
            case static::PHINX_TYPE_BINARY:
                return array('name' => 'binary');
                break;
            case static::PHINX_TYPE_BOOLEAN:
                return array('name' => 'bit');
                break;
	        case static::PHINX_TYPE_UUID:
		        return array('name' => 'uniqueidentifier');
            default:
                throw new \RuntimeException('The type: "' . $type . '" is not supported.');
        }
    }

    /**
     * Returns Phinx type by SQL type
     *
     * @param $sqlTypeDef
     * @throws \RuntimeException
     * @internal param string $sqlType SQL type
     * @returns string Phinx type
     */
	public function getPhinxType($sqlType) {
		switch ($sqlType) {
			case 'nvarchar':
			case 'varchar':
			case 'char':
				return static::PHINX_TYPE_STRING;
			case 'text':
			case 'ntext':
				return static::PHINX_TYPE_TEXT;
			case 'int':
			case 'integer':
				return static::PHINX_TYPE_INTEGER;
			case 'decimal':
			case 'numeric':
			case 'money':
				return static::PHINX_TYPE_DECIMAL;
			case 'bigint':
				return static::PHINX_TYPE_BIG_INTEGER;
			case 'real':
			case 'float':
				return static::PHINX_TYPE_FLOAT;
			case 'binary':
			case 'image':
				return static::PHINX_TYPE_BINARY;
				break;
			case 'time':
				return static::PHINX_TYPE_TIME;
			case 'date':
				return static::PHINX_TYPE_DATE;
			case 'datetime':
			case 'timestamp':
				return static::PHINX_TYPE_DATETIME;
			case 'bit':
				return static::PHINX_TYPE_BOOLEAN;
			case 'uniqueidentifier':
				return static::PHINX_TYPE_UUID;
			default:
				throw new \RuntimeException('The SqlServer type: "' . $sqlType . '" is not supported');
		}
	}

    /**
     * {@inheritdoc}
     */
    public function createDatabase($name, $options = array())
    {
        $this->startCommandTimer();
        $this->writeCommand('createDatabase', array($name));

        if (isset($options['collation'])) {
            $this->execute(sprintf('CREATE DATABASE [%s] COLLATE [%s]', $name, $options['collation']));
        } else {
            $this->execute(sprintf('CREATE DATABASE [%s]', $name));
        }
	    $this->execute(sprintf('USE [%s]', $name));
        $this->endCommandTimer();
    }
    
    /**
     * {@inheritdoc}
     */
    public function hasDatabase($name)
    {
	    $result = $this->fetchRow(
            sprintf(
                'SELECT count(*) as [count] FROM master.dbo.sysdatabases WHERE [name] = \'%s\'',
                $name
            )
        );

	    return $result['count'] > 0;
    }
    
    /**
     * {@inheritdoc}
     */
    public function dropDatabase($name)
    {
        $this->startCommandTimer();
        $this->writeCommand('dropDatabase', array($name));
	    $sql = <<<SQL
USE master;
IF EXISTS(select * from sys.databases where name=N'$name')
ALTER DATABASE [$name] SET SINGLE_USER WITH ROLLBACK IMMEDIATE;
DROP DATABASE [$name];
SQL;
        $this->execute($sql);
        $this->endCommandTimer();
    }
    
    /**
     * Gets the SqlServer Column Definition for a Column object.
     *
     * @param Column $column Column
     * @return string
     */
	protected function getColumnSqlDefinition(Column $column) {
		$buffer = array();

		$sqlType = $this->getSqlType($column->getType());
		$buffer[] = strtoupper($sqlType['name']);
		// integers cant have limits in SQlServer
		$noLimits = array(
			'bigint',
			'int',
			'tinyint'
		);
		if (!in_array($sqlType['name'], $noLimits) && ($column->getLimit() || isset($sqlType['limit']))) {
			$buffer[] = sprintf('(%s)', $column->getLimit() ? $column->getLimit() : $sqlType['limit']);
		}

		$buffer[] = $column->isNull() ? 'NULL' : 'NOT NULL';
		$default = $column->getDefault();
		if (is_numeric($default) || 'CURRENT_TIMESTAMP' === $default) {
			$buffer[] = 'DEFAULT';
			$buffer[] = $default;
		} elseif ($default) {
			$buffer[] = "DEFAULT '{$default}'";
		}

		if ($column->isIdentity()) {
			$buffer[] = 'IDENTITY(1, 1)';
		}

		// TODO - add precision & scale for decimals
		return implode(' ', $buffer);
	}
    
    /**
     * Gets the SqlServer Index Definition for an Index object.
     *
     * @param Index $index Index
     * @return string
     */
	protected function getIndexSqlDefinition(Index $index, $tableName) {
		if (is_string($index->getName())) {
			$indexName = $index->getName();
		} else {
			$columnNames = $index->getColumns();
			if (is_string($columnNames)) {
				$columnNames = array($columnNames);
			}
			$indexName = sprintf('%s_%s', $tableName, implode('_', $columnNames));
		}
		$def = sprintf(
			"CREATE %s INDEX %s ON %s (%s);",
			($index->getType() == Index::UNIQUE ? 'UNIQUE' : ''),
			$indexName,
			$this->quoteTableName($tableName),
			'[' . implode('],[', $index->getColumns()) . ']'
		);

		return $def;
	}

    /**
     * Gets the SqlServer Foreign Key Definition for an ForeignKey object.
     *
     * @param ForeignKey $foreignKey
     * @return string
     */
	protected function getForeignKeySqlDefinition(ForeignKey $foreignKey, $tableName) {
		$def = ' CONSTRAINT "';
		$def .= $tableName . '_' . implode('_', $foreignKey->getColumns());
		$def .= '" FOREIGN KEY ("' . implode('", "', $foreignKey->getColumns()) . '")';
		$def .= " REFERENCES {$foreignKey->getReferencedTable()->getName()} (\"" . implode('", "', $foreignKey->getReferencedColumns()) . '")';
		if ($foreignKey->getOnDelete()) {
			$def .= " ON DELETE {$foreignKey->getOnDelete()}";
		}
		if ($foreignKey->getOnUpdate()) {
			$def .= " ON UPDATE {$foreignKey->getOnUpdate()}";
		}

		return $def;
	}

	/**
	 * {@inheritdoc}
	 */
	public function getColumnTypes() {
		return array(
			'primary_key',
			'string',
			'text',
			'integer',
			'biginteger',
			'float',
			'decimal',
			'datetime',
			'timestamp',
			'time',
			'date',
			'binary',
			'boolean',
			'uuid'
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function migrated(MigrationInterface $migration, $direction, $startTime, $endTime) {
		if (strtolower($direction) == MigrationInterface::UP) {
			// up
			$sql = sprintf(
				"INSERT INTO %s ([version], [start_time], [end_time]) VALUES ('%s', '%s', '%s');",
				$this->getSchemaTableName(),
				$migration->getVersion(),
				$startTime,
				$endTime
			);

			$this->query($sql);
		} else {
			// down
			$sql = sprintf(
				"DELETE FROM %s WHERE [version] = '%s'",
				$this->getSchemaTableName(),
				$migration->getVersion()
			);

			$this->query($sql);
		}

		return $this;
	}
}