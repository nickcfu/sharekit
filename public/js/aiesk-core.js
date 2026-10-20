/** AI Share Kit: scoped conversation controls and accessible sharing dialogs. */
(function () {
  'use strict';
  const config = window.aieskCore || {};
  const settings = config.settings || {};
  const t = (source, values) => window.aieskI18n && typeof window.aieskI18n.t === 'function'
    ? window.aieskI18n.t(source, values)
    : source.replace(/\{([a-zA-Z0-9_]+)\}/g, (match, key) => values && Object.prototype.hasOwnProperty.call(values, key) ? String(values[key]) : match);
  // Translate static template copy without treating report contents as UI strings.
  function translateMarkup(root) {
    const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
    let node;
    while ((node = walker.nextNode())) {
      const value = node.textContent.trim();
      if (value) node.textContent = node.textContent.replace(value, () => t(value));
    }
    root.querySelectorAll('[aria-label], [placeholder]').forEach(el => {
      ['aria-label', 'placeholder'].forEach(name => { if (el.hasAttribute(name)) el.setAttribute(name, t(el.getAttribute(name))); });
    });
  }
  let sessionExpired = false;
  const maxHours = Math.max(1, Math.min(720, Number(settings.maxHours) || 720));
  const defaultHours = Math.min(maxHours, Number(settings.defaultHours) || 168);
  const listSelector = '.mwaief-message-list, .mwai-chat .mwai-conversation';
  const controllers = new Map();
  // One outstanding upload per provider. Closing a dialog never starts a retry.
  const cloudUploads = new Map();
  let ui, template, activeDialog, menuCounter = 0, toastTimer;
  const escape = value => String(value).replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
  const icon = name => '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' + ({
    close: '<path d="m6 6 12 12M6 18 18 6"/>',
    link: '<path d="m10 13 4-4m-6 6-1 1a4.24 4.24 0 0 1-6-6l4-4a4.24 4.24 0 0 1 6 0m2 3 1-1a4.24 4.24 0 0 1 6 6l-4 4a4.24 4.24 0 0 1-6 0"/>',
    check: '<path d="m5 12 4 4L19 6"/>',
    copy: '<rect x="8" y="8" width="12" height="13" rx="2"/><path d="M16 8V3H3v13h5"/>',
    lock: '<rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3m-4 4v3"/>',
    arrow: '<path d="M5 12h14m-5-5 5 5-5 5"/>'
  }[name] || '') + '</svg>';

  function dateLabel(value) {
    return new Date(value).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
  }
  function safeURL(value) {
    const url = new URL(value, location.href);
    if (!/^https?:$/.test(url.protocol) || url.origin !== new URL(config.rest, location.href).origin) throw new Error(t('The server returned an invalid download or share address.'));
    return url.href;
  }
  const cloudNames = { drive: 'Google Drive', onedrive: 'OneDrive', dropbox: 'Dropbox' };
  function cloudURL(value, provider, authentication) {
    const url = new URL(value);
    const authPaths = { drive: ['accounts.google.com', '/o/oauth2/v2/auth'], onedrive: ['login.microsoftonline.com', '/common/oauth2/v2.0/authorize'], dropbox: ['www.dropbox.com', '/oauth2/authorize'] };
    const fileHosts = { drive: ['drive.google.com', 'docs.google.com'], onedrive: ['onedrive.live.com', '1drv.ms'], dropbox: ['www.dropbox.com', 'dropbox.com'] };
    const auth = authPaths[provider];
    const valid = authentication ? auth && url.hostname === auth[0] && url.pathname === auth[1] : fileHosts[provider] && (fileHosts[provider].includes(url.hostname) || (provider === 'onedrive' && /^[a-z0-9][a-z0-9-]*\.sharepoint\.com$/.test(url.hostname)));
    if (url.protocol !== 'https:' || url.username || url.password || url.port || !valid) throw new Error(t('The server returned an invalid provider address.'));
    return url.href;
  }
  const callbackOrigin = (() => {
    try { const url = new URL(config.cloudCallbackOrigin || location.origin); return /^https?:$/.test(url.protocol) && !url.username && !url.password ? url.origin : ''; } catch (_) { return ''; }
  })();
  function transferNotice(job, message, complete) {
    if (!job.notice) {
      const notice = document.createElement('section'); notice.className = 'aiesk-transfer-notice';
      notice.setAttribute('aria-label', t('Cloud upload status'));
      const status = document.createElement('p'); status.setAttribute('role', 'status'); status.setAttribute('aria-live', 'polite'); status.setAttribute('aria-atomic', 'true');
      let tray = ui.querySelector('.aiesk-transfer-tray');
      if (!tray) { tray = document.createElement('div'); tray.className = 'aiesk-transfer-tray'; ui.append(tray); }
      notice.append(status); tray.append(notice); job.notice = notice;
    }
    job.notice.querySelector('p').textContent = message;
    if (complete && !job.notice.querySelector('button')) {
      const done = document.createElement('button'); done.type = 'button'; done.className = 'aiesk-text-button'; done.textContent = t('Dismiss notification');
      done.addEventListener('click', () => job.notice.remove()); job.notice.append(done);
    }
  }
  async function request(path, body, method) {
    const abort = new AbortController();
    const cloudUpload = /^(drive|onedrive|dropbox)\/save$/.test(path);
    // An upload may include folder lookup, token refresh and file creation.
    const timer = setTimeout(() => abort.abort(), cloudUpload ? 120000 : 30000);
    try {
      const response = await fetch(config.rest + path, {
        method: method || 'POST', credentials: 'same-origin', signal: abort.signal,
        headers: { 'X-WP-Nonce': config.nonce, 'Content-Type': 'application/json' },
        body: body ? JSON.stringify(body) : undefined
      });
      const raw = await response.text();
      let data;
      try { data = JSON.parse(raw); } catch (_) {
        if (response.status === 401) data = {};
        else { const unreadable = new Error(t('The server returned an unreadable response. Reload the page and try again.')); unreadable.outcomeUnknown = cloudUpload; throw unreadable; }
      }
      if (!response.ok) {
        const code = data.code || '';
        const connectionRequired = !!(data.data && data.data.connection_required === true);
        const authentication = !connectionRequired && (response.status === 401 || (response.status === 403 && ['rest_cookie_invalid_nonce', 'rest_not_logged_in', 'aiesk_login_required', 'aiesk_not_logged_in', 'aiesk_auth_required', 'not_logged_in'].includes(code)));
        const error = new Error(authentication ? t('Your session has expired. Reload this page or sign in again before sharing.') : data.message || (data.data && data.data.message) || t('Sharing failed. Please try again.'));
        error.connectionRequired = connectionRequired;
        error.outcomeUnknown = !!(data.data && data.data.outcome_unknown === true);
        if (authentication) { sessionExpired = true; error.sessionExpired = true; }
        throw error;
      }
      return data;
    } catch (error) {
      if (error.name === 'AbortError') { const timedOut = new Error(t('The server is taking too long. Please check your connection before trying again.')); timedOut.outcomeUnknown = cloudUpload; throw timedOut; }
      if (cloudUpload && error instanceof TypeError) error.outcomeUnknown = true;
      throw error;
    } finally { clearTimeout(timer); }
  }
  function toast(message) {
    message = t(message);
    const host = activeDialog || ui;
    let box = host.querySelector('.aiesk-toast');
    if (!box) {
      box = document.createElement('div'); box.className = 'aiesk-toast'; box.setAttribute('role', 'status');
      host.append(box);
    }
    clearTimeout(toastTimer); box.textContent = message; box.classList.add('is-visible');
    toastTimer = setTimeout(() => box.classList.remove('is-visible'), 3500);
  }
  async function copy(value, input) {
    try {
      if (navigator.clipboard && window.isSecureContext) { await navigator.clipboard.writeText(value); toast('Link copied'); return; }
    } catch (_) { /* Keep a selectable fallback inside the modal. */ }
    input.focus(); input.select();
    try { if (document.execCommand('copy')) { toast('Link copied'); return; } } catch (_) { /* User can copy the selected URL. */ }
    toast('Link selected. Press Ctrl+C or ⌘C to copy.');
  }
  function closeMenus(except) {
    controllers.forEach(controller => controller.menu(false, except));
  }

  // The toolbar only needs to know whether there may be content. Bound this
  // probe independently of report length; full HTML/text is read on Share only.
  function hasReportContent(content) {
    if (!content) return false;
    const walker = document.createTreeWalker(content, NodeFilter.SHOW_ELEMENT | NodeFilter.SHOW_TEXT);
    let node, inspected = 0;
    while ((node = walker.nextNode())) {
      if (node.nodeType === Node.TEXT_NODE) {
        const value = node.nodeValue || '';
        if (value.slice(0, 256).trim() || value.length > 256) return true;
      }
      // An unusually deep/empty fragment may show a toolbar; snapshot creation
      // still validates actual text before enabling any sharing request.
      if (++inspected >= 32) return true;
    }
    return false;
  }

  class Response {
    constructor(conversation, reply) {
      const list = conversation.list;
      this.conversation = conversation;
      this.reply = reply;
      this.list = list;
      this.easy = conversation.easy;
      this.root = conversation.root;
      this.bar = template.content.firstElementChild.cloneNode(true);
      this.bar.dataset.theme = settings.theme || 'light';
      this.bar.hidden = true;
      if (conversation.savedReport && !this.bar.querySelector('.aiesk-trigger')) {
        // Saved-report rows always use the compact menu, independently of the
        // site's inline toolbar preference for ordinary chat conversations.
        this.bar.classList.remove('aiesk-inline'); this.bar.classList.add('aiesk-dropdown');
        const trigger = document.createElement('button'), popup = document.createElement('div');
        trigger.type = 'button'; trigger.className = 'aiesk-trigger'; trigger.textContent = t('Share');
        trigger.setAttribute('aria-expanded', 'false'); trigger.setAttribute('aria-haspopup', 'menu');
        popup.className = 'aiesk-menu'; popup.setAttribute('role', 'menu'); popup.setAttribute('aria-label', t('Share response'));
        this.bar.querySelectorAll('[data-action]').forEach(button => { button.setAttribute('role', 'menuitem'); popup.append(button); });
        this.bar.append(trigger, popup);
      }
      if (config.docxNeedsEasyForms === true && !conversation.savedReport && !this.root.matches('.mwaief-form[data-mwaief-form][data-mwaief-signature]')) this.bar.querySelectorAll('[data-action="docx"]').forEach(button => button.remove());
      this.trigger = this.bar.querySelector('.aiesk-trigger');
      this.popup = this.bar.querySelector('.aiesk-menu');
      if (this.popup) {
        this.popup.id = 'aiesk-menu-' + (++menuCounter);
        this.trigger.setAttribute('aria-controls', this.popup.id);
        // Viewport coordinates must not be resolved inside a transformed form
        // or page-builder wrapper. Keep the popup beside the modal root.
        this.popup.classList.add('aiesk-scope');
        this.popup.dataset.aiesk = '';
        this.popup.dataset.theme = settings.theme || 'light';
        if (typeof this.popup.showPopover === 'function') this.popup.setAttribute('popover', 'manual');
        ui.append(this.popup);
      }
      this.note = document.createElement('span'); this.note.className = 'aiesk-bar-note';
      this.bar.append(this.note);
      reply.after(this.bar);
      const onClick = event => {
        const trigger = event.target.closest('.aiesk-trigger');
        const button = event.target.closest('[data-action]');
        if (trigger) { this.menu(trigger.getAttribute('aria-expanded') !== 'true'); }
        if (button && !button.disabled) {
          this.menu(false);
          if (conversation.savedReport) this.openSaved(button.dataset.action, this.trigger || button);
          else openShare(this, button.dataset.action, this.trigger || button);
        }
      };
      const onKeydown = event => {
        if (!this.popup) return;
        if (event.target === this.trigger && ['Enter', ' '].includes(event.key)) {
          event.preventDefault(); this.menu(true);
          const first = this.popup.querySelector('button:not(:disabled)'); if (first) first.focus(); return;
        }
        if (event.key === 'Escape') { this.menu(false); this.trigger.focus(); event.preventDefault(); }
        if (event.key === 'Tab' && this.trigger.getAttribute('aria-expanded') === 'true') {
          this.menu(false); this.trigger.focus();
        }
        if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
          event.preventDefault(); this.menu(true);
          const items = [...this.popup.querySelectorAll('button:not(:disabled)')];
          const current = items.indexOf(document.activeElement);
          const index = event.key === 'Home' ? 0 : event.key === 'End' ? items.length - 1 : current < 0 ? (event.key === 'ArrowUp' ? items.length - 1 : 0) : (current + (event.key === 'ArrowUp' ? -1 : 1) + items.length) % items.length;
          if (items[index]) items[index].focus();
        }
      };
      this.bar.addEventListener('click', onClick);
      this.bar.addEventListener('keydown', onKeydown);
      if (this.popup) {
        this.popup.addEventListener('click', onClick);
        this.popup.addEventListener('keydown', onKeydown);
      }
      this.refresh();
    }
    busy() {
      if (this.conversation.savedReport) return !!this.loading;
      // Earlier completed replies remain shareable while a new reply streams.
      if (this.reply.matches('.is-streaming, [aria-busy="true"]') || this.reply.querySelector('.is-streaming-content, [aria-busy="true"]')) return true;
      return this.conversation.lastReply === this.reply && this.conversation.busy();
    }
    refresh() {
      if (!this.reply.isConnected || !this.list.contains(this.reply)) return;
      if (this.bar.previousElementSibling !== this.reply) this.reply.after(this.bar);
      const busy = this.busy(), content = this.conversation.content(this.reply);
      const hasReply = !!this.conversation.savedReport || hasReportContent(content);
      this.bar.hidden = !hasReply;
      [this.bar, this.popup].filter(Boolean).forEach(surface => surface.querySelectorAll('button').forEach(button => {
        if (button.disabled !== busy) button.disabled = busy;
      }));
      const note = t(busy ? 'Preparing response…' : 'Share this response');
      if (this.note.textContent !== note) this.note.textContent = note;
      if (this.trigger) this.trigger.setAttribute('aria-label', t('Share this response'));
      if (busy || !hasReply) this.menu(false);
    }
    async openSaved(action, returnFocus) {
      if (this.loading || activeDialog || !this.root.isConnected) return;
      this.loading = true; this.refresh();
      const source = this.conversation.savedReport;
      try {
        // The adapter must re-read its authenticated, exact saved report for
        // every action. Opening a menu never creates a link or sends a message.
        const value = await source.load();
        if (!this.root.isConnected || controllers.get(this.list) !== this.conversation) return;
        if (!value || typeof value.html !== 'string' || !value.html.trim()
            || new TextEncoder().encode(value.html).length > (Number(config.maxHtmlBytes) || 1048576)) {
          throw new Error(t('The selected response is unavailable.'));
        }
        const form = value.easyForms;
        source.easyForms = form && Number.isSafeInteger(form.form_id) && form.form_id > 0
          && typeof form.signature === 'string' && form.signature.length > 0 && form.signature.length <= 256
          ? Object.freeze({ form_id: form.form_id, signature: form.signature }) : null;
        if (action === 'docx' && config.docxNeedsEasyForms && !source.easyForms) {
          throw new Error(t('This Word export needs its assessment form. Reload the page and try again.'));
        }
        source.content.innerHTML = value.html;
        source.title = typeof value.title === 'string' ? value.title.slice(0, 160) : '';
        this.reply.setAttribute('data-aiesk-report-lang', typeof value.language === 'string' ? value.language : '');
        this.loading = false; this.refresh();
        openShare(this, action, returnFocus);
      } catch (error) {
        source.content.replaceChildren(); source.easyForms = null; source.title = '';
        if (this.root.isConnected) { toast(error.message || t('Sharing failed. Please try again.')); returnFocus.focus(); }
      } finally { this.loading = false; if (this.root.isConnected) this.refresh(); }
    }
    menu(open) {
      if (!this.popup) return;
      if (open) closeMenus(this);
      this.popup.classList.toggle('open', open);
      this.trigger.setAttribute('aria-expanded', String(open));
      if (this.popup.hasAttribute('popover')) {
        if (open && !this.popup.matches(':popover-open')) this.popup.showPopover();
        else if (!open && this.popup.matches(':popover-open')) this.popup.hidePopover();
      }
      if (open) {
        const rect = this.trigger.getBoundingClientRect();
        if (rect.bottom < 0 || rect.top > window.innerHeight) { this.menu(false); return; }
        const availableBelow = window.innerHeight - rect.bottom - 12;
        this.popup.style.maxHeight = Math.max(120, window.innerHeight - 24) + 'px';
        const height = this.popup.offsetHeight;
        this.popup.style.left = Math.max(12, Math.min(rect.left, window.innerWidth - this.popup.offsetWidth - 12)) + 'px';
        this.popup.style.top = Math.max(12, availableBelow >= height ? rect.bottom + 6 : rect.top - height - 6) + 'px';
      }
    }
    entries() {
      return [{ reply: this.reply, number: [...this.conversation.responses.keys()].indexOf(this.reply) + 1, snapshot: null, stale: false }];
    }
    destroy() {
      this.menu(false);
      if (this.dismissDialog) this.dismissDialog(undefined, true);
      if (this.popup) this.popup.remove();
      this.bar.remove();
    }
  }

  class Conversation {
    constructor(list) {
      this.list = list;
      this.easy = list.matches('.mwaief-message-list');
      this.root = list.closest(this.easy ? '.mwaief-form' : '.mwai-chat') || list.parentElement;
      this.replySelector = this.easy ? '.mwaief-message-assistant' : '.mwai-reply.mwai-ai';
      this.responses = new Map();
      this.observer = new MutationObserver(records => {
        const relevant = records.some(record => {
          const el = record.target.nodeType === 1 ? record.target : record.target.parentElement;
          if (!el || el.closest('[data-aiesk]')) return false;
          if (this.nativeWordSnapshotting && record.type === 'childList' && [...record.addedNodes, ...record.removedNodes].every(node => node.nodeType === 1 && node.matches('.aiesk-share-bar[data-aiesk]'))) return false;
          if (record.type === 'attributes') return el.matches('.mwaief-message-assistant, .mwaief-assistant-content, .mwaief-submit-button, .mwaief-new-assessment-button, .mwaief-followup-input, .mwai-input-submit');
          if (el === this.list || !this.list.isConnected) return true;
          // A new wrapper can carry an entire history. Normal token updates
          // inside existing response content do not rescan the conversation.
          const reply = el.closest(this.replySelector), response = this.responses.get(reply);
          if (response) return response.bar.hidden || !response.bar.isConnected;
          return [...record.addedNodes, ...record.removedNodes].some(node => node.nodeType === 1 && (node.matches(this.replySelector + ', ' + listSelector) || node.querySelector(this.replySelector + ', ' + listSelector)));
        });
        if (relevant && !this.timer) this.timer = setTimeout(() => { this.timer = null; this.refresh(); }, 100);
      });
      this.observer.observe(this.root, { childList: true, subtree: true, attributes: true, attributeFilter: ['class', 'aria-busy', 'disabled'] });
      if (this.easy) {
        this.nativeWordCapture = event => this.prepareNativeWord(event);
        this.nativeWordBubble = event => { if (this.nativeWordButton && this.nativeWordButton.contains(event.target)) this.restoreNativeWord(); };
        this.root.addEventListener('click', this.nativeWordCapture, true);
        this.root.addEventListener('click', this.nativeWordBubble);
      }
      this.refresh();
    }
    prepareNativeWord(event) {
      const button = event.target instanceof Element && event.target.closest('.mwaief-global-word');
      if (!button || button.closest('.mwaief-form') !== this.root || button.closest(listSelector) || this.nativeWordSnapshotting) return;
      // Easy Forms 0.6.4/0.6.5 captures list.innerHTML synchronously in its
      // existing target click listener, before the first await. Temporarily
      // detach only controls we own so that original export works even when
      // the server has no DOM extension. Native content/listeners are untouched.
      this.nativeWordSnapshotting = true;
      this.nativeWordButton = button;
      this.nativeWordDetached = [...this.responses.values()].filter(response => response.bar.isConnected && this.list.contains(response.bar));
      this.nativeWordDetached.forEach(response => response.bar.remove());
      // The bubble listener runs after the native target listener captured HTML.
      // Do not queue restoration here: browser microtasks may run between event
      // listeners. A frame fallback also restores if another script stops bubbling.
      this.nativeWordFrame = requestAnimationFrame(() => this.restoreNativeWord());
    }
    restoreNativeWord() {
      if (!this.nativeWordSnapshotting || !this.nativeWordDetached) return;
      cancelAnimationFrame(this.nativeWordFrame);
      this.nativeWordDetached.forEach(response => {
        if (this.list.isConnected && this.list.contains(response.reply) && this.responses.get(response.reply) === response && !response.bar.isConnected) response.reply.after(response.bar);
      });
      this.nativeWordDetached = null; this.nativeWordButton = null;
      // Own remove/restore records must not rescan the history.
      queueMicrotask(() => { this.nativeWordSnapshotting = false; });
    }
    busy() {
      return !!this.root.querySelector(this.easy
        ? '.mwaief-message-assistant.is-streaming, .mwaief-assistant-content[aria-busy="true"], .mwaief-assistant-content.is-streaming-content, .mwaief-submit-button.is-loading, .mwaief-new-assessment-button:disabled, .mwaief-followup-input:disabled'
        : '.mwai-input-submit.mwai-busy');
    }
    content(reply) {
      if (!reply || reply.closest(listSelector) !== this.list || !reply.matches(this.replySelector)) return null;
      const content = reply.querySelector(this.easy ? '.mwaief-assistant-content' : '.mwai-text');
      return content && content.closest(this.replySelector) === reply ? content : null;
    }
    refresh() {
      if (!this.list.isConnected) { this.destroy(); discover(this.root); return; }
      const replies = [...this.list.querySelectorAll(this.replySelector)].filter(reply => reply.closest(listSelector) === this.list && !reply.parentElement.closest('.mwaief-message, .mwaief-message-assistant, .mwaief-message-user, .mwai-reply'));
      this.lastReply = replies.length ? replies[replies.length - 1] : null;
      const current = new Set(replies);
      this.responses.forEach((response, reply) => { if (!current.has(reply)) { response.destroy(); this.responses.delete(reply); } });
      replies.forEach(reply => { if (!this.responses.has(reply)) this.responses.set(reply, new Response(this, reply)); });
      // Preserve DOM order when history inserts a response before existing ones.
      this.responses = new Map(replies.map(reply => [reply, this.responses.get(reply)]));
      this.responses.forEach(response => response.refresh());
    }
    menu(open, except) { this.responses.forEach(response => { if (response !== except) response.menu(open); }); }
    destroy() {
      clearTimeout(this.timer); this.observer.disconnect();
      if (this.nativeWordCapture) { this.root.removeEventListener('click', this.nativeWordCapture, true); this.root.removeEventListener('click', this.nativeWordBubble); cancelAnimationFrame(this.nativeWordFrame); }
      this.responses.forEach(response => response.destroy()); this.responses.clear(); controllers.delete(this.list);
    }
  }

  function discover(node) {
    if (!node || !node.isConnected || node.nodeType !== 1) return;
    const lists = node.matches(listSelector) ? [node] : [...node.querySelectorAll(listSelector)];
    lists.forEach(list => { if (!controllers.has(list)) controllers.set(list, new Conversation(list)); });
  }

  /** Public adapter for authenticated saved-report lists, without fake chat forms. */
  function mountSavedReport(container, options) {
    if (!(container instanceof HTMLElement) || !container.isConnected || typeof options?.load !== 'function'
        || controllers.has(container)) throw new Error('A saved-report container and loader are required.');
    const reply = document.createElement('article'), content = document.createElement('div');
    reply.hidden = true; reply.setAttribute('aria-hidden', 'true'); reply.dataset.aieskSavedSource = '';
    reply.append(content); container.append(reply);
    const conversation = {
      list: container, root: container, easy: false, lastReply: reply, responses: new Map(),
      savedReport: { load: options.load, content, title: '', easyForms: null },
      content: candidate => candidate === reply ? content : null,
      busy: () => false,
      menu(open, except) { this.responses.forEach(response => { if (response !== except) response.menu(open); }); },
      destroy() { this.responses.forEach(response => response.destroy()); this.responses.clear(); reply.remove(); controllers.delete(container); }
    };
    controllers.set(container, conversation);
    const response = new Response(conversation, reply); conversation.responses.set(reply, response);
    return Object.freeze({ destroy: () => conversation.destroy() });
  }

  function openShare(controller, action, returnFocus) {
    if (activeDialog) return;
    if (typeof HTMLDialogElement === 'undefined' || typeof HTMLDialogElement.prototype.showModal !== 'function') { toast('Your browser cannot open the sharing dialog. Update your browser and try again.'); return; }
    if (!controller.list.isConnected || !controller.root.isConnected || !controller.reply.isConnected || !controller.list.contains(controller.reply)) {
      controller.refresh(); toast('The conversation changed. Open Share again to select the current response.'); return;
    }
    if (controller.busy()) { controller.refresh(); toast('Please wait for the response to finish.'); return; }
    const entries = controller.entries();
    if (!entries.length) { toast('No completed response is available yet.'); return; }
    const messenger = action === 'whatsapp' || action === 'viber';
    const cloud = Object.prototype.hasOwnProperty.call(cloudNames, action);
    const linking = action === 'link' || action === 'qr' || messenger;
    const names = { link: 'Share a private link', qr: 'Share with a QR code', email: 'Email this response', pdf: 'Export a PDF', docx: 'Export a Word document', whatsapp: 'Share to WhatsApp', viber: 'Share to Viber', drive: 'Save to Google Drive', onedrive: 'Save to OneDrive', dropbox: 'Save to Dropbox' };
    const labels = { link: 'Create private link', qr: 'Create QR code', email: 'Send email', pdf: 'Prepare PDF', docx: 'Prepare Word document', whatsapp: 'Create link for WhatsApp', viber: 'Create link for Viber', drive: 'Save to Google Drive', onedrive: 'Save to OneDrive', dropbox: 'Save to Dropbox' };
    if (!names[action]) return;
    const easyForm = controller.root.matches('.mwaief-form[data-mwaief-form][data-mwaief-signature]') ? controller.root : null;
    const easyForms = controller.conversation.savedReport ? controller.conversation.savedReport.easyForms
      : easyForm && /^\d+$/.test(easyForm.getAttribute('data-mwaief-form') || '') && easyForm.getAttribute('data-mwaief-signature') ? Object.freeze({ form_id: Number(easyForm.getAttribute('data-mwaief-form')), signature: easyForm.getAttribute('data-mwaief-signature') }) : null;
    const formats = ['html', ...((Array.isArray(config.cloudFormats) ? config.cloudFormats : []).filter(format => format === 'pdf' || (format === 'docx' && (!config.docxNeedsEasyForms || easyForms))))];
    const dialog = document.createElement('dialog');
    dialog.className = 'aiesk-scope aiesk-dialog'; dialog.dataset.theme = settings.theme || 'light';
    dialog.setAttribute('aria-labelledby', 'aiesk-dialog-title'); dialog.setAttribute('aria-describedby', 'aiesk-dialog-description');
    dialog.innerHTML = '<div class="aiesk-dialog-inner"><header class="aiesk-dialog-head"><span class="aiesk-symbol">' + icon(linking ? 'link' : 'arrow') + '</span><button type="button" class="aiesk-icon-button aiesk-close" aria-label="Close sharing dialog">' + icon('close') + '</button></header>' +
      '<h2 id="aiesk-dialog-title">' + names[action] + '</h2><p id="aiesk-dialog-description" class="aiesk-muted">' + (linking ? 'Only this response is shared. You choose who receives it and when the link expires.' : 'Only this response is included. The rest of the conversation stays private.') + '</p>' +
      '<form class="aiesk-form"><div class="aiesk-compose"><input type="hidden" id="aiesk-response" value="0">' +
      '<details class="aiesk-preview"><summary>Preview this response <span>Snapshot</span></summary><div class="aiesk-preview-text"></div></details>' +
      '<label class="aiesk-label" for="aiesk-title">Title</label><input id="aiesk-title" maxlength="200" required>' +
      (cloud ? '<label class="aiesk-label" for="aiesk-cloud-format">File format</label><select id="aiesk-cloud-format">' + [...new Set(formats)].map(format => '<option value="' + format + '">' + escape(t({ html: 'Web page (.html)', pdf: 'PDF (.pdf)', docx: 'Word document (.docx)' }[format])) + '</option>').join('') + '</select><section class="aiesk-cloud-connection" aria-label="Cloud connection"><p class="aiesk-cloud-status" role="status">Checking connection…</p><div class="aiesk-cloud-actions"><button type="button" class="aiesk-secondary aiesk-cloud-connect" hidden>Connect account</button><button type="button" class="aiesk-text-button aiesk-cloud-check">Check connection</button><button type="button" class="aiesk-text-button aiesk-cloud-disconnect" hidden>Disconnect</button></div><p class="aiesk-muted aiesk-cloud-disconnect-note" hidden>Disconnecting stops future uploads. Files already saved stay in your cloud account.</p></section><p class="aiesk-privacy">A separate file will be saved to your cloud account. It remains there until you delete it in the provider. Link expiry and revocation do not remove cloud files.</p>' : '') +
      (linking ? '<label class="aiesk-short-option"><input type="checkbox" id="aiesk-short-url" checked><span><strong>Use short URL</strong><small>A compact address with the same expiry and access controls.</small></span></label><fieldset class="aiesk-expiry"><legend>Link expires after</legend><div class="aiesk-presets">' + [...new Set([24, 168, 720, defaultHours])].filter(h => h <= maxHours).sort((a, b) => a - b).map(h => '<button type="button" data-hours="' + h + '" aria-pressed="' + (h === defaultHours) + '">' + escape(h % 24 === 0 ? t(h === 24 ? '{count} day' : '{count} days', { count: h / 24 }) : t(h === 1 ? '{count} hour' : '{count} hours', { count: h })) + '</button>').join('') + '<button type="button" data-custom aria-pressed="false">Custom</button></div><div class="aiesk-custom" hidden><label for="aiesk-amount" class="aiesk-label">Duration</label><div class="aiesk-duration"><input id="aiesk-amount" type="number" min="1" step="1" value="1"><select id="aiesk-unit" aria-label="Duration unit"><option value="1">Hours</option><option value="24">Days</option><option value="168">Weeks</option><option value="720">Months (30 days)</option></select></div></div><p class="aiesk-expiry-preview aiesk-muted" aria-live="polite"></p></fieldset><p class="aiesk-privacy">' + icon('lock') + '<span>Anyone with this link can read the selected response until it expires or you revoke it.</span></p>' : '') +
      (action === 'email' ? '<label class="aiesk-label" for="aiesk-recipient">Recipient email</label><input id="aiesk-recipient" type="email" autocomplete="email" placeholder="name@example.com" aria-describedby="aiesk-email-attribution" required><p id="aiesk-email-attribution" class="aiesk-muted">Your name and, if applicable, your school are included in this email.</p>' : '') +
      '</div><div class="aiesk-result" hidden></div><p class="aiesk-error" role="alert" hidden></p><footer class="aiesk-dialog-footer"><span class="aiesk-progress" role="status"></span><button type="submit" class="aiesk-primary" aria-live="polite" aria-atomic="true">' + labels[action] + icon('arrow') + '</button></footer></form></div>';
    translateMarkup(dialog);
    ui.append(dialog); activeDialog = dialog;
    const $ = selector => dialog.querySelector(selector);
    const form = $('.aiesk-form'), primary = $('.aiesk-primary'), close = $('.aiesk-close');
    const responseSelect = $('#aiesk-response'), titleInput = $('#aiesk-title');
    let selected = null, hours = defaultHours, custom = false, pending = false, preparing = false, record = null, completed = false, closing = false, preparation = 0;
    let cloudConnected = false, cloudStored = false, cloudConfigured = false, cloudChecking = false, oauthPopup = null, cloudGeneration = 0, uploadJob = null;
    const entriesByReply = new Map(entries.map(entry => [entry.reply, entry]));
    const snapshotObserver = new MutationObserver(records => {
      records.forEach(mutation => {
        const node = mutation.target.nodeType === 1 ? mutation.target : mutation.target.parentElement;
        const reply = controller.conversation.savedReport
          ? (node && controller.reply.contains(node) ? controller.reply : null)
          : node && node.closest(controller.easy ? '.mwaief-message-assistant' : '.mwai-reply.mwai-ai');
        const entry = entriesByReply.get(reply);
        if (entry) entry.stale = true;
      });
    });
    snapshotObserver.observe(controller.list, { subtree: true, childList: true, characterData: true, attributes: true });
    const pause = () => new Promise(resolve => setTimeout(resolve, 0));
    async function chooseResponse() {
      const entry = entries[Number(responseSelect.value)], generation = ++preparation;
      selected = null; preparing = true; error(''); primary.disabled = true; titleInput.disabled = true;
      form.setAttribute('aria-busy', 'true'); $('.aiesk-progress').textContent = t('Preparing selected response…');
      $('.aiesk-preview-text').textContent = ''; titleInput.value = '';
      // Allow the dialog to paint before reading a report. No history-wide DOM
      // clones or preview strings; each selected report is frozen once, in memory.
      await new Promise(resolve => requestAnimationFrame(() => setTimeout(resolve, 0)));
      const current = () => generation === preparation && !closing && dialog.isConnected;
      try {
        if (!current()) return;
        if (!entry.snapshot) {
          const content = controller.conversation.content(entry.reply);
          const valid = () => current() && !entry.stale && content && content.isConnected && content === controller.conversation.content(entry.reply) && controller.list.isConnected && controller.list.contains(entry.reply);
          if (!valid() || controller.busy()) throw new Error(t('The response changed. Close this dialog and open Share again.'));
          const clone = content.cloneNode(false), stack = [...content.childNodes].reverse().map(node => [node, clone]);
          const text = []; let count = 0, bytes = 0, batchStart = performance.now();
          const maxBytes = Number(config.maxHtmlBytes) || 1048576;
          while (stack.length) {
            if (!valid()) throw new Error(t('The response changed. Close this dialog and open Share again.'));
            const [node, parent] = stack.pop();
            if (++count > 50000) throw new Error(t('This response is too large to share. Choose a shorter response.'));
            if (node.nodeType === Node.ELEMENT_NODE && node.matches('script, style, button, input, textarea, select, .mwai-code-header, .mwai-reply-actions, .mwaief-message, .mwaief-message-user, .mwaief-message-assistant, .mwai-reply, .mwaief-message-list, .mwai-conversation, [data-aiesk]')) continue;
            if (node.nodeType === Node.ELEMENT_NODE || node.nodeType === Node.TEXT_NODE) {
              const copied = node.cloneNode(false); parent.append(copied);
              if (node.nodeType === Node.TEXT_NODE) { text.push(node.textContent); bytes += node.textContent.length; }
              else {
                if (node.matches('p, div, br, li, h1, h2, h3, h4, h5, h6, tr')) text.push('\n');
                [...node.childNodes].reverse().forEach(child => stack.push([child, copied]));
              }
              if (bytes > maxBytes) throw new Error(t('This response is too large to share. Choose a shorter response.'));
            }
            if (count % 200 === 0 || performance.now() - batchStart > 8) { await pause(); if (controller.busy()) throw new Error(t('Please wait for the response to finish.')); batchStart = performance.now(); }
          }
          if (!valid()) throw new Error(t('The response changed. Close this dialog and open Share again.'));
          const plain = text.join('').trim(), html = clone.innerHTML;
          if (new TextEncoder().encode(html).length > maxBytes) throw new Error(t('This response is too large to share. Choose a shorter response.'));
          if (!plain) throw new Error(t('No completed response is available yet.'));
          // Easy Forms 0.6.4 does not attach type/language to each response.
          // A selected response's heading or excerpt is reliable; current form
          // controls and conversation-wide titles can describe another report.
          const heading = clone.querySelector('h1, h2, h3');
          const title = (controller.conversation.savedReport?.title || (heading && heading.textContent.trim()) || plain).replace(/\s+/g, ' ').slice(0, 120);
          // Metadata is accepted only on this response or its content root.
          // Never infer an earlier report's language from today's form selection.
          const language = entry.reply.getAttribute('data-aiesk-report-lang') || content.getAttribute('data-aiesk-report-lang') || '';
          const reportLang = /^[a-zA-Z]{2,3}(?:-[a-zA-Z0-9]{2,8})*$/.test(language) && language.length <= 35 ? language : '';
          entry.snapshot = Object.freeze({ html, text: plain, title, reportLang, easyForms });
        }
        if (!current()) return;
        selected = entry.snapshot; titleInput.value = selected.title;
        $('.aiesk-preview-text').textContent = selected.text;
        dialog.setAttribute('data-response-number', String(entry.number));
      } catch (err) { if (current()) showRequestError(err); }
      finally {
        if (current()) { preparing = false; form.setAttribute('aria-busy', String(pending)); titleInput.disabled = pending; if (!pending) $('.aiesk-progress').textContent = ''; primary.disabled = !selected || sessionExpired || pending; if (linking) updateExpiry(); if (cloud) updateCloudControls(); }
      }
    }
    function dismiss(message, force) {
      if (closing || (pending && !uploadJob && !force)) return;
      if (uploadJob) transferNotice(uploadJob, t('Saving to {provider} continues in the background. Wait for the result before saving again.', { provider: cloudNames[action] }), false);
      closing = true; ++preparation; snapshotObserver.disconnect(); dialog.classList.add('is-closing');
      controller.dismissDialog = null;
      if (cloud) { window.removeEventListener('aiesk:cloud-upload-state', updateCloudControls); ++cloudGeneration; window.removeEventListener('message', onCloudMessage); if (oauthPopup && !oauthPopup.closed) oauthPopup.close(); oauthPopup = null; }
      setTimeout(() => { dialog.close(); dialog.remove(); if (activeDialog === dialog) activeDialog = null; if (typeof message === 'string') toast(message); if (returnFocus.isConnected) returnFocus.focus(); }, matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 160);
    }
    controller.dismissDialog = dismiss;
    close.addEventListener('click', () => dismiss());
    dialog.addEventListener('cancel', event => { event.preventDefault(); dismiss(); });
    dialog.addEventListener('click', event => { if (event.target === dialog) { const r = dialog.getBoundingClientRect(); if (event.clientX < r.left || event.clientX > r.right || event.clientY < r.top || event.clientY > r.bottom) dismiss(); } });
    function error(message) { $('.aiesk-error').textContent = t(message); $('.aiesk-error').hidden = !message; }
    function showRequestError(err) {
      error(err.outcomeUnknown ? t('The provider did not confirm the result. Check your cloud folder before trying again; a file may already have been saved.') : err.message || t('Sharing failed. Please try again.'));
      if (cloud && err.connectionRequired) { cloudConnected = false; updateCloudControls(); }
      if (!err.sessionExpired && !sessionExpired) return;
      primary.disabled = true;
      const revoke = $('.aiesk-danger'); if (revoke) revoke.disabled = true;
      if ($('.aiesk-session-actions')) return;
      const actions = document.createElement('div'); actions.className = 'aiesk-session-actions';
      const reload = document.createElement('button'); reload.type = 'button'; reload.className = 'aiesk-secondary'; reload.textContent = t('Reload page');
      reload.addEventListener('click', () => location.reload()); actions.append(reload);
      if (config.loginUrl) {
        try { const login = document.createElement('a'); login.href = safeURL(config.loginUrl); login.className = 'aiesk-text-button'; login.textContent = t('Sign in again'); actions.append(login); } catch (_) { /* Reload remains available. */ }
      }
      $('.aiesk-error').after(actions); reload.focus();
    }
    function setPending(value, message) {
      if (closing) return;
      pending = value; form.setAttribute('aria-busy', String(value)); close.disabled = value && !uploadJob;
      form.querySelectorAll('button, input, select').forEach(el => { el.disabled = value; });
      const resultProgress = $('.aiesk-result-progress');
      primary.classList.toggle('is-loading', value && !resultProgress);
      primary.innerHTML = escape(t(value && !resultProgress ? message : labels[action])) + (value && !resultProgress ? '' : icon('arrow'));
      // The pending label and spinner form a single status on the action.
      // A result action (such as revoke) has its own visible status instead.
      $('.aiesk-progress').textContent = '';
      if (!value && linking && !record) updateExpiry();
      if (resultProgress) resultProgress.textContent = value ? t(message) : '';
      if (sessionExpired) { primary.disabled = true; const revoke = $('.aiesk-danger'); if (revoke) revoke.disabled = true; }
      if (cloud) {
        updateCloudControls();
        let note = $('.aiesk-upload-wait-note');
        if (uploadJob && !note) { note = document.createElement('p'); note.className = 'aiesk-muted aiesk-upload-wait-note'; note.textContent = t('You can close this dialog and keep working. Closing does not cancel the upload.'); $('.aiesk-dialog-footer').before(note); }
        if (note) note.hidden = !value || !uploadJob;
      }
      if (value && uploadJob) { const note = $('.aiesk-upload-wait-note'); note.tabIndex = -1; note.focus(); }
    }
    function updateCloudControls() {
      if (!cloud || closing) return;
      const uploading = cloudUploads.has(action);
      primary.disabled = pending || uploading || preparing || cloudChecking || !selected || !cloudConnected || sessionExpired || completed;
      $('.aiesk-cloud-connect').hidden = !cloudConfigured || cloudConnected;
      $('.aiesk-cloud-connect').disabled = pending || uploading || cloudChecking || sessionExpired;
      $('.aiesk-cloud-check').disabled = pending || cloudChecking || sessionExpired;
      $('.aiesk-cloud-disconnect').hidden = !cloudStored;
      $('.aiesk-cloud-disconnect').disabled = pending || uploading || cloudChecking || sessionExpired;
      $('.aiesk-cloud-disconnect-note').hidden = !cloudStored;
      const status = $('.aiesk-cloud-status');
      if (uploading && !uploadJob) { status.dataset.waiting = 'true'; status.textContent = t('A file is still being saved to {provider}. Wait for its result before saving again.', { provider: cloudNames[action] }); }
      else if (status.dataset.waiting) { delete status.dataset.waiting; status.textContent = cloudConnected ? t('Connected to {provider}', { provider: cloudNames[action] }) : t('Connection could not be checked. Try Check connection again.'); }
    }
    async function checkCloudConnection() {
      if (!cloud || closing || pending || cloudChecking || sessionExpired) return;
      const generation = ++cloudGeneration;
      cloudChecking = true; error(''); updateCloudControls();
      $('.aiesk-cloud-status').textContent = t('Checking connection…');
      try {
        const data = await request(action + '/status', null, 'GET');
        if (closing || generation !== cloudGeneration) return;
        cloudConfigured = data.configured === true && data.enabled !== false; cloudConnected = cloudConfigured && data.connected === true; cloudStored = data.stored_connection === true || data.connected === true;
        $('.aiesk-cloud-status').textContent = !cloudConfigured ? t('{provider} is not configured on this website. Ask the site administrator to enable it.', { provider: cloudNames[action] }) : cloudConnected ? t(completed ? 'Connected to {provider}' : 'Connected to {provider}. Choose Save when you are ready.', { provider: cloudNames[action] }) : t('Connect your {provider} account to save this response.', { provider: cloudNames[action] });
      } catch (err) {
        if (!closing && generation === cloudGeneration) { cloudConnected = false; $('.aiesk-cloud-status').textContent = t('Connection could not be checked. Try Check connection again.'); showRequestError(err); }
      } finally { if (!closing && generation === cloudGeneration) { cloudChecking = false; updateCloudControls(); } }
    }
    async function onCloudMessage(event) {
      if (closing || !oauthPopup || event.source !== oauthPopup || event.origin !== callbackOrigin || !event.data || event.data.type !== 'aiesk-cloud-connected' || event.data.provider !== action || typeof event.data.success !== 'boolean') return;
      if (!oauthPopup.closed) oauthPopup.close(); oauthPopup = null;
      if (event.data.success) await checkCloudConnection();
      else { $('.aiesk-cloud-status').textContent = t('Connection was not completed. You can connect again when ready.'); error('Connection was not completed. You can connect again when ready.'); updateCloudControls(); }
    }
    if (cloud) {
      window.addEventListener('message', onCloudMessage);
      window.addEventListener('aiesk:cloud-upload-state', updateCloudControls);
      $('.aiesk-cloud-check').addEventListener('click', checkCloudConnection);
      $('.aiesk-cloud-connect').textContent = t('Connect {provider}', { provider: cloudNames[action] });
      $('.aiesk-cloud-connect').addEventListener('click', async () => {
        if (pending || cloudChecking || sessionExpired) return;
        if (oauthPopup && !oauthPopup.closed) { oauthPopup.focus(); return; }
        // The blank window must open within the user's gesture, before POST.
        oauthPopup = window.open('', 'aiesk-connect-' + action, 'popup,width=600,height=720');
        if (!oauthPopup) { error('Your browser blocked the connection window. Allow pop-ups for this website, then select Connect again.'); return; }
        error(''); setPending(true, 'Opening account connection…');
        try {
          const data = await request(action + '/oauth/start', {});
          if (closing || !oauthPopup) return;
          oauthPopup.location.href = cloudURL(data.auth_url, action, true);
          $('.aiesk-cloud-status').textContent = t('Finish connecting in the new window, then use Check connection if this screen does not update.');
        } catch (err) { if (oauthPopup && !oauthPopup.closed) oauthPopup.close(); oauthPopup = null; if (!closing) showRequestError(err); }
        finally { setPending(false); }
      });
      $('.aiesk-cloud-disconnect').addEventListener('click', async () => {
        if (pending || cloudChecking || sessionExpired) return;
        error(''); setPending(true, 'Disconnecting…');
        try { await request(action + '/connection', null, 'DELETE'); if (closing) return; cloudConnected = false; cloudStored = false; $('.aiesk-cloud-status').textContent = t('Disconnected. Files already saved remain in your cloud account.'); }
        catch (err) { if (!closing) showRequestError(err); }
        finally { setPending(false); }
      });
    }
    function updateExpiry() {
      if (!linking) return;
      if (custom) hours = Number($('#aiesk-amount').value) * Number($('#aiesk-unit').value);
      const valid = Number.isInteger(hours) && hours >= 1 && hours <= maxHours;
      $('#aiesk-amount').required = custom;
      $('#aiesk-amount').disabled = !custom || pending;
      $('#aiesk-unit').disabled = !custom || pending;
      primary.disabled = !valid || pending || preparing || !selected || sessionExpired;
      $('.aiesk-expiry-preview').textContent = valid ? t('Expires {date}', { date: dateLabel(Date.now() + hours * 3600000) }) : t('Choose a whole duration between 1 and {hours} hours.', { hours: maxHours });
      $('#aiesk-amount').setAttribute('aria-invalid', String(!valid));
    }
    if (linking) {
      $('.aiesk-presets').addEventListener('click', event => {
        const button = event.target.closest('button'); if (!button) return;
        custom = button.hasAttribute('data-custom'); if (!custom) hours = Number(button.dataset.hours);
        $('.aiesk-custom').hidden = !custom;
        $('.aiesk-presets').querySelectorAll('button').forEach(el => el.setAttribute('aria-pressed', String(el === button)));
        updateExpiry(); if (custom) $('#aiesk-amount').focus();
      });
      $('#aiesk-amount').addEventListener('input', updateExpiry); $('#aiesk-unit').addEventListener('change', updateExpiry); updateExpiry();
    }
    function renderQR() {
      const area = $('.aiesk-qr-area'); if (area.childElementCount) { area.hidden = false; return; }
      if (typeof window.qrcode !== 'function') { error('The QR module did not load. Reload this page, or copy the link below.'); return; }
      try {
        const qr = window.qrcode(0, 'M'); qr.addData(record.url); qr.make();
        const modules = qr.getModuleCount(), cell = 8, quiet = 4;
        const canvas = document.createElement('canvas'); canvas.width = canvas.height = (modules + quiet * 2) * cell;
        canvas.setAttribute('role', 'img'); canvas.setAttribute('aria-label', t('QR code for the private share link'));
        const ctx = canvas.getContext('2d'); ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, canvas.width, canvas.height); ctx.fillStyle = '#000';
        for (let row = 0; row < modules; row++) for (let col = 0; col < modules; col++) if (qr.isDark(row, col)) ctx.fillRect((col + quiet) * cell, (row + quiet) * cell, cell, cell);
        const link = document.createElement('a'); link.className = 'aiesk-secondary'; link.href = canvas.toDataURL('image/png'); link.download = 'shared-response-qr.png'; link.textContent = t('Download QR image');
        area.append(canvas, link); area.hidden = false;
        const toggle = $('.aiesk-show-qr'); if (toggle) toggle.hidden = true;
      } catch (_) { error('The QR code could not be created. You can still copy the link.'); }
    }
    function showResult(data) {
      record = data; completed = true; error(''); $('.aiesk-compose').hidden = true; $('.aiesk-dialog-footer').hidden = true;
      const result = $('.aiesk-result'); result.hidden = false;
      $('#aiesk-dialog-title').textContent = t('Ready to share');
      $('#aiesk-dialog-description').textContent = t('Your selected response is saved as a snapshot.');
      const expiry = typeof data.expires_at === 'number' ? data.expires_at * 1000 : Date.parse(data.expires_at);
      result.innerHTML = '<div class="aiesk-success-line">' + icon('check') + '<span>' + escape(t(data.reused === true ? 'Existing active link ready' : 'Private link created')) + '</span></div><label class="aiesk-label" for="aiesk-url">Share link</label><div class="aiesk-link-row"><input id="aiesk-url" readonly><button type="button" class="aiesk-primary aiesk-copy">' + icon('copy') + 'Copy</button></div><p class="aiesk-muted aiesk-expiry-result">' + (Number.isFinite(expiry) ? escape(t('Expires {date}', { date: dateLabel(expiry) })) : escape(t('Expires after {hours} hours', { hours }))) + '</p><div class="aiesk-qr-area" hidden></div><div class="aiesk-result-actions"><button type="button" class="aiesk-secondary aiesk-show-qr">Show QR code</button><button type="button" class="aiesk-text-button aiesk-revoke">Revoke link</button></div><p class="aiesk-result-progress aiesk-muted" role="status"></p><div class="aiesk-revoke-confirm" hidden><p>This will immediately disable this link and its QR code.</p><button type="button" class="aiesk-danger">Revoke now</button><button type="button" class="aiesk-text-button aiesk-keep">Keep link</button></div>';
      translateMarkup(result);
      $('#aiesk-url').value = safeURL(data.url);
      $('.aiesk-copy').addEventListener('click', () => copy(data.url, $('#aiesk-url')));
      $('.aiesk-show-qr').addEventListener('click', renderQR);
      $('.aiesk-show-qr').hidden = !(controller.popup || controller.bar).querySelector('[data-action="qr"]');
      $('.aiesk-revoke').addEventListener('click', () => { $('.aiesk-revoke-confirm').hidden = false; $('.aiesk-danger').focus(); });
      $('.aiesk-keep').addEventListener('click', () => { $('.aiesk-revoke-confirm').hidden = true; $('.aiesk-revoke').focus(); });
      $('.aiesk-danger').addEventListener('click', async () => {
        if (sessionExpired) return;
        error(''); setPending(true, 'Revoking link…');
        try {
          await request('link/' + encodeURIComponent(record.post_id), null, 'DELETE');
          if (!closing) { setPending(false); dismiss('Link revoked'); }
          window.dispatchEvent(new CustomEvent('aiesk:links-changed', { detail: { id: record.post_id, action: 'revoked' } }));
        } catch (err) { if (!closing) { setPending(false); showRequestError(err); } }
      });
      if (config.managerUrl) {
        try {
          const manage = document.createElement('a'); manage.href = safeURL(config.managerUrl);
          manage.className = 'aiesk-text-button aiesk-manage-links'; manage.textContent = t('Manage my shared links');
          result.append(manage);
        } catch (_) { /* Ignore an invalid configured dashboard address. */ }
      }
      if (messenger) {
        const name = action === 'whatsapp' ? 'WhatsApp' : 'Viber';
        const reportTitle = titleInput.value.trim() || selected.title, url = safeURL(data.url);
        // Viber trims forwarded text at 200 characters. Keep the complete URL
        // first and fit the title to a conservative UTF-16 budget.
        let message = reportTitle + '\n' + url;
        if (action === 'viber') {
          message = url;
          let title = '';
          for (const character of reportTitle) { if (url.length + 1 + title.length + character.length > 200) break; title += character; }
          if (title) message += '\n' + title;
        }
        const launch = document.createElement('a'); launch.className = 'aiesk-primary aiesk-messenger-open';
        // These are fixed app endpoints, not a provider-supplied redirect.
        // A report heading truncated at its length limit may end in half an
        // emoji. Replace unpaired surrogates before URL encoding can throw.
        const encoded = encodeURIComponent(Array.from(message, character => character.length === 1 && character.charCodeAt(0) >= 0xd800 && character.charCodeAt(0) <= 0xdfff ? '\ufffd' : character).join(''));
        launch.href = action === 'whatsapp' ? 'https://wa.me/?text=' + encoded : 'viber://forward?text=' + encoded;
        launch.target = '_blank'; launch.rel = 'noopener noreferrer';
        launch.textContent = t('Open {provider}', { provider: name });
        const help = document.createElement('p'); help.className = 'aiesk-muted aiesk-messenger-help';
        help.textContent = t('Choose a recipient in {provider} and send the message there. Nothing is sent automatically. If the app does not open, copy the link or use the QR code.', { provider: name });
        if (action === 'viber' && url.length > 200) {
          launch.hidden = true;
          help.textContent = t('This address is too long for Viber. Create a new link with Use short URL enabled, or copy the full link below.');
        }
        const delivery = document.createElement('div'); delivery.className = 'aiesk-messenger-delivery'; delivery.append(launch, help);
        result.prepend(delivery); $('.aiesk-show-qr').hidden = false;
      }
      if (action === 'qr') renderQR();
      (messenger && !$('.aiesk-messenger-open').hidden ? $('.aiesk-messenger-open') : $('.aiesk-copy')).focus();
    }
    form.addEventListener('submit', async event => {
      event.preventDefault(); if (pending || preparing || sessionExpired || !selected || completed || !form.reportValidity()) return;
      if (cloud && (!cloudConnected || cloudChecking || cloudUploads.has(action))) return;
      if (linking && (!Number.isInteger(hours) || hours < 1 || hours > maxHours)) return;
      if (cloud) { uploadJob = { provider: action, notice: null }; cloudUploads.set(action, uploadJob); }
      error(''); setPending(true, cloud ? 'Saving your file…' : action === 'email' ? 'Sending email…' : linking ? 'Creating your private link…' : 'Preparing your download…');
      const payload = { title: titleInput.value.trim() || selected.title, html: selected.html };
      if (selected.reportLang) payload.report_lang = selected.reportLang;
      if (linking) { payload.amount = hours; payload.unit = 'hours'; payload.short_url = $('#aiesk-short-url').checked; }
      if (action === 'email') payload.to = $('#aiesk-recipient').value;
      if (cloud) payload.format = $('#aiesk-cloud-format').value;
      if ((action === 'docx' || (cloud && payload.format === 'docx')) && selected.easyForms) payload.easy_forms = selected.easyForms;
      try {
        // Creation is explicit: a previously cached URL may have been revoked
        // in another tab, account dashboard, or browser. Always ask the server.
        const data = await request(linking ? 'link' : cloud ? action + '/save' : action, payload);
        if (cloud && (!data || data.success !== true || data.provider !== action || typeof data.file_name !== 'string' || !data.file_name)) { const uncertain = new Error(); uncertain.outcomeUnknown = true; throw uncertain; }
        if (closing) {
          if (cloud) transferNotice(uploadJob, t('Saved to {provider}: {file}', { provider: cloudNames[action], file: data.file_name }), true);
          else if (linking && data.reused !== true) window.dispatchEvent(new CustomEvent('aiesk:links-changed', { detail: { id: data.post_id, action: 'created' } }));
          return;
        }
        if (linking) {
          data.url = safeURL(data.url);
          setPending(false); showResult(data);
          if (data.reused !== true) window.dispatchEvent(new CustomEvent('aiesk:links-changed', { detail: { id: data.post_id, action: 'created' } }));
        } else if (cloud) {
          completed = true; setPending(false);
          $('.aiesk-compose').hidden = true; $('.aiesk-dialog-footer').hidden = true;
          $('#aiesk-dialog-title').textContent = t('Saved to {provider}', { provider: cloudNames[action] });
          $('#aiesk-dialog-description').textContent = t('Your selected response has been saved as a separate file.');
          const result = $('.aiesk-result'); result.hidden = false;
          const success = document.createElement('div'); success.className = 'aiesk-success-line'; success.innerHTML = icon('check');
          const filename = document.createElement('span'); filename.textContent = data.file_name; success.append(filename); result.append(success);
          if (data.url) {
            try {
              const open = document.createElement('a'); open.className = 'aiesk-primary aiesk-cloud-open'; open.href = cloudURL(data.url, action, false); open.target = '_blank'; open.rel = 'noopener noreferrer'; open.textContent = t('Open saved file'); result.append(open);
            } catch (_) { /* Saved file is confirmed; do not make an unsafe link clickable. */ }
          }
          const note = document.createElement('p'); note.className = 'aiesk-muted aiesk-help'; note.textContent = t('The file remains in your cloud account until you delete it there. No private share link was created.'); result.append(note);
          result.append($('.aiesk-cloud-connection')); $('.aiesk-cloud-status').textContent = t('Connected to {provider}', { provider: cloudNames[action] });
          updateCloudControls(); ($('.aiesk-cloud-open') || close).focus();
        } else if (action === 'email') { setPending(false); dismiss('Email sent'); }
        else {
          const url = safeURL(data.url); completed = true; setPending(false);
          $('.aiesk-compose').hidden = true; $('.aiesk-dialog-footer').hidden = true;
          const result = $('.aiesk-result'); result.hidden = false;
          result.innerHTML = '<div class="aiesk-success-line">' + icon('check') + '<span>Your download is ready</span></div><a class="aiesk-primary aiesk-download">' + escape(t({ pdf: 'Download PDF', docx: 'Download Word document' }[action])) + '</a><p class="aiesk-muted aiesk-help">This download link can be used once.</p>';
          translateMarkup(result);
          $('.aiesk-download').href = url; $('.aiesk-download').setAttribute('download', ''); $('.aiesk-download').focus();
        }
      } catch (err) {
        if (closing && cloud) transferNotice(uploadJob, err.outcomeUnknown ? t('The provider did not confirm the result. Check your cloud folder before trying again; a file may already have been saved.') : t('The file was not confirmed saved to {provider}. Open Share to check the connection and try again.', { provider: cloudNames[action] }), true);
        else if (!closing) { setPending(false); showRequestError(err); }
      } finally {
        if (cloud) { cloudUploads.delete(action); uploadJob = null; window.dispatchEvent(new Event('aiesk:cloud-upload-state')); }
      }
    });
    if (linking) {
      const days = Number.isInteger(Number(config.retentionDays)) ? Number(config.retentionDays) : 30;
      const disclosure = document.createElement('p'); disclosure.className = 'aiesk-muted aiesk-storage-note';
      disclosure.textContent = days === 0
        ? t('Sharing stores a copy of this response on this website until you delete it. Expiry and revocation stop access but do not delete the copy.')
        : t('Sharing stores a copy of this response on this website. The copy is scheduled for deletion {days} days after expiry or revocation. You can delete it sooner in My shared links.', { days });
      $('.aiesk-privacy').after(disclosure);
    }
    dialog.showModal(); close.focus(); chooseResponse();
    if (cloud) checkCloudConnection();
    if (sessionExpired) showRequestError({ sessionExpired: true, message: t('Your session has expired. Reload this page or sign in again before sharing.') });
  }

  function boot() {
    template = document.getElementById('aiesk-bar-template'); ui = document.getElementById('aiesk-ui-root');
    if (!template || !ui || !template.content.firstElementChild || config.canShare === false) return;
    ui.dataset.theme = settings.theme || 'light';
    // Some themes invoke wp_footer inside their transformed content wrapper.
    if (ui.parentElement !== document.body) document.body.append(ui);
    window.AIESK_ReportShare = Object.freeze({ apiVersion: 1, mount: mountSavedReport });
    discover(document.body);
    document.addEventListener('click', event => { if (!event.target.closest('[data-aiesk]')) closeMenus(); });
    let positionFrame;
    function positionMenus(event) {
      if (event.target instanceof Element && event.target.closest('.aiesk-menu')) return;
      if (positionFrame) return;
      positionFrame = requestAnimationFrame(() => {
        positionFrame = null;
        controllers.forEach(controller => {
          controller.responses.forEach(response => { if (response.trigger && response.trigger.getAttribute('aria-expanded') === 'true') response.menu(true); });
        });
      });
    }
    window.addEventListener('resize', positionMenus, { passive: true });
    window.addEventListener('scroll', positionMenus, { passive: true, capture: true });
    // Discover newly mounted conversations; existing conversation mutations are
    // handled locally. No REST boot request, polling, or full-page stream scan.
    const discovery = new MutationObserver(records => {
      const added = new Set();
      records.forEach(record => {
        const target = record.target;
        if (target.nodeType === 1) {
          if (target.closest('#aiesk-ui-root, [data-aiesk]')) return;
          const root = target.closest('.mwaief-form, .mwai-chat');
          if (root && [...controllers.values()].some(controller => controller.root === root)) return;
        }
        record.addedNodes.forEach(node => { if (node.nodeType === 1 && !node.matches('[data-aiesk]')) added.add(node); });
      });
      controllers.forEach(controller => { if (!controller.root.isConnected) controller.destroy(); });
      added.forEach(discover);
    });
    discovery.observe(document.body, { childList: true, subtree: true });
    window.dispatchEvent(new Event('aiesk:report-share-ready'));
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
  else boot();
})();
