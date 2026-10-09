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
	// Collapse the filter by default; only a saved 'false' preference keeps it open.
	var keepSearchOpen = false;
	try { keepSearchOpen = localStorage.getItem('syslog.search.collapsed') === 'false'; } catch (error) { /* Storage may be disabled. */ }
	if (!keepSearchOpen) toggleSyslogSearch(false);
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
	box.remove();
	sample.remove();
	return tokens;
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

/** Sample the live theme onto the Status tab card grid, enable drag/keyboard
 *  reordering, the per-card tools (show more/less, maximize, refresh, remove)
 *  and the "Add card" catalogue. Layout changes persist server-side
 *  (settings_user) so they survive page revisits. */
var syslogStatusDragging = null;

function initSyslogStatus() {
	$(function() {
		var grid = document.getElementById('syslog_status');
		if (!grid) {
			return;
		}

		applySyslogTheme(document.getElementById('syslog_status_panel') || grid);

		grid.querySelectorAll('.syslogStatusCard').forEach(function(card) {
			syslogStatusBindCard(grid, card);
		});

		grid.addEventListener('dragover', function(event) {
			if (!syslogStatusDragging) {
				return;
			}
			event.preventDefault();
			event.dataTransfer.dropEffect = 'move';
			var before = syslogStatusDragReference(grid, event.clientX, event.clientY);
			if (before == null) {
				grid.appendChild(syslogStatusDragging);
			} else if (before !== syslogStatusDragging) {
				grid.insertBefore(syslogStatusDragging, before);
			}
		});

		// Per-card tool buttons bubble to the grid.
		$(grid).off('click.syslogstatus').on('click.syslogstatus', '.syslogStatusCardTool', function() {
			var card = this.closest('.syslogStatusCard');
			if (!card) {
				return;
			}
			switch (this.dataset.tool) {
				case 'expand':   card.classList.add('syslogStatusCardExpanded'); syslogStatusSaveLayout(grid); break;
				case 'collapse': card.classList.remove('syslogStatusCardExpanded'); syslogStatusSaveLayout(grid); break;
				case 'maximize': syslogStatusMaximize(card); break;
				case 'refresh':  syslogStatusRefreshCard(grid, card); break;
				case 'remove':   syslogStatusRemoveCard(grid, card); break;
			}
		});

		var add = document.getElementById('syslog_status_add');
		if (add) {
			$(add).off('change.syslogstatus').on('change.syslogstatus', function() {
				var key = this.value;
				this.value = '';
				if (key) {
					syslogStatusAddCard(grid, key);
				}
			});
		}
	});
}

/** Wire a single card's drag handle (mouse + keyboard) and drag events. */
function syslogStatusBindCard(grid, card) {
	var handle = card.querySelector('.syslogStatusCardDrag');
	if (!handle) {
		return;
	}

	// Arm HTML5 drag only from the handle, and disarm on release when no drag
	// began (a plain click/tap) so selecting content elsewhere can't drag the card.
	var disarm = function() { card.draggable = false; };
	handle.addEventListener('mousedown', function() {
		card.draggable = true;
		document.addEventListener('mouseup', disarm, {once: true});
	});
	handle.addEventListener('touchstart', function() {
		card.draggable = true;
		document.addEventListener('touchend', disarm, {once: true});
		document.addEventListener('touchcancel', disarm, {once: true});
	}, {passive: true});

	// Keyboard-accessible reordering: move the card with the arrow keys.
	handle.addEventListener('keydown', function(event) {
		var back = event.key === 'ArrowLeft' || event.key === 'ArrowUp';
		var fwd  = event.key === 'ArrowRight' || event.key === 'ArrowDown';
		if (!back && !fwd) {
			return;
		}
		event.preventDefault();
		if (back && card.previousElementSibling) {
			grid.insertBefore(card, card.previousElementSibling);
		} else if (fwd && card.nextElementSibling) {
			grid.insertBefore(card.nextElementSibling, card);
		} else {
			return;
		}
		handle.focus();
		syslogStatusSaveLayout(grid);
	});

	card.addEventListener('dragstart', function(event) {
		syslogStatusDragging = card;
		card.classList.add('syslogStatusCardDragging');
		event.dataTransfer.effectAllowed = 'move';
		try { event.dataTransfer.setData('text/plain', card.dataset.card || ''); } catch (error) { /* IE guard */ }
	});

	card.addEventListener('dragend', function() {
		card.draggable = false;
		card.classList.remove('syslogStatusCardDragging');
		if (syslogStatusDragging) {
			syslogStatusDragging = null;
			syslogStatusSaveLayout(grid);
		}
	});
}

/** The card the dragged card should be inserted before for the current pointer
 *  position, or null to append at the end. */
function syslogStatusDragReference(grid, x, y) {
	var cards = Array.prototype.slice.call(grid.querySelectorAll('.syslogStatusCard:not(.syslogStatusCardDragging)'));
	for (var i = 0; i < cards.length; i++) {
		var rect = cards[i].getBoundingClientRect();
		if (y < rect.top - 1) {
			return cards[i];
		}
		if (y <= rect.bottom && x < rect.left + rect.width / 2) {
			return cards[i];
		}
	}
	return null;
}

/** Add a card from the catalogue: fetch its HTML, append it, persist and refresh
 *  the "Add" options. */
function syslogStatusAddCard(grid, key) {
	if (syslogStatusHasCard(grid, key)) {
		return;
	}
	$.post('syslog.php', {action: 'status_card', tab: 'status', card: key, __csrf_magic: csrfMagicToken}, null, 'json').done(function(data) {
		if (!data || !data.html) {
			return;
		}
		// A concurrent add for the same card may have landed first.
		if (syslogStatusHasCard(grid, key)) {
			return;
		}
		var card = syslogStatusParseCard(data.html);
		if (!card) {
			return;
		}
		grid.appendChild(card);
		applySyslogTheme(grid);
		syslogStatusBindCard(grid, card);
		syslogStatusSaveLayout(grid);
		syslogStatusSyncAddOptions(grid);
	});
}

/** Whether a card with the given key is currently on the grid. */
function syslogStatusHasCard(grid, key) {
	var present = false;
	grid.querySelectorAll('.syslogStatusCard').forEach(function(card) {
		if (card.dataset.card === key) {
			present = true;
		}
	});
	return present;
}

/** Remove a card from the page and return it to the "Add" catalogue. */
function syslogStatusRemoveCard(grid, card) {
	card.parentNode.removeChild(card);
	syslogStatusSaveLayout(grid);
	syslogStatusSyncAddOptions(grid);
}

/** Re-fetch a single card's HTML and swap it in place, keeping expanded state. */
function syslogStatusRefreshCard(grid, card) {
	var expanded = card.classList.contains('syslogStatusCardExpanded') ? '1' : '0';
	$.post('syslog.php', {action: 'status_card', tab: 'status', card: card.dataset.card, expanded: expanded, __csrf_magic: csrfMagicToken}, null, 'json').done(function(data) {
		// The card may have been removed or already replaced while we waited.
		if (!data || !data.html || !card.parentNode) {
			return;
		}
		var fresh = syslogStatusParseCard(data.html);
		if (!fresh) {
			return;
		}
		card.parentNode.replaceChild(fresh, card);
		applySyslogTheme(grid);
		syslogStatusBindCard(grid, fresh);
	});
}

/** Open a copy of a card's body in a large modal dialog. */
function syslogStatusMaximize(card) {
	var dialog = document.getElementById('syslog_status_dialog');
	if (!dialog) {
		return;
	}
	var title = card.querySelector('.syslogStatusCardTitle');
	var body  = card.querySelector('.syslogStatusCardBody');
	dialog.innerHTML = '<div class="syslogStatusDialogBody">' + (body ? body.innerHTML : '') + '</div>';
	applySyslogTheme(dialog);
	$(dialog).dialog({
		modal: true,
		appendTo: 'body',
		width: Math.min(900, $(window).width() - 40),
		title: title ? title.textContent : '',
		open: function() { applySyslogTheme(this); }
	});
}

/** Parse a card HTML string into its <section> element. */
function syslogStatusParseCard(html) {
	var tmp = document.createElement('div');
	tmp.innerHTML = html;
	return tmp.querySelector('.syslogStatusCard');
}

/** Rebuild the "Add" dropdown so it lists only cards not currently on the page. */
function syslogStatusSyncAddOptions(grid) {
	var add = document.getElementById('syslog_status_add');
	if (!add || typeof syslogStatusCatalog === 'undefined') {
		return;
	}
	var present = {};
	grid.querySelectorAll('.syslogStatusCard').forEach(function(card) {
		if (card.dataset.card) {
			present[card.dataset.card] = true;
		}
	});
	while (add.options.length > 1) {
		add.remove(1);
	}
	Object.keys(syslogStatusCatalog).forEach(function(key) {
		if (!present[key]) {
			var option = document.createElement('option');
			option.value = key;
			option.textContent = syslogStatusCatalog[key];
			add.appendChild(option);
		}
	});
}

/** Serialize layout POSTs so rapid actions can't persist out of order: one
 *  request is in flight at a time, and a save requested meanwhile is coalesced
 *  into a single follow-up that reads the latest DOM, so the final state wins. */
var syslogStatusSaveInFlight = false;
var syslogStatusSaveQueued = false;

function syslogStatusSaveLayout(grid) {
	if (syslogStatusSaveInFlight) {
		syslogStatusSaveQueued = true;
		return;
	}
	syslogStatusSaveInFlight = true;

	var order = [];
	var expanded = {};
	grid.querySelectorAll('.syslogStatusCard').forEach(function(card) {
		if (!card.dataset.card) {
			return;
		}
		order.push(card.dataset.card);
		if (card.classList.contains('syslogStatusCardExpanded')) {
			expanded[card.dataset.card] = true;
		}
	});

	$.post('syslog.php', {
		action: 'status_layout',
		tab: 'status',
		layout: JSON.stringify({order: order, expanded: expanded}),
		__csrf_magic: csrfMagicToken
	}, null, 'json').always(function() {
		syslogStatusSaveInFlight = false;
		if (syslogStatusSaveQueued) {
			syslogStatusSaveQueued = false;
			syslogStatusSaveLayout(grid);
		}
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
					(row.field === 'logtime' ? ['last', '=', '!=', '>', '>=', '<', '<='] : numeric ? ['=', '!=', '>', '>=', '<', '<='] : ['contains', '=', '!=', 'like']);
				var inverse = {'=': '!=', '!=': '=', '>': '<=', '>=': '<', '<': '>=', '<=': '>'};
				var operatorOptions = [];
				if (row.operator && !operators.includes(row.operator)) operators.push(row.operator);
				operators.forEach(function(op) {
					var name = op === 'last' ? 'In the last' : row.field === 'logtime' && op === '>=' ? 'From (>=)' : row.field === 'logtime' && op === '<=' ? 'To (<=)' : op;
					operatorOptions.push([op, name]);
					if (!inverse[op]) operatorOptions.push(['NOT ' + op, op === 'contains' ? 'does not contain' : op === 'like' ? 'does not match pattern' : 'NOT ' + name]);
				});
				var selectedOperator = row.operator;
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
	if (data.export === 'true') {
		var frame = document.getElementById('syslog_download');
		if (!frame) {
			frame = document.createElement('iframe');
			frame.id = frame.name = 'syslog_download';
			frame.hidden = true;
			frame.title = 'Syslog download';
			document.body.appendChild(frame);
		}
		form.target = frame.name;
	}
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
		open: function() {
			// Appended to <body>, outside the form's token scope, so re-sample the
			// theme onto the prompt to keep it readable in dark themes.
			applySyslogTheme(this);
		},
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

		$('#go').click(function() {
			applyFilter();
		});

		$('#clear').click(function() {
			clearFilter();
		});

		$('#export').click(function() {
			exportRecords();
		});

		$('#syslog_search_toggle').click(function() {
			toggleSyslogSearch();
		});

		$('#rows, #refresh, #removal, #grouping').change(function() {
			applyFilter();
		});
	});
}

/**
 * Initialize syslog message tooltips and group expand/collapse functionality
 * Call this after the syslog message table is rendered or updated
 */
function initSyslogMessagesDisplay() {
	$(function() {
		applySyslogTheme(document.getElementById('syslog_workspace'));
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
	var builder = window.alertFilterBuilder || window.removalFilterBuilder;

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

		dialog.dialog({
			title: title,
			minHeight: 80,
			minWidth: 600,
			maxWidth: 900,
			resizable: true,
			draggable: true,
			modal: true
		});
	}).fail(function() {
		var dialog = $(dialogSelector);

		if (dialog.length) {
			dialog.html($('<div class="syslogRuleTestError"/>').text('The preview request failed.'));
			dialog.dialog({
				title: title,
				minHeight: 80,
				minWidth: 400
			});
		}
	});
}
