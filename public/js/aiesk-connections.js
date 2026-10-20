/** Private connection management. No report data, background uploads or polling. */
(function () {
  'use strict';
  const config = window.aieskConnections || {};
  const t = (key) => window.aieskI18n ? window.aieskI18n.t(key) : key;
  const providers = {
    drive: ['https://accounts.google.com', '/o/oauth2/v2/auth'],
    onedrive: ['https://login.microsoftonline.com', '/common/oauth2/v2.0/authorize'],
    dropbox: ['https://www.dropbox.com', '/oauth2/authorize']
  };
  const labels = { drive: 'Google Drive', onedrive: 'OneDrive', dropbox: 'Dropbox' };
  const serviceLabel = (key, provider) => t(key).replace('%s', labels[provider]);
  async function request(provider, path, method = 'GET') {
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 30000);
    try {
      const response = await fetch(config.rest + provider + '/' + path, {
        method, credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
        headers: { 'X-WP-Nonce': config.nonce, Accept: 'application/json' }
      });
      const data = await response.json();
      if (!response.ok) {
        const sessionExpired = response.status === 401 || data.code === 'rest_cookie_invalid_nonce';
        const error = new Error(sessionExpired ? t('Your sign-in session has expired. Reload this page and sign in again.') : data.message || t('The connection could not be updated. Try again.'));
        error.sessionExpired = sessionExpired;
        throw error;
      }
      return data;
    } catch (error) {
      if (error.name === 'AbortError') { throw new Error(t('The connection check timed out. Try again.')); }
      throw error;
    } finally { clearTimeout(timer); }
  }
  function mount(root) {
    if (root.dataset.connectionsMounted) { return; }
    root.dataset.connectionsMounted = 'true';
    const cards = new Map();
    const refreshButton = root.querySelector('[data-connections-refresh]');
    const summary = root.querySelector('[data-connections-summary]');
    let refreshingAll = false;
    root.querySelectorAll('[data-connection]').forEach((card) => {
      const provider = card.dataset.connection;
      if (!providers[provider]) { return; }
      const state = { card, provider, status: null, busy: false, popup: null };
      cards.set(provider, state);
      card.addEventListener('click', (event) => {
        const button = event.target.closest('[data-action]');
        if (!button || state.busy) { return; }
        if (button.dataset.action === 'connect') { connect(state); }
        if (button.dataset.action === 'disconnect') { disconnect(state); }
        if (button.dataset.action === 'refresh') { refresh(state); }
        if (button.dataset.action === 'reload') { window.location.reload(); }
      });
    });
    refreshButton.addEventListener('click', () => refreshAll(true));
    refreshAll(false);
    window.addEventListener('message', (event) => {
      const data = event.data;
      if (!data || data.type !== 'aiesk-cloud-connected' || event.origin !== config.cloudCallbackOrigin) { return; }
      const state = cards.get(data.provider);
      if (!state || !state.popup || event.source !== state.popup) { return; }
      state.popup = null;
      refresh(state, data.success ? t('Account connected. No report has been uploaded.') : t('Connection was not completed. You can connect again.'));
    });

    function feedback(state, message, error = false) {
      const element = state.card.querySelector('[data-connection-feedback]');
      element.textContent = message;
      element.dataset.error = error ? 'true' : 'false';
    }
    function busy(state, value) {
      if (value) { state.focusAction = state.card.contains(document.activeElement) ? document.activeElement.dataset.action || '' : ''; }
      state.busy = value;
      state.card.setAttribute('aria-busy', String(value));
      state.card.querySelectorAll('button').forEach((button) => { button.disabled = value; });
      refreshButton.disabled = refreshingAll || Array.from(cards.values()).some((item) => item.busy);
      if (!value && state.restoreFocus && !state.restoreFocus.disabled) {
        state.restoreFocus.focus({ preventScroll: true });
        state.restoreFocus = null;
      }
    }
    function action(state, key, label) {
      const button = document.createElement('button');
      button.type = 'button'; button.dataset.action = key; button.textContent = label;
      state.card.querySelector('[data-connection-actions]').append(button);
      return button;
    }
    function setupAction(state, label) {
      const link = document.createElement('a');
      link.href = config.settingsUrl + '#aiesk-cloud-' + state.provider;
      link.className = 'aiesk-connection-setup';
      link.textContent = serviceLabel(label, state.provider);
      state.card.querySelector('[data-connection-actions]').append(link);
    }
    function render(state) {
      const data = state.status;
      const actions = state.card.querySelector('[data-connection-actions]');
      const focusedAction = actions.contains(document.activeElement) ? document.activeElement.dataset.action : state.focusAction || '';
      state.focusAction = '';
      actions.replaceChildren();
      if (!data) { return; }
      const active = data.configured && data.enabled && data.connected;
      state.card.dataset.connected = String(!!active);
      state.card.querySelector('[data-connection-status]').textContent = t(!data.configured ? 'Administrator setup required' : !data.enabled ? 'Disabled by administrator' : data.connected ? 'Connected' : data.stored_connection ? 'Reconnect required' : 'Not connected');
      if (!data.configured || !data.enabled) {
        if (config.settingsUrl) {
          setupAction(state, data.configured ? 'Enable %s' : 'Set up %s');
        } else {
          const help = document.createElement('p');
          help.className = 'aiesk-connection-help';
          help.textContent = t(data.configured ? 'Ask your site administrator to enable this service.' : 'Your site administrator needs to set up this service before you can connect.');
          actions.append(help);
        }
      } else if (config.canConnect) {
        action(state, 'connect', data.connected ? t('Switch account') : serviceLabel(data.stored_connection ? 'Reconnect %s' : 'Connect %s', state.provider));
      } else {
        const help = document.createElement('p');
        help.className = 'aiesk-connection-help';
        help.textContent = t('Ask your site administrator for permission to connect cloud storage.');
        actions.append(help);
      }
      if (data.stored_connection) { action(state, 'disconnect', t('Disconnect')); }
      if (state.popup) { action(state, 'refresh', t('Check connection')); }
      if (focusedAction) {
        const next = actions.querySelector('[data-action=' + focusedAction + ']') || actions.querySelector('button,a') || refreshButton;
        state.restoreFocus = next;
      }
    }
    async function refreshAll(manual) {
      if (refreshingAll || Array.from(cards.values()).some((state) => state.busy)) { return; }
      refreshingAll = true;
      refreshButton.disabled = true;
      refreshButton.textContent = t('Checking…');
      if (summary) {
        summary.textContent = manual ? t('Checking your connections…') : '';
        summary.dataset.error = 'false';
      }
      const results = await Promise.all(Array.from(cards.values(), (state) => refresh(state, '', true)));
      refreshingAll = false;
      refreshButton.disabled = false;
      refreshButton.textContent = t('Refresh status');
      if (summary && manual) {
        const failed = results.some((result) => !result);
        summary.textContent = t(failed ? 'Some connections could not be checked. Use Try again beside the affected service.' : 'Connections checked. Use the action shown for each service.');
        summary.dataset.error = String(failed);
      }
    }
    async function refresh(state, message = '', group = false) {
      if (state.busy) { return false; }
      busy(state, true);
      if (!group) { feedback(state, t('Checking connection…')); }
      try {
        state.status = await request(state.provider, 'status');
        render(state);
        feedback(state, message || (group ? '' : t('Connection checked.')));
        return true;
      } catch (error) { showError(state, error); return false; }
      finally { busy(state, false); }
    }
    function showError(state, error) {
      if (!state.status) { state.card.querySelector('[data-connection-status]').textContent = t('Connection status unavailable'); }
      if (!state.card.querySelector('[data-action=refresh]')) { action(state, 'refresh', t('Try again')); }
      feedback(state, error.message || t('The connection could not be updated. Try again.'), true);
      if (error.sessionExpired && !state.card.querySelector('[data-action=reload]')) { action(state, 'reload', t('Reload page')); }
    }
    async function connect(state) {
      if (state.popup && !state.popup.closed) { state.popup.focus(); feedback(state, t('Complete sign-in in the account window. This page updates automatically.')); return; }
      const popup = window.open('about:blank', '_blank', 'width=600,height=740');
      if (!popup) { feedback(state, t('Allow pop-up windows for this site, then connect again.'), true); return; }
      state.popup = popup;
      busy(state, true);
      feedback(state, t('Opening the account connection window…'));
      try {
        const data = await request(state.provider, 'oauth/start', 'POST');
        const url = new URL(data.auth_url);
        const allowed = providers[state.provider];
        if (url.origin !== allowed[0] || url.pathname !== allowed[1] || url.username || url.password) { throw new Error(t('The cloud service returned an invalid connection address.')); }
        if (popup.closed) { throw new Error(t('The account window was closed. Connect again when ready.')); }
        popup.location.href = url.href;
        render(state);
        feedback(state, t('Complete sign-in in the account window. This page updates automatically.'));
      } catch (error) { popup.close(); state.popup = null; showError(state, error); }
      finally { busy(state, false); }
    }
    async function disconnect(state) {
      busy(state, true);
      feedback(state, t('Disconnecting account…'));
      try {
        const data = await request(state.provider, 'connection', 'DELETE');
        if (!data.disconnected) { throw new Error(t('The connection could not be updated. Try again.')); }
        if (state.popup && !state.popup.closed) { state.popup.close(); }
        state.popup = null;
        state.status = Object.assign({}, state.status, { stored_connection: false, connected: false });
        render(state);
        feedback(state, t('Disconnected. Files already saved in your cloud account are unchanged.'));
      } catch (error) { showError(state, error); }
      finally { busy(state, false); }
    }
  }
  function boot() { document.querySelectorAll('[data-aiesk-connections]').forEach(mount); }
  if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', boot); } else { boot(); }
})();
