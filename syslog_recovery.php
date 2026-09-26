<?php
/* Plugin-owned bounded Syslog outbox recovery worker. */
include(__DIR__ . '/../../include/cli_check.php');
include_once(__DIR__ . '/setup.php');
include_once(__DIR__ . '/functions.php');
include_once(__DIR__ . '/database.php');

syslog_connect();
syslog_replication_recovery_run();
