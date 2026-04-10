<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

/**
 * syslog_db_connect_real - makes a connection to the database server
 *
 * @param string $host        The hostname of the database server,
 *                            'localhost' if the database server is running
 *                            on this machine
 * @param string $user        The username to connect to the database server as
 * @param string $pass        The password to connect to the database server with
 * @param string $db_name     The name of the database to connect to
 * @param string $db_type     The type of database server to connect to, only 'mysql' is currently supported
 * @param int    $port        The database port.  Defaults to 3306
 * @param int    $retries     The number a time the server should attempt to connect before failing
 * @param bool   $db_ssl      true or false, is the database using ssl
 * @param string $db_ssl_key  The path to the ssl key file
 * @param string $db_ssl_cert The path to the ssl cert file
 * @param string $db_ssl_ca   The path to the ssl ca file
 *
 * @return object|bool connection_id for success, or bool false for error
 */
function syslog_db_connect_real($host, $user, $pass, $db_name, $db_type, $port = '3306', $retries = 20, $db_ssl = '',
	$db_ssl_key = '', $db_ssl_cert = '', $db_ssl_ca = '') {
	return db_connect_real($host, $user, $pass, $db_name, $db_type, $port, $retries, $db_ssl, $db_ssl_key, $db_ssl_cert, $db_ssl_ca);
}

/**
 * syslog_db_close - closes the open connection
 *
 * @param object $syslog_cnn The connection object to connect to
 *
 * @return bool the result of the close command
 */
function syslog_db_close($syslog_cnn) {
	return db_close($syslog_cnn);
}

/**
 * syslog_db_execute - run an sql query and do not return any output
 *
 * @param string $sql The sql query to execute
 * @param bool $log   Whether to log error messages, defaults to true
 *
 * @return int 1 for success, 0 for error
 */
function syslog_db_execute($sql, $log = true) {
	global $syslog_cnn;

	return db_execute($sql, $log, $syslog_cnn);
}

/**
 * syslog_db_execute_prepared - run an sql query and do not return any output
 *
 * @param string $sql   The sql query to execute
 * @param array  $parms The sql params for the prepare
 * @param bool   $log   Whether to log error messages, defaults to true
 *
 * @return '1' for success, '0' for error
 */
function syslog_db_execute_prepared($sql, $parms = [], $log = true) {
	global $syslog_cnn;

	return db_execute_prepared($sql, $parms, $log, $syslog_cnn);
}

/**
 * syslog_db_fetch_cell - run a 'select' sql query and return the first column of the
 * first row found
 *
 * @param string $sql      The sql query to execute
 * @param string $col_name Use this column name instead of the first one
 * @param bool   $log      Whether to log error messages, defaults to true
 *
 * @return mixed The output of the sql query as a single variable
 */
function syslog_db_fetch_cell($sql, $col_name = '', $log = true) {
	global $syslog_cnn;

	return db_fetch_cell($sql, $col_name, $log, $syslog_cnn);
}

/**
 * syslog_db_fetch_cell_prepared - run a 'select' sql query and return the first column of the
 * first row found
 *
 * @param string $sql      The sql query to execute
 * @param array  $params   An array of parameters
 * @param string $col_name Use this column name instead of the first one
 * @param bool   $log      Whether to log error messages, defaults to true
 *
 * @return mixed The output of the sql query as a single variable
 */
function syslog_db_fetch_cell_prepared($sql, $params = [], $col_name = '', $log = true) {
	global $syslog_cnn;

	return db_fetch_cell_prepared($sql, $params, $col_name, $log, $syslog_cnn);
}

/**
 * syslog_db_fetch_row - run a 'select' sql query and return the first row found
 *
 * @param string $sql The sql query to execute
 * @param bool   $log Whether to log error messages, defaults to true
 *
 * @return array|bool The first row of the result as a hash
 */
function syslog_db_fetch_row($sql, $log = true) {
	global $syslog_cnn;

	return db_fetch_row($sql, $log, $syslog_cnn);
}

/**
 * syslog_db_fetch_row_prepared - run a 'select' sql query and return the first row found
 *
 * @param string $sql    The sql query to execute
 * @param array  $params An array of parameters
 * @param bool   $log    Whether to log error messages, defaults to true
 *
 * @return array|bool The first row of the result as a hash
 */
function syslog_db_fetch_row_prepared($sql, $params = [], $log = true) {
	global $syslog_cnn;

	return db_fetch_row_prepared($sql, $params, $log, $syslog_cnn);
}

/**
 * syslog_db_fetch_assoc - run a 'select' sql query and return all rows found
 *
 * @param string $sql The sql query to execute
 * @param bool   $log Whether to log error messages, defaults to true
 *
 * @return array|bool The entire result set as a multi-dimensional hash
 */
function syslog_db_fetch_assoc($sql, $log = true) {
	global $syslog_cnn;

	return db_fetch_assoc($sql, $log, $syslog_cnn);
}

/**
 * syslog_db_fetch_assoc_prepared - run a 'select' sql query and return all rows found
 *
 * @param string $sql    The sql query to execute
 * @param array  $params An array of parameters
 * @param bool   $log    Whether to log error messages, defaults to true
 *
 * @return array|bool The entire result set as a multi-dimensional hash
 */
function syslog_db_fetch_assoc_prepared($sql, $params = [], $log = true) {
	global $syslog_cnn;

	return db_fetch_assoc_prepared($sql, $params, $log, $syslog_cnn);
}

/**
 * syslog_db_fetch_insert_id - get the last insert_id or auto incriment
 *
 * @param object $syslog_cnn The connection object to connect to
 *
 * @return int The id of the last auto incriment row that was created
 */
function syslog_db_fetch_insert_id() {
	global $syslog_cnn;

	return db_fetch_insert_id($syslog_cnn);
}

/**
 * syslog_db_replace - replaces the data contained in a particular row
 *
 * @param string $table_name  The name of the table to make the replacement in
 * @param array  $array_items An array containing each column -> value mapping in the row
 * @param mixed  $keyCols     The name of the column containing the primary key
 * @param bool   $autoQuote   Whether to use intelligent quoting or not
 *
 * @return bool The auto incriment id column (if applicable)
 */
function syslog_db_replace($table_name, $array_items, $keyCols) {
	global $syslog_cnn;

	return db_replace($table_name, $array_items, $keyCols, $syslog_cnn);
}

/**
 * syslog_sql_save - saves data to an sql table
 *
 * @param array  $array_items An array containing each column -> value mapping in the row
 * @param string $table_name  The name of the table to make the replacement in
 * @param mixed  $key_cols    The primary key(s)
 * @param bool   $autoinc     Is the primary key autoinc
 *
 * @return int The auto incriment id column (if applicable)
 */
function syslog_sql_save($array_items, $table_name, $key_cols = 'id', $autoinc = true) {
	global $syslog_cnn;

	return sql_save($array_items, $table_name, $key_cols, $autoinc, $syslog_cnn);
}

/**
 * syslog_db_table_exists - checks whether a table exists
 *
 * @param string $table The name of the table
 * @param bool   $log   Whether to log error messages, defaults to true
 *
 * @return bool The output of the sql query as a single variable
 */
function syslog_db_table_exists($table, $log = true) {
	global $syslog_cnn;

	preg_match("/([`]{0,1}(?<database>[\w_]+)[`]{0,1}\.){0,1}[`]{0,1}(?<table>[\w_]+)[`]{0,1}/", $table, $matches);

	if ($matches !== false && array_key_exists('table', $matches)) {
		$sql = 'SHOW TABLES LIKE \'' . $matches['table'] . '\'';

		return (db_fetch_cell($sql, '', $log, $syslog_cnn) ? true : false);
	}

	return false;
}

function syslog_db_column_exists($table, $column, $log = true) {
	global $syslog_cnn;

	return db_column_exists($table, $column, $log, $syslog_cnn);
}

function syslog_db_add_column($table, $column, $log = true) {
	global $syslog_cnn;

	return db_add_column($table, $column, $log, $syslog_cnn);
}

/**
 * syslog_db_affected_rows - return the number of rows affected by the last transaction
 *
 * @return bool|int The number of rows affected by the last transaction,
 *                  or false on error
 */
function syslog_db_affected_rows() {
	global $syslog_cnn;

	return db_affected_rows($syslog_cnn);
}
