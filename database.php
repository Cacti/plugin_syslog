<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2007-2011 The Cacti Group                                 |
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

/* syslog_db_connect_real - makes a connection to the database server
   @arg $host - the hostname of the database server, 'localhost' if the database server is running
      on this machine
   @arg $user - the username to connect to the database server as
   @arg $pass - the password to connect to the database server with
   @arg $db_name - the name of the database to connect to
   @arg $db_type - the type of database server to connect to, only 'mysql' is currently supported
   @arg $retries - the number a time the server should attempt to connect before failing
   @returns - (object) connection_id for success, (bool) '0' for error */
function syslog_db_connect_real($host, $user, $pass, $db_name, $db_type, $port = "3306", $retries = 20) {
	global $syslog_cnn;

	$i = 0;
	$syslog_cnn = NewADOConnection($db_type);

	$hostport = $host . ":" . $port;

	while ($i <= $retries) {
		if ($syslog_cnn->NConnect($hostport, $user, $pass, $db_name)) {
			return($syslog_cnn);
		}

		$i++;

		usleep(40000);
	}

	die("FATAL: Cannot connect to MySQL server on '$host'. Please make sure you have specified a valid MySQL database connection information for RTM\n");

	return(0);
}

/* syslog_db_close - closes the open connection
   @arg $syslog_cnn - the connection object to connect to
   @returns - the result of the close command */
function syslog_db_close($syslog_cnn) {
	return $syslog_cnn->Close();
}

/* syslog_db_execute - run an sql query and do not return any output
   @arg $syslog_cnn - the connection object to connect to
   @arg $sql - the sql query to execute
   @arg $log - whether to log error messages, defaults to true
   @returns - '1' for success, '0' for error */
function syslog_db_execute($sql, $log = TRUE) {
	global $syslog_cnn, $cnn_id;

	/* use cacti function if using Cacti db */
	if ($syslog_cnn == $cnn_id) {
		return db_execute($sql, $log);
	}

	$sql = str_replace("  ", " ", str_replace("\n", "", str_replace("\r", "", str_replace("\t", " ", $sql))));

	if (read_config_option("log_verbosity") == POLLER_VERBOSITY_DEBUG) {
		cacti_log("DEBUG: SQL Exec: \"" . $sql . "\"", FALSE);
	}

	$errors = 0;
	while (1) {
		$query = $syslog_cnn->Execute($sql);

		if (($query) || ($syslog_cnn->ErrorNo() == 1032)) {
			return(1);
		}else if (($log) || (read_config_option("log_verbosity") == POLLER_VERBOSITY_DEBUG)) {
			if ((substr_count($syslog_cnn->ErrorMsg(), "Deadlock")) || ($syslog_cnn->ErrorNo() == 1213) || ($syslog_cnn->ErrorNo() == 1205)) {
				$errors++;
				if ($errors > 30) {
					cacti_log("ERROR: Too many Lock/Deadlock errors occurred! SQL:'" . str_replace("\n", "", str_replace("\r", "", str_replace("\t", " ", $sql))) ."'", TRUE);
					return(0);
				}else{
					usleep(500000);
					continue;
				}
			}else{
				cacti_log("ERROR: A DB Exec Failed!, Error:'" . $syslog_cnn->ErrorNo() . "', SQL:\"" . str_replace("\n", "", str_replace("\r", "", str_replace("\t", " ", $sql))) . "'", FALSE);
				return(0);
			}
		}
	}
}

/* syslog_db_fetch_cell - run a 'select' sql query and return the first column of the
     first row found
   @arg $sql - the sql query to execute
   @arg $log - whether to log error messages, defaults to true
   @arg $col_name - use this column name instead of the first one
   @returns - (bool) the output of the sql query as a single variable */
function syslog_db_fetch_cell($sql, $col_name = '', $log = TRUE) {
	global $syslog_cnn, $cnn_id;

	/* use cacti function if using Cacti db */
	if ($syslog_cnn == $cnn_id) {
		return db_fetch_cell($sql, $col_name);
	}

	$sql = str_replace("  ", " ", str_replace("\n", "", str_replace("\r", "", str_replace("\t", " ", $sql))));

	if (read_config_option("log_verbosity") == POLLER_VERBOSITY_DEBUG) {
		cacti_log("DEBUG: SQL Cell: \"" . $sql . "\"", FALSE);
	}

	if ($col_name != '') {
		$syslog_cnn->SetFetchMode(ADODB_FETCH_ASSOC);
	}else{
		$syslog_cnn->SetFetchMode(ADODB_FETCH_NUM);
	}

	$query = $syslog_cnn->Execute($sql);

	if (($query) || ($syslog_cnn->ErrorNo() == 1032)) {
		if (!$query->EOF) {
			if ($col_name != '') {
				$column = $query->fields[$col_name];
			}else{
				$column = $query->fields[0];
			}

			$query->close();

			return($column);
		}
	}else if (($log) || (read_config_option("log_verbosity") == POLLER_VERBOSITY_DEBUG)) {
		cacti_log("ERROR: SQL Cell Failed!, Error:'" . $syslog_cnn->ErrorNo() . "', SQL:\"" . str_replace("\n", "", str_replace("\r", "", str_replace("\t", " ", $sql))) . "\"", FALSE);
	}
}

/* syslog_db_fetch_row - run a 'select' sql query and return the first row found
   @arg $sql - the sql query to execute
   @arg $log - whether to log error messages, defaults to true
   @returns - the first row of the result as a hash */
function syslog_db_fetch_row($sql, $log = TRUE) {
	global $syslog_cnn, $cnn_id;

	/* use cacti function if using Cacti db */
	if ($syslog_cnn == $cnn_id) {
		return db_fetch_row($sql, $log);
	}

	$sql = str_replace("  ", " ", str_replace("\n", "", str_replace("\r", "", str_replace("\t", " ", $sql))));

	if (($log) && (read_config_option("log_verbosity") == POLLER_VERBOSITY_DEBUG)) {
		cacti_log("DEBUG: SQL Row: \"" . $sql . "\"\n", FALSE);
	}

	$syslog_cnn->SetFetchMode(ADODB_FETCH_ASSOC);
	$query = $syslog_cnn->Execute($sql);

	if (($query) || ($syslog_cnn->ErrorNo() == 1032)) {
		if (!$query->EOF) {
			$fields = $query->fields;

			$query->close();

			return($fields);
		}
	}else if (($log) || (read_config_option("log_verbosity") == POLLER_VERBOSITY_DEBUG)) {
		cacti_log("ERROR: SQL Row Failed!, Error:'" . $syslog_cnn->ErrorNo() . "', SQL:\"" . str_replace("\n", "", str_replace("\r", "", str_replace("\t", " ", $sql))) . "\"", FALSE);
	}
}

/* syslog_db_fetch_assoc - run a 'select' sql query and return all rows found
   @arg $sql - the sql query to execute
   @arg $log - whether to log error messages, defaults to true
   @returns - the entire result set as a multi-dimensional hash */
function syslog_db_fetch_assoc($sql, $log = TRUE) {
	global $syslog_cnn, $cnn_id;

	/* use cacti function if using Cacti db */
	if ($syslog_cnn == $cnn_id) {
		return db_fetch_assoc($sql, $log);
	}

	$sql = str_replace("  ", " ", str_replace("\n", "", str_replace("\r", "", str_replace("\t", " ", $sql))));

	if (read_config_option("log_verbosity") == POLLER_VERBOSITY_DEBUG) {
		cacti_log("DEBUG: SQL Assoc: \"" . $sql . "\"", FALSE);
	}

	$data = array();
	$syslog_cnn->SetFetchMode(ADODB_FETCH_ASSOC);
	$query = $syslog_cnn->Execute($sql);

	if (($query) || ($syslog_cnn->ErrorNo() == 1032)) {
		while ((!$query->EOF) && ($query)) {
			$data{sizeof($data)} = $query->fields;
			$query->MoveNext();
		}

		$query->close();

		return($data);
	}else if (($log) || (read_config_option("log_verbosity") == POLLER_VERBOSITY_DEBUG)) {
		cacti_log("ERROR: SQL Assoc Failed!, Error:'" . $syslog_cnn->ErrorNo() . "', SQL:\"" . str_replace("\n", "", str_replace("\r", "", str_replace("\t", " ", $sql))) . "\"");
	}
}

/* syslog_db_fetch_insert_id - get the last insert_id or auto incriment
   @arg $syslog_cnn - the connection object to connect to
   @returns - the id of the last auto incriment row that was created */
function syslog_db_fetch_insert_id($syslog_cnn) {
	return $syslog_cnn->Insert_ID();
}

/* syslog_db_replace - replaces the data contained in a particular row
   @arg $table_name - the name of the table to make the replacement in
   @arg $array_items - an array containing each column -> value mapping in the row
   @arg $keyCols - the name of the column containing the primary key
   @arg $autoQuote - whether to use intelligent quoting or not
   @returns - the auto incriment id column (if applicable) */
function syslog_db_replace($table_name, $array_items, $keyCols) {
	global $syslog_cnn, $cnn_id;

	/* use cacti function if using Cacti db */
	if ($syslog_cnn == $cnn_id) {
		return db_replace($table_name, $array_items, $keyCols);
	}

	$syslog_cnn->Replace($table_name, $array_items, $keyCols);

	return $syslog_cnn->Insert_ID();
}

/* syslog_sql_save - saves data to an sql table
   @arg $array_items - an array containing each column -> value mapping in the row
   @arg $table_name - the name of the table to make the replacement in
   @arg $key_cols - the primary key(s)
   @returns - the auto incriment id column (if applicable) */
function syslog_sql_save($array_items, $table_name, $key_cols = "id", $autoinc = true) {
	global $syslog_cnn, $cnn_id;

	/* use cacti function if using Cacti db */
	if ($syslog_cnn == $cnn_id) {
		return sql_save($array_items, $table_name, $key_cols, $autoinc);
	}

	while (list ($key, $value) = each ($array_items)) {
		$array_items[$key] = "\"" . sql_sanitize($value) . "\"";
	}

	$replace_result = $syslog_cnn->Replace($table_name, $array_items, $key_cols, FALSE, $autoinc);

	if ($replace_result == 0) {
		return 0;
	}

	/* get the last AUTO_ID and return it */
	if (($syslog_cnn->Insert_ID() == "0") || ($replace_result == 1)) {
		if (!is_array($key_cols)) {
			if (isset($array_items[$key_cols])) {
				return str_replace("\"", "", $array_items[$key_cols]);
			}
		}

		return 0;
	}else{
		return $syslog_cnn->Insert_ID();
	}
}

?>
