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

/**
 * Logtime presets, aligned with Cacti's timespan selector; the server's
 * syslog_search_time_presets() is the authority, and the log view passes its
 * translated labels in the builder's data-times attribute.
 */
var SYSLOG_TIME_PRESETS = {
	last: {'1800': '30 minutes', '3600': '1 hour', '7200': '2 hours', '14400': '4 hours', '21600': '6 hours', '43200': '12 hours',
		'86400': '1 day', '172800': '2 days', '259200': '3 days', '345600': '4 days', '604800': '1 week', '1209600': '2 weeks',
		'2592000': '30 days', '1months': '1 month', '2months': '2 months', '3months': '3 months', '4months': '4 months',
		'6months': '6 months', '1years': '1 year', '2years': '2 years'},
	during: {day_shift: 'Day Shift', this_day: 'This Day', this_week: 'This Week', this_month: 'This Month', this_year: 'This Year',
		previous_day: 'Previous Day', previous_week: 'Previous Week', previous_month: 'Previous Month', previous_year: 'Previous Year'}
};

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
	var trim = document.getElementById('trimval');
	toolbar.append(refresh);
	if (trim) toolbar.append(trim.closest('.syslogSearchOption'));
	toolbar.append(document.getElementById('refresh_results'), document.getElementById('export'));
	var summaries = ['rows', 'removal', 'grouping'].map(function(id) {
		var select = document.getElementById(id);
		return select && select.selectedOptions ? select.selectedOptions[0].textContent : '';
	}).filter(Boolean);
	status.textContent = summaries.join(' · ');
	$('#refresh_results').off('click').on('click', refreshResults);
	var pending = $(document).data('syslogFilterRequests') || new Set();
	$(document).data('syslogFilterRequests', pending);
	function showRefreshActivity() {
		$('#refresh_results i').toggleClass('fa-spin', pending.size > 0);
	}
	$(document).off('ajaxSend.syslogRefresh ajaxComplete.syslogRefresh')
		.on('ajaxSend.syslogRefresh', function(event, xhr, settings) {
			var url = new URL(settings.url, window.location.href);
			var data = new URLSearchParams(typeof settings.data === 'string' ? settings.data : '');
			var action = url.searchParams.get('action') || data.get('action');
			if (url.origin === window.location.origin && /\/syslog\.php$/.test(url.pathname) && (!action || action === 'view')) {
				pending.add(xhr);
				showRefreshActivity();
			}
		}).on('ajaxComplete.syslogRefresh', function(event, xhr) {
			pending.delete(xhr);
			showRefreshActivity();
		});
	showRefreshActivity();
	var summary = document.getElementById('syslog_search_summary');
	summary.textContent = document.getElementById('rfilter').value || builder.dataset.message;
	// Collapse the filter by default; only a saved 'false' preference keeps it open.
	var keepSearchOpen = false;
	try { keepSearchOpen = localStorage.getItem('syslog.search.collapsed') === 'false'; } catch (error) { /* Storage may be disabled. */ }
	if (!keepSearchOpen) toggleSyslogSearch(false);
	if (document.getElementById('logical_search_error').textContent.trim()) toggleSyslogSearch(true);
	form.addEventListener('keydown', function(event) {
		if (event.key === 'Escape') form.querySelectorAll('details[open]').forEach(function(menu) { menu.open = false; });
	});
	syslogSingleOpenMenu(form);
}

/**
 * Keep one menu open at a time, like select menus: opening a Manage or View
 * options menu closes the other and any open dropdown, and pressing anywhere
 * outside an open menu closes it. Cacti renders selects with Select2 or jQuery
 * UI selectmenu, which both append their option lists to <body>, so a press
 * inside one does not count as outside.
 */
function syslogSingleOpenMenu(form) {
	function closeSelects() {
		$(form).find('select').each(function() {
			if ($(this).data('select2')) $(this).select2('close');
			else if ($(this).selectmenu('instance')) $(this).selectmenu('close');
		});
	}
	form.querySelectorAll('details.syslogMenu').forEach(function(menu) {
		menu.addEventListener('toggle', function() {
			if (!menu.open) return;
			form.querySelectorAll('details.syslogMenu[open]').forEach(function(other) { if (other !== menu) other.open = false; });
			closeSelects();
		});
	});
	$(document).off('mousedown.syslogMenus').on('mousedown.syslogMenus', function(event) {
		if (!document.body.contains(form)) {
			$(document).off('mousedown.syslogMenus');
			return;
		}
		if ($(event.target).closest('.ui-selectmenu-menu, .select2-container, .select2-dropdown').length) return;
		form.querySelectorAll('details.syslogMenu[open]').forEach(function(menu) {
			if (!menu.contains(event.target)) menu.open = false;
		});
	});
	// A dropdown opening closes any open Manage or View options menu that does not contain it.
	$(form).off('selectmenuopen.syslogMenus select2:opening.syslogMenus').on('selectmenuopen.syslogMenus select2:opening.syslogMenus', 'select', function() {
		var select = this;
		form.querySelectorAll('details.syslogMenu[open]').forEach(function(menu) { if (!menu.contains(select)) menu.open = false; });
	});
}

/**
 * Message details flyout. Clicking a row shows the row's own data at once,
 * then dispatches syslog.php?action=message_details for the stored record and
 * its matching recommendations. Log content is only ever inserted as text.
 */
function initSyslogWorkspace() {
	$(function() {
		var workspace = document.getElementById('syslog_workspace');
		// A flyout left on <body> by the previous Syslog page is stale now; a
		// refresh reopens the record it was showing.
		var reopen = '';
		document.querySelectorAll('body > #syslog_message_details').forEach(function(stale) {
			if (!stale.hidden && stale.dataset.record) reopen = stale.dataset.record;
			stale.remove();
		});
		var pane = workspace ? workspace.querySelector('#syslog_message_details') : null;
		if (!workspace || !pane) return;
		// Themes such as Midwinter lift parts of the page above #main's stacking
		// context, which would cover a flyout nested inside it, so the flyout
		// lives on <body> and takes the theme tokens there.
		document.body.append(pane);
		applySyslogTheme(pane);
		var active, details = {}, request = 0;
		var recSection = document.getElementById('syslog_details_recommendations');
		var recBody = document.getElementById('syslog_details_rec_body');
		var linkButton = document.getElementById('syslog_details_link');
		var status = document.getElementById('syslog_copy_status');

		// Start at the top of Cacti's content column, below every theme's
		// header and breadcrumb bar, so the navigation stays usable.
		function place() {
			var anchor = document.getElementById('navigation_right') || document.getElementById('cactiPageHead');
			var top = anchor ? anchor.getBoundingClientRect()[anchor.id === 'navigation_right' ? 'top' : 'bottom'] : 0;
			pane.style.setProperty('--syslog-flyout-top', Math.max(0, Math.round(top)) + 'px');
		}

		function deselect() {
			if (!active) return;
			active.setAttribute('aria-expanded', 'false');
			active.closest('tr').classList.remove('syslogSelected');
		}

		function close() {
			request++;
			pane.hidden = true;
			workspace.classList.remove('has-details');
			deselect();
			if (active) active.focus();
			active = null;
		}

		function badge(node, value, kind) {
			var pill = document.createElement('span');
			pill.className = kind + ' ' + kind + '-' + value.toLowerCase().replace(/[^a-z]/g, '');
			pill.textContent = value;
			node.replaceChildren(pill);
		}

		function render(data) {
			details = data;
			pane.querySelectorAll('[data-detail]').forEach(function(node) {
				var key = node.dataset.detail;
				var value = data[key] === undefined || data[key] === null ? '' : String(data[key]);
				var optional = key === 'alert' || key === 'id';
				node.hidden = optional && value === '';
				pane.querySelector('[data-detail-row="' + key + '"]').hidden = node.hidden;
				if (value !== '' && key === 'severity') badge(node, value, 'syslogSeverity');
				else if (value !== '' && key === 'facility') badge(node, value, 'syslogFacility');
				else node.textContent = value || '—';
			});
			document.getElementById('syslog_details_subtitle').textContent = [data.device, data.received].filter(Boolean).join(' · ');
			pane.querySelectorAll('[data-rule]').forEach(function(link) {
				var url = (data.rules || {})[link.dataset.rule];
				link.hidden = !url;
				if (url) link.setAttribute('href', url);
				else link.removeAttribute('href');
			});
			document.getElementById('syslog_details_raw').textContent = data.message || '';
			status.textContent = '';
			pane.querySelector('[data-filter-detail="program"]').disabled = !data.program;
			pane.querySelector('[data-filter-detail="host"]').disabled = !data.device;
			linkButton.hidden = !(data.source && data.id);
		}

		function note(text) {
			var paragraph = document.createElement('p');
			paragraph.className = 'syslogFlyoutNote';
			paragraph.textContent = text;
			recBody.replaceChildren(paragraph);
		}

		// Rows that name their record are dispatched by id; anything else falls
		// back to a message-only recommendation lookup.
		function load(data) {
			var token = ++request;
			note(recSection.dataset.loading);
			var call = data.source && data.id
				? $.ajax({url: 'syslog.php', type: 'GET', dataType: 'json', data: {action: 'message_details', source: data.source, id: data.id, logtime: data.received || ''}})
				: $.ajax({url: 'syslog.php', type: 'POST', dataType: 'json', data: {action: 'recommendation', message: data.message || '', __csrf_magic: csrfMagicToken}});
			call.done(function(result) {
				if (token !== request) return;
				if (result && result.record) render($.extend({}, result.record, {rules: result.rules || {}}));
				var matches = result && Array.isArray(result.recommendations) ? result.recommendations : [];
				if (!matches.length) {
					note(recSection.dataset.none);
					return;
				}
				recBody.replaceChildren();
				syslogRecommendationCards($(recBody), matches, result.labels || {}, false);
			}).fail(function() {
				if (token === request) note(recSection.dataset.error);
			});
		}

		function show(data) {
			place();
			render(data);
			pane.dataset.record = data.source && data.id ? data.source + ':' + data.id : '';
			pane.hidden = false;
			workspace.classList.add('has-details');
			load(data);
			pane.focus({preventScroll: true});
		}

		workspace.querySelectorAll('.syslogMessageOpen').forEach(function(button) {
			var row = button.closest('tr');
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
		});
		// Delegation also covers group members loaded after the initial render.
		$(workspace).off('click.syslogMessage').on('click.syslogMessage', 'tr.syslogRow', function(event) {
			var button = this.querySelector('.syslogMessageOpen');
			if (!button || event.target.closest('a, button:not(.syslogMessageOpen), input, select, textarea, summary')) return;
			event.stopPropagation();
			deselect();
			active = button;
			this.classList.add('syslogSelected');
			button.setAttribute('aria-expanded', 'true');
			show(JSON.parse(button.dataset.message));
		});

		/**
		 * Open the flyout for any record by table and sequence id, for example
		 * from a shared link, even when it is not on the current page.
		 */
		window.syslogShowMessageDetails = function(source, id, logtime) {
			deselect();
			active = null;
			show({source: String(source), id: String(id), received: logtime || '', message: ''});
		};

		var linked = /^(main|remove|alert):(\d+)$/.exec(reopen || new URLSearchParams(window.location.search).get('details') || '');
		if (linked) window.syslogShowMessageDetails(linked[1], linked[2]);

		syslogEnableColumnResize(workspace);

		$(window).off('resize.syslogFlyout').on('resize.syslogFlyout', function() { if (!pane.hidden) place(); });
		// Cacti's AJAX navigation replaces #main but not <body>, so drop the
		// flyout once the Syslog page is gone. A refreshed Syslog page takes the
		// flyout over itself and reopens its record.
		$(document).off('ajaxComplete.syslogFlyout').on('ajaxComplete.syslogFlyout', function() {
			if (!document.getElementById('syslog_workspace')) {
				pane.remove();
				$(document).off('ajaxComplete.syslogFlyout');
			}
		});
		document.getElementById('syslog_details_close').addEventListener('click', close);
		pane.addEventListener('keydown', function(event) { if (event.key === 'Escape') close(); });

		async function copy(text, button) {
			try {
				if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(text);
				else {
					var area = document.createElement('textarea');
					area.value = text;
					pane.append(area);
					area.select();
					var copied = document.execCommand('copy');
					area.remove();
					if (!copied) throw new Error('Copy failed');
				}
				status.textContent = status.dataset.success;
			} catch (error) { status.textContent = status.dataset.error; }
			button.focus();
		}

		document.getElementById('syslog_details_copy').addEventListener('click', function() {
			void copy(details.message || '', this);
		});
		linkButton.addEventListener('click', function() {
			var url = new URL(window.location.pathname, window.location.origin);
			url.searchParams.set('tab', details.source === 'alert' ? 'alerts' : 'syslog');
			url.searchParams.set('details', details.source + ':' + details.id);
			void copy(url.toString(), this);
		});
		pane.querySelectorAll('[data-filter-detail]').forEach(function(button) {
			button.addEventListener('click', function() {
				var field = button.dataset.filterDetail;
				syslogAddFilterCondition(field, field === 'host' ? details.device : details.program);
			});
		});
	});
}

// The message header sorts on message in System Logs and logmsg in Alert Logs.
var SYSLOG_MESSAGE_SORT = '.sortinfo[sort-column="message"], .sortinfo[sort-column="logmsg"]';

/**
 * Make Cacti's column resizing work on the log tables.
 *
 * The message cell claims all spare width (width 100%, which also gives long
 * messages their ellipsis), and under the automatic table layout that squeezes
 * every other column to its content, so widths set by dragging were ignored.
 * Pin each column at its rendered width, leave the message column free and
 * switch to the fixed layout, which honors header widths. The message column
 * then absorbs any change.
 *
 * Cacti's resize callback pins preceding columns, including the message, and
 * does not bound the combined widths. Keep that column flexible and limit
 * each drag to the space available inside the results area instead.
 *
 * Releasing a drag over the header text also fires a click, which Cacti's
 * sortable headers treat as a sort, so that one click is swallowed.
 */
function syslogEnableColumnResize(workspace) {
	var fitTables = [];
	workspace.querySelectorAll('tr.tableHeader').forEach(function(header) {
		var table = header.closest('table');
		if (!table || table.classList.contains('syslogFixedColumns')) return;
		var cells = Array.from(header.children);
		var message = cells.find(function(cell) { return cell.querySelector(SYSLOG_MESSAGE_SORT); });
		if (!message) return;
		var main = table.closest('.syslogResultsMain');
		var indicator = cells.find(function(cell) { return cell.querySelector('.syslogRecommendationHeading'); });
		var minimumMessage = 80;
		var widths = cells.map(function(cell) { return $(cell).outerWidth(); });
		var chrome = Math.max(0, table.getBoundingClientRect().width - widths.reduce(function(sum, width) { return sum + width; }, 0));
		cells.forEach(function(cell, index) {
			if (cell === message) cell.style.removeProperty('width');
			else $(cell).outerWidth(cell === indicator ? 20 : widths[index]);
		});
		table.classList.add('syslogFixedColumns');

		function fit() {
			message.style.removeProperty('width');
			if (indicator) $(indicator).outerWidth(20);
			var fixed = cells.filter(function(cell) { return cell !== message && cell !== indicator; });
			var available = Math.max(main.clientWidth, parseFloat(getComputedStyle(table).minWidth) || 0) - chrome;
			var budget = available - minimumMessage - (indicator ? 20 : 0);
			var total = fixed.reduce(function(sum, cell) { return sum + $(cell).outerWidth(); }, 0);
			if (total > budget) {
				var spare = total - fixed.length * 20;
				fixed.forEach(function(cell) {
					$(cell).outerWidth(20 + Math.max(0, $(cell).outerWidth() - 20) * Math.max(0, budget - fixed.length * 20) / spare);
				});
			}
		}
		fit();
		fitTables.push(fit);

		var resized = 0;
		$(header).on('resizestart', 'th', function(event, ui) {
			resized = Date.now();
			fit();
			var active = this;
			var initial = cells.map(function(cell) { return $(cell).outerWidth(); });
			var index = cells.indexOf(active);
			var messageWidth = $(message).outerWidth();
			var padding = $(active).outerWidth() - $(active).width();
			var donor = message.nextElementSibling;
			var donorWidth = donor ? $(donor).outerWidth() : 0;
			var originalWidth = ui.originalSize.width;
			// Replace only this log header's core callbacks; retain core width persistence.
			$(active).resizable('option', {
				minWidth: (active === message ? minimumMessage : 20) - padding,
				maxWidth: active === message ? originalWidth + donorWidth - 20 :
					(active === indicator ? 20 : initial[index] + messageWidth - minimumMessage) - padding,
				resize: function(event, ui) {
					resized = Date.now();
					cells.forEach(function(cell, index) {
						if (cell !== message) $(cell).outerWidth(initial[index]);
					});
					if (active === message) {
						if (donor) $(donor).outerWidth(donorWidth - (ui.size.width - originalWidth));
					} else {
						$(active).width(ui.size.width);
					}
					message.style.removeProperty('width');
					active.style.height = 'auto';
				},
				stop: function() {
					resized = Date.now();
					fit();
					saveTableWidths(false);
					message.style.removeProperty('width');
				}
			});
		});
		header.addEventListener('click', function(event) {
			if (Date.now() - resized < 500) {
				event.stopPropagation();
				event.preventDefault();
			}
		}, true);
	});
	$(window).off('resize.syslogColumns').on('resize.syslogColumns', function() {
		if (!workspace.isConnected) {
			$(window).off('resize.syslogColumns');
			return;
		}
		fitTables.forEach(function(fit) { fit(); });
	});
	// Core may restore persisted widths after the page's inline initialization.
	window.requestAnimationFrame(function() { fitTables.forEach(function(fit) { fit(); }); });
}

function initSyslogValueFilters(root) {
	var buttons = Array.from((root || document).querySelectorAll('.syslogValueFilter'));
	buttons.forEach(function(button) {
		button.addEventListener('click', function(event) {
			event.stopPropagation();
			syslogAddFilterCondition(button.dataset.filterField, button.dataset.filterValue);
		});
	});
	// Mark values the search is already limited to; clicking them adds nothing.
	// The search builder initializes on document ready, so mark after it.
	$(function() {
		var builder = document.getElementById('syslog_search_builder');
		buttons.forEach(function(button) {
			var active = builder && syslogFilterInScope(builder.searchRows, button.dataset.filterField, button.dataset.filterValue);
			button.classList.toggle('syslogValueFilterActive', !!active);
			button.setAttribute('aria-pressed', active ? 'true' : 'false');
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
	button.classList.toggle('ui-state-active', expanded);
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

/** Read the active jQuery UI theme into plugin color tokens, so the scoped
 *  Syslog surfaces follow whatever Cacti theme is live instead of a baked
 *  plugin palette. */
function syslogThemeTokens() {
	var sample = $('<div class="ui-widget-content"><button class="ui-button ui-widget ui-state-default" type="button"></button><div class="ui-widget-header"></div></div>').hide().appendTo(document.body);
	var button = sample.find('button');
	var header = sample.find('.ui-widget-header');

	// Sample the live html_start_box title bar so Status card headers paint with
	// the active theme's native Cacti table header instead of a generic tint.
	var box       = $('<div class="cactiTable"><div class="cactiTableTitleRow"><div class="cactiTableTitle"><span></span></div></div></div>').hide().appendTo(document.body);
	var titleRow  = box.find('.cactiTableTitleRow');
	var title     = box.find('.cactiTableTitle');
	var headerBg  = titleRow.css('background-color');
	var headerImg = titleRow.css('background-image');
	var headerFg  = title.css('color');
	var transparent = (!headerBg || /rgba?\([^)]*,\s*0\s*\)/.test(headerBg));
	if ((!headerImg || headerImg === 'none') && transparent) {
		headerBg  = header.css('background-color');
		headerImg = header.css('background-image');
		headerFg  = header.css('color');
	}

	var tokens = {
		'surface': sample.css('background-color'), 'card': sample.css('background-color'),
		'text': sample.css('color'), 'muted': sample.css('color'),
		'border': sample.css('border-top-color'), 'accent': button.css('color'),
		'tint': button.css('background-color'),
		'primary': header.css('background-color'), 'on-primary': header.css('color'),
		'header-bg': headerBg, 'header-image': headerImg, 'header-fg': headerFg
	};

	// Themes such as Paper Plane draw buttons as light text on a dark fill, so
	// the sampled button text vanishes on the panel surface. Fall back to the
	// body text and a faint text-colored tint when the accent is unreadable.
	if (syslogColorContrast(tokens.accent, tokens.surface) < 3) {
		var text = syslogParseColor(tokens.text);
		tokens.accent = tokens.text;
		if (text) {
			tokens.tint = 'rgba(' + text.join(', ') + ', 0.1)';
		}
	}

	box.remove();
	sample.remove();
	return tokens;
}

/** Parse an rgb()/rgba() color string into [r, g, b], or null when unusable. */
function syslogParseColor(color) {
	var m = /rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)(?:[\s,/]+([\d.]+))?/.exec(color || '');
	if (!m || (m[4] !== undefined && +m[4] === 0)) {
		return null;
	}
	return [+m[1], +m[2], +m[3]];
}

/** WCAG contrast ratio of two colors; Infinity when either cannot be parsed. */
function syslogColorContrast(a, b) {
	var ca = syslogParseColor(a);
	var cb = syslogParseColor(b);
	if (!ca || !cb) {
		return Infinity;
	}
	function luminance(c) {
		return c.reduce(function(sum, v, i) {
			v /= 255;
			v = v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
			return sum + v * [0.2126, 0.7152, 0.0722][i];
		}, 0);
	}
	var la = luminance(ca);
	var lb = luminance(cb);
	return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

/** True when a sampled surface color is dark, so scoped CSS can flip tint sets. */
function syslogSurfaceIsDark(color) {
	var m = /rgba?\(\s*(\d+)[\s,]+(\d+)[\s,]+(\d+)/.exec(color || '');
	if (!m) {
		return false;
	}
	return (0.299 * +m[1] + 0.587 * +m[2] + 0.114 * +m[3]) < 128;
}

/** Apply the sampled theme tokens (and a light/dark flag) to a scope element. */
function applySyslogTheme(el) {
	if (!el) {
		return;
	}
	var tokens = syslogThemeTokens();
	Object.keys(tokens).forEach(function(key) { el.style.setProperty('--search-' + key, tokens[key]); });
	el.setAttribute('data-mode', syslogSurfaceIsDark(tokens.surface) ? 'dark' : 'light');
}

/** Shared-template administration uses the same builder as the log view. */
function initSyslogTemplates() {
	var builder = document.getElementById('syslog_template_builder');
	if (builder) {
		applySyslogTheme(builder);
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
}

function importSavedSearch() {
	var strURL = 'syslog_saved_searches.php?action=import&header=false';
	loadPageNoHeader(strURL);
}

function applyFilterSavedSearches() {
	var strURL = 'syslog_saved_searches.php?filter='+encodeURIComponent($('#filter').val())+'&rows='+$('#rows').val()+'&page='+$('#page').val()+'&header=false';
	loadPageNoHeader(strURL);
}

function clearFilterSavedSearches() {
	var strURL = 'syslog_saved_searches.php?clear=1&header=false';
	loadPageNoHeader(strURL);
}

function initSyslogSavedSearches() {
	$(function() {
		$('#refresh').click(function() {
			applyFilterSavedSearches();
		});

		$('#clear').click(function() {
			clearFilterSavedSearches();
		});

		$('#import').click(function() {
			importSavedSearch();
		});

		$('#rows').change(function() {
			applyFilterSavedSearches();
		});

		$('#saved_searches').submit(function(event) {
			event.preventDefault();
			applyFilterSavedSearches();
		});
	});
}

function importDashboard() {
	var strURL = 'syslog_dashboards.php?action=import&header=false';
	loadPageNoHeader(strURL);
}

function applyFilterDashboards() {
	var strURL = 'syslog_dashboards.php?filter='+encodeURIComponent($('#filter').val())+'&rows='+$('#rows').val()+'&page='+$('#page').val()+'&header=false';
	loadPageNoHeader(strURL);
}

function clearFilterDashboards() {
	var strURL = 'syslog_dashboards.php?clear=1&header=false';
	loadPageNoHeader(strURL);
}

function initSyslogDashboards() {
	$(function() {
		$('#refresh').click(function() {
			applyFilterDashboards();
		});

		$('#clear').click(function() {
			clearFilterDashboards();
		});

		$('#import').click(function() {
			importDashboard();
		});

		$('#rows').change(function() {
			applyFilterDashboards();
		});

		$('#dashboards').submit(function(event) {
			event.preventDefault();
			applyFilterDashboards();
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
				// New rows display these defaults even before a user changes either
				// select. Persist them in the model so structured-rule JSON contains
				// the same field and operator that the editor shows.
				row.field = row.field || 'message';
				row.operator = row.operator || (configuredOperators[row.field] ? configuredOperators[row.field][0] :
					(choices[row.field] || row.field === 'seq' || row.field.endsWith('_id') || row.field === 'logtime' ? '=' : 'contains'));
				line.appendChild(select(Object.entries(fields), row.field, 'Field', function(value) {
					row.field = value;
					row.value = '';
					row.operator = configuredOperators[value] ? configuredOperators[value][0] :
						(choices[value] || value === 'seq' || value.endsWith('_id') || value === 'logtime' ? '=' : 'contains');
					render(container, rows);
					initSyslogSearchDates(container);
				}));
				var numeric = row.field === 'seq' || (row.field || '').endsWith('_id') || row.field === 'logtime';
				var operators = configuredOperators[row.field] ||
					(row.field === 'logtime' ? ['last', 'during', '=', '!=', '>', '>=', '<', '<='] : numeric ? ['=', '!=', '>', '>=', '<', '<='] : ['contains', '=', '!=', 'like']);
				var inverse = {'=': '!=', '!=': '=', '>': '<=', '>=': '<', '<': '>=', '<=': '>'};
				var operatorOptions = [];
				if (row.operator && !operators.includes(row.operator)) operators.push(row.operator);
				operators.forEach(function(op) {
					var name = op === 'last' ? 'In the last' : op === 'during' ? 'In the range' : row.field === 'logtime' && op === '>=' ? 'From (>=)' : row.field === 'logtime' && op === '<=' ? 'To (<=)' : op;
					operatorOptions.push([op, name]);
					if (!inverse[op]) operatorOptions.push(['NOT ' + op, op === 'contains' ? 'does not contain' : op === 'like' ? 'does not match pattern' : 'NOT ' + name]);
				});
				var selectedOperator = row.operator;
				if (row.negative) selectedOperator = inverse[selectedOperator] || 'NOT ' + selectedOperator;
				line.appendChild(select(operatorOptions, selectedOperator, 'Operator', function(value) {
					var previous = row.operator;
					row.negative = value.startsWith('NOT ');
					row.operator = row.negative ? value.slice(4) : value;
					var presetOperator = function(op) { return op === 'last' || op === 'during'; };
					if (row.field === 'logtime' && previous !== row.operator && (presetOperator(previous) || presetOperator(row.operator))) {
						row.value = row.operator === 'last' ? '86400' : row.operator === 'during' ? 'this_day' : '';
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
				if (row.field === 'logtime' && (row.operator === 'last' || row.operator === 'during')) {
					var times = labels.times ? JSON.parse(labels.times) : SYSLOG_TIME_PRESETS;
					input = select(Object.entries(times[row.operator] || {}), row.value, 'Date range', function(value) { row.value = value; });
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

/**
 * Submit filter values in a CSRF-protected POST body. Results reload as a
 * callback into #main, the way Cacti's own page loads work; only exports use
 * a form, which streams the download into a hidden frame.
 */
function postSyslog(data) {
	data.tab = window.pageTab || 'syslog';
	data.__csrf_magic = csrfMagicToken;
	if (data.export !== 'true') {
		$.post('syslog.php?header=false', data).done(function(html) {
			$('#main').empty().html(html);
			if (typeof applySkin === 'function') applySkin();
		});
		return;
	}
	var form = document.createElement('form');
	form.method = 'post';
	form.action = 'syslog.php';
	var frame = document.getElementById('syslog_download');
	if (!frame) {
		frame = document.createElement('iframe');
		frame.id = frame.name = 'syslog_download';
		frame.hidden = true;
		frame.title = 'Syslog download';
		document.body.appendChild(frame);
	}
	form.target = frame.name;
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

/**
 * Whether "field = value" already limits the whole search: a plain top-level
 * condition while every top-level connector is AND. Under an OR the same
 * condition would not narrow the results, so it does not count.
 */
function syslogFilterInScope(rows, field, value) {
	if (!Array.isArray(rows) || rows.some(function(row, index) { return index > 0 && row.join === 'OR'; })) {
		return false;
	}
	value = String(value).toLowerCase();
	return rows.some(function(row) {
		return !row.rows && !row.negative && row.field === field && row.operator === '=' && String(row.value).toLowerCase() === value;
	});
}

/** Narrow the search to "field = value" unless it is already in scope. */
function syslogAddFilterCondition(field, value) {
	var builder = document.getElementById('syslog_search_builder');
	if (!builder) return;
	var expression = syslogBuilderSync(builder);
	if (expression === null || syslogFilterInScope(builder.searchRows, field, value)) return;
	var data = syslogFilterData();
	data.rfilter = (expression ? '(' + expression + ') AND ' : '') + syslogSearchExpression([{field: field, operator: '=', value: value}]);
	postSyslog(data);
}

function syslogFilterData() {
	var data = {search_mode: 'logical', rfilter: $('#rfilter').val(), page: 1};
	['rows', 'removal', 'refresh', 'grouping', 'trimval'].forEach(function(name) {
		if ($('#' + name).length) data[name] = $('#' + name).val();
	});
	return data;
}

function applyFilter() {
	if (syncSyslogSearchBuilder()) postSyslog(syslogFilterData());
}

/** Reload results with the current filter, keeping the active page. */
function refreshResults() {
	postSyslog({page: parseInt($('#page').val(), 10) || 1, refresh: $('#refresh').val(), trimval: $('#trimval').val()});
}

function exportRecords() {
	if (!syncSyslogSearchBuilder()) return;
	var data = syslogFilterData();
	data.export = 'true';
	postSyslog(data);
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
	data.trimval      = $('#trimval').val();
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
		savedSearchPrompt('', function(name, isDefault) {
			savedSearchSave(savedSearchExpression(document.getElementById('syslog_search_builder')), name, function(id) {
				postSyslog({saved: id});
			}, isDefault);
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
	var makeDefault = document.getElementById('syslog_saved_prompt_default');
	input.value = name || '';
	// Saving the default search again keeps it the default unless unticked.
	var selected = document.getElementById('saved_search').selectedOptions[0];
	makeDefault.checked = !!(name && selected && selected.dataset.default === '1');
	$('#syslog_saved_prompt').off('submit.syslogSavedPrompt').on('submit.syslogSavedPrompt', function(event) {
		event.preventDefault();
		event.stopPropagation();
		var value = input.value.trim();
		if (!value) {
			input.focus();
			return;
		}
		$(this).dialog('close');
		accept(value, makeDefault.checked);
	}).dialog({
		modal: false,
		appendTo: 'body',
		width: 420,
		autoOpen: true,
		open: function() {
			// Appended to <body>, outside the form's token scope, so re-sample the
			// theme onto the prompt to keep it readable in dark themes.
			applySyslogTheme(this);
		},
		buttons: [
			{text: text.cancel, click: function() { $(this).dialog('close'); }},
			{text: text.save, type: 'submit', form: 'syslog_saved_prompt', class: 'ui-state-active', click: function(event) {
				event.preventDefault();
				this.requestSubmit();
			}}
		]
	});
}

function savedSearchSave(expression, name, done, isDefault) {
	savedSearchPost({
		action: 'saved_search_save', name: name, rfilter: expression,
		removal: $('#removal').val() || '1', grouping: $('#grouping').val() || '0',
		default: isDefault ? 'on' : ''
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
	var name = mode === 'edit' && selected && selected.length && selected[0].value !== '0' ? (selected[0].dataset.name || selected[0].textContent) : '';

	$('#syslog_saved_dialog').dialog({
		modal: true,
		appendTo: 'body',
		width: Math.min(1040, $(window).width() - 40),
		title: mode === 'new' ? text.newTitle : text.editTitle,
		open: function() {
			// Dialog is appended to <body>, outside the form's token scope, so
			// re-sample the theme onto it to keep it readable in dark themes.
			applySyslogTheme(dialog);
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
				savedSearchPrompt(mode === 'edit' ? name : '', function(chosen, isDefault) {
					savedSearchSave(savedSearchExpression(builder), chosen, function(id) {
						postSyslog({saved: id});
					}, isDefault);
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
		applySyslogTheme(document.getElementById('syslog_form'));
		applySyslogTheme(document.getElementById('syslog_workspace'));
		initSyslogSearchBuilder(document.getElementById('syslog_search_builder'));
		initSavedSearches();
		initSyslogCompactSearch();

		// Let the active theme paint the filter-edit toggle's active state; the
		// primary Search button is painted from the sampled --search-primary pair
		// in search.css rather than a plugin accent color.
		var toggle = $('#syslog_search_toggle');
		toggle.toggleClass('ui-state-active', toggle.attr('aria-expanded') === 'true');

		$('#syslog_form').submit(function(event) {
			event.preventDefault();
			event.stopImmediatePropagation();
			applyFilter();
		});

		$('#save').click(function() {
			saveSettings();
		});

		// Search is the form's submit button, so the submit handler above runs it.

		$('#clear').click(function() {
			clearFilter();
		});

		$('#export').click(function() {
			exportRecords();
		});

		$('#syslog_search_toggle').click(function() {
			toggleSyslogSearch();
		});

		$('#rows, #refresh, #trimval, #removal, #grouping').change(function() {
			applyFilter();
		});

		// Delegated so classic/autocomplete dropdowns injected via AJAX keep working
		// without an inline per-select script under nonce-enforced CSP.
		$(document).off('change.syslogCallback').on('change.syslogCallback', 'select[data-on-change]', function() {
			syslogInvokeCallback($(this).attr('data-on-change'));
		});
	});
}

/**
 * Append one card per recommendation: identifier, vendor and severity badges,
 * then the explanation, recommended action and reference. All server data is
 * inserted as text, never as markup.
 *
 * @param {jQuery} $parent The element the cards are appended to.
 * @param {Array} recommendations The recommendations from the lookup response.
 * @param {Object} labels The localized section labels from the response.
 * @param {boolean} clipped Whether long texts are shortened, as in the tooltip.
 */
function syslogRecommendationCards($parent, recommendations, labels, clipped) {
	var limit = 700;

	function clip(text) {
		text = String(text || '');

		return clipped && text.length > limit ? text.substring(0, limit) + '\u2026' : text;
	}

	function section($card, label, text, extra) {
		if (!text) {
			return;
		}

		var $section = $('<div class="syslogRecSection ' + (extra || '') + '"></div>').appendTo($card);
		$('<div class="syslogRecLabel"></div>').text(label).appendTo($section);
		$('<div class="syslogRecText"></div>').text(clip(text)).appendTo($section);
	}

	$.each(recommendations, function(i, rec) {
		var $card = $('<div class="syslogRecCard"></div>').appendTo($parent);
		var $head = $('<div class="syslogRecHead"></div>').appendTo($card);

		$('<span class="syslogRecId"></span>').text(rec.identifier).appendTo($head);

		if (rec.vendor) {
			$('<span class="syslogRecBadge"></span>').text(rec.vendor).appendTo($head);
		}

		if (rec.severity_name) {
			$('<span class="syslogRecBadge syslogRecSev' + (Number.parseInt(rec.severity, 10) || 0) + '"></span>').text(rec.severity_name).appendTo($head);
		}

		section($card, labels.explanation || '', rec.explanation);
		section($card, labels.action || '', rec.recommended_action, 'syslogRecAction');
		section($card, labels.reference || '', rec.reference);
	});
}

/**
 * Initialize group expand/collapse and tooltips for non-message buttons.
 * Call this after the syslog message table is rendered or updated
 */
function initSyslogMessagesDisplay() {
	$(function() {
		applySyslogTheme(document.getElementById('syslog_workspace'));
		// Initialize tooltips for buttons
		$('button').not('.syslogRow button').tooltip({
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
			var toggle = this;
			var page = $(toggle).data('syslogGroupPage');

			if (!page) {
				var parent = toggle.closest('tr');
				var control = $('<tr class="syslogGroupPage"></tr>').insertAfter(parent);
				var cell = $('<td></td>').attr('colspan', parent.cells.length).appendTo(control);
				page = {
					control: control, rows: $(), offset: 0, loaded: false, busy: false,
					status: $('<span role="status"></span>').appendTo(cell),
					previous: $('<button type="button"></button>').text(toggle.dataset.previous).appendTo(cell),
					next: $('<button type="button"></button>').text(toggle.dataset.next).appendTo(cell)
				};
				$(toggle).data('syslogGroupPage', page);
				page.previous.on('click', function() { load(page.offset - 50); });
				page.next.on('click', function() { load(page.offset + 50); });
			}

			var expanded = toggle.getAttribute('aria-expanded') !== 'true';
			toggle.setAttribute('aria-expanded', String(expanded));
			$(toggle).find('i').toggleClass('fa-chevron-up', expanded).toggleClass('fa-chevron-down', !expanded);
			page.control.toggle(expanded);
			page.rows.toggle(expanded);
			if (expanded && !page.loaded && !page.busy) load(0);

			function load(offset) {
				if (page.busy) return;
				page.busy = true;
				page.status.text(toggle.dataset.loading);
				page.previous.prop('disabled', true);
				page.next.prop('disabled', true);
				$.getJSON('syslog.php', {action: 'group_details', context: toggle.dataset.context, group: toggle.dataset.group, offset: offset})
					.done(function(result) {
						if (!document.documentElement.contains(toggle)) return;
						if (!result || typeof result.html !== 'string' || typeof result.more !== 'boolean') {
							page.status.text(result && result.error || toggle.dataset.error);
							return;
						}
						var body = $('<tbody></tbody>').html(result.html);
						initSyslogValueFilters(body[0]);
						page.rows.remove();
						page.rows = body.children('tr').insertAfter(page.control);
						page.rows.toggle(toggle.getAttribute('aria-expanded') === 'true');
						page.offset = offset;
						page.loaded = true;
						page.more = result.more;
						page.status.text(page.rows.length ? (offset + 1) + ' - ' + (offset + page.rows.length) : toggle.dataset.empty);
					}).fail(function() {
						page.status.text(toggle.dataset.error);
					}).always(function() {
						page.busy = false;
						page.previous.prop('disabled', !page.loaded || page.offset === 0);
						page.next.prop('disabled', !page.loaded || !page.more);
					});
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
	var strURL = 'syslog_removal.php?filter='+encodeURIComponent($('#filter').val())+'&enabled='+$('#enabled').val()+'&rows='+$('#rows').val()+'&page='+$('#page').val()+'&header=false';
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

		$('#enabled, #rows').change(function() {
			applyFilterRemoval();
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
	var strURL = 'syslog_alerts.php?filter='+encodeURIComponent($('#filter').val())+'&enabled='+$('#enabled').val()+'&rows='+$('#rows').val()+'&page='+$('#page').val()+'&header=false';

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

		$('#enabled, #rows').change(function() {
			applyFilterAlerts();
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
	var strURL = 'syslog_reports.php?filter='+encodeURIComponent($('#filter').val())+'&enabled='+$('#enabled').val()+'&rows='+$('#rows').val()+'&page='+$('#page').val()+'&header=false';

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

		$('#enabled, #rows').change(function() {
			applyFilterReports();
		});

		$('#reports').submit(function(event) {
			event.preventDefault();
			applyFilterReports();
		});
	});
}

/* ========================================================================
 * Recommendations Functions (syslog_recommendations.php)
 * ======================================================================== */

/**
 * Build the list URL for the current recommendation filters.
 *
 * @param {Object} extra Additional query parameters.
 * @returns {string} The relative URL.
 */
function syslogRecommendationsUrl(extra) {
	var params = $.extend({
		filter: $('#filter').val(), vendor: $('#vendor').val(), severity: $('#severity').val(),
		matchable: $('#matchable').val(), rows: $('#rows').val(), page: $('#page').val()
	}, extra || {});

	return 'syslog_recommendations.php?' + $.param(params);
}

/**
 * Initialize the recommendations list: filters, import and export run as
 * callbacks instead of full page loads.
 */
function initSyslogRecommendations() {
	$(function() {
		$('#refresh').off('click').on('click', function() {
			loadPageNoHeader(syslogRecommendationsUrl({page: 1, header: 'false'}));
		});

		$('#clear').off('click').on('click', function() {
			loadPageNoHeader('syslog_recommendations.php?clear=1&header=false');
		});

		$('#vendor, #severity, #matchable, #rows').off('change').on('change', function() {
			loadPageNoHeader(syslogRecommendationsUrl({page: 1, header: 'false'}));
		});

		$('#recommendation_filter').off('submit').on('submit', function(event) {
			event.preventDefault();
			loadPageNoHeader(syslogRecommendationsUrl({page: 1, header: 'false'}));
		});

		$('#import').off('click').on('click', function() {
			loadPageNoHeader('syslog_recommendations.php?action=import&header=false');
		});

		// Download in a hidden frame so Cacti's page-unload spinner never starts.
		$('#export').off('click').on('click', function() {
			$('#syslog_download').attr('src', syslogRecommendationsUrl({action: 'export_all'}));
		});
	});
}

/**
 * Submit the recommendations import as a callback. The form carries a file,
 * which Cacti's own AJAX form handler cannot send, so it is posted as
 * FormData; the server answers with the page to show next, whose session
 * messages report the outcome.
 *
 * @param {string} busyText Text shown on the button while the import runs.
 */
function initSyslogRecommendationImport(busyText) {
	$(function() {
		var form = $('#syslog_recommendation_import');

		form.off('submit').on('submit', function(event) {
			event.preventDefault();

			var data = new FormData(this);
			var submit = form.find('input[type="submit"]');

			data.append('callback', '1');

			if (!data.has('__csrf_magic') && typeof csrfMagicToken !== 'undefined') {
				data.append('__csrf_magic', csrfMagicToken);
			}

			submit.prop('disabled', true).val(busyText);

			$.ajax({
				url: 'syslog_recommendations.php', type: 'POST', data: data,
				processData: false, contentType: false, dataType: 'json'
			}).done(function(result) {
				var next = (result && result.next) ? result.next : 'syslog_recommendations.php';

				loadPageNoHeader(next + (next.indexOf('?') >= 0 ? '&' : '?') + 'header=false');
			}).fail(function() {
				loadPageNoHeader('syslog_recommendations.php?header=false');
			});
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

/* ========================================================================
 * Rule Preview ("Test rule")
 * ======================================================================== */

/**
 * Post the rule editor form to the page's test action and render a
 * bounded preview dialog.  The endpoint is strictly read-only: it never
 * saves, enables, disables, deletes, or executes anything.
 *
 * @param {string} formSelector  Selector of the editor form.
 * @param {string} dialogSelector Selector of the preview dialog div.
 * @param {string} title        Translated dialog title.
 */
function testSyslogRule(formSelector, dialogSelector, title) {
	var form = $(formSelector);

	if (!form.length) {
		return;
	}

	var messageField = form.find('#message');
	var typeField = form.find('#type');

	// Sync the visual filter builder into the message field exactly as
	// the save path does, so the preview matches what would be stored.
	// Builders from rule pages visited earlier through AJAX navigation stay
	// on window, so only accept the one whose element is inside this form.
	var builder = [window.alertFilterBuilder, window.removalFilterBuilder, window.reportFilterBuilder].filter(function(candidate) {
		return candidate && candidate.element && $.contains(form[0], candidate.element);
	})[0];

	if (builder && typeField.val() == 'filter' && !builder.syncTo(messageField[0])) {
		return;
	}

	var data = {
		action: 'test',
		type: typeField.val() || 'filter',
		name: form.find('#name').val() || '',
		message: messageField.val() || '',
		preview_rows: 10,
		__csrf_magic: csrfMagicToken
	};

	$.post(window.location.pathname, data, null, 'json').done(function(result) {
		var dialog = $(dialogSelector);

		if (!dialog.length) {
			return;
		}

		if (result && result.error) {
			dialog.html($('<div class="syslogRuleTestError"/>').text(result.error));
		} else if (result) {
			var container = $('<div class="syslogRuleTestResult"/>');

			container.append($('<p/>').append($('<span class="syslogRuleTestCount"/>').text(
				(result.count === 1 ? '1 matching message' : result.count + ' matching messages')
			)));

			if (result.notice) {
				container.append($('<p/>').text(result.notice));
			}

			if (result.history_limit) {
				container.append($('<p/>').text('No matches in incoming data. Results are from the most recent ' + result.history_limit + ' stored messages. Testing does not send alarms.'));
			}

			var table = $('<table class="syslogRuleTestRows"><thead><tr>' +
				'<th>Date</th><th>Host</th><th>Program</th><th>Message</th>' +
				'</tr></thead><tbody></tbody></table>');
			var tbody = table.find('tbody');

			(result.rows || []).forEach(function(row) {
				var tr = $('<tr/>');
				tr.append($('<td/>').text(row.logtime || ''));
				tr.append($('<td/>').text(row.host || ''));
				tr.append($('<td/>').text(row.program || ''));
				tr.append($('<td class="syslogRuleTestMessage"/>').text(row.message || ''));
				tbody.append(tr);
			});

			container.append(table);
			dialog.html(container);
		} else {
			dialog.html($('<div class="syslogRuleTestError"/>').text('The preview returned no result.'));
		}

		// Modeless, so the rule can be edited and re-tested while the preview stays open.
		dialog.dialog({
			title: title,
			minHeight: 80,
			minWidth: 600,
			maxWidth: 900,
			resizable: true,
			draggable: true,
			modal: false
		});
	}).fail(function() {
		var dialog = $(dialogSelector);

		if (dialog.length) {
			dialog.html($('<div class="syslogRuleTestError"/>').text('The preview request failed.'));
			dialog.dialog({
				title: title,
				minHeight: 80,
				minWidth: 400,
				modal: false
			});
		}
	});
}
