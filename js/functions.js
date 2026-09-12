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
 * Apply main syslog filter
 */
/** Convert saved searches to editable conditions, retaining explicit groups. */
function syslogSearchRows(tree) {
	var rows = [];
	function append(node, join, minimum) {
		if (!node) {
			return;
		}
		var precedence = {OR: 1, AND: 2}[node[0]];
		if (precedence && precedence >= minimum) {
			append(node[1], join, precedence);
			append(node[2], node[0], precedence);
		} else if (node[0] === 'predicate') {
			rows.push({join: join, negative: false, field: node[1], operator: node[2], value: node[3]});
		} else if (node[0] === 'term') {
			rows.push({join: join, negative: false, value: node[1]});
		} else if (node[0] === 'NOT') {
			var children = syslogSearchRows(node[1]);
			if (children.length === 1) {
				children[0].join = join;
				children[0].negative = !children[0].negative;
				rows.push(children[0]);
			} else {
				rows.push({join: join, negative: true, rows: children});
			}
		} else {
			rows.push({join: join, negative: false, rows: syslogSearchRows(node)});
		}
	}
	append(tree, 'AND', 0);
	return rows;
}

/** Serialize literal row values; users never have to quote search syntax. */
function syslogSearchExpression(rows) {
	return rows.map(function(row, index) {
		var term = row.rows ? '(' + syslogSearchExpression(row.rows) + ')' :
			'"' + row.value.replace(/\\/g, '\\\\').replace(/"/g, '\\"') + '"';
		if (!row.rows && (row.field && row.field !== 'message' || row.operator && row.operator !== 'contains')) {
			term = (row.field || 'message') + ' ' + (row.operator || 'contains') + ' ' + term;
		}
		return (index ? ' ' + row.join + ' ' : '') + (row.negative ? 'NOT ' : '') + term;
	}).join('');
}

function syncSyslogSearchBuilder() {
	var builder = document.getElementById('syslog_search_builder');
	if (!builder || $('#search_mode').val() !== 'logical') {
		return true;
	}
	var inputs = builder.querySelectorAll('.syslogSearchText');
	if (inputs.length === 1 && inputs[0].value === '' && builder.querySelectorAll('.syslogSearchRow').length === 1 &&
		(!builder.searchRows[0].field || builder.searchRows[0].field === 'message') &&
		(!builder.searchRows[0].operator || builder.searchRows[0].operator === 'contains') && !builder.searchRows[0].negative) {
		$('#rfilter').val('');
		return true;
	}
	for (var input of inputs) {
		if (!input.reportValidity()) {
			return false;
		}
	}
	$('#rfilter').val(syslogSearchExpression(builder.searchRows));
	return true;
}

function initSyslogSearchDates(container) {
	container.querySelectorAll('.syslogSearchDate').forEach(function(input) {
		$(input).datetimepicker({
			minuteGrid: 10,
			stepMinute: 1,
			showAnim: 'slideDown',
			numberOfMonths: 1,
			timeFormat: 'HH:mm:ss',
			dateFormat: 'yy-mm-dd',
			showButtonPanel: false,
			onSelect: function(value) {
				input.value = value;
				input.dispatchEvent(new Event('change'));
			}
		});
	});
}

function initSyslogSearchBuilder() {
	var builder = document.getElementById('syslog_search_builder');
	if (!builder) {
		return;
	}
	var labels = builder.dataset;
	var tree = JSON.parse(labels.tree || 'null');
	builder.searchRows = syslogSearchRows(tree);
	if (!builder.searchRows.length) {
		builder.searchRows.push({join: 'AND', negative: false, value: $('#rfilter').val() || ''});
	}

	function element(tag, className, text) {
		var node = document.createElement(tag);
		node.className = className;
		if (text) {
			node.textContent = text;
		}
		return node;
	}
	function select(options, value, label, onChange) {
		var node = element('select', '');
		node.setAttribute('aria-label', label);
		options.forEach(function(option) {
			var item = element('option', '', option[1]);
			item.value = option[0];
			node.appendChild(item);
		});
		node.value = value;
		// Cacti selectmenu emits a jQuery change event; this also handles native selects.
		$(node).on('change', function() { onChange(node.value); });
		return node;
	}
	function render(container, rows) {
		// Let jQuery dispose themed selectmenu widgets before rebuilding rows.
		$(container).empty();
		rows.forEach(function(row, index) {
			var line = element('div', 'syslogSearchRow' + (row.rows ? ' syslogSearchGroupRow' : ''));
			var connector = element('div', 'syslogSearchConnector');
			if (index) {
				connector.appendChild(select([['AND', 'AND'], ['OR', 'OR']], row.join, 'AND / OR', function(value) { row.join = value; }));
			}
			line.appendChild(connector);
			if (row.rows) {
				line.appendChild(select([['0', labels.match], ['1', labels.exclude]], row.negative ? '1' : '0', labels.message, function(value) { row.negative = value === '1'; }));
				var group = element('div', 'syslogSearchGroup');
				render(group, row.rows);
				line.appendChild(group);
			} else {
				var fields = JSON.parse(labels.fields || '{"message":"Message","host":"Host"}');
				line.appendChild(select(Object.entries(fields), row.field || 'message', 'Field', function(value) {
					row.field = value;
					row.operator = value === 'seq' || value.endsWith('_id') || value === 'logtime' ? '=' : 'contains';
					render(container, rows);
					initSyslogSearchDates(container);
				}));
				var numeric = row.field === 'seq' || (row.field || '').endsWith('_id') || row.field === 'logtime';
				var operators = numeric ? ['=', '!=', '>', '>=', '<', '<='] : ['contains', '=', '!=', 'like'];
				line.appendChild(select(operators.map(function(op) { return [op, row.field === 'logtime' && op === '>=' ? 'From (>=)' : row.field === 'logtime' && op === '<=' ? 'To (<=)' : op]; }), row.operator || 'contains', 'Operator', function(value) { row.operator = value; }));
				line.appendChild(select([['0', labels.match], ['1', labels.exclude]], row.negative ? '1' : '0', labels.message, function(value) { row.negative = value === '1'; }));
				var input = element('input', 'syslogSearchText');
				input.type = 'text';
				input.size = 35;
				input.placeholder = row.field === 'logtime' ? 'YYYY-MM-DD HH:MM:SS' : labels.placeholder || labels.message;
				input.required = true;
				input.value = row.value;
				input.setAttribute('aria-label', fields[row.field || 'message']);
				input.addEventListener('input', function() { row.value = input.value; });
				input.addEventListener('change', function() { row.value = input.value; });
				if (row.field === 'logtime') {
					input.className += ' syslogSearchDate';
				}
				line.appendChild(input);
			}
			var remove = element('button', 'syslogSearchRemove', '\u00d7');
			remove.type = 'button';
			remove.title = labels.remove;
			remove.setAttribute('aria-label', labels.remove);
			remove.addEventListener('click', function() {
				rows.splice(index, 1);
				if (!rows.length) {
					rows.push({join: 'AND', negative: false, value: ''});
				}
				render(container, rows);
				initSyslogSearchDates(container);
				container.querySelector('.syslogSearchText').focus();
			});
			line.appendChild(remove);
			container.appendChild(line);
		});
		var actions = element('div', 'syslogSearchActions');
		['AND', 'OR', 'NOT'].forEach(function(operator) {
			var button = element('button', 'syslogSearchAdd', operator);
			button.setAttribute('aria-label', operator);
			button.type = 'button';
			button.addEventListener('click', function() {
				rows.push({join: operator === 'OR' ? 'OR' : 'AND', negative: operator === 'NOT', value: ''});
				render(container, rows);
				initSyslogSearchDates(container);
				var inputs = container.querySelectorAll('.syslogSearchText');
				inputs[inputs.length - 1].focus();
			});
			actions.appendChild(button);
		});
		container.appendChild(actions);
	}
	render(builder, builder.searchRows);
	initSyslogSearchDates(builder);
	$('#rfilter').toggle(false);
	// Go/Enter share custom validation, which permits a single empty search row.
	$('#syslog_form').attr('novalidate', 'novalidate');
}

/** Submit filter values in a CSRF-protected POST body, including downloads. */
function postSyslog(data) {
	var form = document.createElement('form');
	form.method = 'post';
	form.action = 'syslog.php';
	data.tab = window.pageTab || 'syslog';
	data.__csrf_magic = csrfMagicToken;
	Object.keys(data).forEach(function(name) {
		var input = document.createElement('input');
		input.type = 'hidden';
		input.name = name;
		input.value = data[name];
		form.appendChild(input);
	});
	document.body.appendChild(form);
	form.submit();
	form.remove();
}

function syslogFilterData() {
	var data = {search_mode: 'logical', rfilter: $('#rfilter').val(), page: 1};
	['rows', 'trimval', 'removal', 'refresh', 'grouping'].forEach(function(name) {
		if ($('#' + name).length) data[name] = $('#' + name).val();
	});
	return data;
}

function applyFilter() {
	if (syncSyslogSearchBuilder()) postSyslog(syslogFilterData());
}

function exportRecords() {
	if (!syncSyslogSearchBuilder()) return;
	var data = syslogFilterData();
	data.export = 'true';
	postSyslog(data);
	Pace.stop();
}

function clearFilter() {
	postSyslog({clear: 'true'});
}

/**
 * Save syslog filter settings
 */
function saveSettings() {
	var strURL  = 'syslog.php';
	var data    = {action: 'save', tab: window.pageTab || 'syslog'};

	data.trimval      = $('#trimval').val();
	data.rows         = $('#rows').val();
	data.removal      = $('#removal').val();
	data.refresh      = $('#refresh').val();
	data.__csrf_magic = csrfMagicToken;


	$.post(strURL, data).done(function() {
		$('#text').show().text('Filter Settings Saved').fadeOut(2000);
	});
}

/**
 * Initialize main syslog view
 * @param {object} config - Configuration object containing pageTab
 */
function initSyslogMain(config) {
	var pageTab   = config.pageTab || '';

	// Make pageTab global for other functions
	window.pageTab = pageTab;

	$(function() {
		initSyslogSearchBuilder();
		$('#syslog_form').submit(function(event) {
			event.preventDefault();
			event.stopImmediatePropagation();
			applyFilter();
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
