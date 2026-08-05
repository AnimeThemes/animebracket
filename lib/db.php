<?php

/**
 * PDO wrapper class
 */

namespace Lib {

	use PDO;
	use PDOException;
	use stdClass;

	class Db {

		/**
		 * The handle to the database connection
		 */
		public static $_conn = null;

		/**
		 * The value of the last error message
		 */
		public static $lastError = '';

		/**
		 * Opens a connection to the database
		 */
		public static function Connect($dsn, $user = '', $pass = '')
		{
			$retVal = false;

			if (!defined('DB_DISABLE')) {
				self::$_conn = new PDO($dsn, $user, $pass, array( PDO::MYSQL_ATTR_FOUND_ROWS => true ));
				self::$_conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
				$retVal = true;
			}

			return $retVal;
		}

		/**
		 * Executes a query
		 */
		public static function Query($sql, $params = null)
		{
			$retVal = null;

			try {
				$comm = self::$_conn->prepare($sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
				$comm->execute($params);

				switch (strtolower(current(explode(' ', $sql)))) {
					case 'call':
					case 'select':
						$retVal = new stdClass();
						$retVal->count = $comm->rowCount();
						$retVal->comm = $comm;
						break;
					case 'insert':
						$retVal = new stdClass;
						$retVal->insertId = self::$_conn->lastInsertId();
						$retVal->count = $comm->rowCount();
						break;
					case 'update':
					case 'delete':
						$retVal = $comm->rowCount();
						break;
				}

				self::$lastError = self::$_conn->errorInfo();

			} catch (PDOException $e) {
				self::$lastError = $e;
				$retVal = false;
			}

			return $retVal;
		}

		/**
		 * Fetches the next row in a record set
		 */
		public static function Fetch($rs)
		{
			$retVal = null;

			if (is_object($rs) && null != $rs->comm) {
				$retVal = $rs->comm->fetchObject();
			}

			return $retVal;
		}

		/**
		 * Runs a SELECT in MySQL unbuffered mode and invokes $callback for each
		 * row so the full result is never buffered in PHP. Restores buffered
		 * mode when finished (including on error). Do not run other queries on
		 * this connection from inside $callback. SELECT only.
		 *
		 * @return bool true if the query ran, false on failure
		 */
		public static function StreamQuery($sql, $params, callable $callback)
		{
			if (strtolower(current(explode(' ', ltrim($sql)))) !== 'select') {
				self::$lastError = 'StreamQuery only supports SELECT statements';
				return false;
			}

			$comm = null;

			try {
				self::$_conn->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
				$comm = self::$_conn->prepare($sql);
				$comm->execute($params);
				self::$lastError = self::$_conn->errorInfo();

				while ($row = $comm->fetchObject()) {
					$callback($row);
				}

				return true;
			} catch (PDOException $e) {
				self::$lastError = $e;
				return false;
			} finally {
				if ($comm) {
					$comm->closeCursor();
				}
				self::$_conn->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
			}
		}

        /**
         * Do bulk insert/update using transaction for faster processing
         * @param $transactions
         */
		public static function BulkQuery($transactions)
        {
            self::$_conn->beginTransaction();
            try {
                foreach ($transactions as $transaction) {
                    $sql = $transaction[0];
                    $params = $transaction[1];

                    $comm = self::$_conn->prepare($sql);
                    $comm->execute($params);
                }
                self::$_conn->commit();
            } catch (\Exception $e) {
                self::$_conn->rollback();
            }
        }

	}
}
