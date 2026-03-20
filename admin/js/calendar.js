(function () {
	'use strict';

	var cfg = window.rotapress, i18n = cfg.i18n;
	var canEdit = parseInt(cfg.can_edit, 10) === 1;
	var myId = parseInt(cfg.current_user_id, 10);
	var calendar, users = [], userMap = {}, filterValue = 'all';
	var pendingScope = null, pendingViewEvent = null, bulkSelected = {}, activeScopeId = null;
	var hasIcalToken = parseInt(cfg.has_ical_token, 10) === 1;
	var currentIcalUrl = cfg.ical_url || '';

	/* ── API ───────────────────────────────────────────────────────── */
	function hdrs() { return { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce }; }
	function chk(r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.json(); }
	function apiPost(d) { return fetch(cfg.api_base + '/events', { method: 'POST', headers: hdrs(), body: JSON.stringify(d) }).then(chk); }
	function apiPut(id, d) { return fetch(cfg.api_base + '/events/' + encodeURIComponent(id), { method: 'PUT', headers: hdrs(), body: JSON.stringify(d) }).then(chk); }
	function apiDel(id, d) { return fetch(cfg.api_base + '/events/' + encodeURIComponent(id), { method: 'DELETE', headers: hdrs(), body: JSON.stringify(d || {}) }).then(chk); }
	function apiBulk(d) { return fetch(cfg.api_base + '/events/bulk', { method: 'POST', headers: hdrs(), body: JSON.stringify(d) }).then(chk); }

	/* ── Users ─────────────────────────────────────────────────────── */
	function loadUsers() {
		return fetch(cfg.api_base + '/users', { headers: hdrs() }).then(chk).then(function (d) {
			users = d; userMap = {};
			d.forEach(function (u) { userMap[u.ID] = u; });
		});
	}
	function populateSelect(sel) {
		sel.innerHTML = '';
		if (!users.length) {
			var o = document.createElement('option');
			o.value = ''; o.textContent = i18n.nobody_available; o.disabled = true; o.selected = true;
			sel.appendChild(o); return;
		}
		var ph = document.createElement('option');
		ph.value = ''; ph.textContent = i18n.select_user; sel.appendChild(ph);
		users.forEach(function (u) {
			var o = document.createElement('option');
			o.value = u.ID; o.textContent = u.display_name; sel.appendChild(o);
		});
	}

	/* ── FullCalendar ──────────────────────────────────────────────── */
	function fetchEvents(info, ok, fail) {
		var s = info.startStr.substring(0, 10), e = info.endStr.substring(0, 10);
		fetch(cfg.api_base + '/events?start=' + s + '&end=' + e, { headers: hdrs() }).then(chk).then(function (data) {
			var filtered = data;
			if (filterValue === 'mine') {
				filtered = data.filter(function (ev) { return parseInt(ev.assigned_user, 10) === myId; });
			} else if (filterValue === 'unassigned') {
				filtered = data.filter(function (ev) { return !ev.assigned_user || parseInt(ev.assigned_user, 10) === 0; });
			} else if (filterValue !== 'all') {
				var fid = parseInt(filterValue, 10);
				filtered = data.filter(function (ev) { return parseInt(ev.assigned_user, 10) === fid; });
			}
			ok(filtered.map(function (ev) {
				var u = userMap[ev.assigned_user] || {}, color = u.color || ev.color || '#2271b1';
				var label = ev.title;
				if (ev.assigned_name) label += ' \u2013 ' + ev.assigned_name;
				if (ev.is_recurring) label = '\u21BB ' + label;
				return { id: ev.id, title: label, start: ev.start, backgroundColor: color, borderColor: color, extendedProps: ev };
			}));
		}).catch(fail);
	}

	function updateViewControls() {
		var vt = calendar.view.type;
		var tog = document.getElementById('rp-list-scope-toggle');
		var isList = (vt === 'listDay' || vt === 'listMonth' || vt === 'listYear');
		if (tog) tog.style.display = isList ? '' : 'none';

		/* Update scope button active states. */
		document.querySelectorAll('.rp-scope-btn').forEach(function (b) { b.classList.remove('rp-scope-active'); });
		if (isList) {
			/* Use the explicitly clicked button; fall back to view type on first load. */
			var activeId = activeScopeId || (vt === 'listYear' ? 'rp-scope-year' : 'rp-scope-month');
			var activeBtn = document.getElementById(activeId);
			if (activeBtn) activeBtn.classList.add('rp-scope-active');
		}
		clearBulkSelection();
	}

	function initCalendar() {
		calendar = new FullCalendar.Calendar(document.getElementById('rotapress-calendar'), {
			initialView: 'dayGridMonth',
			locale: document.documentElement.lang || 'en',
			firstDay: 1,
			height: 'auto', editable: canEdit, selectable: canEdit,
			headerToolbar: { left: 'prev,next today', center: 'title', right: 'dayGridMonth,listMonth' },
			buttonText: { month: i18n.view_month, listMonth: i18n.view_list },
			events: fetchEvents,
			datesSet: function () { updateViewControls(); },
			eventDidMount: function (info) {
				if (!canEdit) return;
				var vt = calendar.view.type;
				if (vt !== 'listDay' && vt !== 'listMonth' && vt !== 'listYear') return;
				var dot = info.el.querySelector('.fc-list-event-dot');
				if (!dot) return;
				var evId = String(info.event.extendedProps.id);
				var cb = document.createElement('input');
				cb.type = 'checkbox'; cb.className = 'rp-bulk-cb';
				cb.dataset.eventId = evId;
				cb.dataset.isRecurring = info.event.extendedProps.is_recurring ? '1' : '0';
				cb.checked = !!bulkSelected[evId];
				cb.addEventListener('change', function (e) {
					e.stopPropagation();
					if (cb.checked) bulkSelected[evId] = { recurring: info.event.extendedProps.is_recurring };
					else delete bulkSelected[evId];
					updateBulkBar();
				});
				cb.addEventListener('click', function (e) { e.stopPropagation(); });
				dot.parentNode.insertBefore(cb, dot);
			},
			dateClick: function (info) { if (canEdit) openModal(null, info.dateStr); },
			eventClick: function (info) { handleEventClick(info.event.extendedProps); },
			eventDrop: function (info) {
				var ev = info.event.extendedProps;
				if (ev.is_recurring) { alert(i18n.no_drag_recurring); info.revert(); return; }
				apiPut(ev.id, { event_date: info.event.startStr.substring(0, 10) }).catch(function () { alert(i18n.error); info.revert(); });
			}
		});
		calendar.render();
		updateViewControls();
	}

	/* ── Event click ───────────────────────────────────────────────── */
	async function handleEventClick(ev) {
		pendingScope = null;
		pendingViewEvent = null;
		if (ev.is_recurring && canEdit) {
			openModal(ev, null, true);
			return;
		}
		openModal(ev, null, false);
	}

	/* ── Freq UI ───────────────────────────────────────────────────── */
	function updateFreqUI(freq) {
		document.getElementById('rp-byday-row').style.display = (freq === 'weekly') ? '' : 'none';
		var lbl = document.getElementById('rp-interval-label');
		if (freq === 'daily') lbl.textContent = i18n.days;
		else if (freq === 'weekly') lbl.textContent = i18n.weeks;
		else lbl.textContent = i18n.months;
	}
	function resetRecFields() {
		document.getElementById('rp-freq').value = 'weekly';
		document.getElementById('rp-interval').value = '1';
		document.getElementById('rp-until').value = '';
		updateFreqUI('weekly');
		document.querySelectorAll('input[name="rp-byday"]').forEach(function (c) { c.checked = false; });
	}
	function applyRrule(rrule) {
		var f = rrule.freq || 'weekly';
		document.getElementById('rp-freq').value = f;
		document.getElementById('rp-interval').value = rrule.interval || 1;
		document.getElementById('rp-until').value = rrule.until || '';
		updateFreqUI(f);
		var bd = rrule.byday || [];
		document.querySelectorAll('input[name="rp-byday"]').forEach(function (c) { c.checked = bd.indexOf(c.value) !== -1; });
	}

	/* ── Modal ──────────────────────────────────────────────────────── */
	function openModal(ev, dateStr, viewOnly) {
		viewOnly = viewOnly === true;
		var isEdit = !!ev;
		document.getElementById('rp-event-id').value = isEdit ? ev.id : '';
		document.getElementById('rp-is-recurring').value = (isEdit && ev.is_recurring) ? '1' : '0';
		document.getElementById('rp-modal-title').textContent = isEdit ? i18n.edit_event : i18n.new_event;
		document.getElementById('rp-title').value = isEdit ? ev.title : '';
		document.getElementById('rp-date').value = isEdit ? ev.event_date : (dateStr || '');
		document.getElementById('rp-user').value = isEdit ? ev.assigned_user : '';
		document.getElementById('rp-notes').value = isEdit ? ev.notes : '';

		var noReminderRow = document.getElementById('rp-no-reminder-row');
		var noReminderChk = document.getElementById('rp-no-reminder');
		noReminderChk.checked = isEdit ? !!ev.no_reminder : false;

		var recSec = document.getElementById('rp-recurrence-section');
		var recChk = document.getElementById('rp-recurring-check');
		var recFld = document.getElementById('rp-recurrence-fields');

		if (viewOnly) {
			document.querySelectorAll('#rp-modal input:not([type="hidden"]), #rp-modal select, #rp-modal textarea').forEach(function (el) { el.disabled = true; });
			document.getElementById('rp-save').style.display = 'none';
			document.getElementById('rp-delete').style.display = 'none';
			noReminderRow.style.display = 'none';
			recSec.style.display = 'none';
			document.getElementById('rp-edit-event').style.display = '';
			document.getElementById('rp-edit-series-hint').style.display = '';
			pendingViewEvent = ev;
		} else {
			document.querySelectorAll('#rp-modal input:not([type="hidden"]), #rp-modal select, #rp-modal textarea').forEach(function (el) { el.disabled = false; });
			document.getElementById('rp-edit-event').style.display = 'none';
			document.getElementById('rp-edit-series-hint').style.display = 'none';
			document.getElementById('rp-delete').style.display = (isEdit && canEdit) ? '' : 'none';
			document.getElementById('rp-save').style.display = canEdit ? '' : 'none';
			noReminderRow.style.display = canEdit ? '' : 'none';

			var show = false;
			if (!isEdit && canEdit) {
				show = true; recChk.checked = false; recFld.style.display = 'none'; resetRecFields();
			} else if (isEdit && canEdit && ev.is_recurring && pendingScope === 'all') {
				show = true; recChk.checked = true; recFld.style.display = '';
				if (ev.rrule) applyRrule(ev.rrule); else resetRecFields();
			} else {
				recChk.checked = false; recFld.style.display = 'none';
			}
			recSec.style.display = show ? '' : 'none';
		}
		document.getElementById('rp-modal').style.display = 'flex';
		document.getElementById('rp-title').focus();
	}
	function closeModal() { document.getElementById('rp-modal').style.display = 'none'; pendingScope = null; pendingViewEvent = null; }

	/* ── Scope dialog ──────────────────────────────────────────────── */
	function askScope(type) {
		return new Promise(function (resolve) {
			var dlg = document.getElementById('rp-scope-dialog');
			document.getElementById('rp-scope-title').textContent = (type === 'delete') ? i18n.delete_recurring : i18n.edit_recurring;
			document.getElementById('rp-scope-description').textContent = '';
			/* Show all 3 options for single-event edit/delete. */
			document.querySelectorAll('#rp-scope-dialog .rp-scope-option').forEach(function (el) { el.style.display = ''; });
			dlg.querySelectorAll('input[name="rp-scope"]')[0].checked = true;
			dlg.style.display = 'flex';
			var onC, onX;
			function done() {
				document.getElementById('rp-scope-continue').removeEventListener('click', onC);
				document.getElementById('rp-scope-cancel').removeEventListener('click', onX);
				dlg.style.display = 'none';
			}
			onC = function () { var c = dlg.querySelector('input[name="rp-scope"]:checked'); done(); resolve(c ? c.value : null); };
			onX = function () { done(); resolve(null); };
			document.getElementById('rp-scope-continue').addEventListener('click', onC);
			document.getElementById('rp-scope-cancel').addEventListener('click', onX);
		});
	}

	/**
	 * Bulk scope dialog — only "this" and "all" with explanatory text.
	 */
	function askBulkScope() {
		return new Promise(function (resolve) {
			var dlg = document.getElementById('rp-scope-dialog');
			document.getElementById('rp-scope-title').textContent = i18n.bulk_recurring_title;
			document.getElementById('rp-scope-description').textContent = i18n.bulk_recurring_desc;
			/* Hide the "following" option for bulk. */
			document.querySelectorAll('#rp-scope-dialog .rp-scope-option').forEach(function (el, i) {
				el.style.display = (i === 1) ? 'none' : ''; /* index 1 = following */
			});
			dlg.querySelectorAll('input[name="rp-scope"]')[0].checked = true;
			dlg.style.display = 'flex';
			var onC, onX;
			function done() {
				document.getElementById('rp-scope-continue').removeEventListener('click', onC);
				document.getElementById('rp-scope-cancel').removeEventListener('click', onX);
				dlg.style.display = 'none';
			}
			onC = function () { var c = dlg.querySelector('input[name="rp-scope"]:checked'); done(); resolve(c ? c.value : null); };
			onX = function () { done(); resolve(null); };
			document.getElementById('rp-scope-continue').addEventListener('click', onC);
			document.getElementById('rp-scope-cancel').addEventListener('click', onX);
		});
	}

	/* ── Save / Delete ─────────────────────────────────────────────── */
	async function saveEvent() {
		var id = document.getElementById('rp-event-id').value;
		var isRec = document.getElementById('rp-is-recurring').value === '1';
		var isEdit = id !== '';
		var data = {
			title: document.getElementById('rp-title').value,
			event_date: document.getElementById('rp-date').value,
			assigned_user: parseInt(document.getElementById('rp-user').value, 10) || 0,
			notes: document.getElementById('rp-notes').value
		};
		data.no_reminder = document.getElementById('rp-no-reminder').checked ? 1 : 0;
		if (document.getElementById('rp-recurring-check').checked) {
			var bd = [];
			document.querySelectorAll('input[name="rp-byday"]:checked').forEach(function (c) { bd.push(c.value); });
			data.rrule = { freq: document.getElementById('rp-freq').value, interval: parseInt(document.getElementById('rp-interval').value, 10) || 1, until: document.getElementById('rp-until').value };
			if (data.rrule.freq === 'weekly' && bd.length) data.rrule.byday = bd;
		}
		/* Require a title. */
	if (!data.title.trim()) { alert(i18n.title_required); document.getElementById('rp-title').focus(); return; }
	/* Warn if no participant assigned. */
	if (!data.assigned_user && !window.confirm(i18n.confirm_no_assignee)) return;
		try {
			if (isEdit) { if (isRec && pendingScope) data.recurrence_scope = pendingScope; await apiPut(id, data); }
			else { await apiPost(data); }
			closeModal(); calendar.refetchEvents();
		} catch (e) { alert(i18n.error); }
	}

	async function deleteEvent() {
		var id = document.getElementById('rp-event-id').value;
		var isRec = document.getElementById('rp-is-recurring').value === '1';
		if (!id) return;
		var body = {};
		if (isRec) {
			var s = await askScope('delete');
			if (!s) return;
			body.recurrence_scope = s;
		} else {
			if (!window.confirm(i18n.confirm_delete)) return;
		}
		try { await apiDel(id, body); closeModal(); calendar.refetchEvents(); }
		catch (e) { alert(i18n.error); }
	}

	/* ── Bulk ──────────────────────────────────────────────────────── */
	function getSelectedIds() { return Object.keys(bulkSelected); }
	function checkBulkHasRecurring() {
		var ids = getSelectedIds();
		for (var i = 0; i < ids.length; i++) {
			if (bulkSelected[ids[i]] && bulkSelected[ids[i]].recurring) return true;
		}
		return false;
	}
	function updateBulkBar() {
		var ids = getSelectedIds(), bar = document.getElementById('rp-bulk-bar');
		if (!bar) return;
		if (!ids.length) { bar.style.display = 'none'; return; }
		bar.style.display = 'flex';
		document.getElementById('rp-bulk-count').textContent = i18n.n_selected.replace('%d', ids.length);
	}
	function clearBulkSelection() {
		bulkSelected = {};
		document.querySelectorAll('.rp-bulk-cb').forEach(function (c) { c.checked = false; });
		var sa = document.getElementById('rp-select-all');
		if (sa) sa.checked = false;
		updateBulkBar();
	}
	function handleSelectAll(checked) {
		document.querySelectorAll('.rp-bulk-cb').forEach(function (cb) {
			var evId = cb.dataset.eventId;
			cb.checked = checked;
			if (checked) bulkSelected[evId] = { recurring: cb.dataset.isRecurring === '1' };
			else delete bulkSelected[evId];
		});
		updateBulkBar();
	}

	async function bulkReassign() {
		var ids = getSelectedIds(), uid = parseInt(document.getElementById('rp-bulk-user').value, 10);
		if (!ids.length || !uid) return;
		var body = { action: 'reassign', ids: ids, assigned_user: uid };
		if (checkBulkHasRecurring()) {
			var scope = await askBulkScope();
			if (!scope) return;
			body.recurrence_scope = scope;
		}
		try { await apiBulk(body); clearBulkSelection(); calendar.refetchEvents(); }
		catch (e) { alert(i18n.error); }
	}

	async function bulkDelete() {
		var ids = getSelectedIds();
		if (!ids.length) return;
		var body = { action: 'delete', ids: ids };
		if (checkBulkHasRecurring()) {
			var scope = await askBulkScope();
			if (!scope) return;
			body.recurrence_scope = scope;
		}
		if (!window.confirm(i18n.confirm_bulk_delete)) return;
		try { await apiBulk(body); clearBulkSelection(); calendar.refetchEvents(); }
		catch (e) { alert(i18n.error); }
	}

	/* ── iCal panel ────────────────────────────────────────────────── */
	function showIcalState() {
		var noFeed = document.getElementById('rp-ical-no-feed');
		var hasFeed = document.getElementById('rp-ical-has-feed');
		if (hasIcalToken && currentIcalUrl) {
			noFeed.style.display = 'none';
			hasFeed.style.display = '';
			document.getElementById('rp-ical-url').value = currentIcalUrl;
		} else {
			noFeed.style.display = '';
			hasFeed.style.display = 'none';
		}
	}
	function openIcal() {
		showIcalState();
		document.getElementById('rp-ical-panel').style.display = 'flex';
	}
	function closeIcal() { document.getElementById('rp-ical-panel').style.display = 'none'; }
	function copyIcal() {
		var inp = document.getElementById('rp-ical-url');
		if (navigator.clipboard) navigator.clipboard.writeText(inp.value).then(flashCopy);
		else { inp.select(); document.execCommand('copy'); flashCopy(); }
	}
	function flashCopy() {
		var b = document.getElementById('rp-copy-url'), o = b.textContent;
		b.textContent = i18n.copied; setTimeout(function () { b.textContent = o; }, 1500);
	}

	async function generateIcalToken() {
		try {
			var res = await fetch(cfg.api_base + '/ical/generate', { method: 'POST', headers: hdrs() }).then(chk);
			currentIcalUrl = res.url;
			hasIcalToken = true;
			showIcalState();
		} catch (e) { alert(i18n.error); }
	}

	async function revokeOwnIcalToken() {
		if (!window.confirm(i18n.ical_revoke_confirm)) return;
		try {
			await fetch(cfg.api_base + '/ical/revoke', { method: 'POST', headers: hdrs() }).then(chk);
			currentIcalUrl = '';
			hasIcalToken = false;
			showIcalState();
		} catch (e) { alert(i18n.error); }
	}

	async function regenerateIcalToken() {
		if (!window.confirm(i18n.regen_confirm)) return;
		try {
			var res = await fetch(cfg.api_base + '/ical/generate', { method: 'POST', headers: hdrs() }).then(chk);
			currentIcalUrl = res.url;
			hasIcalToken = true;
			showIcalState();
		} catch (e) { alert(i18n.error); }
	}

	/* ── Bind ──────────────────────────────────────────────────────── */
	function bindAll() {
		document.getElementById('rp-save').addEventListener('click', saveEvent);
		document.getElementById('rp-delete').addEventListener('click', deleteEvent);
		document.getElementById('rp-close').addEventListener('click', closeModal);
		document.getElementById('rp-edit-event').addEventListener('click', async function () {
			var ev = pendingViewEvent;
			closeModal();
			var scope = await askScope('edit');
			if (!scope) return;
			pendingScope = scope;
			openModal(ev, null, false);
		});

		var addBtn = document.getElementById('rp-add-event');
		if (addBtn) addBtn.addEventListener('click', function () { if (canEdit) openModal(null, ''); });

		/* Filter. */
		document.getElementById('rp-filter-participant').addEventListener('change', function () {
			filterValue = this.value;
			calendar.refetchEvents();
		});

		/* Scope toggle: Year / Month / Today. */
		var bYear = document.getElementById('rp-scope-year');
		var bMonth = document.getElementById('rp-scope-month');
		var bToday = document.getElementById('rp-scope-today');
		if (bYear)  bYear.addEventListener('click',  function () { activeScopeId = 'rp-scope-year';  calendar.changeView('listYear'); });
		if (bMonth) bMonth.addEventListener('click', function () { activeScopeId = 'rp-scope-month'; calendar.changeView('listMonth'); calendar.today(); });
		if (bToday) bToday.addEventListener('click', function () {
			activeScopeId = 'rp-scope-today';
			calendar.changeView('listDay');
			calendar.today();
		});

		/* Select all. */
		var sa = document.getElementById('rp-select-all');
		if (sa) sa.addEventListener('change', function () { handleSelectAll(this.checked); });

		/* Bulk. */
		var brBtn = document.getElementById('rp-bulk-reassign');
		var bdBtn = document.getElementById('rp-bulk-delete');
		var bcBtn = document.getElementById('rp-bulk-cancel');
		if (brBtn) brBtn.addEventListener('click', bulkReassign);
		if (bdBtn) bdBtn.addEventListener('click', bulkDelete);
		if (bcBtn) bcBtn.addEventListener('click', clearBulkSelection);
		var bulkSel = document.getElementById('rp-bulk-user');
		if (bulkSel) populateSelect(bulkSel);

		/* iCal. */
		document.getElementById('rp-ical-toggle').addEventListener('click', openIcal);
		document.getElementById('rp-ical-close').addEventListener('click', closeIcal);
		document.getElementById('rp-ical-close-nofeed').addEventListener('click', closeIcal);
		document.getElementById('rp-copy-url').addEventListener('click', copyIcal);
		document.getElementById('rp-ical-generate').addEventListener('click', generateIcalToken);
		document.getElementById('rp-ical-regen').addEventListener('click', regenerateIcalToken);
		document.getElementById('rp-ical-revoke').addEventListener('click', revokeOwnIcalToken);

		/* Recurrence. */
		document.getElementById('rp-recurring-check').addEventListener('change', function () {
			document.getElementById('rp-recurrence-fields').style.display = this.checked ? '' : 'none';
		});
		document.getElementById('rp-freq').addEventListener('change', function () { updateFreqUI(this.value); });

		/* Close overlays. */
		document.querySelectorAll('.rp-modal-overlay').forEach(function (o) {
			o.addEventListener('click', function (e) { if (e.target === o) { o.style.display = 'none'; pendingScope = null; pendingViewEvent = null; } });
		});
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') { document.querySelectorAll('.rp-modal-overlay').forEach(function (o) { o.style.display = 'none'; }); pendingScope = null; pendingViewEvent = null; }
		});
	}

	/* ── Init ──────────────────────────────────────────────────────── */
	document.addEventListener('DOMContentLoaded', function () {
		loadUsers().then(function () {
			populateSelect(document.getElementById('rp-user'));
			var sel = document.getElementById('rp-filter-participant');
			var unassignedOpt = sel.querySelector('option[value="unassigned"]');
			users.forEach(function (u) {
				if (parseInt(u.ID, 10) === myId) return;
				var opt = document.createElement('option');
				opt.value = u.ID;
				opt.textContent = u.display_name;
				sel.insertBefore(opt, unassignedOpt);
			});
			initCalendar(); bindAll();
		}).catch(function (e) { console.error('RotaPress init:', e); });
	});
})();
