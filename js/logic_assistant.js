/**
 * Logic Assistant - adds a "Suggest logic" panel to REDCap's logic editor dialog (openLogicEditor in Project.js).
 *
 * Approach: wrap the global openLogicEditor() so that, after core has built the dialog, a panel is
 * injected above the ACE editor. Nothing in core is modified. All model output is inserted as text
 * (never HTML) and is only applied to the editor when the user clicks Replace or Insert.
 */
window.IntersectLogicAssistant = (function () {
	'use strict';

	let jsmo = null;
	let config = { contexts: {} };
	let currentOpener = null;

	function init(moduleObject, moduleConfig, isRetry) {
		jsmo = moduleObject;
		config = moduleConfig || config;
		if (typeof window.openLogicEditor !== 'function') {
			// Project.js may not have loaded yet when the hook output runs; try once more when the DOM is ready
			if (!isRetry) $(function () { init(moduleObject, moduleConfig, true); });
			return;
		}
		if (window.openLogicEditor.__logicAssistantWrapped) return;

		const original = window.openLogicEditor;
		const wrapped = function (opener, fullscreen, onUpdateFn) {
			const result = original.apply(this, arguments);
			currentOpener = (opener instanceof HTMLElement) ? $(opener) : opener;
			// Core may defer building the dialog (e.g. while ace.js loads), so check shortly after
			setTimeout(injectPanel, 50);
			return result;
		};
		wrapped.__logicAssistantWrapped = true;
		window.openLogicEditor = wrapped;
	}

	/* ---------- Context detection ---------- */

	function guessContext(opener) {
		const id = ((opener && opener.attr && (opener.attr('id') || opener.attr('name'))) || '').toLowerCase();
		const url = window.location.href.toLowerCase();

		// Element IDs/names used by REDCap core's logic boxes (checked against REDCap 19.9.9).
		// Anything not recognised falls back to "Other / not sure"; the user can always change it.
		if (id === 'field_annotation') return 'action_tag';
		if (id === 'auto_schedule_logic') return 'auto_schedule';
		if (id === 'pdfsnapshotlogic' || id === 'trigger_logic') return 'pdf_snapshot';
		if (id === 'realtime-logic') return 'randomisation';
		if (id === 'filter_logic') return 'dashboard_filter';
		if (id === 'allow-participant-condition') return 'mycap';
		if (id === 'end_survey_redirect_next_survey_logic') return 'survey_auto_continue';
		if (id.startsWith('sqcondlogic') || id.startsWith('sqcondoption-logic')) return 'survey_queue';
		if (id.startsWith('sscondlogic') || id.startsWith('sscondoption-logic')) return 'asi';
		if (id.startsWith('control-condition')) return 'form_display';
		if (id === 'advbranchingbox' || id.includes('branch')) return 'branching';
		if (id === 'element_enum' && url.includes('design/')) return 'calc';
		if (id === 'alert-condition' || url.includes('alertscontroller')) return 'alert';
		if (id.startsWith('input_rulelogic') || url.includes('dataquality')) return 'dq';
		if (id === 'advanced_logic') return 'report_filter';
		return 'generic';
	}

	function guessLocation() {
		const params = new URLSearchParams(window.location.search);
		return {
			instrument: url_includes('design/online_designer') ? (params.get('page') || '') : '',
			event: '',
			field: ''
		};
		function url_includes(s) { return window.location.pathname.toLowerCase().includes(s); }
	}

	/* ---------- Panel ---------- */

	function injectPanel() {
		const parent = $('#rc-ace-editor-parent');
		if (!parent.length || parent.find('.ila-panel').length) return;
		// Core also uses this editor for a Dynamic SQL field's query, which is SQL, not logic
		if (currentOpener && currentOpener.attr && currentOpener.attr('id') === 'element_enum' && $('#field_type').val() === 'sql') return;

		const context = guessContext(currentOpener);
		const $panel = $(
			'<div class="ila-panel">' +
				'<button type="button" class="btn btn-xs btn-primaryrc ila-toggle"><i class="fas fa-wand-magic-sparkles"></i> Suggest logic with AI</button>' +
				'<div class="ila-body" style="display:none;">' +
					'<div class="ila-row">' +
						'<label class="ila-label">Kind of logic:</label> ' +
						'<select class="form-select form-select-sm ila-context"></select>' +
					'</div>' +
					'<textarea class="form-control ila-request" rows="3" maxlength="2000" ' +
						'placeholder="Describe what the logic should do, e.g. &quot;Show this field if the participant is over 65 and reported a fall at baseline&quot;"></textarea>' +
					'<div class="ila-row ila-actions">' +
						'<button type="button" class="btn btn-xs btn-rcgreen ila-submit"><i class="fas fa-paper-plane"></i> Suggest</button>' +
						'<span class="ila-status"></span>' +
					'</div>' +
					'<div class="ila-result" style="display:none;"></div>' +
					'<div class="ila-note"><i class="fas fa-shield-halved"></i> Only project <b>metadata</b> (field names, labels, choices, events and instruments) is sent to the AI service, never record data. Always test suggested logic before relying on it.</div>' +
				'</div>' +
			'</div>'
		);

		const $select = $panel.find('.ila-context');
		$.each(config.contexts || {}, function (key, label) {
			$select.append($('<option>').val(key).text(label));
		});
		$select.val(context);

		$panel.find('.ila-toggle').on('click', function () {
			withDisclaimerAccepted(function () {
				$panel.find('.ila-body').slideToggle(150, function () {
					if ($(this).is(':visible')) $panel.find('.ila-request').trigger('focus');
				});
			});
		});
		$panel.find('.ila-submit').on('click', function () { submit($panel); });
		$panel.find('.ila-request').on('keydown', function (e) {
			if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); submit($panel); }
		});

		parent.prepend($panel);
	}

	/* ---------- Disclaimer (accepted once per browser, remembered for a year) ---------- */

	// Bump the version to ask everyone to accept again after the disclaimer text changes
	const DISCLAIMER_COOKIE_BASE = 'intersect_logic_assistant_disclaimer_v1';

	// One cookie per REDCap user, so each user on a shared browser accepts for themselves.
	// The username is URI-encoded, which keeps the cookie name valid and unambiguous.
	function disclaimerCookieName() {
		return DISCLAIMER_COOKIE_BASE + '_' + encodeURIComponent((config && config.username) || '');
	}
	const DISCLAIMER_HTML =
		'<p><b>Before using the Logic Assistant, please read and accept the following.</b></p>' +
		'<ul>' +
			'<li>Suggestions are <b>generated by AI and may be incorrect</b>, even when they pass the module\'s checks. ' +
				'You are responsible for reviewing, verifying and testing any logic before relying on it.</li>' +
			'<li>Your request, any logic already in the editor, and the project\'s <b>metadata</b> (field names, labels, choices, ' +
				'instruments, events and arms) are sent to the GenAI service configured by your REDCap administrators. ' +
				'<b>No record data is sent.</b></li>' +
			'<li><b>Do not include identifying or sensitive information in your request.</b></li>' +
			'<li>Use of the assistant is subject to your institution\'s policies on the use of AI.</li>' +
		'</ul>';

	function disclaimerAccepted() {
		return document.cookie.split('; ').some(function (c) { return c === disclaimerCookieName() + '=1'; });
	}

	function rememberDisclaimer() {
		const secure = window.location.protocol === 'https:' ? '; Secure' : '';
		document.cookie = disclaimerCookieName() + '=1; Max-Age=' + (365 * 24 * 60 * 60) + '; Path=/; SameSite=Lax' + secure;
	}

	function withDisclaimerAccepted(callback) {
		if (disclaimerAccepted()) { callback(); return; }
		const $dlg = $('<div class="ila-disclaimer">').html(DISCLAIMER_HTML);
		$dlg.dialog({
			title: 'Logic Assistant: AI disclaimer',
			modal: true,
			width: 560,
			close: function () { $dlg.dialog('destroy').remove(); },
			buttons: [
				{ text: 'Cancel', click: function () { $dlg.dialog('close'); } },
				{ text: 'I accept', class: 'font-weight-bold', click: function () {
					rememberDisclaimer();
					$dlg.dialog('close');
					callback();
				} }
			]
		});
	}

	function getEditor() {
		try { return ace.edit('rc-ace-editor'); } catch (e) { return null; } // returns the existing instance
	}

	function submit($panel) {
		const request = $.trim($panel.find('.ila-request').val());
		if (!request) { $panel.find('.ila-request').trigger('focus'); return; }

		const editor = getEditor();
		const payload = Object.assign({
			request: request,
			context: $panel.find('.ila-context').val(),
			existing_logic: editor ? editor.getSession().getValue() : ''
		}, guessLocation());

		const $btn = $panel.find('.ila-submit').prop('disabled', true);
		$panel.find('.ila-status').html('<i class="fas fa-spinner fa-spin"></i> Thinking&hellip;');
		$panel.find('.ila-result').hide().empty();

		jsmo.ajax('suggest', payload).then(function (resp) {
			$panel.find('.ila-status').empty();
			if (!resp || resp.error) {
				showError($panel, (resp && resp.error) || 'Something went wrong. Please try again.');
				return;
			}
			showResult($panel, resp);
		}).catch(function (err) {
			$panel.find('.ila-status').empty();
			showError($panel, 'The request failed: ' + (err && err.message ? err.message : err));
		}).finally(function () {
			$btn.prop('disabled', false);
		});
	}

	function showError($panel, message) {
		$panel.find('.ila-result').empty()
			.append($('<div class="ila-error">').text(message))
			.show();
	}

	function showResult($panel, resp) {
		const $r = $panel.find('.ila-result').empty();

		if (!resp.logic) {
			$r.append($('<div class="ila-clarify">').text(resp.clarification || resp.explanation || 'The assistant could not suggest logic for that request. Try adding more detail.'));
			$r.show();
			return;
		}

		$r.append(
			'<div class="ila-ai-notice" role="note"><i class="fas fa-robot"></i> <b>AI-generated suggestion.</b> ' +
			'This logic was generated by AI and may be incorrect. Review it, verify that it matches your intent, ' +
			'and test it with real or test records before relying on it.</div>'
		);
		$r.append($('<pre class="ila-logic">').text(resp.logic));
		if (resp.explanation) $r.append($('<div class="ila-explanation">').text(resp.explanation));
		appendList($r, 'Assumptions', resp.assumptions, 'ila-assumptions');
		appendList($r, 'Check before using', resp.errors, 'ila-errors');
		appendList($r, 'Note', resp.warnings, 'ila-warnings');
		if (resp.clarification) $r.append($('<div class="ila-clarify">').text(resp.clarification));

		const $actions = $('<div class="ila-row ila-apply">');
		$('<button type="button" class="btn btn-xs btn-defaultrc"><i class="fas fa-right-left"></i> Replace editor contents</button>')
			.on('click', function () { applyLogic(resp.logic, 'replace'); }).appendTo($actions);
		$('<button type="button" class="btn btn-xs btn-defaultrc"><i class="fas fa-i-cursor"></i> Insert at cursor</button>')
			.on('click', function () { applyLogic(resp.logic, 'insert'); }).appendTo($actions);
		$('<button type="button" class="btn btn-xs btn-defaultrc"><i class="fas fa-copy"></i> Copy</button>')
			.on('click', function () {
				if (navigator.clipboard) navigator.clipboard.writeText(resp.logic);
			}).appendTo($actions);
		$r.append($actions).show();
	}

	function appendList($container, title, items, cls) {
		if (!items || !items.length) return;
		const $ul = $('<ul>');
		items.forEach(function (item) { $ul.append($('<li>').text(item)); });
		$container.append($('<div>').addClass(cls).append($('<b>').text(title + ':')).append($ul));
	}

	function applyLogic(logic, mode) {
		const editor = getEditor();
		if (!editor) return;
		if (mode === 'replace') {
			editor.getSession().setValue(logic); // core's change handler copies this into the opener field
		} else {
			editor.insert(logic);
		}
		editor.focus();
	}

	return { init: init };
})();
