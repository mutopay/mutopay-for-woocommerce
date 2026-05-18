(function () {
	'use strict';

	if (typeof window.mutopayAdminSettings === 'undefined') {
		return;
	}
	var settings = window.mutopayAdminSettings;

	document.addEventListener('DOMContentLoaded', function () {
		initAdvancedToggle();
		initDisconnectButton();
	});

	function initAdvancedToggle() {
		var toggle = document.getElementById('mutopay-advanced-toggle');
		if (!toggle) {
			return;
		}
		var rows = document.querySelectorAll('.mutopay-advanced-field');
		rows.forEach(function (input) {
			input.closest('tr').style.display = 'none';
		});
		toggle.addEventListener('click', function (e) {
			e.preventDefault();
			var hidden = rows[0] && rows[0].closest('tr').style.display === 'none';
			rows.forEach(function (input) {
				input.closest('tr').style.display = hidden ? '' : 'none';
			});
			toggle.textContent = (hidden ? '▼ ' : '▶ ') + settings.advancedTitle;
		});
	}

	function initDisconnectButton() {
		var btn = document.getElementById('mutopay-disconnect');
		if (!btn) {
			return;
		}
		btn.addEventListener('click', function () {
			if (!window.confirm(settings.i18n.disconnectConfirm)) {
				return;
			}
			btn.disabled = true;
			btn.textContent = settings.i18n.disconnecting;
			var body = 'action=mutopay_disconnect&nonce=' + encodeURIComponent(settings.disconnectNonce);
			fetch(settings.ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body
			}).then(function () {
				location.reload();
			});
		});
	}
})();
