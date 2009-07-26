<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2007 The Cacti Group                                      |
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

$syslogdb_type     = 'mysql';
$syslogdb_default  = 'syslog';
$syslogdb_hostname = 'localhost';
$syslogdb_username = 'cactiuser';
$syslogdb_password = 'cactiuser';

//  Field Mappings, adjust to match the syslog table columns in use
$syslog_config['syslogTable']        = 'syslog';
$syslog_config['syslogRemovedTable'] = 'syslog_removed';
$syslog_config['incomingTable']      = 'syslog_incoming';
$syslog_config['removeTable']        = 'syslog_remove';
$syslog_config['alertTable']         = 'syslog_alert';
$syslog_config['reportTable']        = 'syslog_reports';
$syslog_config['hostTable']          = 'syslog_hosts';
$syslog_config['facilityTable']      = 'syslog_facilities';

/* field in the incomming table */
$syslog_config['dateField']          = 'date';
$syslog_config['timeField']          = 'time';
$syslog_config['priorityField']      = 'priority';
$syslog_config['facilityField']      = 'facility';
$syslog_config['hostField']          = 'host';
$syslog_config['textField']          = 'message';
$syslog_config['id']                 = 'seq';

?>
