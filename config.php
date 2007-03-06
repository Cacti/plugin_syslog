<?php

$syslogdb_type     = 'mysql';
$syslogdb_default  = 'syslog';
$syslogdb_hostname = 'localhost';
$syslogdb_username = 'cactiuser';
$syslogdb_password = 'CactiMadeEZ';

//  Integrate with Graph View Timespan Selector. If false, keep seperate timespan settings
$syslog_config['graphtime'] = true;

//  Display timespan selector or not [ only used if $syslog_config['graphtime']=false ]
$syslog_config['timespan_sel'] = true;

//  Field Mappings, adjust to match the syslog table columns in use
$syslog_config['syslogTable']      = 'syslog';
$syslog_config['incomingTable']   = 'syslog_incoming';
$syslog_config['removeTable']     = 'syslog_remove';
$syslog_config['alertTable']      = 'syslog_alert';
$syslog_config['reportTable']     = 'syslog_reports';
$syslog_config['dateField']       = 'date';
$syslog_config['timeField']       = 'time';
$syslog_config['priorityField']   = 'priority';
$syslog_config['facilityField']   = 'facility';
$syslog_config['hostField']       = 'host';
$syslog_config['textField']       = 'message';
$syslog_config['id']              = 'seq';

//  Background colors, change/add/delete to suit
//  Not all these are necessary, they are according to the messages in your DB
$syslog_colors['Emergency']	= 'E6808C';
$syslog_colors['Critical']	= 'F08896';
$syslog_colors['Notice']		= '';
$syslog_colors['Info']		= '';
$syslog_colors['Debug']		= 'D0D0D0';

$syslog_colors['alert']		= 'F6909C';
$syslog_colors['err']		= 'E6808C';
$syslog_colors['crit']		= 'F08896';
$syslog_colors['warn']		= 'FCF0C0';
$syslog_colors['notice']		= '';
$syslog_colors['info']		= '';
$syslog_colors['debug']		= 'D0D0D0';


//  Font Text colors (defaults to 000000)
$syslog_text_colors['Emergency']	= '';
$syslog_text_colors['Critical']	= '';
$syslog_text_colors['Notice']	= '';
$syslog_text_colors['Info']		= '';
$syslog_text_colors['Debug']		= '';

$syslog_text_colors['alert']		= '';
$syslog_text_colors['err']		= '';
$syslog_text_colors['warn']		= '';
$syslog_text_colors['notice']	= '';
$syslog_text_colors['info']		= '';
$syslog_text_colors['debug']		= '';

?>