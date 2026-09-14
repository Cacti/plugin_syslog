/**
 * Syslog Plugin - JavaScript Functions
 * Consolidated functions from inline JavaScript in PHP files
 */

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

function initSyslogCompactSearch() {
	var form = document.getElementById('syslog_form');
	if (!form) return;
	var builder = document.getElementById('syslog_search_builder');

	var actions = document.createElement('div');
	actions.className = 'syslogQueryActions';
	actions.append(document.getElementById('go'), document.getElementById('clear'));
	// The results limit scopes the search itself, so keep it beside the search buttons.
	var limit = document.querySelector('.syslogResultsLimit');
	if (limit) actions.append(limit);
	var query = document.createElement('div');
	query.className = 'syslogQueryLayout';
	builder.before(query);
	query.append(builder, actions);

	var toolbar = document.createElement('div');
	toolbar.className = 'syslogResultsToolbar';
	form.append(toolbar);
	var status = document.createElement('span');
	status.id = 'syslog_view_summary';
	toolbar.append(status);
	var options = document.getElementById('syslog_view_options');
	toolbar.append(options);
	options.querySelector('.syslogMenuBody').append(document.getElementById('save'), document.getElementById('text'));
	document.getElementById('text').setAttribute('role', 'status');
	var refresh = document.getElementById('refresh').closest('.syslogSearchOption');
	toolbar.append(refresh, document.getElementById('refresh_results'), document.getElementById('export'));
	var summaries = ['rows', 'removal', 'grouping'].map(function(id) {
		var select = document.getElementById(id);
		return select && select.selectedOptions ? select.selectedOptions[0].textContent : '';
	}).filter(Boolean);
	status.textContent = summaries.join(' · ');
	$('#refresh_results').off('click').on('click', refreshResults);
	var summary = document.getElementById('syslog_search_summary');
	summary.textContent = document.getElementById('rfilter').value || builder.dataset.message;
	try { if (localStorage.getItem('syslog.search.collapsed') === 'true') toggleSyslogSearch(false); } catch (error) { /* Storage may be disabled. */ }
	if (document.getElementById('logical_search_error').textContent.trim()) toggleSyslogSearch(true);
	form.addEventListener('keydown', function(event) {
		if (event.key === 'Escape') form.querySelectorAll('details[open]').forEach(function(menu) { menu.open = false; });
	});
}

/** Inspect complete messages locally; never inject log content as HTML. */
function initSyslogWorkspace() {
	$(function() {
		var workspace = document.getElementById('syslog_workspace');
		var pane = document.getElementById('syslog_message_details');
		if (!workspace || !pane) return;
		var active, details;
		function close() {
			pane.hidden = true;
			workspace.classList.remove('has-details');
			if (active) {
				active.setAttribute('aria-expanded', 'false');
				active.closest('tr').classList.remove('syslogSelected');
				active.focus();
			}
		}
		workspace.querySelectorAll('.syslogMessageOpen').forEach(function(button) {
			var row = button.closest('tr');
			row.removeAttribute('title');
			row.classList.add('syslogRowOpen');
			var data = JSON.parse(button.dataset.message);
			// Show facility and severity through badges, leaving message backgrounds neutral.
			[
				['facility', 'syslogFacility'],
				['severity', 'syslogSeverity']
			].forEach(function(item) {
				var value = String(data[item[0]] || '');
				Array.from(row.cells).forEach(function(cell) {
					if (value && cell.textContent.trim().toLowerCase() === value.toLowerCase() && !cell.querySelector('button, a, .syslogFacility, .syslogSeverity')) {
						var badge = document.createElement('span');
						badge.className = item[1] + ' ' + item[1] + '-' + value.toLowerCase().replace(/[^a-z]/g, '');
						badge.textContent = cell.textContent;
						cell.replaceChildren(badge);
					}
				});
			});
			function open() {
				if (active) { active.setAttribute('aria-expanded', 'false'); active.closest('tr').classList.remove('syslogSelected'); }
				active = button;
				details = data;
				pane.querySelectorAll('[data-detail]').forEach(function(node) { node.textContent = data[node.dataset.detail] || '—'; });
				pane.querySelectorAll('[data-rule]').forEach(function(link) {
					var url = (data.rules || {})[link.dataset.rule];
					link.hidden = !url;
					if (url) link.setAttribute('href', url);
					else link.removeAttribute('href');
				});
				document.getElementById('syslog_details_raw').textContent = data.message;
				document.getElementById('syslog_copy_status').textContent = '';
				pane.querySelector('[data-filter-detail="program"]').disabled = !data.program;
				pane.querySelector('[data-filter-detail="host"]').disabled = !data.device;
				pane.hidden = false;
				workspace.classList.add('has-details');
				row.classList.add('syslogSelected');
				button.setAttribute('aria-expanded', 'true');
				pane.focus({preventScroll: true});
			}
			// The message button keeps keyboard access; the rest of the row opens the pane too.
			button.addEventListener('click', function(event) {
				event.stopPropagation();
				open();
			});
			row.addEventListener('click', function(event) {
				if (event.target.closest('a, button, input, select, textarea, summary, .syslog-group-toggle')) return;
				open();
			});
		});
		document.getElementById('syslog_details_close').addEventListener('click', close);
		pane.addEventListener('keydown', function(event) { if (event.key === 'Escape') close(); });
		document.getElementById('syslog_details_copy').addEventListener('click', async function() {
			var status = document.getElementById('syslog_copy_status');
			try {
				if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(details.message);
				else {
					var text = document.createElement('textarea');
					text.value = details.message;
					pane.append(text);
					text.select();
					var copied = document.execCommand('copy');
					text.remove();
					if (!copied) throw new Error('Copy failed');
				}
				status.textContent = status.dataset.success;
			} catch (error) { status.textContent = status.dataset.error; }
			this.focus();
		});
		pane.querySelectorAll('[data-filter-detail]').forEach(function(button) {
			button.addEventListener('click', function() {
				var builder = document.getElementById('syslog_search_builder');
				var expression = syslogBuilderSync(builder);
				if (expression === null) return;
				var condition = syslogSearchExpression([{field: button.dataset.filterDetail, operator: '=', value: button.dataset.filterDetail === 'host' ? details.device : details.program}]);
				var query = (expression ? '(' + expression + ') AND ' : '') + condition;
				if (query === null) return;
				var data = syslogFilterData();
				data.rfilter = query;
				postSyslog(data);
			});
		});
	});
}

function initSyslogValueFilters() {
	document.querySelectorAll('.syslogValueFilter').forEach(function(button) {
		button.addEventListener('click', function(event) {
			event.stopPropagation();
			var builder = document.getElementById('syslog_search_builder');
			if (!builder) return;
			var expression = syslogBuilderSync(builder);
			if (expression === null) return;
			var condition = syslogSearchExpression([{field: button.dataset.filterField, operator: '=', value: button.dataset.filterValue}]);
			var data = syslogFilterData();
			data.rfilter = (expression ? '(' + expression + ') AND ' : '') + condition;
			postSyslog(data);
		});
	});
}

function toggleSyslogSearch(expanded) {
	var content = document.getElementById('syslog_search_content');
	var button = document.getElementById('syslog_search_toggle');
	if (!content || !button) return;
	if (typeof expanded !== 'boolean') expanded = content.hidden;
	content.hidden = !expanded;
	var summary = document.getElementById('syslog_search_summary');
	if (summary) summary.hidden = expanded;
	try { localStorage.setItem('syslog.search.collapsed', String(!expanded)); } catch (error) { /* Storage may be disabled. */ }
	button.setAttribute('aria-expanded', String(expanded));
	button.title = expanded ? button.dataset.hide : button.dataset.show;
	button.setAttribute('aria-label', button.title);
	var label = button.querySelector('span');
	if (label) label.textContent = button.title;
	button.querySelector('i').className = 'fa ' + (expanded ? 'fa-chevron-up' : 'fa-chevron-down');
}

/** Serialize a builder into an expression; null when a value is invalid. */
function syslogBuilderSync(builder) {
	var inputs = builder.querySelectorAll('.syslogSearchText');
	if (inputs.length === 1 && inputs[0].value === '' && builder.querySelectorAll('.syslogSearchRow').length === 1 &&
		(!builder.searchRows[0].field || builder.searchRows[0].field === 'message') &&
		(!builder.searchRows[0].operator || builder.searchRows[0].operator === 'contains') && !builder.searchRows[0].negative) {
		return '';
	}
	for (var input of inputs) {
		if (input.validity && !input.validity.valid && builder.id === 'syslog_search_builder') toggleSyslogSearch(true);
		if (!input.reportValidity()) {
			return null;
		}
	}
	return syslogSearchExpression(builder.searchRows);
}

function syncSyslogSearchBuilder() {
	var builder = document.getElementById('syslog_search_builder');
	if (!builder || $('#search_mode').val() !== 'logical') {
		return true;
	}
	var expression = syslogBuilderSync(builder);
	if (expression === null) {
		return false;
	}
	$('#rfilter').val(expression);
	return true;
}

/** Shared-template administration uses the same builder as the log view. */
function initSyslogTemplates() {
	var builder = document.getElementById('syslog_template_builder');
	if (builder) {
		// Read the active jQuery UI theme rather than imposing a plugin palette.
		var sample = $('<div class="ui-widget-content"><button class="ui-button ui-widget ui-state-default" type="button"></button></div>').hide().appendTo(document.body);
		var button = sample.find('button');
		var tokens = {
			'surface': sample.css('background-color'), 'card': sample.css('background-color'),
			'text': sample.css('color'), 'muted': sample.css('color'),
			'border': sample.css('border-top-color'), 'accent': button.css('color'),
			'tint': button.css('background-color')
		};
		Object.keys(tokens).forEach(function(key) { builder.style.setProperty('--search-' + key, tokens[key]); });
		sample.remove();
		initSyslogSearchBuilder(builder);
	}
	$('#syslog_template_form').attr('novalidate', 'novalidate').off('submit.syslogTemplates').on('submit.syslogTemplates', function(event) {
		var name = this.querySelector('[name="name"]');
		name.required = true;
		name.setCustomValidity(name.value.trim() ? '' : 'Enter a template name.');
		if (!name.reportValidity()) { event.preventDefault(); return; }
		if (builder) {
			var expression = syslogBuilderSync(builder);
			if (expression === null) { event.preventDefault(); return; }
			$('#template_search').val(expression);
		}
	});
	$('.syslogTemplateDelete').off('submit.syslogTemplates').on('submit.syslogTemplates', function(event) {
		if (this.dataset.confirmed === 'true') return;
		event.preventDefault();
		var form = this;
		$('<div>').text(form.dataset.confirm).dialog({
			modal: true, title: form.dataset.title, width: Math.min(440, window.innerWidth - 32),
			close: function() { $(this).dialog('destroy').remove(); },
			buttons: [
				{text: form.dataset.cancel, click: function() { $(this).dialog('close'); }},
				{text: form.dataset.delete, click: function() {
					form.dataset.confirmed = 'true';
					$(this).dialog('close');
					form.requestSubmit();
				}}
			]
		});
	});
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
			beforeShow: function() {
				// The picker is appended to <body>; raise it above a modal dialog
				// whose z-index jQuery UI may have bumped past the CSS default.
				var dialog = input.closest ? input.closest('.ui-dialog') : null;
				if (dialog) {
					setTimeout(function() {
						var z = parseInt(window.getComputedStyle(dialog).zIndex, 10);
						if (z) {
							var picker = document.getElementById('ui-datepicker-div');
							if (picker) picker.style.zIndex = z + 50;
						}
					}, 0);
				}
			},
			onSelect: function(value) {
				input.value = value;
				input.dispatchEvent(new Event('change'));
			}
		});
	});
}

function initSyslogSearchAutocomplete(input, field, row) {
	$(input).autocomplete({
		classes: {'ui-autocomplete': 'syslogSearchSuggestions'},
		minLength: field === 'message' ? 2 : 0,
		delay: 250,
		source: function(request, respond) {
			$.ajax({
				url: 'syslog.php', type: 'POST', dataType: 'json',
				data: {action: 'ajax_search_values', field: field, term: request.term,
					tab: window.pageTab || 'syslog', removal: $('#removal').val() || '-1',
					__csrf_magic: csrfMagicToken}
			}).done(respond).fail(function() { respond([]); });
		},
		select: function(event, ui) {
			input.value = ui.item.value;
			row.value = ui.item.value;
			return false;
		}
	}).on('focus', function() {
		if (field !== 'message' || input.value.length >= 2) $(input).autocomplete('search', input.value);
	});
}

function initSyslogSearchBuilder(builder, rows) {
	if (builder === undefined) {
		builder = document.getElementById('syslog_search_builder');
	}
	if (!builder) {
		return;
	}
	var labels = builder.dataset;
	var choices = JSON.parse(labels.choices || '{}');
	var configuredOperators = JSON.parse(labels.operators || '{}');
	if (!rows) {
		var tree = JSON.parse(labels.tree || 'null');
		rows = syslogSearchRows(tree);
	}
	builder.searchRows = rows;
	if (!builder.searchRows.length) {
		builder.searchRows.push({join: 'AND', negative: false, value: builder.id === 'syslog_search_builder' ? ($('#rfilter').val() || '') : ''});
	}

	function element(tag, className, text) {
		var node = document.createElement(tag);
		node.className = className;
		if (labels.theme === 'cacti' && tag === 'button') node.className += ' ui-button ui-corner-all ui-widget';
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
		// The Cacti theme turns selects into selectmenu widgets; destroy them so
		// their body-appended menus are removed before rebuilding the rows.
		$(container).find('select').each(function() {
			if ($(this).selectmenu('instance')) {
				$(this).selectmenu('destroy');
			}
		});
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
					row.value = '';
					row.operator = configuredOperators[value] ? configuredOperators[value][0] :
						(choices[value] || value === 'seq' || value.endsWith('_id') || value === 'logtime' ? '=' : 'contains');
					render(container, rows);
					initSyslogSearchDates(container);
				}));
				var numeric = row.field === 'seq' || (row.field || '').endsWith('_id') || row.field === 'logtime';
				var operators = configuredOperators[row.field || 'message'] ||
					(row.field === 'logtime' ? ['last', '=', '!=', '>', '>=', '<', '<='] : numeric ? ['=', '!=', '>', '>=', '<', '<='] : ['contains', '=', '!=', 'like']);
				var inverse = {'=': '!=', '!=': '=', '>': '<=', '>=': '<', '<': '>=', '<=': '>'};
				var operatorOptions = [];
				if (row.operator && !operators.includes(row.operator)) operators.push(row.operator);
				operators.forEach(function(op) {
					var name = op === 'last' ? 'In the last' : row.field === 'logtime' && op === '>=' ? 'From (>=)' : row.field === 'logtime' && op === '<=' ? 'To (<=)' : op;
					operatorOptions.push([op, name]);
					if (!inverse[op]) operatorOptions.push(['NOT ' + op, op === 'contains' ? 'does not contain' : op === 'like' ? 'does not match pattern' : 'NOT ' + name]);
				});
				var selectedOperator = row.operator || 'contains';
				if (row.negative) selectedOperator = inverse[selectedOperator] || 'NOT ' + selectedOperator;
				line.appendChild(select(operatorOptions, selectedOperator, 'Operator', function(value) {
					var previous = row.operator;
					row.negative = value.startsWith('NOT ');
					row.operator = row.negative ? value.slice(4) : value;
					if (row.field === 'logtime' && previous !== row.operator && (previous === 'last' || row.operator === 'last')) {
						row.value = row.operator === 'last' ? '86400' : '';
						render(container, rows);
						initSyslogSearchDates(container);
					}
				}));
				var input = element('input', 'syslogSearchText');
				input.type = 'text';
				input.size = 35;
				input.placeholder = row.field === 'logtime' ? 'YYYY-MM-DD HH:MM:SS' :
					choices[row.field] ? 'Select or enter a value…' : labels.placeholder || labels.message;
				input.required = true;
				input.value = row.value;
				input.setAttribute('aria-label', fields[row.field || 'message']);
				if (numeric && row.field !== 'logtime') {
					input.pattern = '[0-9]+';
					input.inputMode = 'numeric';
					input.title = labels.integer || 'Enter a nonnegative integer';
				}
				input.addEventListener('input', function() { row.value = input.value; });
				input.addEventListener('change', function() { row.value = input.value; });
				if (row.field === 'logtime' && row.operator === 'last') {
					input = select([['3600', '1 hour'], ['21600', '6 hours'], ['86400', '24 hours'], ['604800', '7 days'], ['1209600', '2 weeks'], ['2592000', '30 days'], ['3months', '3 months'], ['6months', '6 months']], row.value, 'Date range', function(value) { row.value = value; });
					input.className = 'syslogSearchText';
					input.required = true;
				} else if (row.field === 'logtime') {
					input.className += ' syslogSearchDate';
				} else if (choices[row.field]) {
					// Suggestions assist entry without restricting searches to existing values.
					// Menus default to <body>, which a modal dialog can cover; the
					// wrapper is applied after the row joins the document below.
					$(input).autocomplete({
						classes: {'ui-autocomplete': 'syslogSearchSuggestions'},
						minLength: 0,
						source: choices[row.field].map(function(option) { return {value: option[0], label: option[1]}; }),
						select: function(event, ui) {
							input.value = ui.item.value;
							row.value = ui.item.value;
							return false;
						}
					}).on('focus', function() { $(input).autocomplete('search', ''); });
				} else {
					initSyslogSearchAutocomplete(input, row.field || 'message', row);
				}
				line.appendChild(input);
				if (input.classList.contains('syslogSearchText') && $(input).data('ui-autocomplete')) {
					// The widget was created before this row joined the document, so
					// a dialog wrapper could not be resolved then; attach the menu to
					// the enclosing modal dialog, whose z-index jQuery UI may raise
					// above body-appended menus.
					var wrapper = input.closest('.ui-dialog');
					var widget = $(input).data('ui-autocomplete');
					if (wrapper && !wrapper.contains(widget.menu.element[0])) {
						widget.menu.element.appendTo(wrapper);
					}
				}
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
		['AND', 'OR', 'NOT', 'Group'].forEach(function(operator) {
			var button = element('button', 'syslogSearchAdd', operator);
			button.setAttribute('aria-label', operator);
			button.type = 'button';
			button.addEventListener('click', function() {
				rows.push(operator === 'Group' ? {join: 'AND', negative: false, rows: [{join: 'AND', negative: false, value: ''}]} : {join: operator === 'OR' ? 'OR' : 'AND', negative: operator === 'NOT', value: ''});
				render(container, rows);
				initSyslogSearchDates(container);
				var inputs = container.querySelectorAll('.syslogSearchText');
				inputs[inputs.length - 1].focus();
			});
			actions.appendChild(button);
		});
		container.appendChild(actions);
		if (labels.theme === 'cacti') {
			$(container).find('select').each(function() {
				if (!$(this).selectmenu('instance')) {
					$(this).selectmenu({change: function(event, ui) { $(this).val(ui.item.value).trigger('change'); }});
				}
			});
		}
	}
	render(builder, builder.searchRows);
	initSyslogSearchDates(builder);
	if (builder.id === 'syslog_search_builder') {
		$('#rfilter').toggle(false);
		// Go/Enter share custom validation, which permits a single empty search row.
		$('#syslog_form').attr('novalidate', 'novalidate');
	}
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
	['rows', 'removal', 'refresh', 'grouping'].forEach(function(name) {
		if ($('#' + name).length) data[name] = $('#' + name).val();
	});
	return data;
}

function applyFilter() {
	if (syncSyslogSearchBuilder()) postSyslog(syslogFilterData());
}

/** Reload results with the current filter, keeping the active page. */
function refreshResults() {
	postSyslog({page: parseInt($('#page').val(), 10) || 1, refresh: $('#refresh').val()});
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

	data.rows         = $('#rows').val();
	data.removal      = $('#removal').val();
	data.refresh      = $('#refresh').val();
	data.__csrf_magic = csrfMagicToken;


	$.post(strURL, data).done(function() {
		$('#text').show().text('Filter Settings Saved').fadeOut(2000);
	});
}

/* ========================================================================
 * Saved Searches (predefined filters)
 * ======================================================================== */

/** Labels rendered by the server as data attributes on the saved search dialog. */
function savedSearchText() {
	var dialog = document.getElementById('syslog_saved_dialog');
	return dialog ? dialog.dataset : {};
}

/** POST a saved search action, then continue with the JSON result. */
function savedSearchPost(data, done) {
	data.tab = window.pageTab || 'syslog';
	data.__csrf_magic = csrfMagicToken;
	$.post('syslog.php', data, null, 'json').done(function(result) {
		if (result && result.error) {
			alert(result.error);
			return;
		}
		if (done) done(parseInt(result.id, 10));
	}).fail(function() {
		alert('Saved search request failed.');
	});
}

function savedSearchActive() {
	var value = $('#saved_search').val();
	return value && value !== '0' ? parseInt(value, 10) : 0;
}

/** Only the server's own permission stamp may take Delete away; missing flag defers to it. */
function savedSearchCanManage() {
	var id = savedSearchActive();
	var selected = document.getElementById('saved_search')?.selectedOptions[0];
	return !!(id && selected && selected.dataset.manage !== '0');
}

/** Never offer deletion for a template the current user cannot manage. */
function savedSearchButtons() {
	var canManage = savedSearchCanManage();
	$('#saved_edit').prop('disabled', !canManage);
	$('#saved_delete').prop('disabled', !canManage).toggle(canManage);

	var global = $('#saved_global');
	if (global.length) {
		global.prop('disabled', !canManage);
	}
}

/** Save all authored conditions, including relative or fixed date filters. */
function savedSearchExpression(builder) {
	return syslogBuilderSync(builder);
}

function initSavedSearches() {
	var dropdown = document.getElementById('saved_search');
	if (!dropdown) {
		return;
	}
	dropdown.dataset.active = dropdown.value;
	$('#saved_search').on('change', function() {
		var id = parseInt(this.value, 10);
		if (!id) return;
		postSyslog({saved: id});
	});
	$('#saved_new').click(function() { openSavedSearchDialog('new'); });
	$('#saved_edit').click(function() { openSavedSearchDialog('edit'); });
	$('#saved_delete').click(function() {
		var id = savedSearchActive();
		if (!savedSearchCanManage()) return;
		if (!window.confirm(savedSearchText().deleteConfirm)) return;
		savedSearchPost({action: 'saved_search_delete', id: id}, function() {
			postSyslog({});
		});
	});
	$('#saved_saveas').click(function() {
		$(this).closest('details').prop('open', false);
		if (!syncSyslogSearchBuilder()) return;
		savedSearchPrompt('', function(name) {
			savedSearchSave(savedSearchExpression(document.getElementById('syslog_search_builder')), name, function(id) {
				postSyslog({saved: id});
			});
		});
	});
	$('#saved_global').click(function() {
		var id = savedSearchActive();
		if (!id) return;
		savedSearchPost({action: 'saved_search_global', id: id}, function() {
			postSyslog({saved: id});
		});
	});
	savedSearchButtons();
}

function savedSearchPrompt(name, accept) {
	var text = savedSearchText();
	var input = document.getElementById('syslog_saved_prompt_name');
	input.value = name || '';
	$('#syslog_saved_prompt').dialog({
		modal: true,
		appendTo: 'body',
		width: 420,
		autoOpen: true,
		buttons: [
			{text: text.save, click: function() {
				var value = input.value.trim();
				if (!value) {
					input.focus();
					return;
				}
				$(this).dialog('close');
				accept(value);
			}},
			{text: text.cancel, click: function() { $(this).dialog('close'); }}
		]
	});
}

function savedSearchSave(expression, name, done) {
	savedSearchPost({
		action: 'saved_search_save', name: name, rfilter: expression,
		removal: $('#removal').val() || '1', grouping: $('#grouping').val() || '0'
	}, done);
}

function openSavedSearchDialog(mode) {
	var dialog = document.getElementById('syslog_saved_dialog');
	var main = document.getElementById('syslog_search_builder');
	var builder = document.getElementById('syslog_saved_builder');
	var text = savedSearchText();
	var rows;

	if (mode === 'edit' && main && main.searchRows) {
		rows = JSON.parse(JSON.stringify(main.searchRows));
	} else {
		rows = [{join: 'AND', negative: false, value: ''}];
	}

	initSyslogSearchBuilder(builder, rows);

	var selected = document.getElementById('saved_search').selectedOptions;
	var name = mode === 'edit' && selected && selected.length && selected[0].value !== '0' ? selected[0].textContent : '';

	$('#syslog_saved_dialog').dialog({
		modal: true,
		appendTo: 'body',
		width: Math.min(1040, $(window).width() - 40),
		title: mode === 'new' ? text.newTitle : text.editTitle,
		open: function() {
			// The builder renders before .dialog() creates its wrapper, so
			// suggestions appended to <body> stack behind the raised dialog;
			// move them inside the dialog's own stacking context.
			var wrapper = dialog.closest('.ui-dialog');
			$(builder).find('.syslogSearchText').each(function() {
				var widget = $(this).data('ui-autocomplete');
				if (widget && !wrapper.contains(widget.menu.element[0])) {
					widget.menu.element.appendTo(wrapper);
				}
			});
		},
		buttons: [
			{text: text.saveas, click: function() {
				var expression = syslogBuilderSync(builder);
				if (expression === null) return;
				// Close the editor first; stacking a second modal over it is unreliable.
				$(dialog).dialog('close');
				savedSearchPrompt(mode === 'edit' ? name : '', function(chosen) {
					savedSearchSave(savedSearchExpression(builder), chosen, function(id) {
						postSyslog({saved: id});
					});
				});
			}},
			{text: text.apply, click: function() {
				var expression = syslogBuilderSync(builder);
				if (expression === null) return;
				$(dialog).dialog('close');
				var data = syslogFilterData();
				data.rfilter = expression;
				if (data.rfilter === null) return;
				postSyslog(data);
			}},
			{text: text.cancel, click: function() { $(dialog).dialog('close'); }}
		]
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
		initSyslogSearchBuilder(document.getElementById('syslog_search_builder'));
		initSavedSearches();
		initSyslogCompactSearch();
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

/**
 * Invoke a whitelisted callback by bare identifier.
 *
 * Only accepts a simple identifier ([A-Za-z_$][A-Za-z0-9_$]*). Dotted
 * paths, arguments, and non-identifier characters are rejected so an
 * attacker who controls an onChange string cannot reach arbitrary
 * globals (eval, Function, fetch, ...). If the callback is unknown or
 * not a function, the call is skipped with a console warning.
 */
function syslogInvokeCallback(functionName) {
	if (typeof functionName !== 'string') {
		return;
	}

	let trimmed = functionName.trim();

	// Only tolerate an exact trailing "()" for legacy callers; reject any arguments.
	if (trimmed.endsWith('()')) {
		trimmed = trimmed.substring(0, trimmed.length - 2).trim();
	} else if (trimmed.indexOf('(') !== -1 || trimmed.indexOf(')') !== -1) {
		if (window.console && console.warn) {
			console.warn('syslog: refusing to invoke callback with arguments or invalid parentheses', functionName);
		}

		return;
	}

	if (!/^[A-Za-z_$][A-Za-z0-9_$]*$/.test(trimmed)) {
		if (window.console && console.warn) {
			console.warn('syslog: refusing to invoke non-identifier callback', functionName);
		}

		return;
	}

	const fn = window[trimmed];

	if (typeof fn !== 'function') {
		if (window.console && console.warn) {
			console.warn('syslog: callback is not a function', trimmed);
		}

		return;
	}

	return fn();
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
					syslogInvokeCallback(onChange);
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
