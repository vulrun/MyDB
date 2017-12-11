<?php

$mydb = new MyDB($db_user, $db_pass, $db_name, $db_host);

///----------------------------------------------------------------------------------------------------------------------------///
///############################################################# MyDB #########################################################///
///----------------------------------------------------------------------------------------------------------------------------///

class MyDB {
	/**
	 * Amount of queries made
	 *
	 * @since 1.2.0
	 * @var int
	 */
	public $num_queries = 0;

	/**
	 * Count of rows returned by previous query
	 *
	 * @since 0.71
	 * @var int
	 */
	public $num_rows = 0;

	/**
	 * Count of affected rows by previous query
	 *
	 * @since 0.71
	 * @var int
	 */
	var $rows_affected = 0;

	/**
	 * The ID generated for an AUTO_INCREMENT column by the previous query (usually INSERT).
	 *
	 * @since 0.71
	 * @var int
	 */
	public $insert_id = 0;

	/**
	 * Last query made
	 *
	 * @since 0.71
	 * @var array
	 */
	var $last_query;

	/**
	 * Results of the last query made
	 *
	 * @since 0.71
	 * @var array|null
	 */
	var $last_result;

	/**
	 * MySQL result, which is either a resource or boolean.
	 *
	 * @since 0.71
	 * @var mixed
	 */
	protected $result;

	/**
	 * Saved queries that were executed
	 *
	 * @since 1.5.0
	 * @var array
	 */
	var $queries;

	/**
	 * Database table columns charset
	 *
	 * @since 2.2.0
	 * @var string
	 */
	public $charset;

	/**
	 * Database table columns collate
	 *
	 * @since 2.2.0
	 * @var string
	 */
	public $collate;

	/**
	 * Database Username
	 *
	 * @since 2.9.0
	 * @var string
	 */
	protected $dbuser;

	/**
	 * Database Password
	 *
	 * @since 3.1.0
	 * @var string
	 */
	protected $dbpass;

	/**
	 * Database Name
	 *
	 * @since 3.1.0
	 * @var string
	 */
	protected $dbname;

	/**
	 * Database Host
	 *
	 * @since 3.1.0
	 * @var string
	 */
	protected $dbhost;

	/**
	 * Database Handle
	 *
	 * @since 0.71
	 * @var string
	 */
	protected $dbh;

	/**
	 * Whether to use mysqli over mysql.
	 *
	 * @since 3.9.0
	 * @var bool
	 */
	private $use_mysqli = false;

	/**
	 * Whether we've managed to successfully connect at some point
	 *
	 * @since 3.9.0
	 * @var bool
	 */
	private $has_connected = false;

	/**
	 * Whether the database queries are ready to start executing.
	 *
	 * @since 2.3.2
	 * @var bool
	 */
	var $ready = false;

	/**
	 * Debug Mode
	 *
	 * @since 3.9.0
	 * @var bool
	 */
	public $debug = false;




	/**
	 * Connects to the database server and selects a database
	 *
	 * PHP5 style constructor for compatibility with PHP5. Does
	 * the actual setting up of the class properties and connection
	 * to the database.
	 *
	 * @since 2.0.8
	 *
	 * @global string $mydb
	 *
	 * @param string $dbuser     MySQL database user
	 * @param string $dbpass     MySQL database pass
	 * @param string $dbname     MySQL database name
	 * @param string $dbhost     MySQL database host
	 */
	public function __construct( $dbuser, $dbpass, $dbname, $dbhost ) {
		register_shutdown_function( array( $this, '__destruct' ) );

		/* Use ext/mysqli if it exists and:
		 *  - We are running PHP 5.5 or greater, or
		 *  - ext/mysql is not loaded.
		 */
		if ( function_exists( 'mysqli_connect' ) ) {
			if ( version_compare( phpversion(), '5.5', '>=' ) || ! function_exists( 'mysql_connect' ) ) {
				$this->use_mysqli = true;
			}
		}

		$this->dbuser = $dbuser;
		$this->dbpass = $dbpass;
		$this->dbname = $dbname;
		$this->dbhost = $dbhost;

		$this->db_connect();
	}

	/**
	 * PHP5 style destructor and will run when database object is destroyed.
	 *
	 * @see mydb::__construct()
	 * @since 2.0.8
	 * @return true
	 */
	public function __destruct() {
		return true;
	}

	/**
	 * Set $this->charset and $this->collate
	 *
	 * @since 3.1.0
	 */
	public function init_charset() {
		$charset = 'utf8';
		$collate = 'utf8_general_ci';

		$charset_collate = $this->determine_charset( $charset, $collate );

		$this->charset = $charset_collate['charset'];
		$this->collate = $charset_collate['collate'];
	}

	/**
	 * Determines the best charset and collation to use given a charset and collation.
	 *
	 * For example, when able, utf8mb4 should be used instead of utf8.
	 *
	 * @since 4.6.0
	 *
	 * @param string $charset The character set to check.
	 * @param string $collate The collation to check.
	 * @return array The most appropriate character set and collation to use.
	 */
	public function determine_charset( $charset, $collate ) {
		if ( ( $this->use_mysqli && ! ( $this->dbh instanceof mysqli ) ) || empty( $this->dbh ) ) {
			return compact( 'charset', 'collate' );
		}

		if ( 'utf8' === $charset && $this->has_cap( 'utf8mb4' ) ) {
			$charset = 'utf8mb4';
		}

		if ( 'utf8mb4' === $charset && ! $this->has_cap( 'utf8mb4' ) ) {
			$charset = 'utf8';
			$collate = str_replace( 'utf8mb4_', 'utf8_', $collate );
		}

		if ( 'utf8mb4' === $charset ) {
			// _general_ is outdated, so we can upgrade it to _unicode_, instead.
			if ( ! $collate || 'utf8_general_ci' === $collate ) {
				$collate = 'utf8mb4_unicode_ci';
			}
			else {
				$collate = str_replace( 'utf8_', 'utf8mb4_', $collate );
			}
		}

		// _unicode_520_ is a better collation, we should use that when it's available.
		if ( $this->has_cap( 'utf8mb4_520' ) && 'utf8mb4_unicode_ci' === $collate ) {
			$collate = 'utf8mb4_unicode_520_ci';
		}

		return compact( 'charset', 'collate' );
	}

	/**
	 * Sets the connection's character set.
	 *
	 * @since 3.1.0
	 *
	 * @param resource $dbh     The resource given by mysql_connect
	 * @param string   $charset Optional. The character set. Default null.
	 * @param string   $collate Optional. The collation. Default null.
	 */
	public function set_charset( $dbh, $charset = null, $collate = null ) {
		if ( ! isset( $charset ) )
			$charset = $this->charset;
		if ( ! isset( $collate ) )
			$collate = $this->collate;
		if ( $this->has_cap( 'collation' ) && ! empty( $charset ) ) {
			$set_charset_succeeded = true;

			if ( $this->use_mysqli ) {
				if ( function_exists( 'mysqli_set_charset' ) && $this->has_cap( 'set_charset' ) ) {
					$set_charset_succeeded = mysqli_set_charset( $dbh, $charset );
				}

				if ( $set_charset_succeeded ) {
					$query = "SET NAMES $charset";
					if ( ! empty( $collate ) )
						$query .= " COLLATE $collate";
					mysqli_query( $dbh, $query );
				}
			}
			else {
				if ( function_exists( 'mysql_set_charset' ) && $this->has_cap( 'set_charset' ) ) {
					$set_charset_succeeded = mysql_set_charset( $charset, $dbh );
				}
				if ( $set_charset_succeeded ) {
					$query = "SET NAMES $charset";
					if ( ! empty( $collate ) )
						$query .= " COLLATE $collate";
					mysql_query( $query, $dbh );
				}
			}
		}
	}	

	/**
	 * Selects a database using the current database connection.
	 *
	 * The database name will be changed based on the current database
	 * connection. On failure, the execution will display an DB error.
	 *
	 * @since 0.71
	 *
	 * @param string        $db  MySQL database name
	 * @param resource|null $dbh Optional link identifier.
	 */
	public function select( $db, $dbh = null ) {
		if ( is_null($dbh) )
			$dbh = $this->dbh;

		if ( $this->use_mysqli ) {
			$success = mysqli_select_db( $dbh, $db );
		}
		else {
			$success = mysql_select_db( $db, $dbh );
		}
		if ( ! $success ) {
			$this->ready = false;
		}
		return $success;
	}

	/**
	 * Connect to and select database.
	 *
	 * If $allow_bail is false, the lack of database connection will need
	 * to be handled manually.
	 *
	 * @since 3.0.0
	 *
	 * @param bool $allow_bail Optional. Allows the function to bail. Default true.
	 * @return bool True with a successful connection, false on failure.
	 */
	public function db_connect() {
		// MYSQLi
		if ( $this->use_mysqli ) {
			$this->dbh = mysqli_init();

			$dbname = null;
			$port    = null;
			$socket  = null;
			$new_link = true;
			$client_flags = 0;

			if ( $this->debug ) {
				mysqli_real_connect( $this->dbh, $this->dbhost, $this->dbuser, $this->dbpass, $dbname, $port, $socket, $client_flags );
			}
			else {
				@mysqli_real_connect( $this->dbh, $this->dbhost, $this->dbuser, $this->dbpass, $dbname, $port, $socket, $client_flags );
			}

			if ( $this->dbh->connect_errno ) {
				$this->dbh = null;

				/*
				 * It's possible ext/mysqli is misconfigured. Fall back to ext/mysql if:
		 		 *  - We haven't previously connected, and
		 		 *  - ext/mysql is loaded.
		 		 */
				$attempt_fallback = true;

				if ( $this->has_connected ) {
					$attempt_fallback = false;
				}
				elseif ( ! function_exists( 'mysql_connect' ) ) {
					$attempt_fallback = false;
				}

				if ( $attempt_fallback ) {
					$this->use_mysqli = false;
					return $this->db_connect();
				}
			}
		}

		// MYSQL
		else {
			if ( $this->debug ) {
				$this->dbh = mysql_connect( $this->dbhost, $this->dbuser, $this->dbpass, $new_link, $client_flags );
			}
			else {
				$this->dbh = @mysql_connect( $this->dbhost, $this->dbuser, $this->dbpass, $new_link, $client_flags );
			}
		}



		// Checking Database Handler
		if ( ! $this->dbh ) {

			$this->message('Error establishing a database connection', "This either means that the username and password information is incorrect or we can't contact the database server at $this->dbhost. This could mean your host's database server is down.", 'db_connect_fail');

			return false;
		}
		elseif ( $this->dbh ) {
			if ( ! $this->has_connected ) {
				$this->init_charset();
			}

			$this->has_connected = true;

			$this->set_charset( $this->dbh );

			$this->ready = true;
			$this->select( $this->dbname, $this->dbh );

			return true;
		}

		return false;
	}

	/**
	 * Checks that the connection to the database is still up. If not, try to reconnect.
	 *
	 * If this function is unable to reconnect, it will forcibly die, or if after the
	 * the {@see 'template_redirect'} hook has been fired, return false instead.
	 *
	 *
	 * @since 3.9.0
	 *
	 * @param bool $allow_bail Optional. Allows the function to bail. Default true.
	 * @return bool|void True if the connection is up.
	 */
	public function check_connection() {
		if ( $this->use_mysqli ) {
			if ( ! empty( $this->dbh ) && mysqli_ping( $this->dbh ) ) {
				return true;
			}
		}
		else {
			if ( ! empty( $this->dbh ) && mysql_ping( $this->dbh ) ) {
				return true;
			}
		}

		$error_reporting = false;
		$reconnect_retries = 5;

		// Disable warnings, as we don't want to see a multitude of "unable to connect" messages
		if ( $this->debug ) {
			$error_reporting = error_reporting();
			error_reporting( $error_reporting & ~E_WARNING );
		}

		for ( $tries = 1; $tries <= $reconnect_retries; $tries++ ) {
			// On the last try, re-enable warnings. We want to see a single instance of the
			// "unable to connect" message on the bail() screen, if it appears.
			if ( $reconnect_retries === $tries && $this->debug ) {
				error_reporting( $error_reporting );
			}

			if ( $this->db_connect() ) {
				if ( $error_reporting ) {
					error_reporting( $error_reporting );
				}

				return true;
			}

			sleep( 1 );
		}

		// If redirect has no happened, it's too late for die()/kill.
		// Let's just message and hope for the best.
		$this->message('Error reconnecting to the database', "This means that we can't contact the database server at $this->dbhost. This could mean your host's database server is down.", 'db_connect_fail');

		// Trying to Flush and close database, because this database is no more. It has ceased to be (at least temporarily).
		$this->flush();
		$this->close();
	}

	/**
	 * Insert a row into a table.
	 *
	 *     mydb::insert( 'table', array( 'column' => 'foo', 'field' => 1337 ) )
	 *
	 * @since 2.5.0
	 * @see wpdb::prepare()
	 * @see wpdb::$field_types
	 * @see wp_set_wpdb_vars()
	 *
	 * @param string       $table  Table name
	 * @param array        $data   Data to insert (in column => value pairs).
	 *                             Both $data columns and $data values should be "raw" (neither should be SQL escaped).
	 *                             Sending a null value will cause the column to be set to NULL - corresponding format is ignored.
	 * @return int|false The number of rows inserted, or false on error.
	 */
	public function insert( $table, $data ) {
		return $this->_insert_replace_helper( $table, $data, 'INSERT' );
	}

	/**
	 * Replace a row into a table.
	 *
	 *     mydb::replace( 'table', array( 'column' => 'foo', 'field' => 1337 ) )
	 *
	 * @since 3.0.0
	 * @see wpdb::prepare()
	 * @see wpdb::$field_types
	 * @see wp_set_wpdb_vars()
	 *
	 * @param string       $table  Table name
	 * @param array        $data   Data to insert (in column => value pairs).
	 *                             Both $data columns and $data values should be "raw" (neither should be SQL escaped).
	 *                             Sending a null value will cause the column to be set to NULL - corresponding format is ignored.
	 * @return int|false The number of rows affected, or false on error.
	 */
	public function replace( $table, $data ) {
		return $this->_insert_replace_helper( $table, $data, 'REPLACE' );
	}

	/**
	 * Helper function for insert and replace.
	 *
	 * Runs an insert or replace query based on $type argument.
	 *
	 * @since 3.0.0
	 * @see wpdb::prepare()
	 * @see wpdb::$field_types
	 * @see wp_set_wpdb_vars()
	 *
	 * @param string       $table  Table name
	 * @param array        $data   Data to insert (in column => value pairs).
	 *                             Both $data columns and $data values should be "raw" (neither should be SQL escaped).
	 *                             Sending a null value will cause the column to be set to NULL - the corresponding format is ignored in this case.
	 * @param array|string $format Optional. An array of formats to be mapped to each of the value in $data.
	 *                             If string, that format will be used for all of the values in $data.
	 *                             A format is one of '%d', '%f', '%s' (integer, float, string).
	 *                             If omitted, all values in $data will be treated as strings unless otherwise specified in wpdb::$field_types.
	 * @param string $type         Optional. What type of operation is this? INSERT or REPLACE. Defaults to INSERT.
	 * @return int|false The number of rows affected, or false on error.
	 */
	private function _insert_replace_helper( $table, $data, $type = 'INSERT' ) {

		if ( ! in_array( strtoupper( $type ), array( 'REPLACE', 'INSERT' ) ) ) {
			return false;
		}

		if ( false === $data ) {
			return false;
		}

		$values = array();
		foreach ( $data as $field => $value ) {
			if ( is_null( $value ) ) {
				$values[] = 'NULL';
				continue;
			}

			$values[]  = "'$value'";
		}

		$fields = '`' . implode( '`, `', array_keys( $data ) ) . '`';
		$values = implode( ', ', $values );

		$sql = "$type INTO `$table` ($fields) VALUES ($values)";

		return $this->query( $sql );
	}

	/**
	 * Update a row in the table
	 *
	 *     mydb::update( 'table', array( 'column' => 'foo', 'field' => 1337 ), array( 'ID' => 1 ) )
	 *
	 * @since 2.5.0
	 * @see wpdb::prepare()
	 * @see wpdb::$field_types
	 * @see wp_set_wpdb_vars()
	 *
	 * @param string       $table        Table name
	 * @param array        $data         Data to update (in column => value pairs).
	 *                                   Both $data columns and $data values should be "raw" (neither should be SQL escaped).
	 *                                   Sending a null value will cause the column to be set to NULL - the corresponding
	 *                                   format is ignored in this case.
	 * @param array        $where        A named array of WHERE clauses (in column => value pairs).
	 *                                   Multiple clauses will be joined with ANDs.
	 *                                   Both $where columns and $where values should be "raw".
	 *                                   Sending a null value will create an IS NULL comparison.
	 * @return int|false The number of rows updated, or false on error.
	 */
	public function update( $table, $data, $where ) {
		if ( ! is_array( $data ) || ! is_array( $where ) ) {
			return false;
		}

		if ( false === $data ) {
			return false;
		}

		if ( false === $where ) {
			return false;
		}

		$fields = $conditions = array();
		foreach ( $data as $field => $value ) {
			if ( is_null( $value ) ) {
				$fields[] = "`$field` = NULL";
				continue;
			}

			$fields[] = "`$field` = '$value'";
		}
		foreach ( $where as $field => $value ) {
			if ( is_null( $value ) ) {
				$conditions[] = "`$field` IS NULL";
				continue;
			}

			$conditions[] = "`$field` = '$value'";
		}

		$fields = implode( ', ', $fields );
		$conditions = implode( ' AND ', $conditions );

		$sql = "UPDATE `$table` SET $fields WHERE $conditions";

		return $this->query( $sql );
	}

	/**
	 * Delete a row in the table
	 *
	 *     mydb::delete( 'table', array( 'ID' => 1 ) )
	 *
	 * @since 3.4.0
	 * @see wpdb::prepare()
	 * @see wpdb::$field_types
	 * @see wp_set_wpdb_vars()
	 *
	 * @param string       $table        Table name
	 * @param array        $where        A named array of WHERE clauses (in column => value pairs).
	 *                                   Multiple clauses will be joined with ANDs.
	 *                                   Both $where columns and $where values should be "raw".
	 *                                   Sending a null value will create an IS NULL comparison.
	 * @return int|false The number of rows updated, or false on error.
	 */
	public function delete( $table, $where ) {
		if ( ! is_array( $where ) ) {
			return false;
		}

		if ( false === $where ) {
			return false;
		}

		$conditions = $values = array();
		foreach ( $where as $field => $value ) {
			if ( is_null( $value ) ) {
				$conditions[] = "`$field` IS NULL";
				continue;
			}

			$conditions[] = "`$field` = '$value'";
		}

		$conditions = implode( ' AND ', $conditions );

		$sql = "DELETE FROM `$table` WHERE $conditions";

		return $this->query( $sql );
	}








	/**
	 * Check if a string is ASCII.
	 *
	 * The negative regex is faster for non-ASCII strings, as it allows
	 * the search to finish as soon as it encounters a non-ASCII character.
	 *
	 * @since 4.2.0
	 *
	 * @param string $string String to check.
	 * @return bool True if ASCII, false if not.
	 */
	protected function check_ascii( $string ) {
		if ( function_exists( 'mb_check_encoding' ) ) {
			if ( mb_check_encoding( $string, 'ASCII' ) ) {
				return true;
			}
		}
		elseif ( ! preg_match( '/[^\x00-\x7F]/', $string ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Perform a MySQL database query, using current database connection.
	 *
	 * More information can be found on the codex page.
	 *
	 * @since 0.71
	 *
	 * @param string $query Database query
	 * @return int|false Number of rows affected/selected or false on error
	 */
	public function query( $query ) {

		if ( ! $this->ready ) {
			return false;
		}

		// If we're writing to the database, make sure the query will write safely.
		if ( ! $this->check_ascii( $query ) ) {

			// We don't need to check the collation for queries that don't read data.
			$trimmed_query = ltrim( $query, "\r\n\t (" );
			if ( ! preg_match( '/^(?:SHOW|DESCRIBE|DESC|EXPLAIN|CREATE)\s/i', $trimmed_query ) ) {
				$this->insert_id = 0;
				return false;
			}

		}

		$this->flush();
		$this->_do_query( $query );

		// MySQL server has gone away, try to reconnect.
		$mysql_errno = 0;
		if ( ! empty( $this->dbh ) ) {
			if ( $this->use_mysqli ) {
				if ( $this->dbh instanceof mysqli ) {
					$mysql_errno = mysqli_errno( $this->dbh );
				}
				else {
					// $dbh is defined, but isn't a real connection.
					// Something has gone horribly wrong, let's try a reconnect.
					$mysql_errno = 2006;
				}
			}
			else {
				if ( is_resource( $this->dbh ) ) {
					$mysql_errno = mysql_errno( $this->dbh );
				}
				else {
					$mysql_errno = 2006;
				}
			}

		}

		if ( empty( $this->dbh ) || 2006 == $mysql_errno ) {
			if ( $this->check_connection() ) {
				$this->_do_query( $query );
			}
			else {
				$this->message('Error while parsing the query', "This means that we can't contact the database server at $this->dbhost. This could mean your host's database server has gone away. We are trying to reconnect.", $mysql_errno);
				$this->insert_id = 0;
				return false;
			}
		}


		// Check for Errors
		if ( $this->use_mysqli ) {
			if ( $this->dbh instanceof mysqli ) {
				$error = mysqli_error( $this->dbh );
			}
			else {
				$error = 'Unable to retrieve the error message from MySQL';
			}
		}
		else {
			if ( is_resource( $this->dbh ) ) {
				$error = mysql_error( $this->dbh );
			}
			else {
				$error = 'Unable to retrieve the error message from MySQL';
			}
		}

		// If there is an error then take note of it.
		if ( $error ) {
			// Clear insert_id on a subsequent failed insert.
			if ( $this->insert_id && preg_match( '/^\s*(insert|replace)\s/i', $query ) )
				$this->insert_id = 0;

			$this->message( $error );
			return false;
		}


		// Return VALUE
		if ( preg_match( '/^\s*(create|alter|truncate|drop)\s/i', $query ) ) {
			$return_val = $this->result;
		}
		elseif ( preg_match( '/^\s*(insert|delete|update|replace)\s/i', $query ) ) {
			if ( $this->use_mysqli ) {
				$this->rows_affected = mysqli_affected_rows( $this->dbh );
			}
			else {
				$this->rows_affected = mysql_affected_rows( $this->dbh );
			}
			// Take note of the insert_id
			if ( preg_match( '/^\s*(insert|replace)\s/i', $query ) ) {
				if ( $this->use_mysqli ) {
					$this->insert_id = mysqli_insert_id( $this->dbh );
				}
				else {
					$this->insert_id = mysql_insert_id( $this->dbh );
				}
			}
			// Return number of rows affected
			$return_val = $this->rows_affected;
		}
		else {
			$num_rows = 0;
			if ( $this->use_mysqli && $this->result instanceof mysqli_result ) {
				while ( $row = mysqli_fetch_object( $this->result ) ) {
					$this->last_result[$num_rows] = $row;
					$num_rows++;
				}
			}
			elseif ( is_resource( $this->result ) ) {
				while ( $row = mysql_fetch_object( $this->result ) ) {
					$this->last_result[$num_rows] = $row;
					$num_rows++;
				}
			}

			// Log number of rows the query returned
			// and return number of rows selected
			$this->num_rows = $num_rows;
			$return_val     = $num_rows;
		}

		return $return_val;
	}

	/**
	 * Internal function to perform the mysql_query() call.
	 *
	 * @since 3.9.0
	 *
	 * @see mydb::query()
	 *
	 * @param string $query The query to run.
	 */
	private function _do_query( $query ) {
		$this->last_query = $query;
		$this->timer_start();

		if ( ! empty( $this->dbh ) && $this->use_mysqli ) {
			$this->result = mysqli_query( $this->dbh, $query );
		}
		elseif ( ! empty( $this->dbh ) ) {
			$this->result = mysql_query( $query, $this->dbh );
		}

		$this->num_queries++;

		$this->queries[] = array( 'database_query' => $query, 'execution_time' => $this->timer_stop() );
	}

	/**
	 * Retrieve an entire SQL result set from the database (i.e., many rows)
	 *
	 * Executes a SQL query and returns the entire SQL result.
	 *
	 * @since 0.71
	 *
	 * @param string $query  SQL query.
	 * @param string $output Optional. Any of ARRAY_A | ARRAY_N | OBJECT | OBJECT_K constants.
	 *                       With one of the first three, return an array of rows indexed from 0 by SQL result row number.
	 *                       Each row is an associative array (column => value, ...), a numerically indexed array (0 => value, ...), or an object. ( ->column = value ), respectively.
	 *                       With OBJECT_K, return an associative array of row objects keyed by the value of each row's first column's value.
	 *                       Duplicate keys are discarded.
	 * @return array|object|null Database query results
	 */
	public function get_results( $query = null, $output = OBJECT ) {

		if ( $query ) {
			$this->query( $query );
		}
		else {
			return null;
		}

		$new_array = array();
		if ( $output == OBJECT ) {
			// Return an integer-keyed array of row objects
			return $this->last_result;
		}
		elseif ( $output == 'OBJECT_K' ) {
			// Return an array of row objects with keys from column 1
			// (Duplicates are discarded)
			foreach ( $this->last_result as $row ) {
				$var_by_ref = get_object_vars( $row );
				$key = array_shift( $var_by_ref );

				if ( ! isset( $new_array[ $key ] ) )
					$new_array[ $key ] = $row;
			}
			return $new_array;
		}
		elseif ( $output == 'ARRAY_A' || $output == 'ARRAY_N' ) {
			// Return an integer-keyed array of...
			if ( $this->last_result ) {
				foreach ( (array) $this->last_result as $row ) {
					if ( $output == 'ARRAY_N' ) {
						// ...integer-keyed row arrays
						$new_array[] = array_values( get_object_vars( $row ) );
					}
					else {
						// ...column name-keyed row arrays
						$new_array[] = get_object_vars( $row );
					}
				}
			}
			return $new_array;
		}
		elseif ( strtoupper( $output ) === OBJECT ) {
			// Back compat for OBJECT being previously case insensitive.
			return $this->last_result;
		}
		return null;
	}

	/**
	 * Kill cached query results.
	 *
	 * @since 0.71
	 */
	public function flush() {
		$this->last_result = array();
		$this->last_query  = null;
		$this->rows_affected = $this->num_rows = 0;

		if ( $this->result instanceof mysqli_result ) {
			mysqli_free_result( $this->result );
			$this->result = null;

			// Sanity check before using the handle
			if ( empty( $this->dbh ) || !( $this->dbh instanceof mysqli ) ) {
				return;
			}

			// Clear out any results from a multi-query
			while ( mysqli_more_results( $this->dbh ) ) {
				mysqli_next_result( $this->dbh );
			}
		}
		elseif ( is_resource( $this->result ) ) {
			mysql_free_result( $this->result );
		}
	}

	/**
	 * Closes the current database connection.
	 *
	 * @since 4.5.0
	 *
	 * @return bool True if the connection was successfully closed, false if it wasn't,
	 *              or the connection doesn't exist.
	 */
	public function close() {
		if ( ! $this->dbh ) {
			return false;
		}

		if ( $this->use_mysqli ) {
			$closed = mysqli_close( $this->dbh );
		}
		else {
			$closed = mysql_close( $this->dbh );
		}

		if ( $closed ) {
			$this->dbh = null;
			$this->ready = false;
			$this->has_connected = false;
		}

		return $closed;
	}






	/**
	 * Retrieves the MySQL server version.
	 *
	 * @since 2.7.0
	 *
	 * @return null|string Null on failure, version number on success.
	 */
	public function db_version() {
		if ( $this->use_mysqli ) {
			$server_info = mysqli_get_server_info( $this->dbh );
		}
		else {
			$server_info = mysql_get_server_info( $this->dbh );
		}
		return preg_replace( '/[^0-9.].*/', '', $server_info );
	}

	/**
	 * Determine if a database supports a particular feature.
	 *
	 * @since 4.6.0 Added support for the 'utf8mb4_520' feature.
	 *
	 * @see wpdb::db_version()
	 *
	 * @param string $db_cap         The feature to check for. Accepts 'collation', 'group_concat',
	 *                               'subqueries', 'set_charset', 'utf8mb4', or 'utf8mb4_520'.
	 * @return int|false             Whether the database feature is supported, false otherwise.
	 */
	public function has_cap( $db_cap ) {
		$version = $this->db_version();

		switch ( strtolower( $db_cap ) ) {
			case 'collation' :
			case 'group_concat' :
			case 'subqueries' :
			return version_compare( $version, '4.1', '>=' );
			case 'set_charset' :
			return version_compare( $version, '5.0.7', '>=' );
			case 'utf8mb4' :
			if ( version_compare( $version, '5.5.3', '<' ) ) {
				return false;
			}
			if ( $this->use_mysqli ) {
				$client_version = mysqli_get_client_info();
			}
			else {
				$client_version = mysql_get_client_info();
			}

				/*
				 * libmysql has supported utf8mb4 since 5.5.3, same as the MySQL server.
				 * mysqlnd has supported utf8mb4 since 5.0.9.
				 */
				if ( false !== strpos( $client_version, 'mysqlnd' ) ) {
					$client_version = preg_replace( '/^\D+([\d.]+).*/', '$1', $client_version );
					return version_compare( $client_version, '5.0.9', '>=' );
				}
				else {
					return version_compare( $client_version, '5.5.3', '>=' );
				}

			case 'utf8mb4_520' : // @since 4.6.0
			return version_compare( $version, '5.6', '>=' );
		}

		return false;
	}





	/**
	 * Starts the timer, for debugging purposes.
	 *
	 * @since 1.5.0
	 *
	 * @return true
	 */
	public function timer_start() {
		$this->time_start = microtime( true );
		return true;
	}

	/**
	 * Stops the debugging timer.
	 *
	 * @since 1.5.0
	 *
	 * @return float Total time spent on the query, in seconds
	 */
	public function timer_stop() {
		return ( microtime( true ) - $this->time_start );
	}

	/**
	 * Output Message
	 *
	 * @since 2.7.0
	 *
	 * @return array Output Message
	 */
	public function message($brief, $detailed = null, $code = null) {
		$msg = array('title' => $brief, 'message' => $detailed, 'type' => $code);

		if ( $this->debug ) {
			print_r( $msg );
		}
		else {
			return $msg;
		}
	}

}