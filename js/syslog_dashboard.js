/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2004-2026 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDtool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
*/

/* Syslog Status adapter for the shared card chrome (js/cards.js). The
 * CactiCards controller owns drag/keyboard reorder, the per-card tools and the
 * debounced layout save; this adapter maps those hooks to syslog.php's status
 * endpoints (status_card / status_layout) and wires syslog's extras: the
 * "Add card" catalogue, an auto-refresh interval and a manual refresh-all. Card
 * markup uses the shared .cc* chrome contract; the card bodies keep syslog's
 * own prefixed content classes (tables, key/value rows, notes). */

function initSyslogStatus() {
	$(function() {
		var grid = document.getElementById('syslog_status_grid');

		if (!grid || !window.CactiCards) {
			return;
		}

		function catalogue() {
			return typeof syslogStatusCatalog !== 'undefined' ? syslogStatusCatalog : null;
		}

		function syncAddOptions() {
			var add = document.getElementById('syslog_status_add');
			var cat = catalogue();

			if (!add || !cat) {
				return;
			}

			var present = {};

			grid.querySelectorAll('.ccCard').forEach(function(card) {
				if (card.dataset.card) {
					present[card.dataset.card] = true;
				}
			});

			while (add.options.length > 1) {
				add.remove(1);
			}

			Object.keys(cat).forEach(function(key) {
				if (!present[key]) {
					var option = document.createElement('option');
					option.value = key;
					option.textContent = cat[key];
					add.appendChild(option);
				}
			});
		}

		// A per-card-id request generation shared by the per-card refresh (routed
		// through the controller) and the toolbar's Refresh all (which calls
		// replaceCard directly). Both refresh paths pass through this helper, so
		// tracking the generation here lets an earlier response resolve to null
		// instead of overwriting the markup of a newer refresh for the same card.
		var fetchGen = {};

		function fetchCard(id, expanded) {
			var gen = (fetchGen[id] = (fetchGen[id] || 0) + 1);

			return $.post('syslog.php', {
				action: 'status_card',
				tab: 'status',
				card: id,
				expanded: expanded ? '1' : '0',
				__csrf_magic: csrfMagicToken
			}, null, 'json').then(function(d) {
				if (fetchGen[id] !== gen) {
					return null;
				}

				return d && d.html ? d.html : null;
			});
		}

		function maximize(card) {
			var dialog = document.getElementById('syslog_status_dialog');

			if (!dialog) {
				return;
			}

			var titleEl = card.querySelector('.ccTitle');
			var body    = card.querySelector('.ccCardBody');

			dialog.innerHTML = '<div class="ccDialogBody syslogStatusDialogBody">' + (body ? body.innerHTML : '') + '</div>';

			if (typeof applySyslogTheme === 'function') {
				applySyslogTheme(dialog);
			}

			$(dialog).dialog({
				modal: true,
				appendTo: 'body',
				width: Math.min(900, $(window).width() - 40),
				title: titleEl ? titleEl.textContent : '',
				open: function() {
					if (typeof applySyslogTheme === 'function') {
						applySyslogTheme(this);
					}
				}
			});
		}

		/* The live card currently in the grid for an id; an overlapping swap may
		 * have detached the node captured when a request was issued. */
		function liveCard(id) {
			var match = null;

			grid.querySelectorAll('.ccCard').forEach(function(card) {
				if (match === null && card.dataset.card === id) {
					match = card;
				}
			});

			return match;
		}

		// Guard against appending duplicate cards when the same absent card is
		// chosen twice before its fetch completes (the shared controller appends
		// every successful add response).
		var pendingAdds = {};

		// Serialize layout saves so a newer snapshot cannot be overwritten by a
		// slower, earlier POST that the shared debounce already released.
		var layoutSaving = false;
		var layoutQueued = null;

		function sendLayout(payload) {
			layoutSaving = true;

			$.post('syslog.php', {
				action: 'status_layout',
				tab: 'status',
				layout: JSON.stringify(payload),
				__csrf_magic: csrfMagicToken
			}, null, 'json').always(function() {
				layoutSaving = false;

				if (layoutQueued) {
					var next = layoutQueued;
					layoutQueued = null;
					sendLayout(next);
				}
			});
		}

		var adapter = {
			addControl: 'syslog_status_add',

			// Keep syslog's content palette (the note accent) in sync with the
			// live Cacti theme; the shared chrome paints from the --cc-* custom
			// properties bound in css/search.css.
			applyTheme: function(gridEl) {
				if (typeof applySyslogTheme === 'function') {
					applySyslogTheme(gridEl);
				}
			},

			fetchCard: function(id, state) {
				return fetchCard(id, state && state.expanded);
			},

			onAddCard: function(id) {
				if (liveCard(id) || pendingAdds[id]) {
					return null;
				}

				pendingAdds[id] = true;

				return fetchCard(id, false).always(function() {
					delete pendingAdds[id];
				});
			},

			onLayoutChange: function(state) {
				var expanded = {};

				state.order.forEach(function(id) {
					if (state.cards[id] && state.cards[id].expanded) {
						expanded[id] = true;
					}
				});

				var payload = {order: state.order, expanded: expanded};

				if (layoutSaving) {
					layoutQueued = payload;
				} else {
					sendLayout(payload);
				}

				syncAddOptions();
			},

			maximize: function(id, mode, card) {
				maximize(card);
			}
		};

		CactiCards.init(grid, adapter);
		syncAddOptions();

		// Changing the interval reloads the page so Cacti's page refresh picks up
		// the new value; it persists in the session for subsequent auto-reloads.
		var interval = document.getElementById('syslog_status_refresh');
		if (interval) {
			$(interval).off('change.syslogstatus').on('change.syslogstatus', function() {
				window.location = 'syslog.php?tab=status&refresh=' + encodeURIComponent(this.value);
			});
		}

		var refreshNow = document.getElementById('syslog_status_refresh_now');
		if (refreshNow) {
			$(refreshNow).off('click.syslogstatus').on('click.syslogstatus', function() {
				var icon = this.querySelector('.fa, .fas');
				var cards = Array.prototype.slice.call(grid.querySelectorAll('.ccCard'));
				var pending = cards.length;

				if (!pending) {
					return;
				}

				if (icon) {
					icon.classList.add('fa-spin');
				}

				cards.forEach(function(card) {
					var wasExpanded = card.classList.contains('ccCardExpanded');
					fetchCard(card.dataset.card, wasExpanded).then(function(html) {
						if (html && card.parentNode && window.CactiCards) {
							CactiCards.replaceCard(grid, adapter, card, html, wasExpanded);
						}
					}).always(function() {
						pending--;
						if (pending <= 0 && icon) {
							icon.classList.remove('fa-spin');
						}
					});
				});
			});
		}
	});
}
