--
-- Table structure for table `syslog`
--

DROP TABLE IF EXISTS syslog;
CREATE TABLE syslog (
  facility varchar(10) default NULL,
  priority varchar(10) default NULL,
  `date` date default NULL,
  `time` time default NULL,
  host varchar(128) default NULL,
  message text,
  seq bigint unsigned NOT NULL auto_increment,
  PRIMARY KEY  (seq),
  KEY `date` (`date`),
  KEY `time` (`time`),
  KEY host (host),
  KEY `priority` (`priority`),
  KEY `facility` (`facility`)
) ENGINE=MyISAM;


-- --------------------------------------------------------

--
-- Table structure for table `syslog_alert`
--
DROP TABLE IF EXISTS syslog_alert;
CREATE TABLE syslog_alert (
  id int(10) NOT NULL auto_increment,
  name varchar(255) NOT NULL default '',
  `type` varchar(16) NOT NULL default '',
  message text NOT NULL,
  `user` varchar(32) NOT NULL default '',
  `date` int(16) NOT NULL default '0',
  email text NOT NULL,
  notes text NOT NULL,
  PRIMARY KEY  (id)
) ENGINE=MyISAM;

-- --------------------------------------------------------

--
-- Table structure for table `syslog_incoming`
--

DROP TABLE IF EXISTS syslog_incoming;
CREATE TABLE syslog_incoming (
  facility varchar(10) default NULL,
  priority varchar(10) default NULL,
  `date` date default NULL,
  `time` time default NULL,
  host varchar(128) default NULL,
  message text,
  seq bigint unsigned NOT NULL auto_increment,
  `status` tinyint(4) NOT NULL default '0',
  PRIMARY KEY  (seq),
  KEY `status` (`status`)
) ENGINE=MyISAM;

-- --------------------------------------------------------

--
-- Table structure for table `syslog_remove`
--

DROP TABLE IF EXISTS syslog_remove;
CREATE TABLE syslog_remove (
  id int(10) NOT NULL auto_increment,
  name varchar(255) NOT NULL default '',
  `type` varchar(16) NOT NULL default '',
  message text NOT NULL,
  `user` varchar(32) NOT NULL default '',
  `date` int(16) NOT NULL default '0',
  notes text NOT NULL,
  PRIMARY KEY  (id)
) ENGINE=MyISAM;

--
-- Table structure for table `syslog_reports`
--

DROP TABLE IF EXISTS syslog_reports;
CREATE TABLE syslog_reports (
  id int(10) NOT NULL auto_increment,
  name varchar(255) NOT NULL default '',
  `type` varchar(16) NOT NULL default '',
  timespan int(16) NOT NULL default '0',
  lastsent int(16) NOT NULL default '0',
  hour int(6) NOT NULL default '0',
  min int(6) NOT NULL default '0',
  message text NOT NULL,
  `user` varchar(32) NOT NULL default '',
  `date` int(16) NOT NULL default '0',
  email text NOT NULL,
  notes text NOT NULL,
  PRIMARY KEY  (id)
) ENGINE=MyISAM;