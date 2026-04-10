/**
 * Syslog Plugin - JavaScript Functions
 * Consolidated functions from inline JavaScript in PHP files
 */

/* ========================================================================
 * Statistics View Functions (syslog.php - stats tab)
 * ======================================================================== */

/**
 * Clear filter for statistics view
 */
function clearFilterStats() {
	strURL = 'syslog.php?tab=stats&clear=1&header=false';
	loadPageNoHeader(strURL);
}

/**
 * Apply filter for statistics view
 */
function applyFilterStats() {
	var strURL  = 'syslog.php?header=false';
	strURL += '&none=true';
	strURL += '&facility=' + $('#facility').val();
	strURL += '&host=' + $('#host').val();
	strURL += '&priority=' + $('#priority').val();
	strURL += '&program=' + $('#eprogram').val();
	strURL += '&timespan=' + $('#timespan').val();
	strURL += '&rfilter=' + base64_encode($('#rfilter').val());
	strURL += '&rows=' + $('#rows').val();
	strURL += '&grouping=' + ($('#grouping').length ? $('#grouping').val() : '0');
	loadPageNoHeader(strURL);
}

/**
 * Initialize statistics view
 */
function initSyslogStats() {
	$(function() {
		$('#go').click(function() {
			applyFilterStats();
		});

		$('#clear').click(function() {
			clearFilterStats();
		});

		$('#host').selectmenu({
			open: function() {
				$('div.ui-selectmenu-menu li.ui-menu-item').each(function(idx){
					$(this).addClass( $('#host option').eq(idx).attr('class') )
				})
			}
		});
	});
}

/* ========================================================================
 * Main Syslog View Functions (syslog.php - syslog/alerts tabs)
 * ======================================================================== */

/**
 * Apply timespan filter
 */
function applyTimespan() {
	var strURL  = urlPath+'plugins/syslog/syslog.php?header=false';
	strURL += '&predefined_timespan=' + $('#predefined_timespan').val();
	loadPageNoHeader(strURL);
}

/**
 * Apply main syslog filter
 */
function applyFilter() {
	var strURL  = 'syslog.php?tab='+(window.pageTab || '');

	strURL += '&header=false';
	strURL += '&host='+$('#host').val();
	strURL += '&rfilter='+base64_encode($('#rfilter').val());
	strURL += '&efacility='+$('#efacility').val();
	strURL += '&epriority='+$('#epriority').val();
	strURL += '&eprogram='+$('#eprogram').val();
	strURL += '&rows='+$('#rows').val();
	strURL += '&trimval='+$('#trimval').val();
	strURL += '&removal='+$('#removal').val();
	strURL += '&refresh='+$('#refresh').val();
	strURL += '&grouping=' + ($('#grouping').length ? $('#grouping').val() : '0');
	strURL += '&predefined_timespan='+$('#predefined_timespan').val();
	strURL += '&date1='+$('#date1').val();
	strURL += '&date2='+$('#date2').val();

	loadPageNoHeader(strURL);
}

/**
 * Export records to CSV
 */
function exportRecords() {
	document.location = 'syslog.php?export=true';
	Pace.stop();
}

/**
 * Clear main syslog filter
 */
function clearFilter() {
	var strURL  = 'syslog.php?tab=' + (window.pageTab || '');
	strURL += '&header=false&clear=true';
	loadPageNoHeader(strURL);
}

/**
 * Save syslog filter settings
 */
function saveSettings() {
	var strURL  = 'syslog.php?action=save&tab=' + (window.pageTab || '');
	var data    = {};

	data.trimval      = $('#trimval').val();
	data.rows         = $('#rows').val();
	data.removal      = $('#removal').val();
	data.refresh      = $('#refresh').val();
	data.efacility    = $('#efacility').val();
	data.epriority    = $('#epriority').val();
	data.eprogram     = $('#eprogram').val();
	data.__csrf_magic = csrfMagicToken;

	if ($('#predefined_timespan').val() > 0) {
		data.predefined_timespan = $('#predefined_timespan').val();
	}

	data.predefined_timeshift = $('#predefined_timeshift').val();

	$.post(strURL, data).done(function() {
		$('#text').show().text('Filter Settings Saved').fadeOut(2000);
	});
}

/**
 * Shift time filter left (backward)
 */
function timeshiftFilterLeft() {
	var strURL  = 'syslog.php?tab='+(window.pageTab || '')+'&header=false';
	strURL += '&shift_left=true';
	strURL += '&date1='+$('#date1').val();
	strURL += '&date2='+$('#date2').val();
	strURL += '&predefined_timeshift='+$('#predefined_timeshift').val();
	loadPageNoHeader(strURL);
}

/**
 * Shift time filter right (forward)
 */
function timeshiftFilterRight() {
	var strURL  = 'syslog.php?tab='+(window.pageTab || '')+'&header=false';
	strURL += '&shift_right=true';
	strURL += '&date1='+$('#date1').val();
	strURL += '&date2='+$('#date2').val();
	strURL += '&predefined_timeshift='+$('#predefined_timeshift').val();
	loadPageNoHeader(strURL);
}

/**
 * Initialize main syslog view
 * @param {object} config - Configuration object containing: pageTab, placeHolder, noneSelectedText, devicesSelectedText, allDevicesText
 */
function initSyslogMain(config) {
	var date1Open = false;
	var date2Open = false;
	var pageTab   = config.pageTab || '';
	var hostTerm  = '';
	var placeHolder = config.placeHolder || '';
	var origDate1 = $('#date1').val();
	var origDate2 = $('#date2').val();

	// Make pageTab global for other functions
	window.pageTab = pageTab;

	$(function() {
		$('#syslog_form').submit(function(event) {
			event.preventDefault();
			applyFilter();
		});

		$('#host').multiselect({
			menuHeight: $(window).height()*.7,
			menuWidth: '220',
			linkInfo: faIcons,
			noneSelectedText: config.noneSelectedText || '',
			selectedText: function(numChecked, numTotal, checkedItems) {
				var myReturn = numChecked + ' ' + config.devicesSelectedText;
				$.each(checkedItems, function(index, value) {
					if (value.value == '0') {
						myReturn = config.allDevicesText;
						return false;
					}
				});
				return myReturn;
			},
			uncheckAll: function() {
				$(this).multiselect('widget').find(':checkbox:first').each(function() {
					$(this).prop('checked', true);
				});
				$('#test').trigger('keyup');
			},
			checkAll: function() {
				$(this).multiselect('widget').find(':checkbox').not(':first').each(function() {
					$(this).prop('checked', true);
				});
				$(this).multiselect('widget').find(':checkbox:first').each(function() {
					$(this).prop('checked', false);
				});
			},
			open: function(event, ui) {
				if ($('#term').length == 0) {
					var width = parseInt($(this).multiselect('widget').find('.ui-multiselect-header').width() - 5);
					$(this).multiselect('widget').find('.ui-multiselect-header').after('<input id="term" placeholder="'+placeHolder+'" class="ui-state-default ui-corner-all" style="width:'+width+'px" type="text" value="'+hostTerm+'">');
					$('#term').on('keyup', function() {
						$.getJSON('syslog.php?action=ajax_hosts&term='+$('#term').val(), function(data) {
							$('#host').find('option').not(':selected').each(function() {
								if ($(this).attr('id') != 'host_all') {
									$(this).remove();
								}
							});

							$.each(data, function(index, hostData) {
								if ($('#host option[value="'+index+'"]').length == 0) {
									$('#host').append('<option class="'+DOMPurify.sanitize(hostData.class)+'" value="'+DOMPurify.sanitize(index)+'">'+DOMPurify.sanitize(hostData.host)+'</option>');
								}
							});

							$('#host').multiselect('refresh');
						});
					});
				}

				$('#term').focus();
			},
			click: function(event, ui) {
				var checked = $(this).multiselect('widget').find('input:checked').length;

				if (ui.value == '0') {
					if (ui.checked == true) {
						$('#host').multiselect('uncheckAll');
						$(this).multiselect('widget').find(':checkbox:first').each(function() {
							$(this).prop('checked', true);
						});
					}
				} else if (checked == 0) {
					$(this).multiselect('widget').find(':checkbox:first').each(function() {
						$(this).click();
					});
				} else if ($(this).multiselect('widget').find('input:checked:first').val() == '0') {
					if (checked > 0) {
						$(this).multiselect('widget').find(':checkbox:first').each(function() {
							$(this).click();
							$(this).prop('disable', true);
						});
					}
				}
			}
		});

		$('#save').click(function() {
			saveSettings();
		});

		$('#go').click(function() {
			applyFilter();
		});

		$('#clear').click(function() {
			clearFilter();
		});

		$('#export').click(function() {
			exportRecords();
		});

		$('#balerts').click(function() {
			loadTopTab(urlPath+'plugins/syslog/syslog_alerts.php?header=false');
			$('.maintabs').find('a').removeClass('selected');
			$('#tab-console').addClass('selected');
		});

		$('#bremoval').click(function() {
			loadTopTab(urlPath+'plugins/syslog/syslog_removal.php?header=false');
			$('.maintabs').find('a').removeClass('selected');
			$('#tab-console').addClass('selected');
		});

		$('#breports').click(function() {
			loadTopTab(urlPath+'plugins/syslog/syslog_reports.php?header=false');
			$('.maintabs').find('a').removeClass('selected');
			$('#tab-console').addClass('selected');
		});

		$('#startDate').click(function() {
			if (date1Open) {
				date1Open = false;
				$('#date1').datetimepicker('hide');
			} else {
				date1Open = true;
				$('#date1').datetimepicker('show');
			}
		});

		$('#endDate').click(function() {
			if (date2Open) {
				date2Open = false;
				$('#date2').datetimepicker('hide');
			} else {
				date2Open = true;
				$('#date2').datetimepicker('show');
			}
		});

		$('#date1').datetimepicker({
			minuteGrid: 10,
			stepMinute: 1,
			showAnim: 'slideDown',
			numberOfMonths: 1,
			timeFormat: 'HH:mm:ss',
			dateFormat: 'yy-mm-dd',
			showButtonPanel: false
		}).on('change', function() {
			if ($('#date1').val() != origDate1) {
				$('#predefined_timespan').val(0).selectmenu('refresh');
			}
		});

		$('#date2').datetimepicker({
			minuteGrid: 10,
			stepMinute: 1,
			showAnim: 'slideDown',
			numberOfMonths: 1,
			timeFormat: 'HH:mm:ss',
			dateFormat: 'yy-mm-dd',
			showButtonPanel: false
		}).on('change', function() {
			if ($('#date2').val() != origDate2) {
				$('#predefined_timespan').val(0).selectmenu('refresh');
			}
		});;
	});
}

/**
 * Initialize syslog message tooltips and group expand/collapse functionality
 * Call this after the syslog message table is rendered or updated
 */
function initSyslogMessagesDisplay() {
	$(function() {
		// Initialize tooltips for syslog rows
		$('.syslogRow').tooltip({
			track: true,
			show: {
				effect: 'fade',
				duration: 250,
				delay: 125
			},
			position: { my: 'left+15 center', at: 'right center' }
		});

		// Initialize tooltips for buttons
		$('button').tooltip({
			closed: true
		}).on('focus', function() {
			$('#filter').tooltip('close');
		}).on('click', function() {
			$(this).tooltip('close');
		});

		// Handle syslog group expand/collapse
		$('.syslog-group-toggle').off('click').on('click', function(e) {
			e.preventDefault();
			e.stopPropagation();

			var seq = $(this).data('seq');
			var detailRows = $('.syslog-detail-' + seq);
			var icon = $(this);

			if (detailRows.is(':visible')) {
				// Collapse
				detailRows.hide();
				icon.removeClass('fa-chevron-up').addClass('fa-chevron-down');
			} else {
				// Expand
				detailRows.show();
				icon.removeClass('fa-chevron-down').addClass('fa-chevron-up');
			}
		});
	});
}

/* ========================================================================
 * Removal Rules Functions (syslog_removal.php)
 * ======================================================================== */

/**
 * Apply filter for removal rules view
 */
function applyFilterRemoval() {
	var strURL = 'syslog_removal.php?filter='+$('#filter').val()+'&enabled='+$('#enabled').val()+'&rows='+$('#rows').val()+'&page='+$('#page').val()+'&header=false';
	loadPageNoHeader(strURL);
}

/**
 * Clear filter for removal rules view
 */
function clearFilterRemoval() {
	var strURL = 'syslog_removal.php?clear=1&header=false';
	loadPageNoHeader(strURL);
}

/**
 * Import removal rule
 */
function importRemoval() {
	var strURL = 'syslog_removal.php?action=import&header=false';
	loadPageNoHeader(strURL);
}

/**
 * Change message textarea rows based on type
 */
function changeTypes() {
	if ($('#type').val() == 'sql') {
		$('#message').prop('rows', 5);
	} else {
		$('#message').prop('rows', 2);
	}
}

/**
 * Initialize removal rules view
 * @param {boolean} allowEdits - Whether edits are allowed
 */
function initSyslogRemoval(allowEdits) {
	$(function() {
		if (!allowEdits) {
			$('#syslog_edit').find('select, input, textarea, submit').not(':button').prop('disabled', true);
			$('#syslog_edit').find('select').each(function() {
				if ($(this).selectmenu('instance')) {
					$(this).selectmenu('refresh');
				}
			});
		}

		$('#refresh').click(function() {
			applyFilterRemoval();
		});

		$('#clear').click(function() {
			clearFilterRemoval();
		});

		$('#import').click(function() {
			importRemoval();
		});

		$('#removal').submit(function(event) {
			event.preventDefault();
			applyFilterRemoval();
		});
	});
}

/* ========================================================================
 * Alert Rules Functions (syslog_alerts.php)
 * ======================================================================== */

/**
 * Apply filter for alert rules view
 */
function applyFilterAlerts() {
	var strURL = 'syslog_alerts.php?filter='+$('#filter').val()+'&enabled='+$('#enabled').val()+'&rows='+$('#rows').val()+'&page='+$('#page').val()+'&header=false';

	loadPageNoHeader(strURL);
}

/**
 * Clear filter for alert rules view
 */
function clearFilterAlerts() {
	var strURL = 'syslog_alerts.php?clear=1&header=false';

	loadPageNoHeader(strURL);
}

/**
 * Import alert rule
 */
function importAlert() {
	var strURL = 'syslog_alerts.php?action=import&header=false';

	loadPageNoHeader(strURL);
}

/**
 * Initialize alert rules view
 */
function initSyslogAlerts() {
	$(function() {
		$('#refresh').click(function() {
			applyFilterAlerts();
		});

		$('#clear').click(function() {
			clearFilterAlerts();
		});

		$('#import').click(function() {
			importAlert();
		});

		$('#alert').submit(function(event) {
			event.preventDefault();
			applyFilterAlerts();
		});
	});
}

/* ========================================================================
 * Report Rules Functions (syslog_reports.php)
 * ======================================================================== */

/**
 * Apply filter for report rules view
 */
function applyFilterReports() {
	var strURL = 'syslog_reports.php?filter='+$('#filter').val()+'&enabled='+$('#enabled').val()+'&rows='+$('#rows').val()+'&page='+$('#page').val()+'&header=false';

	loadPageNoHeader(strURL);
}

/**
 * Clear filter for report rules view
 */
function clearFilterReports() {
	var strURL = 'syslog_reports.php?clear=1&header=false';

	loadPageNoHeader(strURL);
}

/**
 * Import report rule
 */
function importReport() {
	var strURL = 'syslog_reports.php?action=import&header=false';

	loadPageNoHeader(strURL);
}

/**
 * Initialize report rules view
 */
function initSyslogReports() {
	$(function() {
		$('#refresh').click(function() {
			applyFilterReports();
		});

		$('#clear').click(function() {
			clearFilterReports();
		});

		$('#import').click(function() {
			importReport();
		});

		$('#reports').submit(function(event) {
			event.preventDefault();
			applyFilterReports();
		});
	});
}

/* ========================================================================
 * Autocomplete Form Callback Functions
 * ======================================================================== */

function syslogExecuteFunctionByName(functionName, context /*, args */) {
	var args       = Array.prototype.slice.call(arguments, 2);
	var namespaces = functionName.split('.');
	var func       = namespaces.pop();

	for(var i = 0; i < namespaces.length; i++) {
		context = context[namespaces[i]];
	}

	return context[func].apply(context, args);
}

/**
 * Initialize autocomplete for form dropdown fields
 * @param {string} formName - The name of the form field
 * @param {string} callback - The AJAX callback action
 * @param {string} onChange - The onChange callback to execute when selection changes
 */
function initSyslogAutocomplete(formName, callback, onChange) {
	var formNameTimer;
	var formNameClickTimer;
	var formNameOpen = false;

	$(function() {
		$('#' + formName + '_input').autocomplete({
			source: window.location.pathname + '?action=' + callback,
			autoFocus: true,
			minLength: 0,
			select: function(event, ui) {
				$('#' + formName + '_input').val(ui.item.label);

				if (ui.item.id) {
					$('#' + formName).val(ui.item.id);
				} else {
					$('#' + formName).val(ui.item.value);
				}

				if (onChange) {
					$(this).autocomplete('close');

					onChange = onChange.replace('(', '').replace(')', '');

					syslogExecuteFunctionByName(onChange, window);
				}
			}
		}).css('border', 'none').css('background-color', 'transparent');

		$('#' + formName + '_wrap').on('dblclick', function() {
			formNameOpen = false;
			clearTimeout(formNameTimer);
			clearTimeout(formNameClickTimer);
			$('#' + formName + '_input').autocomplete('close');
		}).on('click', function() {
			if (formNameOpen) {
				$('#' + formName + '_input').autocomplete('close');
				clearTimeout(formNameTimer);
				formNameOpen = false;
			} else {
				formNameClickTimer = setTimeout(function() {
					$('#' + formName + '_input').autocomplete('search', '');
					clearTimeout(formNameTimer);
					formNameOpen = true;
				}, 200);
			}
		}).on('mouseleave', function() {
			formNameTimer = setTimeout(function() {
				$('#' + formName + '_input').autocomplete('close');
			}, 800);
		});

		var width = $('#' + formName + '_input').textBoxWidth();
		if (width < 100) {
			width = 100;
		}

		$('#' + formName + '_wrap').css('width', width + 20);
		$('#' + formName + '_input').css('width', width);

		$('ul[id^="ui-id"]').on('mouseenter', function() {
			clearTimeout(formNameTimer);
		}).on('mouseleave', function() {
			formNameTimer = setTimeout(function() {
				$('#' + formName + '_input').autocomplete('close');
			}, 800);
		});

		$('ul[id^="ui-id"] > li').each().on('mouseenter', function() {
			$(this).addClass('ui-state-hover');
		}).on('mouseleave', function() {
			$(this).removeClass('ui-state-hover');
		});

		$('#' + formName + '_wrap').on('mouseenter', function() {
			$(this).addClass('ui-state-hover');
			$('input#' + formName + '_input').addClass('ui-state-hover');
		}).on('mouseleave', function() {
			$(this).removeClass('ui-state-hover');
			$('input#' + formName + '_input').removeClass('ui-state-hover');
		});
	});
}
