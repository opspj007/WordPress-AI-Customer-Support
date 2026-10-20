(function () {
	'use strict';
	var cfg = window.AISA;
	if (!cfg) { return; }

	var KEY = 'aisa_token';
	var state = { open: false, lastId: 0, status: 'ai', busy: false, loaded: false, timer: null };
	var el = {};

	function newToken() {
		var a = new Uint8Array(16);
		(window.crypto || window.msCrypto).getRandomValues(a);
		return Array.prototype.map.call(a, function (b) { return ('0' + b.toString(16)).slice(-2); }).join('');
	}
	var existing = false;
	function getToken() {
		try {
			var t = localStorage.getItem(KEY);
			if (t && /^[a-f0-9]{32}$/.test(t)) { existing = true; return t; }
			t = newToken();
			localStorage.setItem(KEY, t);
			return t;
		} catch (e) { return newToken(); }
	}
	var token = getToken();

	function h(tag, cls, text) {
		var n = document.createElement(tag);
		if (cls) { n.className = cls; }
		if (text != null) { n.textContent = text; }
		return n;
	}

	function api(path, opt) {
		opt = opt || {};
		opt.credentials = 'same-origin';
		opt.headers = { 'X-WP-Nonce': cfg.nonce, 'Content-Type': 'application/json' };
		return fetch(cfg.rest + path, opt).then(function (r) {
			return r.json().then(function (j) { if (!r.ok) { throw j; } return j; });
		});
	}

	function scroll() { el.log.scrollTop = el.log.scrollHeight; }

	function addMsg(role, text, id) {
		var m = h('div', 'aisa-msg aisa-' + role);
		if (role === 'agent') { m.appendChild(h('span', 'aisa-label', cfg.i18n.agent)); }
		m.appendChild(document.createTextNode(text));
		el.log.appendChild(m);
		if (id) { state.lastId = Math.max(state.lastId, id); }
		if (!state.open && role !== 'user') { el.btn.classList.add('aisa-unread'); }
		scroll();
	}

	function setStatus(s) {
		if (!s || s === state.status) { return; }
		var prev = state.status;
		state.status = s;
		if (s === 'waiting_human' && prev === 'ai') { addMsg('note', cfg.i18n.waiting); }
		if (s === 'waiting_human' || s === 'human') { startPoll(); } else { stopPoll(); }
	}

	function startPoll() { if (!state.timer) { state.timer = setInterval(poll, 5000); } }
	function stopPoll() { clearInterval(state.timer); state.timer = null; }

	function poll() {
		if (state.busy) { return; }
		api('messages?token=' + token + '&after=' + state.lastId).then(function (d) {
			d.messages.forEach(function (m) { if (m.role !== 'user') { addMsg(m.role, m.content, m.id); } else { state.lastId = Math.max(state.lastId, m.id); } });
			state.lastId = Math.max(state.lastId, d.last_id);
			setStatus(d.status);
		}).catch(function () {});
	}

	function load() {
		if (state.loaded) { return; }
		state.loaded = true;
		api('messages?token=' + token + '&after=0').then(function (d) {
			if (!d.messages.length) {
				if (cfg.greeting) { addMsg('assistant', cfg.greeting); }
			}
			d.messages.forEach(function (m) { addMsg(m.role, m.content, m.id); });
			state.lastId = d.last_id;
			state.status = d.status;
			if (d.status === 'waiting_human' || d.status === 'human') { startPoll(); }
		}).catch(function () {
			if (cfg.greeting) { addMsg('assistant', cfg.greeting); }
		});
	}

	function setBusy(b) {
		state.busy = b;
		el.send.disabled = b;
		if (b) {
			el.typing = h('div', 'aisa-msg aisa-assistant aisa-typing', '…');
			el.log.appendChild(el.typing);
			scroll();
		} else if (el.typing) {
			el.typing.remove();
			el.typing = null;
		}
	}

	function send(text) {
		addMsg('user', text);
		setBusy(true);
		api('chat', { method: 'POST', body: JSON.stringify({ token: token, message: text, page_url: location.href }) })
			.then(function (d) {
				setBusy(false);
				if (d.reply) { addMsg('assistant', d.reply.content, d.reply.id); }
				if (d.error) { addMsg('note', d.error); }
				state.lastId = Math.max(state.lastId, d.last_id || 0);
				setStatus(d.status);
			})
			.catch(function (e) {
				setBusy(false);
				addMsg('note', (e && e.message) || cfg.i18n.error);
			});
	}

	function toggle(open) {
		state.open = open;
		el.panel.hidden = !open;
		el.btn.setAttribute('aria-expanded', open ? 'true' : 'false');
		if (open) {
			el.btn.classList.remove('aisa-unread');
			load();
			setTimeout(function () { el.input.focus(); }, 50);
			scroll();
		}
	}

	function build() {
		var root = h('div', 'aisa-root aisa-' + cfg.position);
		root.style.setProperty('--aisa-color', cfg.color);

		el.btn = h('button', 'aisa-launcher');
		el.btn.type = 'button';
		el.btn.setAttribute('aria-label', cfg.i18n.open);
		el.btn.setAttribute('aria-expanded', 'false');
		el.btn.innerHTML = '<svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path fill="currentColor" d="M4 4h16a2 2 0 0 1 2 2v10a2 2 0 0 1-2 2H9l-5 4v-4a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2z"/></svg>';

		el.panel = h('div', 'aisa-panel');
		el.panel.hidden = true;
		el.panel.setAttribute('role', 'dialog');
		el.panel.setAttribute('aria-label', cfg.title);

		var head = h('div', 'aisa-head');
		head.appendChild(h('strong', '', cfg.title));
		var close = h('button', 'aisa-close', '\u00d7');
		close.type = 'button';
		close.setAttribute('aria-label', cfg.i18n.close);
		head.appendChild(close);

		el.log = h('div', 'aisa-log');
		el.log.setAttribute('role', 'log');
		el.log.setAttribute('aria-live', 'polite');

		var form = h('form', 'aisa-form');
		el.input = h('input', 'aisa-input');
		el.input.type = 'text';
		el.input.maxLength = 1000;
		el.input.placeholder = cfg.i18n.placeholder;
		el.input.setAttribute('aria-label', cfg.i18n.placeholder);
		el.send = h('button', 'aisa-send', cfg.i18n.send);
		el.send.type = 'submit';
		form.appendChild(el.input);
		form.appendChild(el.send);

		el.panel.appendChild(head);
		el.panel.appendChild(el.log);
		if (cfg.consent) { el.panel.appendChild(h('div', 'aisa-consent', cfg.consent)); }
		el.panel.appendChild(form);
		root.appendChild(el.panel);
		root.appendChild(el.btn);
		document.body.appendChild(root);

		el.btn.addEventListener('click', function () { toggle(!state.open); });
		close.addEventListener('click', function () { toggle(false); el.btn.focus(); });
		el.panel.addEventListener('keydown', function (e) { if (e.key === 'Escape') { toggle(false); el.btn.focus(); } });
		form.addEventListener('submit', function (e) {
			e.preventDefault();
			var t = el.input.value.trim();
			if (!t || state.busy) { return; }
			el.input.value = '';
			send(t);
		});

		if (existing) { load(); } // Restore an in-progress conversation / handoff after page reload.
	}

	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', build); } else { build(); }
})();
