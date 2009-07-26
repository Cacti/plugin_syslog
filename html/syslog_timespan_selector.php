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

<script type='text/javascript'>
	function reLoadIt() {
		document.syslog_timespan_selector['syslog_pdt_change'].value="true";
		document.syslog_timespan_selector.submit();
	}
</script>



	<nobr>
						<strong>&nbsp;Presets:</strong>
						<select name='predefined_timespan' onChange="reLoadIt();">
							<?php
							if ($syslog_config["graphtime"] ? $_SESSION["custom"] : $_SESSION["sess_syslog_array"]["custom"]) {
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
									print "<option value='" . $value . "'"; if (($syslog_config["graphtime"] ? $_SESSION["sess_current_timespan"] : $_SESSION["sess_syslog_array"]["sess_current_timespan"]) == $value) { print " selected"; } print ">" . title_trim($graph_timespans[$value], 40) . "</option>\n";
								}
							}
							?>
						</select>
						<strong>&nbsp;From:</strong>
						<?php
						if ($syslog_config["graphtime"]) { ?>
							<input type='text' name='date1' id='date1' size='14' value='<?php print (isset($_SESSION["sess_current_date1"]) ? $_SESSION["sess_current_date1"] : "");?>'>
							&nbsp;<input type='image' src='<?php print $config['url_path']; ?>images/calendar.gif' alt='Start date selector' border='0' align='absmiddle' onclick="return showCalendar('date1');">&nbsp;
							<strong>To:</strong>
							<input type='text' name='date2' id='date2' size='14' value='<?php print (isset($_SESSION["sess_current_date2"]) ? $_SESSION["sess_current_date2"] : "");?>'>
							&nbsp;<input type='image' src='<?php print $config['url_path']; ?>images/calendar.gif' alt='End date selector' border='0' align='absmiddle' onclick="return showCalendar('date2');">
						<?php
						} elseif ($syslog_config["timespan_sel"]) { ?>
							<input type='text' name='date1' id='date1' size='14' value='<?php print (isset($_SESSION["sess_syslog_array"]["sess_current_date1"]) ? $_SESSION["sess_syslog_array"]["sess_current_date1"] : "");?>'>
							&nbsp;<input type='image' src='<?php print $config['url_path']; ?>images/calendar.gif' alt='Start date selector' border='0' align='absmiddle' onclick="return showCalendar('date1');">&nbsp;
							<strong>To:</strong>
							<input type='text' name='date2' id='date2' size='14' value='<?php print (isset($_SESSION["sess_syslog_array"]["sess_current_date2"]) ? $_SESSION["sess_syslog_array"]["sess_current_date2"] : "");?>'>
							&nbsp;<input type='image' src='<?php print $config['url_path']; ?>images/calendar.gif' alt='End date selector' border='0' align='absmiddle' onclick="return showCalendar('date2');">
						<?php } ?>
	</nobr>
