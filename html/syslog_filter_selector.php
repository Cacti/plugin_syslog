	<script type='text/javascript'>
		// Initialize the calendar
		calendar=null;

		// This function displays the calendar associated to the input field 'id'
		function showCalendar(id) {
			var el = document.getElementById(id);
			if (calendar != null) {
				// we already have some calendar created
				calendar.hide();  // so we hide it first.
			} else {
				// first-time call, create the calendar.
				var cal = new Calendar(true, null, selected, closeHandler);
				cal.weekNumbers = false;  // Do not display the week number
				cal.showsTime = true;     // Display the time
				cal.time24 = true;        // Hours have a 24 hours format
				cal.showsOtherMonths = false;    // Just the current month is displayed
				calendar = cal;                  // remember it in the global var
				cal.setRange(1900, 2070);        // min/max year allowed.
				cal.create();
			}

			calendar.setDateFormat('%Y-%m-%d %H:%M');    // set the specified date format
			calendar.parseDate(el.value);                // try to parse the text in field
			calendar.sel = el;                           // inform it what input field we use

			// Display the calendar below the input field
			calendar.showAtElement(el, "Br");        // show the calendar

			return false;
		}

		// This function update the date in the input field when selected
		function selected(cal, date) {
			cal.sel.value = date;      // just update the date in the input field.
		}

		// This function gets called when the end-user clicks on the 'Close' button.
		// It just hides the calendar without destroying it.
		function closeHandler(cal) {
			cal.hide();                        // hide the calendar
			calendar = null;
		}
	</script>
	<script type="text/javascript">
		<!--
		function applyTimespanFilterChange(objForm) {
			strURL = '?predefined_timespan=' + objForm.predefined_timespan.value;
			strURL = strURL + '&predefined_timeshift=' + objForm.predefined_timeshift.value;
			document.location = strURL;
		}
		-->
	</script>
	<form id="syslog_form" name="syslog_timespan_selector" method="post" action="syslog.php">
	<table width="100%" cellspacing="1" cellpadding="0">
		<tr>
			<td colspan="2" style="background-color:#EFEFEF;">
				<table width='100%' cellpadding=0 cellspacing=0>
					<tr>
						<td width='100%'>
							<?php
							html_start_box("<strong>Syslog Message Filter</strong>", "100%", $colors["header"], "1", "center", "");?>
							<tr bgcolor="<?php print $colors["panel"];?>" class="noprint">
								<td class="noprint">
									<table cellpadding="0" cellspacing="0">
										<tr>
											<td nowrap style='white-space: nowrap;' width='60'>
												&nbsp;<strong>Presets:</strong>&nbsp;
											</td>
											<td nowrap style='white-space: nowrap;' width='130'>
												<select name='predefined_timespan' onChange="applyTimespanFilterChange(document.syslog_timespan_selector)">
													<?php
													if ($_SESSION["custom"]) {
														$graph_timespans[GT_CUSTOM] = "Custom";
														$start_val = 0;
														$end_val = sizeof($graph_timespans);
													} else {
														if (isset($graph_timespans[GT_CUSTOM])) {
															asort($graph_timespans);
															array_shift($graph_timespans);
														}
														$start_val = 1;
														$end_val = sizeof($graph_timespans)+1;
													}

													if (sizeof($graph_timespans) > 0) {
														for ($value=$start_val; $value < $end_val; $value++) {
															print "<option value='$value'"; if ($_SESSION["sess_current_timespan"] == $value) { print " selected"; } print ">" . title_trim($graph_timespans[$value], 40) . "</option>\n";
														}
													}
													?>
												</select>
											</td>
											<td nowrap style='white-space: nowrap;' width='30'>
												&nbsp;<strong>From:</strong>&nbsp;
											</td>
											<td width='150' nowrap style='white-space: nowrap;'>
												<input type='text' name='date1' id='date1' title='Graph Begin Timestamp' size='14' value='<?php print (isset($_SESSION["sess_current_date1"]) ? $_SESSION["sess_current_date1"] : "");?>'>
												&nbsp;<input style='padding-bottom: 4px;' type='image' src='<?php print $config["url_path"];?>images/calendar.gif' alt='Start date selector' title='Start date selector' border='0' align='absmiddle' onclick="return showCalendar('date1');">&nbsp;
											</td>
											<td nowrap style='white-space: nowrap;' width='20'>
												&nbsp;<strong>To:</strong>&nbsp;
											</td>
											<td width='150' nowrap style='white-space: nowrap;'>
												<input type='text' name='date2' id='date2' title='Graph End Timestamp' size='14' value='<?php print (isset($_SESSION["sess_current_date2"]) ? $_SESSION["sess_current_date2"] : "");?>'>
												&nbsp;<input style='padding-bottom: 4px;' type='image' src='<?php print $config["url_path"];?>images/calendar.gif' alt='End date selector' title='End date selector' border='0' align='absmiddle' onclick="return showCalendar('date2');">
											</td>
											<td width='130' nowrap style='white-space: nowrap;'>
												&nbsp;&nbsp;<input style='padding-bottom: 4px;' type='image' name='move_left' src='<?php print $config["url_path"];?>images/move_left.gif' alt='Left' border='0' align='absmiddle' title='Shift Left'>
												<select name='predefined_timeshift' title='Define Shifting Interval' onChange="applyTimespanFilterChange(document.syslog_timespan_selector)">
													<?php
													$start_val = 1;
													$end_val = sizeof($graph_timeshifts)+1;
													if (sizeof($graph_timeshifts) > 0) {
														for ($shift_value=$start_val; $shift_value < $end_val; $shift_value++) {
															print "<option value='$shift_value'"; if ($_SESSION["sess_current_timeshift"] == $shift_value) { print " selected"; } print ">" . title_trim($graph_timeshifts[$shift_value], 40) . "</option>\n";
														}
													}
													?>
												</select>
												<input style='padding-bottom: 4px;' type='image' name='move_right' src='<?php print $config["url_path"];?>images/move_right.gif' alt='Right' border='0' align='absmiddle' title='Shift Right'>
											</td>
										</tr>
									</table>
								</td>
							</tr>
							<tr bgcolor="<?php print $colors["panel"];?>" class="noprint">
								<td>
									<table cellpadding="0" cellspacing="0">
										<tr>
											<td nowrap style='white-space: nowrap;' width='60'>
												&nbsp;<strong>Search:</strong>
											</td>
											<td style='padding-right:2px;'>
												<input type="text" name="filter" size="30" value="<?php print $_REQUEST["filter"];?>">
											</td>
											<td style='padding-right:2px;'>
												<select name="efacility" onChange="javascript:document.getElementById('syslog_form').submit();">
													<option value="0"<?php if ($_REQUEST["efacility"] == "0") {?> selected<?php }?>>All Facilities</option>
													<?php
													$efacilities = db_fetch_assoc("SELECT DISTINCT " . $syslog_config["facilityField"] . "
														FROM " . $syslog_config["facilityTable"] . (strlen($hostfilter) ? " WHERE ":"") . $hostfilter . "
														ORDER BY " . $syslog_config["facilityField"]);

													if (sizeof($efacilities) > 0) {
													foreach ($efacilities as $efacility) {
														print "<option value=" . $efacility[$syslog_config["facilityField"]]; if ($_REQUEST["efacility"] == $efacility[$syslog_config["facilityField"]]) { print " selected"; } print ">" . ucfirst($efacility[$syslog_config["facilityField"]]) . "</option>\n";
													}
													}
													?>
												</select>
											</td>
											<td style='padding-right:2px;'>
												<select name="elevel" onChange="javascript:document.getElementById('syslog_form').submit();">
													<option value="0"<?php if ($_REQUEST["elevel"] == "0") {?> selected<?php }?>>All Priorities</option>
													<option value="1"<?php if ($_REQUEST["elevel"] == "1") {?> selected<?php }?>>Emergency</option>
													<option value="2"<?php if ($_REQUEST["elevel"] == "2") {?> selected<?php }?>>Alert++</option>
													<option value="3"<?php if ($_REQUEST["elevel"] == "3") {?> selected<?php }?>>Critical++</option>
													<option value="4"<?php if ($_REQUEST["elevel"] == "4") {?> selected<?php }?>>Error++</option>
													<option value="5"<?php if ($_REQUEST["elevel"] == "5") {?> selected<?php }?>>Warning++</option>
													<option value="6"<?php if ($_REQUEST["elevel"] == "6") {?> selected<?php }?>>Notice++</option>
													<option value="7"<?php if ($_REQUEST["elevel"] == "7") {?> selected<?php }?>>Info++</option>
													<option value="8"<?php if ($_REQUEST["elevel"] == "8") {?> selected<?php }?>>Debug</option>
												</select>
											</td>
											<td style='padding-right:2px;'>
												<select name="removal" onChange="javascript:document.getElementById('syslog_form').submit();">
													<option value="-1"<?php if ($_REQUEST["removal"] == "-1") {?> selected<?php }?>>Exclude Removed</option>
													<option value="1"<?php if ($_REQUEST["removal"] == "1") {?> selected<?php }?>>Include Removed</option>
												</select>
											</td>
											<td style='padding-right:2px;'>
												<select name="rows" onChange="javascript:document.getElementById('syslog_form').submit();">
													<option value="10"<?php if ($_REQUEST["rows"] == "10") {?> selected<?php }?>>10</option>
													<option value="15"<?php if ($_REQUEST["rows"] == "15") {?> selected<?php }?>>15</option>
													<option value="20"<?php if ($_REQUEST["rows"] == "20") {?> selected<?php }?>>20</option>
													<option value="25"<?php if ($_REQUEST["rows"] == "25") {?> selected<?php }?>>25</option>
													<option value="30"<?php if ($_REQUEST["rows"] == "30") {?> selected<?php }?>>30</option>
													<option value="50"<?php if ($_REQUEST["rows"] == "50") {?> selected<?php }?>>50</option>
													<option value="100"<?php if ($_REQUEST["rows"] == "100") {?> selected<?php }?>>100</option>
													<option value="200"<?php if ($_REQUEST["rows"] == "200") {?> selected<?php }?>>200</option>
													<option value="500"<?php if ($_REQUEST["rows"] == "500") {?> selected<?php }?>>500</option>
												</select>
											</td>
											<td nowrap style='white-space:nowrap;padding-right:2px;'>
												<input type="image" name='button_refresh' src="<?php print $config['url_path']; ?>images/button_go.gif" alt="Go" border="0" align="absmiddle" action='submit'>
												<input type='image' name='button_clear' src='<?php print $config["url_path"];?>images/button_clear.gif' alt='Return to the default time span' border='0' align='absmiddle' action='submit'>
												<input type='image' name='export' src='<?php print $config['url_path']; ?>images/button_export.gif' alt='Reset fields to defaults' border='0' align='absmiddle' action='submit'>
												<input type='hidden' name='action' value='actions'>
												<input type='hidden' name='syslog_pdt_change' value='false'>
											</td>
										</tr>
									</table>
								</td>
							</tr>
							<?php html_end_box(false);?>
						</td>
						<td valign='top' style='padding-left:5px; background-color:#FFFFFF;'>
							<?php html_start_box("<strong>Rules</strong>", "100%", $colors["header"], "3", "center", "");?>
							<tr bgcolor='#<?php print $colors["panel"];?>'>
								<td class='textHeader'>
									<a href='syslog_alerts.php'>Alerts</a>
									<br>
									<a href='syslog_removal.php'>Removals</a>
									<br>
									<a href='syslog_reports.php'>Reports</a>
								</td>
							</tr>
							<?php html_end_box(false);?>
						</td>
					</tr>
				</table>
			</td>
		</tr>
		<tr>
			<td valign="top" style="border-right: #aaaaaa 1px solid;" bgcolor='#efefef'>
				<table align="center" cellpadding=0 cellspacing=0 border=0>
					<tr>
						<td>
							<?php html_start_box("", "", $colors["header"], "3", "center", ""); ?>
							<tr>
								<td class="textHeader" nowrap>
									Select Host(s):&nbsp;
								</td>
							</tr>
							<tr>
								<td>
									<select name="host[]" multiple size="<?php print read_config_option("syslog_hosts");?>" style="width: 150px; overflow: scroll; height: auto;" onChange="javascript:document.getElementById('syslog_form').submit();">
										<option value="0"<?php if (((is_array($_REQUEST["host"])) && ($_REQUEST["host"][0] == "0")) || ($reset_multi)) {?> selected<?php }?>>Show All Hosts&nbsp;&nbsp;</option>
										<?php
										$query = mysql_query("SELECT " . $syslog_config["hostField"] . " FROM " . $syslog_config["hostTable"]);

										while ($hosts[] = mysql_fetch_assoc($query));

										array_pop($hosts);
										if (sizeof($hosts) > 0) {
											foreach($hosts as $host) {
												$new_hosts[] = $host[$syslog_config["hostField"]];
											}
											$hosts = natsort($new_hosts);
											foreach ($new_hosts as $host) {
												print "<option value=" . $host;
												if (sizeof($_REQUEST["host"])) {
													foreach ($_REQUEST["host"] as $rh) {
														if (($rh == $host) &&
															(!$reset_multi)) {
															print " selected";
															break;
														}
													}
												}else{
													if (($host == $_REQUEST["host"]) &&
														(!$reset_multi)) {
														print " selected";
													}
												}
												print ">";
												print $host . "</option>\n";
											}
										}
										?>
									</select>
								</td>
							</tr>
							<?php html_end_box(false); ?>
						</td>
					</tr>
				</table>
			</td>
			<td width="100%" valign="top" style="padding: 0px;">
				<table width="100%" cellspacing="0" cellpadding="1">
					<tr>
						<td width="100%" valign="top"><?php display_output_messages();?>
							<?php
							$total_rows = mysql_fetch_array(mysql_query("SELECT count(*) from " . $syslog_config["syslogTable"] . " " . $sql_where));
							$total_rows = $total_rows[0];

							html_start_box("", "100%", $colors["header"], "3", "center", "");
							$hostarray = "";
							if (is_array($_REQUEST["host"])) {
								foreach ($_REQUEST["host"] as $h) {
									$hostarray .= "host[]=$h&";
								}
							}else{
								$hostarray .= "host[]=" . $_REQUEST["host"] . "&";
							}
