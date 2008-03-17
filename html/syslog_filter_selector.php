	<nobr style='padding-bottom: 5px;'>
						&nbsp;Search:&nbsp;
						<input type="text" name="filter" size="30" value="<?php print $_REQUEST["filter"];?>">
						<select name="efacility" onChange="javascript:document.getElementById('syslog_form').submit();">
							<option value="0"<?php if ($_REQUEST["efacility"] == "0") {?> selected<?php }?>>All Facilities</option>
							<?php
							$query = mysql_query("SELECT DISTINCT " . $syslog_config["facilityField"] . " FROM " . $syslog_config["syslogTable"] . $where_hostfilter . " ORDER BY " . $syslog_config["facilityField"]);
							while ($efacilities[] = mysql_fetch_assoc($query));
							array_pop($efacilities);
							if (sizeof($efacilities) > 0) {
							foreach ($efacilities as $efacility) {
								print "<option value=" . $efacility[$syslog_config["facilityField"]]; if ($_REQUEST["efacility"] == $efacility[$syslog_config["facilityField"]]) { print " selected"; } print ">" . ucfirst($efacility[$syslog_config["facilityField"]]) . "</option>\n";
							}
							}
							?>
						</select>
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
	</nobr>
					</td>
					<td class='textEditTitle' nowrap>
						&nbsp;Output&nbsp;To:&nbsp;
						<select name="output" onChange="javascript:document.getElementById('syslog_form').submit();">
							<option value="screen" selected>Screen&nbsp;&nbsp;&nbsp;</option>
							<option value="file" <?php if ($_REQUEST["output"] == "file") {?> selected<?php }?>>File&nbsp;</option>
						</select>
						&nbsp;<input type='image' name='button_clear' src='<?php print $config['url_path']; ?>images/button_clear.gif' alt='Reset fields to defaults' border='0' align='absmiddle' action='submit'>
						<input type="image" src="<?php print $config['url_path']; ?>images/button_go.gif" alt="Go" border="0" align="absmiddle" action='submit'>
						<input type='hidden' name='page' value='1'>
						<input type='hidden' name='action' value='actions'>
						<input type='hidden' name='syslog_pdt_change' value='false'>
