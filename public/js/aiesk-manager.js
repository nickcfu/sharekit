/** Owner-scoped shared-link dashboard. No polling or third-party services. */
(function () {
  'use strict';
  const config = window.aieskManager || {};
  const messengers = ['whatsapp', 'viber'].filter(name => Array.isArray(config.messengers) && config.messengers.includes(name));
  const t = (key, vars = {}) => window.aieskI18n && typeof window.aieskI18n.t === 'function'
    ? window.aieskI18n.t(key, vars)
    : key.replace(/\{(\w+)\}/g, (match, name) => Object.prototype.hasOwnProperty.call(vars, name) ? String(vars[name]) : match);
  const esc = value => String(value == null ? '' : value).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const paths = {
    link: '<path d="m10 13 4-4m-6 6-1 1a4.24 4.24 0 0 1-6-6l4-4a4.24 4.24 0 0 1 6 0m2 3 1-1a4.24 4.24 0 0 1 6 6l-4 4a4.24 4.24 0 0 1-6 0"/>',
    copy: '<rect x="8" y="8" width="12" height="13" rx="2"/><path d="M16 8V3H3v13h5"/>',
    qr: '<rect x="3" y="3" width="6" height="6" rx="1"/><rect x="15" y="3" width="6" height="6" rx="1"/><rect x="3" y="15" width="6" height="6" rx="1"/><path d="M15 15h3v3h3v3h-6v-3m6-3v-3"/>',
    eye: '<path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>',
    clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    close: '<path d="m6 6 12 12M6 18 18 6"/>',
    lock: '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v3"/>',
    check: '<path d="m5 12 4 4L19 6"/>',
    trash: '<path d="M3 6h18M9 6V3h6v3M5 6l1 15h12l1-15M10 10v7m4-7v7"/>',
    send: '<path d="m21 3-7 18-4-7-7-4 18-7ZM10 14 21 3"/>'
  };
  const icon = name => '<svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + (paths[name] || paths.link) + '</svg>';
  const date = timestamp => timestamp ? new Date(Number(timestamp) * 1000).toLocaleString(document.documentElement.lang || undefined, { day: 'numeric', month: 'short', year: 'numeric', hour: 'numeric', minute: '2-digit' }) : t('Not recorded');
  function safeURL(value) {
    if (typeof value !== 'string' || !value) throw new Error(t('This link has no valid address.'));
    const url = new URL(value, location.href);
    if (!/^https?:$/.test(url.protocol) || url.origin !== new URL(config.rest, location.href).origin) throw new Error(t('The server returned an invalid share address.'));
    return url.href;
  }
  async function api(path, options = {}) {
    const controller = options.controller || new AbortController();
    const timeout = setTimeout(() => controller.abort('timeout'), 30000);
    try {
      const response = await fetch(config.rest + path, {
        method: options.method || 'GET', signal: controller.signal, credentials: 'same-origin', cache: 'no-store',
        headers: { 'X-WP-Nonce': config.nonce, Accept: 'application/json', ...(options.body !== undefined ? { 'Content-Type': 'application/json' } : {}) },
        body: options.body !== undefined ? JSON.stringify(options.body) : undefined
      });
      const text = await response.text();
      let data;
      try { data = JSON.parse(text); } catch (_) { data = null; }
      const authCodes = ['rest_cookie_invalid_nonce', 'rest_not_logged_in', 'rest_not_authenticated', 'aiesk_login_required', 'aiesk_not_logged_in'];
      if (response.status === 401 || response.status === 403 && data && authCodes.includes(data.code)) {
        const error = new Error(t('Session expired. Reload this page or sign in again.'));
        error.sessionExpired = true; throw error;
      }
      if (!data) throw new Error(t('The server returned an unreadable response. Reload the page and try again.'));
      if (!response.ok) {
        const error = new Error(data.message || t('Could not load your shared links. Please try again.'));
        error.accessClosed = !!(data.data && data.data.access_closed === true);
        throw error;
      }
      return data;
    } catch (error) {
      if (controller.signal.aborted && controller.signal.reason === 'timeout') throw new Error(t('The server is taking too long. Please try again.'));
      if (error instanceof TypeError) throw new Error(t('Could not connect. Check your connection and try again.'));
      throw error;
    } finally { clearTimeout(timeout); }
  }
  function qrCanvas(url) {
    if (typeof window.qrcode !== 'function') throw new Error(t('The QR module did not load. Reload the page or copy the link.'));
    const qr = window.qrcode(0, 'M'); qr.addData(url); qr.make();
    const count = qr.getModuleCount(), cell = 8, quiet = 4;
    const canvas = document.createElement('canvas'); canvas.width = canvas.height = (count + quiet * 2) * cell;
    canvas.setAttribute('role', 'img'); canvas.setAttribute('aria-label', t('QR code for this shared report'));
    const ctx = canvas.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height); ctx.fillStyle = '#000';
    for (let row = 0; row < count; row++) for (let col = 0; col < count; col++) if (qr.isDark(row, col)) ctx.fillRect((col + quiet) * cell, (row + quiet) * cell, cell, cell);
    return canvas;
  }
  class Manager {
    constructor(root) {
      this.root = root; this.page = 1; this.pages = 1; this.status = 'all'; this.search = ''; this.scope = root.dataset.scope || 'mine';
      this.items = []; this.clockOffset = 0; this.requestId = 0; this.loading = false; this.sessionExpired = false;
      this.list = root.querySelector('[data-aiesk-list]');
      this.feedback = root.querySelector('[data-aiesk-manager-status]');
      this.searchForm = root.querySelector('[data-aiesk-search-form]');
      this.searchForm.addEventListener('submit', event => {
        event.preventDefault(); this.search = this.searchForm.querySelector('[name="search"]').value.trim(); this.page = 1; this.load();
      });
      const scope = root.querySelector('[name="scope"]');
      if (scope) scope.addEventListener('change', () => { this.scope = scope.value; this.page = 1; this.root.querySelector('[data-aiesk-manager-title]').textContent = this.scope === 'all' ? t('All shared links') : t('My shared links'); this.load(); });
      root.addEventListener('click', event => {
        const button = event.target.closest('button'); if (!button || button.disabled) return;
        if (button.hasAttribute('data-aiesk-reload')) location.reload();
        else if (button.hasAttribute('data-status')) { this.status = button.dataset.status; this.page = 1; this.load(); }
        else if (button.hasAttribute('data-aiesk-refresh') || button.hasAttribute('data-aiesk-retry')) this.load();
        else if (button.hasAttribute('data-aiesk-reset')) { this.status = 'all'; this.search = ''; this.searchForm.reset(); this.page = 1; this.load(); }
        else if (button.hasAttribute('data-page')) { this.page += button.dataset.page === 'next' ? 1 : -1; this.load(); }
        else if (button.dataset.linkAction) {
          if (this.loading) return;
          const item = this.items.find(i => String(i.id) === button.dataset.id);
          if (item) this.action(item, button.dataset.linkAction, button);
        }
      });
      window.addEventListener('aiesk:links-changed', () => this.load());
      this.load();
    }
    feedbackText(message, error = false) { this.feedback.textContent = message; this.feedback.classList.toggle('is-error', error); }
    recoveryHTML() {
      let login = '';
      try { if (config.loginUrl) login = '<a class="aiesk-session-signin" href="' + esc(safeURL(config.loginUrl)) + '">' + esc(t('Sign in again')) + '</a>'; } catch (_) { /* A reload remains available for invalid login configuration. */ }
      return '<div class="aiesk-session-actions"><button type="button" data-aiesk-reload>' + esc(t('Reload page')) + '</button>' + login + '</div>';
    }
    expireSession() {
      this.sessionExpired = true; this.items = [];
      this.list.innerHTML = '<div class="aiesk-manager-empty"><h3>' + esc(t('Session expired')) + '</h3><p>' + esc(t('Reload this page or sign in again to manage your shared links.')) + '</p>' + this.recoveryHTML() + '</div>';
      this.root.querySelector('[data-aiesk-pagination-label]').textContent = '';
      this.root.querySelectorAll('[data-page]').forEach(button => { button.disabled = true; });
      this.feedbackText(t('Session expired. Reload this page or sign in again.'), true);
    }
    now() { return Date.now() / 1000 + this.clockOffset; }
    active(item) { return item.status === 'active' && (!item.expires_at || Number(item.expires_at) > this.now()); }
    async load() {
      if (this.sessionExpired) { this.expireSession(); return; }
      if (this.controller) this.controller.abort();
      const controller = this.controller = new AbortController(), id = ++this.requestId;
      this.loading = true; this.root.setAttribute('aria-busy', 'true'); this.list.setAttribute('aria-busy', 'true');
      this.root.querySelectorAll('[data-status]').forEach(el => el.setAttribute('aria-pressed', String(el.dataset.status === this.status)));
      this.root.querySelectorAll('[data-page]').forEach(el => { el.disabled = true; });
      this.feedbackText(t('Loading shared links…'));
      if (!this.items.length) this.list.innerHTML = '<div class="aiesk-manager-loading" aria-hidden="true"><span></span><span></span><span></span></div>';
      try {
        const query = new URLSearchParams({ page: String(this.page), per_page: '20', status: this.status, search: this.search, scope: this.scope });
        const data = await api('links?' + query, { controller });
        if (id !== this.requestId) return;
        if (!Array.isArray(data.items)) throw new Error(t('The server returned an invalid links list.'));
        this.clockOffset = Number(data.server_time || Date.now() / 1000) - Date.now() / 1000;
        const seen = new Set();
        this.items = data.items.filter(item => {
          const recordId = Number(item && item.id);
          if (!Number.isSafeInteger(recordId) || recordId <= 0 || seen.has(recordId)) return false;
          seen.add(recordId); return true;
        });
        this.pages = Math.max(1, Number(data.total_pages) || 1);
        if (this.page > this.pages) { this.page = this.pages; this.load(); return; }
        this.render();
        const total = Number(data.total) || 0;
        this.root.querySelector('[data-aiesk-pagination-label]').textContent = total ? t('{first}–{last} of {total} links', { first: (this.page - 1) * 20 + 1, last: Math.min(this.page * 20, total), total }) : t('0 links');
        this.root.querySelector('[data-page="previous"]').disabled = this.page <= 1;
        this.root.querySelector('[data-page="next"]').disabled = this.page >= this.pages;
        this.feedbackText(total ? t('Updated just now') : '');
      } catch (error) {
        if (id !== this.requestId || controller.signal.aborted && controller.signal.reason !== 'timeout') return;
        if (error.sessionExpired) { this.expireSession(); return; }
        this.items = []; this.list.innerHTML = '<div class="aiesk-manager-empty"><h3>' + esc(t('Couldn’t load your links')) + '</h3><p>' + esc(t('Check your connection and try again.')) + '</p><button type="button" data-aiesk-retry>' + esc(t('Try again')) + '</button></div>';
        this.root.querySelector('[data-aiesk-pagination-label]').textContent = '';
        this.feedbackText(error.message, true);
      } finally {
        if (id === this.requestId) { this.loading = false; this.root.setAttribute('aria-busy', 'false'); this.list.setAttribute('aria-busy', 'false'); }
      }
    }
    render() {
      if (!this.items.length) {
        const filtered = this.search || this.status !== 'all';
        this.list.innerHTML = '<div class="aiesk-manager-empty"><div class="aiesk-empty-icon">' + icon('link') + '</div><h3>' + esc(filtered ? t('No matching links') : t('Your shared reports will appear here')) + '</h3><p>' + esc(filtered ? t('Try another title or status.') : t('Create a private link from an AI response, then return here to manage access.')) + '</p>' + (filtered ? '<button type="button" data-aiesk-reset>' + esc(t('Clear filters')) + '</button>' : '') + '</div>';
        return;
      }
      this.list.innerHTML = this.items.map(item => {
        const active = this.active(item), status = active ? 'active' : item.status === 'revoked' ? 'revoked' : 'expired';
        const expiryText = status === 'revoked' ? t('Revoked {date}', { date: date(item.revoked_at) }) : status === 'expired' ? t('Expired {date}', { date: date(item.expires_at) }) : !item.expires_at ? t('No expiry recorded') : t('Expires {date}', { date: date(item.expires_at) });
        let short = '';
        try { const url = new URL(safeURL(item.url)); short = url.host + url.pathname + url.search; } catch (_) { /* Inactive legacy links may have no recoverable token. */ }
        const action = (name, label, glyph, extra = '') => '<button type="button" class="aiesk-row-action ' + extra + '" data-link-action="' + name + '" data-id="' + Number(item.id) + '" aria-label="' + esc(t('{action}: {title}', { action: label, title: item.title || t('Shared response') })) + '">' + icon(glyph) + '<span>' + esc(label) + '</span></button>';
        const labels = { active: t('Active'), expired: t('Expired'), revoked: t('Revoked') };
        const retention = Number(item.delete_after) > 0 ? t('Saved report will be deleted on {date}.', { date: date(item.delete_after) }) : '';
        const canSend = active && short && messengers.length > 0;
        const renew = status === 'expired' && item.status !== 'revoked' ? action('renew', t('Renew link'), 'clock', 'is-primary') : '';
        return '<article class="aiesk-link-card" data-link-id="' + Number(item.id) + '"><div class="aiesk-card-main"><span class="aiesk-card-symbol">' + icon('link') + '</span><div class="aiesk-card-summary"><div class="aiesk-card-title"><h3>' + esc(item.title || t('Shared response')) + '</h3><span class="aiesk-link-status is-' + status + '">' + esc(labels[status]) + '</span></div><p class="aiesk-card-url" title="' + esc(short) + '">' + esc(short || t('Link unavailable')) + '</p><div class="aiesk-card-meta"><span>' + esc(t('Created {date}', { date: date(item.created_at) })) + '</span><span title="' + esc(date(item.expires_at)) + '">' + icon('clock') + esc(expiryText) + '</span>' + (item.owner_name ? '<span>' + esc(t('By {name}', { name: item.owner_name })) + '</span>' : '') + '</div></div></div><div class="aiesk-card-actions">' + action('preview', t('Preview'), 'eye') + (active && short ? action('copy', t('Copy link'), 'copy', 'is-primary') + action('qr', t('QR code'), 'qr') : '') + (active && item.can_revoke ? action('revoke', t('Revoke'), 'lock', 'is-revoke') : !active ? '<span class="aiesk-closed-note">' + esc(t('Public access closed')) + '</span>' : '') + '</div>' + (retention || item.can_delete || canSend || renew ? '<div class="aiesk-card-footer">' + (retention ? '<p class="aiesk-retention-note">' + esc(retention) + '</p>' : '') + '<div class="aiesk-card-footer-actions' + (canSend ? ' has-send' : '') + '">' + renew + (canSend ? action('send', t('Send link'), 'send', 'is-send') : '') + (item.can_delete ? action('delete', t('Delete saved report'), 'trash', 'is-delete') : '') + '</div></div>' : '') + '</article>';
      }).join('');
    }
    notify(message, host) {
      const surface = host || document.body;
      let notice = host ? host.querySelector('.aiesk-manager-notice') : this.notice;
      if (!notice) { notice = document.createElement('div'); notice.className = 'aiesk-manager-notice'; notice.setAttribute('role', 'status'); surface.append(notice); if (!host) this.notice = notice; }
      notice.textContent = message; notice.hidden = false;
      clearTimeout(this.noticeTimer); this.noticeTimer = setTimeout(() => { notice.hidden = true; }, 3500);
    }
    modal(title, description, source) {
      if (this.dialog) return null;
      const dialog = document.createElement('dialog'); dialog.className = 'aiesk-scope aiesk-manager-dialog'; dialog.dataset.theme = this.root.dataset.theme || 'light';
      const id = 'aiesk-manager-dialog-' + Math.random().toString(36).slice(2, 9);
      dialog.setAttribute('aria-labelledby', id);
      dialog.innerHTML = '<div class="aiesk-manager-modal-head"><span class="aiesk-card-symbol">' + icon('link') + '</span><button type="button" data-close aria-label="' + esc(t('Close dialog')) + '">' + icon('close') + '</button></div><h2 id="' + id + '">' + esc(title) + '</h2><p class="aiesk-modal-description"></p><div class="aiesk-manager-modal-body"></div><div class="aiesk-modal-error" role="alert" hidden></div>';
      dialog.querySelector('.aiesk-modal-description').textContent = description;
      const close = () => {
        if (dialog.dataset.pending === 'true') return;
        if (dialog._abort) dialog._abort.abort();
        dialog.close(); dialog.remove(); this.dialog = null;
        if (source.isConnected) source.focus();
      };
      dialog.querySelector('[data-close]').addEventListener('click', close);
      dialog.addEventListener('cancel', event => { event.preventDefault(); close(); });
      dialog.addEventListener('click', event => {
        if (event.target !== dialog) return;
        const rect = dialog.getBoundingClientRect();
        if (event.clientX < rect.left || event.clientX > rect.right || event.clientY < rect.top || event.clientY > rect.bottom) close();
      });
      document.body.append(dialog); this.dialog = dialog; dialog.showModal();
      return { dialog, body: dialog.querySelector('.aiesk-manager-modal-body'), close, error: error => {
        const el = dialog.querySelector('.aiesk-modal-error'); el.textContent = error && error.message || error || ''; el.hidden = !error;
        if (error && error.sessionExpired) {
          this.expireSession();
          el.insertAdjacentHTML('beforeend', this.recoveryHTML());
          el.querySelector('[data-aiesk-reload]').addEventListener('click', () => location.reload());
          dialog.querySelectorAll('[data-confirm]').forEach(button => { button.disabled = true; });
        }
      } };
    }
    async copyURL(url, input, host) {
      try {
        if (navigator.clipboard && window.isSecureContext) { await navigator.clipboard.writeText(url); this.notify(t('Link copied'), host); return true; }
      } catch (_) { /* Use the selectable modal input below. */ }
      if (input) {
        input.focus(); input.select();
        try { if (document.execCommand('copy')) { this.notify(t('Link copied'), host); return true; } } catch (_) { /* Native selection remains available. */ }
        this.notify(t('Link selected. Use Copy or press Ctrl+C / ⌘C.'), host);
      }
      return false;
    }
    async action(item, action, source) {
      if (this.sessionExpired) { this.expireSession(); return; }
      if (action === 'delete' && !item.can_delete) return;
      if (action === 'revoke' && !item.can_revoke) return;
      if (action === 'renew' && (item.status === 'revoked' || this.active(item))) return;
      if (action === 'send' && !messengers.length) return;
      // Expired links may be previewed by their owner or renewed in place;
      // public actions such as copy, QR and messaging still require active
      // access.
      if (!['preview', 'delete', 'renew'].includes(action) && !this.active(item)) { this.render(); this.notify(t('This link has expired. Public access is closed.')); return; }
      if (action === 'copy') {
        try {
          const url = safeURL(item.url);
          if (await this.copyURL(url)) return;
          const view = this.modal(t('Copy share link'), item.title, source); if (!view) return;
          const input = document.createElement('input'); input.readOnly = true; input.value = url; input.setAttribute('aria-label', t('Share link'));
          const button = document.createElement('button'); button.type = 'button'; button.className = 'aiesk-manager-primary'; button.textContent = t('Copy link');
          view.body.append(input, button); input.focus(); input.select(); button.addEventListener('click', () => this.copyURL(url, input, view.dialog));
        } catch (error) { this.notify(error.message); }
        return;
      }
      const view = this.modal(action === 'renew' ? t('Renew this link?') : action === 'qr' ? t('Share with a QR code') : action === 'send' ? t('Send link') : action === 'revoke' ? t('Revoke this link?') : action === 'delete' ? t('Permanently delete this saved report?') : t('Report preview'), item.title, source);
      if (!view) return;
      if (action === 'send') {
        try {
          const url = safeURL(item.url);
          const intro = document.createElement('p'); intro.textContent = t('Choose an app and select the recipient there. Nothing is sent automatically.');
          const apps = document.createElement('div'); apps.className = 'aiesk-messenger-options';
          const title = String(item.title || t('Shared response'));
          const encoded = encodeURIComponent(title + '\n' + url);
          // Viber may trim forwarded text after 200 characters. Keep the entire
          // URL first, and clip only its optional title on Unicode boundaries.
          const urlLength = Array.from(url).length, viberTooLong = urlLength > 200;
          const titleRoom = Math.max(0, 200 - urlLength - 1);
          const viberText = url + (titleRoom ? '\n' + Array.from(title).slice(0, titleRoom).join('') : '');
          messengers.forEach(name => {
            if (name === 'viber' && viberTooLong) {
              const unavailable = document.createElement('button'); unavailable.type = 'button'; unavailable.disabled = true;
              unavailable.dataset.messenger = name; unavailable.className = 'aiesk-messenger-option'; unavailable.textContent = t('Viber'); apps.append(unavailable); return;
            }
            const anchor = document.createElement('a'); anchor.dataset.messenger = name; anchor.className = 'aiesk-messenger-option';
            anchor.textContent = name === 'whatsapp' ? t('WhatsApp') : t('Viber');
            anchor.href = name === 'whatsapp' ? 'https://wa.me/?text=' + encoded : 'viber://forward?text=' + encodeURIComponent(viberText);
            anchor.rel = 'noopener noreferrer'; anchor.referrerPolicy = 'no-referrer';
            if (name === 'whatsapp') anchor.target = '_blank';
            anchor.addEventListener('click', event => {
              const current = this.items.find(record => Number(record.id) === Number(item.id));
              if (!current || !this.active(current) || this.sessionExpired) { event.preventDefault(); view.error(t('This link has expired. Public access is closed.')); }
            });
            apps.append(anchor);
          });
          const available = document.createElement('p'); available.className = 'aiesk-messenger-help'; available.textContent = t('The app needs to be available on your device. If it does not open, copy the link instead.');
          const limit = document.createElement('p'); limit.className = 'aiesk-messenger-help'; limit.textContent = t('This link is too long for Viber. Copy it instead, or create a short link.');
          const copyRow = document.createElement('div'); copyRow.className = 'aiesk-messenger-copy';
          const input = document.createElement('input'); input.readOnly = true; input.value = url; input.setAttribute('aria-label', t('Share link'));
          const copy = document.createElement('button'); copy.type = 'button'; copy.className = 'aiesk-manager-primary'; copy.textContent = t('Copy link');
          copy.addEventListener('click', () => this.copyURL(url, input, view.dialog));
          copyRow.append(input, copy);
          const expiry = document.createElement('p'); expiry.className = 'aiesk-messenger-help'; expiry.textContent = t('Expires {date}', { date: date(item.expires_at) });
          const privacy = document.createElement('p'); privacy.className = 'aiesk-messenger-help'; privacy.textContent = t('Anyone with this link can read the selected response until it expires or you revoke it.');
          view.body.append(intro, apps, available);
          if (viberTooLong && messengers.includes('viber')) view.body.append(limit);
          view.body.append(copyRow, expiry, privacy);
        } catch (error) { view.error(error); }
      } else if (action === 'qr') {
        try {
          const url = safeURL(item.url), canvas = qrCanvas(url);
          const download = document.createElement('a'); download.className = 'aiesk-manager-primary'; download.textContent = t('Download QR image'); download.download = 'shared-report-qr.png'; download.href = canvas.toDataURL('image/png');
          view.body.classList.add('is-qr'); view.body.append(canvas, download);
          const note = document.createElement('p'); note.textContent = t('Expires {date}. Revoking the link also disables this QR code.', { date: date(item.expires_at) }); view.body.append(note);
        } catch (error) { view.error(error); }
      } else if (action === 'preview') {
        view.dialog.classList.add('is-preview'); view.body.innerHTML = '<p role="status">' + esc(t('Loading preview…')) + '</p>';
        const controller = new AbortController(); view.dialog._abort = controller;
        try {
          const detail = await api('link/' + Number(item.id), { controller });
          if (!view.dialog.isConnected) return;
          const frame = document.createElement('iframe'); frame.title = t('Shared report preview'); frame.setAttribute('sandbox', ''); frame.referrerPolicy = 'no-referrer';
          frame.srcdoc = '<!doctype html><html><head><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src &#39;none&#39;; style-src &#39;unsafe-inline&#39;; img-src data:;"><style>body{font:15px/1.65 system-ui;color:#1b2940;margin:22px;overflow-wrap:anywhere}table{border-collapse:collapse;max-width:100%;display:block;overflow:auto}td,th{border:1px solid #dce3ed;padding:8px}img{max-width:100%}pre{white-space:pre-wrap}</style></head><body>' + String(detail.html || '<p>' + esc(t('No preview is available.')) + '</p>') + '</body></html>';
          view.body.replaceChildren(frame);
        } catch (error) { if (view.dialog.isConnected && (!controller.signal.aborted || controller.signal.reason === 'timeout')) { view.body.replaceChildren(); view.error(error); } }
      } else if (action === 'renew') {
        view.body.innerHTML = '<p>' + esc(t('This keeps the same link and makes it active again using the current link lifetime.')) + '</p><div class="aiesk-revoke-actions"><button type="button" data-cancel>' + esc(t('Keep expired')) + '</button><button type="button" class="aiesk-manager-primary" data-confirm>' + esc(t('Renew link')) + '</button></div><p role="status" data-revoke-progress></p>';
        view.body.querySelector('[data-cancel]').addEventListener('click', view.close);
        view.body.querySelector('[data-confirm]').addEventListener('click', async () => { if (view.dialog.dataset.pending === 'true') return; view.dialog.dataset.pending = 'true'; view.body.querySelector('[data-revoke-progress]').textContent = t('Renewing link…'); try { await api('link/' + Number(item.id) + '/renew', { method: 'POST', body: {} }); view.close(); this.notify(t('Link renewed. The existing address works again.')); window.dispatchEvent(new CustomEvent('aiesk:links-changed')); } catch (error) { view.dialog.dataset.pending = 'false'; view.error(error); } });
      } else if (action === 'revoke' || action === 'delete') {
        const permanent = action === 'delete';
        view.body.innerHTML = '<div class="aiesk-revoke-message">' + icon(permanent ? 'trash' : 'lock') + '<p>' + esc(permanent ? t('This permanently deletes the saved shared report and its link. This cannot be undone. The original assessment is not deleted.') : t('Anyone with this link or its QR code will lose access. You can create a new link from the report if you need to share it again.')) + '</p></div><div class="aiesk-revoke-actions"><button type="button" data-cancel>' + esc(permanent ? t('Keep saved report') : t('Keep link')) + '</button><button type="button" class="aiesk-manager-danger" data-confirm>' + esc(permanent ? t('Delete permanently') : t('Revoke access')) + '</button></div><p role="status" data-revoke-progress></p>';
        view.body.querySelector('[data-cancel]').addEventListener('click', view.close);
        view.body.querySelector('[data-confirm]').addEventListener('click', async () => {
          if (view.dialog.dataset.pending === 'true' || this.sessionExpired) return;
          view.dialog.dataset.pending = 'true'; view.dialog.querySelectorAll('button').forEach(button => { button.disabled = true; }); view.error('');
          view.body.querySelector('[data-revoke-progress]').textContent = permanent ? t('Deleting saved report…') : t('Revoking access…');
          try {
            const result = await api('link/' + Number(item.id) + (permanent ? '/permanent' : ''), { method: 'DELETE', ...(permanent ? { body: { confirm: true } } : {}) });
            view.dialog.dataset.pending = 'false'; view.close(); this.root.querySelector('[data-aiesk-refresh]').focus();
            const message = permanent ? t('Saved report permanently deleted. Its link and QR code no longer work.') : t('Link revoked. Public access is closed.');
            this.notify(message + (typeof result.warning === 'string' && result.warning ? ' ' + result.warning : ''));
            window.dispatchEvent(new CustomEvent('aiesk:links-changed', { detail: { id: item.id, action: permanent ? 'deleted' : 'revoked' } }));
          } catch (error) {
            view.dialog.dataset.pending = 'false'; view.dialog.querySelectorAll('button').forEach(button => { button.disabled = false; });
            view.body.querySelector('[data-revoke-progress]').textContent = ''; view.error(error);
            // A failed permanent deletion can still have successfully closed
            // public access. Refresh the list and invalidate creation caches,
            // while keeping the deletion error and explicit retry available.
            if (permanent && error.accessClosed) window.dispatchEvent(new CustomEvent('aiesk:links-changed', { detail: { id: item.id, action: 'revoked' } }));
          }
        });
      }
    }
  }
  function boot() { document.querySelectorAll('[data-aiesk-manager]').forEach(root => { if (!root.dataset.initialized) { root.dataset.initialized = '1'; new Manager(root); } }); }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true }); else boot();
})();
