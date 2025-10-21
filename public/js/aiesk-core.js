/**
 * AI Share Kit — Core JS
 * - Injects share bar after the LAST .mwai-reply.mwai-ai.mwai-rendered
 * - Wires actions: eml, link (TTL), qr, pdf, docx, drive/dropbox/onedrive
 */
(function () {
  'use strict';

  const SELECTOR_REPLY = '.mwai-reply.mwai-ai.mwai-rendered';

  function lastReply() {
    const nodes = document.querySelectorAll(SELECTOR_REPLY);
    return nodes.length ? nodes[nodes.length - 1] : null;
  }

  async function renderBarHtml() {
    const res = await fetch(aieskCore.rest + 'render-bar', {
      method: 'GET',
      headers: { 'X-WP-Nonce': aieskCore.nonce }
    });
    if (!res.ok) return '';
    const j = await res.json();
    return j.html || '';
  }

  function getTitle() {
    const t = document.querySelector('.mwai-discussion-title');
    return t ? (t.textContent || '').trim() : (document.title || '').trim();
  }

  function getReplyHtml() {
    const reply = lastReply();
    return reply ? reply.innerHTML : '';
  }

  async function mount() {
    // Only for logged-in users (server enforces; if not logged-in render-bar will 401)
    const portal = document.getElementById('aiesk-portal-root');
    if (!portal || portal.dataset.mounted === '1') return;

    try {
      const html = await renderBarHtml();
      if (!html) return;

      portal.innerHTML = html;
      const reply = lastReply();
      if (!reply) return;

      reply.insertAdjacentElement('afterend', portal);
      portal.dataset.mounted = '1';

      // Dropdown behavior
      const trigger = portal.querySelector('.aiesk-trigger');
      const menu = portal.querySelector('.aiesk-menu');
      if (trigger && menu) {
        trigger.addEventListener('click', (e) => {
          e.preventDefault();
          menu.classList.toggle('open');
        });
        document.addEventListener('click', (e) => {
          if (!portal.contains(e.target)) menu.classList.remove('open');
        });
      }

      // Wire buttons
      portal.querySelectorAll('.aiesk-btn').forEach((btn) => {
        btn.addEventListener('click', () => handle(btn.dataset.action));
      });
    } catch (e) {
      // Silently fail; controls simply won't render
      console.warn('[AIESK] mount failed:', e);
    }
  }

  async function handle(action) {
    const html = getReplyHtml();
    const title = getTitle();

    try {
      switch (action) {
        case 'eml': {
          const r = await fetch(aieskCore.rest + 'eml', {
            method: 'POST',
            headers: {
              'X-WP-Nonce': aieskCore.nonce,
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({ title, html })
          });
          if (!r.ok) return handleHttpError(r, 'EML failed');
          const j = await r.json();
          if (j.url) window.open(j.url, '_blank');
          break;
        }

        case 'link': {
          // Ask optional TTL; empty → defaults used server-side
          const amountStr = prompt('TTL amount (leave blank for default):', '');
          const unitStr = prompt('TTL unit (days/weeks/months, blank for default):', '');
          const amount = amountStr ? parseInt(amountStr, 10) : undefined;
          const unit = unitStr || undefined;

          const r = await fetch(aieskCore.rest + 'link', {
            method: 'POST',
            headers: {
              'X-WP-Nonce': aieskCore.nonce,
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({ title, html, amount, unit })
          });
          if (!r.ok) return handleHttpError(r, 'Link failed');
          const j = await r.json();
          if (j.url) {
            // Show & copy
            prompt('Copy link for guests:', j.url);
          }
          break;
        }

        case 'qr': {
          // Create or reuse a TTL link first (preferred: ask server to mint one on the fly)
          const rLink = await fetch(aieskCore.rest + 'link', {
            method: 'POST',
            headers: {
              'X-WP-Nonce': aieskCore.nonce,
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({ title, html })
          });
          if (!rLink.ok) return handleHttpError(rLink, 'Link creation failed');
          const link = await rLink.json();
          const r = await fetch(aieskCore.rest + 'qr', {
            method: 'POST',
            headers: {
              'X-WP-Nonce': aieskCore.nonce,
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({ url: link.url })
          });
          if (!r.ok) return handleHttpError(r, 'QR failed');
          const j = await r.json();
          if (j.url) window.open(j.url, '_blank');
          break;
        }

        case 'pdf': {
          const r = await fetch(aieskCore.rest + 'pdf', {
            method: 'POST',
            headers: {
              'X-WP-Nonce': aieskCore.nonce,
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({ title, html })
          });
          if (!r.ok) return handleHttpError(r, 'PDF failed');
          const j = await r.json();
          if (j.url) window.open(j.url, '_blank');
          break;
        }

        case 'docx': {
          const r = await fetch(aieskCore.rest + 'docx', {
            method: 'POST',
            headers: {
              'X-WP-Nonce': aieskCore.nonce,
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({ title, html })
          });
          if (!r.ok) return handleHttpError(r, 'DOCX failed');
          const j = await r.json();
          if (j.url) window.open(j.url, '_blank');
          break;
        }

        case 'drive': {
          const path = prompt('Google Drive folder path (optional):', '') || '';
          const name = prompt('File name (without extension):', getSafeName(title)) || getSafeName(title);
          const r = await fetch(aieskCore.rest + 'drive/save', { // NOTE: adjust if your Drive route differs
            method: 'POST',
            headers: {
              'X-WP-Nonce': aieskCore.nonce,
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({ title: name, html, path, format: 'pdf' })
          });
          if (r.status === 401) {
            // prompt OAuth start
            const o = await fetch(aieskCore.rest + 'drive/oauth/start', { headers: { 'X-WP-Nonce': aieskCore.nonce } });
            const j = o.ok ? await o.json() : {};
            if (j.auth_url) window.location.href = j.auth_url;
            else alert('Drive auth not configured yet.');
            return;
          }
          if (!r.ok) return handleHttpError(r, 'Drive save failed');
          alert('Drive save: completed or queued.');
          break;
        }

        case 'dropbox': {
          const path = prompt('Dropbox folder path (optional):', '') || '';
          const name = prompt('File name (without extension):', getSafeName(title)) || getSafeName(title);
          const r = await fetch(aieskCore.rest + 'dropbox/save', {
            method: 'POST',
            headers: {
              'X-WP-Nonce': aieskCore.nonce,
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({ title: name, html, path, format: 'pdf' })
          });
          if (r.status === 401) {
            const o = await fetch(aieskCore.rest + 'dropbox/oauth/start', { headers: { 'X-WP-Nonce': aieskCore.nonce } });
            const j = o.ok ? await o.json() : {};
            if (j.auth_url) window.location.href = j.auth_url;
            else alert('Dropbox auth not configured yet.');
            return;
          }
          if (!r.ok) return handleHttpError(r, 'Dropbox save failed');
          alert('Dropbox save: completed or queued.');
          break;
        }

        case 'onedrive': {
          const path = prompt('OneDrive folder path (optional):', '') || '';
          const name = prompt('File name (without extension):', getSafeName(title)) || getSafeName(title);
          const r = await fetch(aieskCore.rest + 'onedrive/save', {
            method: 'POST',
            headers: {
              'X-WP-Nonce': aieskCore.nonce,
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({ title: name, html, path, format: 'pdf' })
          });
          if (r.status === 401) {
            const o = await fetch(aieskCore.rest + 'onedrive/oauth/start', { headers: { 'X-WP-Nonce': aieskCore.nonce } });
            const j = o.ok ? await o.json() : {};
            if (j.auth_url) window.location.href = j.auth_url;
            else alert('OneDrive auth not configured yet.');
            return;
          }
          if (!r.ok) return handleHttpError(r, 'OneDrive save failed');
          alert('OneDrive save: completed or queued.');
          break;
        }

        default:
          console.warn('[AIESK] Unknown action', action);
      }
    } catch (err) {
      alert('Error: ' + (err && err.message ? err.message : err));
    }
  }

  function getSafeName(title) {
    return (title || 'shared-message').replace(/[\\\/:*?"<>|]+/g, '').trim().slice(0, 120) || 'shared-message';
  }

  async function handleHttpError(res, fallbackMsg) {
    if (res.status === 401) {
      alert('Please log in to use sharing.');
      return;
    }
    const text = await res.text();
    alert(text || fallbackMsg || 'Request failed');
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
})();
