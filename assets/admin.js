/* AutoSocial Poster — Admin JS */
/* global sasp_ajax */

(function ($) {
	'use strict';

	var s = sasp_ajax.strings;

	// ── Helpers ───────────────────────────────────────────────────────────────

	function setInlineResult($el, message, ok) {
		$el.removeClass('sasp-ok sasp-fail')
		   .addClass(ok ? 'sasp-ok' : 'sasp-fail')
		   .text(message)
		   .show();
	}

	function setNotice($el, message, ok) {
		$el.removeClass('sasp-ok sasp-fail')
		   .addClass(ok ? 'sasp-ok' : 'sasp-fail')
		   .text(message)
		   .show();
	}

	// ── Test Facebook ─────────────────────────────────────────────────────────

	$('#sasp-test-facebook').on('click', function () {
		var $btn    = $(this);
		var $result = $('#sasp-fb-result');
		var origTxt = $btn.text();

		$btn.prop('disabled', true).text(s.testing);
		$result.hide();

		$.post(sasp_ajax.ajax_url, {
			action: 'sasp_test_facebook',
			nonce:  sasp_ajax.nonce
		})
		.done(function (res) {
			setInlineResult($result, res.data, res.success);
		})
		.fail(function () {
			setInlineResult($result, s.conn_error, false);
		})
		.always(function () {
			$btn.prop('disabled', false).text(origTxt);
		});
	});

	// ── Test Instagram ────────────────────────────────────────────────────────

	$('#sasp-test-instagram').on('click', function () {
		var $btn    = $(this);
		var $result = $('#sasp-ig-result');
		var origTxt = $btn.text();

		$btn.prop('disabled', true).text(s.testing);
		$result.hide();

		$.post(sasp_ajax.ajax_url, {
			action: 'sasp_test_instagram',
			nonce:  sasp_ajax.nonce
		})
		.done(function (res) {
			setInlineResult($result, res.data, res.success);
		})
		.fail(function () {
			setInlineResult($result, s.conn_error, false);
		})
		.always(function () {
			$btn.prop('disabled', false).text(origTxt);
		});
	});

	// ── Post Now ──────────────────────────────────────────────────────────────

	$('#sasp-post-now').on('click', function () {
		if (!window.confirm(s.confirm_post)) {
			return;
		}

		var $btn    = $(this);
		var $notice = $('#sasp-action-result');
		var origTxt = $btn.text();

		$btn.prop('disabled', true).text(s.posting);
		$notice.hide();

		$.post(sasp_ajax.ajax_url, {
			action: 'sasp_post_now',
			nonce:  sasp_ajax.nonce
		})
		.done(function (res) {
			setNotice($notice, res.data, res.success);
		})
		.fail(function () {
			setNotice($notice, s.conn_error, false);
		})
		.always(function () {
			$btn.prop('disabled', false).text(origTxt);
		});
	});

	// ── Reset history ─────────────────────────────────────────────────────────

	$('#sasp-reset-history').on('click', function () {
		if (!window.confirm(s.confirm_reset)) {
			return;
		}

		var $btn    = $(this);
		var $notice = $('#sasp-action-result');

		$btn.prop('disabled', true);
		$notice.hide();

		$.post(sasp_ajax.ajax_url, {
			action: 'sasp_reset_history',
			nonce:  sasp_ajax.nonce
		})
		.done(function (res) {
			setNotice($notice, res.data, res.success);
			if (res.success) {
				// Refresh page so the posted-count in the status bar updates.
				setTimeout(function () { window.location.reload(); }, 1200);
			}
		})
		.fail(function () {
			setNotice($notice, s.conn_error, false);
		})
		.always(function () {
			$btn.prop('disabled', false);
		});
	});

	// ── Clear logs ────────────────────────────────────────────────────────────

	$('#sasp-clear-logs').on('click', function () {
		if (!window.confirm(s.confirm_clear)) {
			return;
		}

		var $btn = $(this);
		$btn.prop('disabled', true);

		$.post(sasp_ajax.ajax_url, {
			action: 'sasp_clear_logs',
			nonce:  sasp_ajax.nonce
		})
		.done(function (res) {
			if (res.success) {
				window.location.reload();
			}
		})
		.always(function () {
			$btn.prop('disabled', false);
		});
	});

	// ── Inline help toggles ───────────────────────────────────────────────────

	$('.sasp-help-toggle').on('click', function (e) {
		e.preventDefault();
		var target = $(this).data('target');
		var $panel = $('#' + target);
		var isVisible = $panel.is(':visible');
		$panel.toggle(150);
		// Update arrow glyph.
		var txt = $(this).text();
		$(this).text(
			isVisible
				? txt.replace('▴', '▾')
				: txt.replace('▾', '▴')
		);
	});

	// ── Dismiss setup checklist ───────────────────────────────────────────────

	$('#sasp-dismiss-checklist').on('click', function () {
		$.post(sasp_ajax.ajax_url, {
			action: 'sasp_dismiss_checklist',
			nonce:  sasp_ajax.nonce
		});
		$('#sasp-checklist').slideUp(200);
	});

}(jQuery));
