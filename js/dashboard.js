/**
 * Syslog Dashboard (tab=dashboard)
 *
 * Renders per-user chart panels with Cacti's bundled billboard.js. Data
 * comes from syslog.php?action=dashboard_chart, which aggregates with the
 * same logical search DSL as the log viewer.
 */

/* Chart handles so panels can be destroyed before re-rendering. */
var syslogDashboardCharts = {};

function syslogDashboardPost(data, done) {
	data.tab = 'dashboard';
	data.__csrf_magic = csrfMagicToken;
	$.post('syslog.php', data, null, 'json').done(function(result) {
		if (result && result.error) {
			alert(result.error);
			return;
		}
		if (done) done(result);
	}).fail(function() {
		alert('Dashboard request failed.');
	});
}

/** Full-page navigation back into the dashboard tab. */
function syslogDashboardLoad(data) {
	var form = document.createElement('form');
	form.method = 'post';
	form.action = 'syslog.php';
	data.tab = 'dashboard';
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

function syslogDashboardPanelCard(panel) {
	var card = document.createElement('article');
	card.className = 'syslogDashboardCard';
	card.id = 'syslog_panel_' + panel.id;

	var header = document.createElement('header');
	header.className = 'syslogDashboardCardHeader';

	var title = document.createElement('h3');
	title.className = 'syslogDashboardCardTitle';
	title.textContent = panel.title;
	header.append(title);

	var actions = document.createElement('div');
	actionButtons().forEach(function(button) {
		actions.append(button);
	});
	header.append(actions);
	card.append(header);

	var chart = document.createElement('div');
	chart.className = 'syslogChart';
	chart.id = 'syslog_chart_' + panel.id;
	card.append(chart);

	var status = document.createElement('p');
	status.className = 'syslogDashboardCardStatus';
	status.setAttribute('role', 'status');
	status.textContent = '...';
	card.append(status);

	return card;
}

function actionButtons() {
	var buttons = [];
	[['edit', 'fa-pencil', 'Edit'], ['up', 'fa-arrow-up', 'Move up'], ['down', 'fa-arrow-down', 'Move down'], ['delete', 'fa-trash', 'Delete']].forEach(function(entry) {
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'syslogDashboardCardButton';
		button.dataset.action = entry[0];
		button.innerHTML = '<i class="fa ' + entry[1] + '" aria-hidden="true"></i>';
		button.title = entry[2];
		button.setAttribute('aria-label', entry[2]);
		buttons.push(button);
	});
	return buttons;
}

function syslogDashboardDestroyChart(panelId) {
	if (syslogDashboardCharts[panelId]) {
		syslogDashboardCharts[panelId].destroy();
		delete syslogDashboardCharts[panelId];
	}
}

function syslogDashboardRenderChart(panel, data) {
	var status = document.querySelector('#syslog_panel_' + panel.id + ' .syslogDashboardCardStatus');
	var holder = document.getElementById('syslog_chart_' + panel.id);

	if (typeof bb === 'undefined') {
		if (holder) holder.hidden = true;
		if (status) status.textContent = syslogDashboard.text.libraryUnavailable;
		return;
	}

	if (data.error) {
		if (status) status.textContent = data.error;
		return;
	}

	if (status) status.hidden = true;

	var columns = [['x'].concat(data.labels), ['records'].concat(data.series[0])];

	var config = {
		bindto: '#syslog_chart_' + panel.id,
		data: {
			x: 'x',
			columns: columns,
			names: { records: panel.title },
			type: data.kind === 'breakdown' ? 'donut' : (data.chart === 'bar' ? 'bar' : (data.chart === 'area' ? 'area' : 'line'))
		},
		legend: { show: data.kind !== 'timeseries' },
		padding: { right: 16 }
	};

	if (data.kind === 'timeseries') {
		config.axis = {
			x: { type: 'category', tick: { culling: { max: 8 }, multiline: false } },
			y: { tick: { culling: { max: 5 } }, min: 0, padding: { bottom: 0 } }
		};
	} else {
		config.donut = { title: panel.title };
		config.legend = { position: 'right' };
	}

	syslogDashboardDestroyChart(panel.id);
	syslogDashboardCharts[panel.id] = bb.generate(config);
}

function syslogDashboardLoadPanel(panel) {
	var card = document.getElementById('syslog_panel_' + panel.id);
	if (!card) return;

	var status = card.querySelector('.syslogDashboardCardStatus');
	if (status) {
		status.hidden = false;
		status.textContent = '...';
	}

	syslogDashboardPost({
		action: 'dashboard_chart',
		panel_id: panel.id,
		dashboard_timespan: $('#syslog_dashboard_timespan').val() || '86400'
	}, function(data) {
		syslogDashboardRenderChart(panel, data);
	});
}

function syslogDashboardRenderGrid() {
	var grid = document.getElementById('syslog_dashboard_grid');
	if (!grid) return;

	grid.textContent = '';
	syslogDashboardCharts = {};

	(syslogDashboard.panels || []).forEach(function(panel) {
		grid.append(syslogDashboardPanelCard(panel));
		syslogDashboardLoadPanel(panel);
	});
}

/* ===================== Panel editor dialog ===================== */

function syslogPanelDialogFields(panel) {
	var editor = document.getElementById('syslog_panel_dialog');

	$('#syslog_panel_title').val(panel ? panel.title : '');
	$('#syslog_panel_source').val(panel ? panel.source : 'syslog').trigger('change');
	$('#syslog_panel_kind').val(panel ? panel.kind : 'timeseries').trigger('change');
	$('#syslog_panel_chart').val(panel ? (panel.chart || 'line') : 'line');
	$('#syslog_panel_interval').val(panel ? (panel.interval || 'dashboard') : 'dashboard');
	$('#syslog_panel_field').val(panel ? (panel.field || 'host') : 'host');
	$('#syslog_panel_top_n').val(panel ? (panel.top_n || 10) : 10);
	$('#syslog_panel_timespan').val(panel ? (panel.timespan || 'dashboard') : 'dashboard');
	$('#syslog_panel_removal').val(panel ? (panel.removal || '1') : '1');
	$('#syslog_panel_saved').val('0');

	var builder = document.getElementById('syslog_panel_builder');

	if (window.SyslogFilterBuilder) {
		if (builder.instance) {
			builder.instance = null;
		}

		builder.instance = new SyslogFilterBuilder(builder, {
			fields: JSON.parse(builder.dataset.fields || '{}'),
			choices: JSON.parse(builder.dataset.choices || '{}'),
			conditions: panel && panel.tree ? panel.tree : [],
			labels: {
				message: builder.dataset.message,
				placeholder: builder.dataset.placeholder,
				remove: builder.dataset.remove,
				match: builder.dataset.match,
				exclude: builder.dataset.exclude
			}
		});
	}
}

function syslogPanelDialogRead() {
	var builder = document.getElementById('syslog_panel_builder');
	var expression = builder.instance ? builder.instance.serialize() : '';

	return {
		title: $('#syslog_panel_title').val().trim(),
		source: $('#syslog_panel_source').val(),
		kind: $('#syslog_panel_kind').val(),
		chart: $('#syslog_panel_chart').val(),
		interval: $('#syslog_panel_interval').val(),
		field: $('#syslog_panel_field').val(),
		top_n: $('#syslog_panel_top_n').val(),
		timespan: $('#syslog_panel_timespan').val(),
		removal: $('#syslog_panel_removal').val(),
		expression: expression
	};
}

function syslogPanelDialogOpen(panelId) {
	var dialog = document.getElementById('syslog_panel_dialog');
	var editing = panelId > 0 ? syslogDashboardPanelById(panelId) : null;

	syslogPanelDialogFields(editing);

	$('#syslog_panel_dialog').dialog({
		modal: true,
		appendTo: 'body',
		width: Math.min(760, $(window).width() - 40),
		height: $(window).height() - 100,
		title: dialog.dataset.title,
		buttons: [
			{
				text: dialog.dataset.save,
				click: function() {
					var values = syslogPanelDialogRead();
					if (!values.title) {
						$('#syslog_panel_title').focus();
						return;
					}
					$(this).dialog('close');

					values.action = 'dashboard_panel_save';
					values.dashboard_id = syslogDashboard.dashboardId;
					if (panelId > 0) values.panel_id = panelId;

					syslogDashboardPost(values, function() {
						syslogDashboardLoad({dashboard_id: syslogDashboard.dashboardId});
					});
				}
			},
			{
				text: dialog.dataset.cancel,
				click: function() { $(this).dialog('close'); }
			}
		]
	});
}

function syslogDashboardPanelById(panelId) {
	var found = null;
	(syslogDashboard.panels || []).forEach(function(panel) {
		if (panel.id == panelId) found = panel;
	});
	return found;
}

/* ===================== Event wiring ===================== */

$(function() {
	if (!document.getElementById('syslog_dashboard_grid')) {
		return;
	}

	$('#syslog_dashboard_new').click(function() {
		syslogDashboardPrompt('', function(name) {
			syslogDashboardPost({action: 'dashboard_save', name: name}, function(result) {
				syslogDashboardLoad({dashboard_id: result.id});
			});
		});
	});

	$('#syslog_dashboard_rename').click(function() {
		var id = parseInt($('#syslog_dashboard_select').val(), 10);
		if (!id) return;

		var current = $('#syslog_dashboard_select').find(':selected').text();
		syslogDashboardPrompt(current, function(name) {
			syslogDashboardPost({action: 'dashboard_save', id: id, name: name}, function() {
				syslogDashboardLoad({dashboard_id: id});
			});
		});
	});

	$('#syslog_dashboard_delete').click(function() {
		var id = parseInt($('#syslog_dashboard_select').val(), 10);
		if (!id) return;
		if (!window.confirm(syslogDashboard.text.deleteDashboardConfirm)) return;

		syslogDashboardPost({action: 'dashboard_save', id: id, dashboard_delete: '1'}, function() {
			syslogDashboardLoad({});
		});
	});

	$('#syslog_dashboard_select').on('change', function() {
		syslogDashboardLoad({dashboard_id: parseInt(this.value, 10) || 0});
	});

	$('#syslog_dashboard_timespan').on('change', function() {
		syslogDashboardLoad({
			dashboard_id: syslogDashboard.dashboardId,
			dashboard_timespan: this.value
		});
	});

	$('#syslog_panel_new').click(function() {
		if (!syslogDashboard.dashboardId) {
			alert(syslogDashboard.text.emptyTitle);
			return;
		}
		syslogPanelDialogOpen(0);
	});

	// Show/hide dialog rows that only apply to the chosen type/source.
	$('#syslog_panel_kind, #syslog_panel_source').on('change', function() {
		var kind = $('#syslog_panel_kind').val();
		var source = $('#syslog_panel_source').val();

		$('.syslogPanelOnlyTimeseries').toggle(kind === 'timeseries');
		$('.syslogPanelOnlyBreakdown').toggle(kind === 'breakdown');
		$('.syslogPanelOnlySyslog').toggle(source === 'syslog');

		if (kind === 'breakdown' && $('#syslog_panel_chart').val() !== 'donut') {
			$('#syslog_panel_chart').val('donut');
		}
	});

	// Importing a saved search seeds the expression and record type.
	$('#syslog_panel_saved').on('change', function() {
		var option = this.selectedOptions[0];
		if (!option || option.value === '0') return;

		var builder = document.getElementById('syslog_panel_builder');
		if (builder.instance) {
			builder.instance.element.searchRows = [];
			builder.instance.element.dispatchEvent(new Event('syslog:rebuild'));
		}

		var savedExpression = option.dataset.expression || '';
		$('#syslog_panel_removal').val(option.dataset.removal || '1');
		$('#syslog_panel_builder').attr('data-saved-expression', savedExpression);
	});

	// Panel card actions bubble from the grid.
	$('#syslog_dashboard_grid').on('click', '.syslogDashboardCardButton', function(event) {
		event.preventDefault();
		var action = this.dataset.action;
		var panelId = parseInt(this.closest('.syslogDashboardCard').id.replace('syslog_panel_', ''), 10);
		if (!panelId) return;

		if (action === 'edit') {
			syslogPanelDialogOpen(panelId);
		} else if (action === 'up' || action === 'down') {
			syslogDashboardPost({
				action: 'dashboard_panel_save',
				dashboard_id: syslogDashboard.dashboardId,
				panel_id: panelId,
				panel_move: action
			}, function() {
				syslogDashboardLoad({dashboard_id: syslogDashboard.dashboardId});
			});
		} else if (action === 'delete') {
			if (!window.confirm(syslogDashboard.text.deletePanelConfirm)) return;
			syslogDashboardPost({
				action: 'dashboard_panel_save',
				dashboard_id: syslogDashboard.dashboardId,
				panel_id: panelId,
				panel_delete: '1'
			}, function() {
				syslogDashboardLoad({dashboard_id: syslogDashboard.dashboardId});
			});
		}
	});

	syslogDashboardRenderGrid();
});

/* Name prompt for create/rename, mirroring the saved search prompt. */
function syslogDashboardPrompt(name, accept) {
	var input = document.getElementById('syslog_dashboard_prompt_name');
	input.value = name || '';
	$('#syslog_dashboard_prompt').dialog({
		modal: true,
		appendTo: 'body',
		width: 420,
		autoOpen: true,
		buttons: [
			{text: syslogDashboard.text.save, click: function() {
				var value = input.value.trim();
				if (!value) {
					input.focus();
					return;
				}
				$(this).dialog('close');
				accept(value);
			}},
			{text: syslogDashboard.text.cancel, click: function() { $(this).dialog('close'); }}
		]
	});
}