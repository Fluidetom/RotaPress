(function () {
	'use strict';

	document.addEventListener('DOMContentLoaded', function () {
		/* Auto-grow body textarea. */
		var bodyField = document.getElementById('rp-email-body');
		if (bodyField) {
			function growBody() {
				bodyField.style.height = 'auto';
				bodyField.style.height = bodyField.scrollHeight + 'px';
			}
			growBody();
			bodyField.addEventListener('input', growBody);
		}

		/* Color picker live preview. */
		document.querySelectorAll('.rp-color-picker').forEach(function (picker) {
			function updateSwatch() {
				var swatch = picker.previousElementSibling;
				if (swatch && swatch.classList.contains('rp-color-swatch')) {
					swatch.style.background = picker.value;
				}
			}
			picker.addEventListener('input', updateSwatch);
			picker.addEventListener('change', updateSwatch);
		});

		/* Test email button. */
		var testBtn    = document.getElementById('rp-test-email-btn');
		var testAddr   = document.getElementById('rp-test-email-addr');
		var testMode   = document.getElementById('rp-test-email-mode');
		var testStatus = document.getElementById('rp-test-email-status');

		/* ── Participant removal guard ────────────────────────────── */

		/* Snapshot checked state on page load. */
		var participantSnapshot = {};
		document.querySelectorAll('input[name^="rotapress_participants["]').forEach(function (cb) {
			var m = cb.name.match(/\[(\d+)\]/);
			if (m) { participantSnapshot[m[1]] = cb.checked; }
		});

		/* Intercept form submit. */
		var settingsForm = document.querySelector('form[action*="admin-post.php"]');
		if (settingsForm && window.rotapress_settings) {
			settingsForm.addEventListener('submit', function (e) {
				e.preventDefault();
				var removed = [];
				document.querySelectorAll('input[name^="rotapress_participants["]').forEach(function (cb) {
					var m = cb.name.match(/\[(\d+)\]/);
					if (m && participantSnapshot[m[1]] === true && !cb.checked) {
						removed.push({ uid: m[1], name: getParticipantName(m[1]) });
					}
				});
				if (!removed.length) { settingsForm.submit(); return; }
				processNext(removed, 0, function () { settingsForm.submit(); });
			});
		}

		function getParticipantName(uid) {
			var list = window.rotapress_settings ? (window.rotapress_settings.participants || []) : [];
			for (var i = 0; i < list.length; i++) {
				if (String(list[i].id) === String(uid)) { return list[i].name; }
			}
			return 'User #' + uid;
		}

		function processNext(users, index, onComplete) {
			if (index >= users.length) { onComplete(); return; }
			checkAndHandle(users[index], function () {
				processNext(users, index + 1, onComplete);
			});
		}

		function checkAndHandle(user, onDone) {
			var cfg = window.rotapress_settings;
			fetch(cfg.api_base + '/users/' + user.uid + '/future-events', {
				headers: { 'X-WP-Nonce': cfg.nonce }
			})
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (!data.count) { onDone(); return; }
				showRemoveModal(user, data, onDone);
			})
			.catch(function () { onDone(); });
		}

		var removeModalInjected = false;

		function showRemoveModal(user, data, onDone) {
			var cfg = window.rotapress_settings;
			var i18n = cfg.i18n;

			if (!removeModalInjected) {
				var overlay = document.createElement('div');
				overlay.id = 'rp-remove-modal';
				overlay.className = 'rp-settings-modal-overlay';
				overlay.innerHTML =
					'<div class="rp-settings-modal-box">' +
						'<h2 id="rp-remove-modal-title"></h2>' +
						'<p id="rp-remove-modal-name"></p>' +
						'<p id="rp-remove-modal-series" class="description" style="display:none"></p>' +
						'<p><label id="rp-remove-reassign-label"></label><br>' +
						'<select id="rp-remove-select" style="width:100%;max-width:300px"></select></p>' +
						'<div class="rp-remove-modal-buttons">' +
							'<button type="button" class="button button-primary" id="rp-remove-reassign-btn"></button>' +
							'<button type="button" class="button rp-btn-delete" id="rp-remove-delete-btn"></button>' +
							'<button type="button" class="button" id="rp-remove-clear-btn"></button>' +
							'<button type="button" class="button" id="rp-remove-keep-btn"></button>' +
						'</div>' +
						'<p id="rp-remove-status" style="color:#d63638;margin-top:8px"></p>' +
					'</div>';
				document.body.appendChild(overlay);
				removeModalInjected = true;
			}

			var overlay      = document.getElementById('rp-remove-modal');
			var titleEl      = document.getElementById('rp-remove-modal-title');
			var nameEl       = document.getElementById('rp-remove-modal-name');
			var seriesEl     = document.getElementById('rp-remove-modal-series');
			var reassignLbl  = document.getElementById('rp-remove-reassign-label');
			var selectEl     = document.getElementById('rp-remove-select');
			var statusEl     = document.getElementById('rp-remove-status');

			titleEl.textContent    = i18n.remove_has_events.replace('%d', data.count);
			nameEl.textContent     = user.name;
			reassignLbl.textContent = i18n.remove_reassign_label;
			statusEl.textContent   = '';

			var hasRecurring = data.events.some(function (ev) { return ev.is_recurring; });
			if (hasRecurring) {
				seriesEl.textContent = i18n.remove_series_note;
				seriesEl.style.display = '';
			} else {
				seriesEl.style.display = 'none';
			}

			/* Populate reassign dropdown from currently-checked participants, excluding removed user. */
			selectEl.innerHTML = '';
			document.querySelectorAll('input[name^="rotapress_participants["]').forEach(function (cb) {
				var m = cb.name.match(/\[(\d+)\]/);
				if (m && cb.checked && String(m[1]) !== String(user.uid)) {
					var opt = document.createElement('option');
					opt.value = m[1];
					opt.textContent = getParticipantName(m[1]);
					selectEl.appendChild(opt);
				}
			});

			/* Clone-replace buttons to clear stale listeners. */
			function freshBtn(id) {
				var old = document.getElementById(id);
				var fresh = old.cloneNode(true);
				old.parentNode.replaceChild(fresh, old);
				return fresh;
			}

			var reassignBtn = freshBtn('rp-remove-reassign-btn');
			var deleteBtn   = freshBtn('rp-remove-delete-btn');
			var clearBtn    = freshBtn('rp-remove-clear-btn');
			var keepBtn     = freshBtn('rp-remove-keep-btn');

			reassignBtn.textContent = i18n.remove_reassign_btn;
			deleteBtn.textContent   = i18n.remove_delete_btn.replace('%s', user.name);
			clearBtn.textContent    = i18n.remove_clear_btn;
			keepBtn.textContent     = i18n.remove_keep_btn;

			/* Disable reassign if no options available. */
			reassignBtn.disabled = (selectEl.options.length === 0);

			function closeModal() {
				overlay.style.display = 'none';
				onDone();
			}

			function setProcessing() {
				statusEl.textContent = i18n.remove_processing;
				reassignBtn.disabled = true;
				deleteBtn.disabled   = true;
				clearBtn.disabled    = true;
				keepBtn.disabled     = true;
			}

			function resetButtons() {
				reassignBtn.disabled = (selectEl.options.length === 0);
				deleteBtn.disabled   = false;
				clearBtn.disabled    = false;
				keepBtn.disabled     = false;
			}

			reassignBtn.addEventListener('click', function () {
				var targetUid = parseInt(selectEl.value, 10);
				setProcessing();
				callBulk('reassign', data.events, targetUid, closeModal, statusEl, resetButtons);
			});

			deleteBtn.addEventListener('click', function () {
				if (!window.confirm(i18n.remove_delete_confirm.replace('%s', user.name))) { return; }
				setProcessing();
				callBulk('delete', data.events, 0, closeModal, statusEl, resetButtons);
			});

			clearBtn.addEventListener('click', function () {
				if (!window.confirm(i18n.remove_clear_confirm.replace('%s', user.name))) { return; }
				setProcessing();
				callBulk('reassign', data.events, 0, closeModal, statusEl, resetButtons);
			});

			keepBtn.addEventListener('click', function () {
				closeModal();
			});

			overlay.style.display = 'flex';
		}

		function callBulk(action, events, targetUid, onSuccess, statusEl, onError) {
			var cfg = window.rotapress_settings;
			var payload = {
				action: action,
				ids: events.map(function (ev) { return String(ev.id); }),
				recurrence_scope: 'all'
			};
			if (action === 'reassign') { payload.assigned_user = targetUid; }
			fetch(cfg.api_base + '/events/bulk', {
				method: 'POST',
				headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': cfg.nonce },
				body: JSON.stringify(payload)
			})
			.then(function (r) {
				if (!r.ok) { return r.json().then(function (d) { throw new Error(d.message || 'Error'); }); }
				return r.json();
			})
			.then(function () { onSuccess(); })
			.catch(function (err) {
				statusEl.textContent = err.message;
				onError();
			});
		}

		/* ── End participant removal guard ────────────────────────── */

		if (testBtn && testAddr && testMode && testStatus && window.rotapress_settings) {
			var cfg = window.rotapress_settings;

			testBtn.addEventListener('click', function () {
				var email    = testAddr.value.trim();
				var modeVal  = testMode.value;
				if (!email) { testAddr.focus(); return; }

				var payload = { to: email };
				if (modeVal === 'dummy') {
					payload.mode = 'dummy';
				} else {
					payload.mode  = 'events';
					payload.count = parseInt(modeVal, 10);
				}

				testBtn.disabled = true;
				testStatus.textContent = cfg.i18n.sending;
				testStatus.className   = '';
				testStatus.style.color = '';

				fetch(cfg.api_base + '/test-email', {
					method:  'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce':   cfg.nonce
					},
					body: JSON.stringify(payload)
				})
				.then(function (r) {
					/* Capture error body before throwing so we can surface the message. */
					if (!r.ok) {
						return r.json().then(function (d) {
							throw new Error(d.message || ('HTTP ' + r.status));
						});
					}
					return r.json();
				})
				.then(function (data) {
					if (data.mode === 'events') {
						testStatus.textContent = cfg.i18n.sent_ok_n.replace('%d', data.sent);
					} else {
						testStatus.textContent = cfg.i18n.sent_ok;
					}
					testStatus.style.color = '#00a32a';
				})
				.catch(function (err) {
					testStatus.textContent = err.message || cfg.i18n.sent_fail;
					testStatus.style.color = '#d63638';
				})
				.finally(function () {
					testBtn.disabled = false;
				});
			});
		}
	});
})();
