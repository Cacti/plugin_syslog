	<nobr>
						<strong>&nbsp;Search&nbsp;text:&nbsp;</strong>
						<input type="text" name="filter" size="40" value="<?php print $_REQUEST["filter"];?>">
						<select name="efacility">
							<option value="0"<?php if ($_REQUEST["efacility"] == "0") {?> selected<?php }?>>All Facilities</option>
							<?php
							$query = mysql_query("SELECT DISTINCT " . $syslog_config["facilityField"] . " FROM " . $syslog_config["syslogTable"] . $where_hostfilter . " ORDER BY " . $syslog_config["facilityField"]);
							while ($efacilities[] = mysql_fetch_assoc($query));
							array_pop($efacilities);
							if (sizeof($efacilities) > 0) {
							foreach ($efacilities as $efacility) {
								print "<option value=" . $efacility[$syslog_config["facilityField"]]; if ($_REQUEST["efacility"] == $efacility[$syslog_config["facilityField"]]) { print " selected"; } print ">" . $efacility[$syslog_config["facilityField"]] . "</option>\n";
							}
							}
							?>
						</select>
						<select name="elevel">
							<option value="0"<?php if ($_REQUEST["elevel"] == "0") {?> selected<?php }?>>All Priorities</option>
							<?php
							$query = mysql_query("SELECT DISTINCT " . $syslog_config["priorityField"] . " FROM " . $syslog_config["syslogTable"] . $where_hostfilter . " ORDER BY " . $syslog_config["priorityField"]);
							while ($elevels[] = mysql_fetch_assoc($query));
							array_pop($elevels);
							if (sizeof($elevels) > 0) {
							foreach ($elevels as $elevel) {
								print "<option value=" . $elevel[$syslog_config["priorityField"]]; if ($_REQUEST["elevel"] == $elevel[$syslog_config["priorityField"]]) { print " selected"; } print ">" . $elevel[$syslog_config["priorityField"]] . "</option>\n";
							}
							}
							?>
						</select>
	</nobr>
					</td>
					<td nowrap>
						<strong>&nbsp;Output&nbsp;To:&nbsp;</strong>
						<select name="output">
							<option value="screen" selected>Screen&nbsp;&nbsp;&nbsp;</option>
							<option value="file" <?php if ($_REQUEST["output"] == "file") {?> selected<?php }?>>File&nbsp;</option>
						</select>
						&nbsp;<input type='image' name='button_clear' src='<?php print $config['url_path']; ?>images/button_clear.gif' alt='Reset fields to defaults' border='0' align='absmiddle' action='submit'>
						<input type="image" src="<?php print $config['url_path']; ?>images/button_go.gif" alt="Go" border="0" align="absmiddle" action='submit'>

						<input type='hidden' name='page' value='1'>
						<input type='hidden' name='action' value='actions'>
						<input type='hidden' name='syslog_pdt_change' value='false'>
