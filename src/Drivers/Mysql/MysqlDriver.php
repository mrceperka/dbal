<?php

/**
 * This file is part of the Nextras\Dbal library.
 * @license    MIT
 * @link       https://github.com/nextras/dbal
 */

namespace Nextras\Dbal\Drivers\Mysql;

use DateInterval;
use DateTime;
use DateTimeZone;
use mysqli;
use Nextras\Dbal\Connection;
use Nextras\Dbal\Drivers\IDriver;
use Nextras\Dbal\Drivers\DriverException;
use Nextras\Dbal\Exceptions;
use Nextras\Dbal\Platforms\MysqlPlatform;
use Nextras\Dbal\Result\Result;


class MysqlDriver implements IDriver
{
	/** @var mysqli */
	private $connection;

	/** @var DateTimeZone Timezone for columns without timezone handling (datetime). */
	private $simpleStorageTz;

	/** @var DateTimeZone Timezone for database connection. */
	private $connectionTz;


	public function __destruct()
	{
		$this->disconnect();
	}


	public function connect(array $params)
	{
		$host   = isset($params['host']) ? $params['host'] : ini_get('mysqli.default_host');
		$port   = isset($params['port']) ? $params['port'] : ini_get('mysqli.default_port');
		$port   = $port ?: 3306;
		$dbname = isset($params['dbname']) ? $params['dbname'] : (isset($params['database']) ? $params['database'] : NULL);
		$socket = isset($params['unix_socket']) ? $params['unix_socket'] : (ini_get('mysqli.default_socket') ?: NULL);
		$flags  = isset($params['flags']) ? $params['flags'] : 0;

		$this->connection = new mysqli();

		if (!$this->connection->real_connect($host, $params['username'], $params['password'], $dbname, $port, $socket, $flags)) {
			throw new DriverException(
				$this->connection->connect_error,
				$this->connection->connect_errno,
				@$this->connection->sqlstate ?: 'HY000'
			);
		}

		$this->processInitialSettings($params);
	}


	public function disconnect()
	{
		if ($this->connection) {
			$this->connection->close();
			$this->connection = NULL;
		}
	}


	public function isConnected()
	{
		return $this->connection !== NULL;
	}


	/**
	 * This method is based on Doctrine\DBAL project.
	 * @link www.doctrine-project.org
	 */
	public function convertException(DriverException $exception)
	{
		$message = $exception->getMessage();
		$code = (int) $exception->getErrorCode();
		if (in_array($code, [1216, 1217, 1451, 1452, 1701], TRUE)) {
			return new Exceptions\ForeignKeyConstraintViolationException($message, $exception);

		} elseif (in_array($code, [1062, 1557, 1569, 1586], TRUE)) {
			return new Exceptions\UniqueConstraintViolationException($message, $exception);

		} elseif (in_array($code, [1044, 1045, 1046, 1049, 1095, 1142, 1143, 1227, 1370, 2002, 2005], TRUE)) {
			return new Exceptions\ConnectionException($message, $exception);

		} elseif (in_array($code, [1048, 1121, 1138, 1171, 1252, 1263, 1566], TRUE)) {
			return new Exceptions\NotNullConstraintViolationException($message, $exception);

		} else {
			return new Exceptions\DbalException($message, $exception);
		}
	}


	/** @return mysqli */
	public function getResourceHandle()
	{
		return $this->connection;
	}


	public function nativeQuery($query)
	{
		$result = $this->connection->query($query);
		if ($this->connection->errno) {
			throw new DriverException(
				$this->connection->error,
				$this->connection->errno,
				$this->connection->sqlstate
			);
		}

		if ($result === TRUE) {
			return NULL;
		}

		return new Result(new MysqlResultAdapter($result), $this);
	}


	public function getLastInsertedId($sequenceName = NULL)
	{
		return $this->connection->insert_id;
	}


	public function createPlatform(Connection $connection)
	{
		return new MysqlPlatform($connection);
	}


	public function getServerVersion()
	{
		$version = $this->connection->server_version;
		$majorVersion = floor($version / 10000);
		$minorVersion = floor(($version - $majorVersion * 10000) / 100);
		$patchVersion = floor($version - $majorVersion * 10000 - $minorVersion * 100);
		return $majorVersion . '.' . $minorVersion . '.' . $patchVersion;
	}


	public function ping()
	{
		return $this->connection->ping();
	}


	public function transactionBegin()
	{
		$this->nativeQuery('START TRANSACTION');
	}


	public function transactionCommit()
	{
		$this->nativeQuery('COMMIT');
	}


	public function transactionRollback()
	{
		$this->nativeQuery('ROLLBACK');
	}


	protected function processInitialSettings(array $params)
	{
		if (isset($params['charset'])) {
			$charset = $params['charset'];
		} elseif (($version = $this->getServerVersion()) && version_compare($version, '5.5.3', '>=')) {
			$charset = 'utf8mb4';
		} else {
			$charset = 'utf8';
		}

		$this->connection->set_charset($charset);

		if (isset($params['sqlMode'])) {
			$this->nativeQuery('SET sql_mode = ' . $this->convertToSql($params['sqlMode'], self::TYPE_STRING));
		}

		$this->simpleStorageTz = new DateTimeZone(isset($params['simple_storage_tz']) ? $params['simple_storage_tz'] : 'UTC');
		$this->connectionTz = new DateTimeZone(isset($params['connection_tz']) ? $params['connection_tz'] : date_default_timezone_get());
		$this->nativeQuery('SET time_zone = ' . $this->convertToSql($this->connectionTz->getName(), self::TYPE_STRING));
	}


	public function convertToPhp($value, $nativeType)
	{
		if ($nativeType === MYSQLI_TYPE_TIME) {
			preg_match('#^(-?)(\d+):(\d+):(\d+)#', $value, $m);
			$value = new DateInterval("PT{$m[2]}H{$m[3]}M{$m[4]}S");
			$value->invert = $m[1] ? 1 : 0;
			return $value;

		} elseif ($nativeType === MYSQLI_TYPE_DATE || $nativeType === MYSQLI_TYPE_DATETIME) {
			return new DateTime($value . ' ' . $this->simpleStorageTz->getName());

		} elseif ($nativeType === MYSQLI_TYPE_TIMESTAMP) {
			return new DateTime($value . ' ' . $this->connectionTz->getName());

		} elseif ($nativeType === MYSQLI_TYPE_BIT) {
			// called only under HHVM
			return ord($value);

		} else {
			throw new Exceptions\NotSupportedException("MysqlDriver does not support '{$nativeType}' type conversion.");
		}
	}


	public function convertToSql($value, $type)
	{
		switch ($type) {
			case self::TYPE_STRING:
				return "'" . $this->connection->escape_string($value) . "'";

			case self::TYPE_BOOL:
				return $value ? '1' : '0';

			case self::TYPE_IDENTIFIER:
				return str_replace('`*`', '*', '`' . str_replace(['`', '.'], ['``', '`.`'], $value) . '`');

			case self::TYPE_DATETIME:
				if ($value->getTimezone()->getName() !== $this->connectionTz->getName()) {
					$value = clone $value;
					$value->setTimezone($this->connectionTz);
				}
				return "'" . $value->format('Y-m-d H:i:s') . "'";

			case self::TYPE_DATETIME_SIMPLE:
				if ($value->getTimezone()->getName() !== $this->simpleStorageTz->getName()) {
					$value = clone $value;
					$value->setTimeZone($this->simpleStorageTz);
				}
				return "'" . $value->format('Y-m-d H:i:s') . "'";

			default:
				throw new Exceptions\InvalidArgumentException();
		}
	}


	public function modifyLimitQuery($query, $limit, $offset)
	{
		if ($limit !== NULL || $offset !== NULL) {
			// 18446744073709551615 is maximum of unsigned BIGINT
			// see http://dev.mysql.com/doc/refman/5.0/en/select.html
			$query .= ' LIMIT ' . ($limit !== NULL ? (int) $limit : '18446744073709551615');
		}

		if ($offset !== NULL) {
			$query .= ' OFFSET ' . (int) $offset;
		}

		return $query;
	}

}
