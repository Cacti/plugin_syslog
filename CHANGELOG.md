## ChangeLog

--- develop ---

* issue#184: Unable to remove messages by expression from 'program' column

* issue#208: Uncaught ValueError: escapeshellarg()

* issue#211: Device Filter missing devices

--- 4.1 ---

* issue#185: Wrong column specification on cleanup query

* issue#186: Wrong connection in functions.php

* issue#189: Issues with undefined variable in traditional table maintenance

* issue#193: Fix command execution

* issue#196: Undefined variable $ignore

* issue#205: function syslog_strip_incoming_domains($uniqueID)

* feature: Provide better messaging if the Data Collector is in offline mode


--- 4.0 ---

* issue: Removal of partition issues incorrect message

* issue: Make the syslog processing routine more readable

* issue: Fix oversight in database connection handling around default values
  identified in PHP 8.1.2 testing

* issue#65: MySQL failures due to large amount of syslog data

* issue#108: Add Body Text to Alert email

* issue#135: Syslog plugin will break remote collectors if DB is not present

* issue#146: Support Email address distribution lists

* issue#151: Syslog 3.1 has a hardcoded path for sh which causes issues running
  other scripts

* issue#160: PHP 8 Support

* issue#166: Allow Syslog to pass hostname for the threshold type reporting

* feature#181: For Regex Message Processing Rules test the regex at save time
  and inform user if it's syntactically correct

* feature: Support a local Syslog config file when Syslog is designed to work
  independently from the main Cacti server.

* feature: Support the replication of the main Cacti Syslog rules to Remote Data
  Collectors

* feature: Support process interlocking using the Cacti process registration
  functions

* feature: Support both system and host level re-alert cycles and command
  execution

* feature: Support using the Thold notification lists if Thold is installed on
  the system

* feature: Support using Cacti Format CSS files to construct Alert and Report
  messages.


--- 3.2 ---

* issue#114: Message Column missing

* issue#154: When removing a rule, wrong database connection is used

* issue#155: Wrong database connection is used resulting in missing table errors

* issue#159: Sync 'syslog' schema cross Traditional/Partitioned mode to avoid
  audit issue

* issue#161: Message colum does not follow RFC 5424


--- 3.1 ---

* issue#140: The indicator is not removed upon completion when export syslog

* issue#141: Import syslog - Alert rule has error

* issue#142: Syslog save button can not work well


--- 3.0 ---

* issue#122: Apply Cacti#3191 for XSS exposure (CVE-2020-7106)

* issue#124: Feature request: Syslog Search for message NOT containing something

* issue#128：The syslog alert email is not sent if the Reporting Method is set
  to threshold.

* issue#132: Cacti log shows syslog error when setting the "Re-Alert Cycle" in
  Alert Rules settings

* issue#133: Saving Settings on the Syslog Tab are not retained in latest Cacti

* feature#134: Syslog Search to include Program column - Reports

* feature: Migrate all Syslog Images to Fontawesome Glyphs


--- 2.9 ---

* issue#120: SQL syntax error for syslog when click browser back button

* issue: Syslog stats not reporting properly

* issue: Internationalization issues on console


--- 2.8 ---

* issue#115: Some field where not corrected following the version change

* issue#116: Background process fail to operate syslog_coming table;
  syslog_process.php fail if current workdir is not CACTI_TOP

* issue#117: Export of rules does not work when using db other than Cacti


--- 2.7 ---

* issue#110: Syslog Alerts cause DB errors

* issue#111: Can not load host table when use different syslog server


--- 2.6 ---

* issue#104: When filtering, syslog incorrectly thinks the Cacti hosts table
  does not exist

* issue#107: Removal rule not using correct DB when using $use_cacti_db = false;

* issue#109: Should merge CVE-2020-7106 solution to syslog plugin

* issue: Massive performance improvement in statistics page rendering


--- 2.5 ---

* issue#103: Allow syslog to use rsyslog new tizezone sensitive timestamps
  instead of legacy date/time

* issue#102: Syslog statistics filter problem - select program

* issue#101: Alert rule SQL Expression not working as expected

* issue#100: Fix odd/even classes generation in report

* issue#99: Re-Alert Cycle (Alert Rules) is wrong in case of 1 minute poller
  interval

* issue#96: Syslog filtering does not work with some international characters

* issue#88: Provide text color to indicate device status in Cacti

* issue#87: Program data is not sync with syslog_incoming under PHP 7.2


--- 2.4 ---

* issue: Resolving issues with nav level cache being set incorrectly


--- 2.3 ---

* issue#90: Can not show correct info when choose device filter in Syslog -
  Alert Log page

* issue#91: Page become blank after collecting multiple host syslog info

* issue#94: Stored XSS in syslog_removal.php

* issue#95: Syslog Hosts and Syslog Programs table looses sync with data


--- 2.2 ---

* feature: Allow for reprocess message per rule

* issue#66: Filter for All Programs can not work well

* issue#67: SQL error after choose device

* issue#69: Cirtical and Alert filter can not work well

* issue#71: Export alert log has sql error

* issue#72: Graph Template not workable after import by cli/import_template.php

* issue#73: Gap to Cacti 1.x: Syslog missed to support database ssl

* issue#74: New Requirement: another new hook 'syslog_update_hostsalarm'

* issue#76: New Requirement: background install syslog plugin with pre-defined
  options

* issue#77: Fixed: PHP Notice undefined variable

* issue#78: Misc issue about syslog_alerts->log->host

* issue#79: PHP 7.2 supporting to remove deprecated each()

* issue#80: Syslog plugin auto disabled after import an alert rule

* issue#81: php error when enter a value in Program filter and click go

* issue#82: Syslog can not deal with with single quotation

* issue#83: Change device filter can not return correct value in syslog- alert
  rule page

* issue#84: All Progarms not show anything using Classic theme

* issue#86: No color for emergency item

* issue#89: plugins/syslog/syslog_reports.php:89: Undefined variable '$id'


--- 2.1 ---

* issue#18: Issues with syslog statistics display

* issue#17: Compatibility with remote database

* issue#19: Removal rules issues

* issue#20: Issues viewing removed records

* issue#23: Threshold rule alert format issues

* issue#30: Syslog page slows when too many programs are in the programs table

* issue#32: Export of Syslog records not functional

* issue#38: Enhance the documentation to discuss config.php.dist and doco site

* issue#40: Adds hostname column to emailed reports

* issue: SQL for matching Cacti host incorrect

* issue: Syslog Reports were not functional

* issue: Cleanup formating of Threshold messaging and viewing


--- 2.0 ---

* feature: Compatibility with Cacti 1.0


--- 1.30 ---

* feature: Allow Statistics to be disabled

* feature: Allow Processing of Removal Rules on Main Syslog Table

* feature: Cleanup UI irregularities

* feature: Allow purging of old host entries

* issue: Remove syslog 'message' from Log message to prvent deadlock on cacti
  log syslog processing


--- 1.22 ---

* issue: Upgrade script does not properly handle all conditions

* issue: Strip domain does not always work as expected

* issue: Resizing a page on IE6 caused a loop on the syslog page

* issue: Correct issue where 'warning' is used instead of 'warn' on log insert

* issue: Issue with Plugin Realm naming


--- 1.21 ---

* issue: Fix timespan selector

* issue: Reintroduce Filter time range view

* issue: Syslog Statistics Row Counter Invalid

* feature: Provide option to tag invalid hosts


--- 1.20 ---

* feature: Provide host based statistics tab

* feature: Support generic help desk integration.  Requires customer script

* feature: Support re-alert cycles for all alert type

* feature: Limit re-alert cycles to the max log retention

* feature: Make the default timespan 30 minutes for performance reasons

* issue: sort fields interfering with one another between syslog and alarm tabs

* issue: Message column was date column


--- 1.10 ---

* feature: Allow Syslog to Strip Domains Suffix's.

* feature: Make compatible with earlier versions of Cacti.

* feature: Allow Plugins to extend filtering

* issue: Minor issue with wrong db function being called.

* issue: Legend had Critical and Alert reversed.

* issue: Syslog filter can cause SQL errors

* issue: Wrong page redirect links.

* issue: Partitioning was writing always to the dMaxValue partition

* issue: Emergency Logs were not being highlighted correctly

* issue: Can not add disabled alarm/removal/report rule


--- 1.07 ---

* issue: Rearchitect to improve support mutliple databases

* issue: Don't process a report if it's not enabled.

* issue: Don't process an alert if it's not enabled.

* issue: Don't process a removal rule if it's not enabled.


--- 1.06 ---

* issue#0001854: Error found in Cacti Log

* issue#0001871: Priority dropdown labels in syslog.php for "All Priorities" set
  to incorrect priority id

* issue#0001872: Priorities drop drown to show specific value

* issue: Only show one facility in the dropdown

* issue: Hex Errors Upon Install


--- 1.05 ---

* issue: Remove poorly defined security settings

* issue: Don't show actions if you don't have permissions

* issue: Fix page refresh dropdown bug

* feature: Re-add refresh settings to syslog


--- 1.04 ---

* issue#0001824: Syslog icon is not shown in graph view

* issue: Link on Alarm Log does not properly redirect to 'current' tab

* issue: Unselecting all hosts results in SQL error

* issue: Exporting to CSV not working properly

* compat: Remove deprecated split() command


--- 1.03 ---

* feature: Add alarm host and counts to sms messages

* issue: Fix issue with individual syslog html messages

* issue: Fix creating alarms and removals from the syslog tab

* issue: Fix syslog removal UI with respect to rule type's


--- 1.02 ---

* feature: Add syslog database functions to mitigate issues with same system
  installs


--- 1.01 ---

* feature: Add alert commands by popular demand

* issue#0001788: missing closing quote in syslog_alerts.php

* issue#0001785: revision 1086 can not save reports when using seperate syslog
  mysql database


--- 1.0 ---

* feature: Support SMS e-mail messages

* feature: Support MySQL partitioning for MySQL 5.1 and above for performance
  reasons

* feature: Normalize the syslog table for performance reasons

* feature: Allow editing of Alerts, Removal Rules and Reports

* feature: Priorities are now >= behavior from syslog interface

* feature: Move Altering and Removal menu's to the Console

* feature: Allow specification of foreground/background colors from UI

* feature: Add Walter Zorn's tooltip to syslog messages (www.walterzorn.com)

* feature: Allow the syslog page to be sorted

* feature: Add Removal Rules to simply move log messages to a lower priority
  table

* feature: Use more Javascript on the Syslog page

* feature: Add HTML e-Mail capability with CSS

* feature: Display Alert Log history from the UI

* feature: Allow Removal Rules to be filtered from the UI

* feature: Add Reporting capability

* feature: Add Threshold Alarms

* feature: Add Alert Severity to Alarms

* feature: Turn images to buttons


--- 0.5.2 ---

* issue: Fixes to make syslog work properly when using the Superlinks plugin

* issue: Fix a few image errors


--- 0.5.1 ---

* issue: More 0.8.7 Compatibility fixes


--- 0.5 ---

* feature: Modified Message retrieval function to better make use of indexes,
  which greatly speeds it up

* feature: When adding a removal rule, only that rule will execute immediately,
  instead of rerunning all rules

* feature: Alert email now uses the Alert Name in the subject

* feature: Add ability to create Reports

* feature: Allow access for the guest account

* feature: Change name to syslog, from haloe

* feature: Use mailer options from the Settings Plugin

* feature: Add option for From Email address and From Display Name

* feature: Use new "api_user_realm_auth" from Plugin Architecture

* issue#0000046 - Event text colors (black) when setup a event color in black

* issue#0000047 - Change the Priority and Levels to be in Ascending order

* issue: Fixes for errors when using removal rules

* issue: Minor fix for error that would sometimes cause Syslog to not be
  processed

* issue: Update SQL to include indexes

* issue: Fix pagination of Alerts and Removal Rules

* issue: Lots of code / html cleanup for faster pages loads (use a little CSS
  also)

* issue: Fix for improper display of html entities in the syslog message (thanks
  dagonet)

* issue: Fix Cacti 0.8.7 compatibility


--- 0.4 ---

* issue#0000034 - Fix for shadow.gif file error in httpd logs.

* issue#0000036 - Syslog plugin causes duplicates if multiple log processors are
  running at once

* issue#0000037 - Option for max time to save syslog events

* issue: Removed some debugging code


--- 0.3 ---

* feature: Move Processing code to its own file

* feature: Add Debugging to the Processing Code (/debug)

* issue: Fixed an issue with "message" being hard coded

* issue: Fixed a typo in the removal code


--- 0.2 ---

* issue#0000010 Remove use of CURRENT_TIMESTAMP so that Mysql 3.x works again

* issue#0000013 - Fix issues with database names with uncommon characters by
  enclosing in back-ticks

* issue: Fixed a minor error that caused the graphs page to not refresh

* issue: Modified SQL query in syslog processor to speed things up greatly


--- 0.1 ---

* Initial release

-----------------------------------------------
Copyright (c) 2004-2023 - The Cacti Group, Inc.
