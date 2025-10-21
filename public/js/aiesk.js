/**
 * AI Share Kit — frontend (auto-inject after last Meow AI reply)
 * Works with:
 *  1) Mountpoint + REST (/render-bar)  OR
 *  2) Shortcode-rendered [data-aiesk]
 */
(function ($) {
  'use strict';

  const SELECTOR_REPLY = '.mwai-reply.mwai-ai.mwai-rendered';

  function lastReply() {
    const nodes = document.querySelectorAll(SELECTOR_REPLY);
    return nodes.length ? nodes[nodes.length - 1] : null;
  }

  async function fetchRenderBar() {
    try {
      const res = await fetch(aieskVars.restUrl + 'render-bar', {
        method: 'GET',
        headers: { 'X-WP-Nonce': aieskVars.nonce }
      });
      if (!res.ok) return null;
      const data = await res.json();
      return data && data.html ? data.html : null;
    } catch (e) {
      return null;
    }
  }

  function placeShortcodeBar() {
    // Move any existing [data-aiesk] (from shortcode) after last reply
    const bar = document.querySelector('[data-aiesk]');
    const reply = lastReply();
    if (bar && reply && !bar.dataset.aieskAttached) {
      reply.insertAdjacentElement('afterend', bar);
      bar.dataset.aieskAttached = '1';
      initBar(bar);
    }
  }

  async function placeMountpointBar() {
    // If there is a mountpoint #aiesk-portal-root, use REST to render and inject
    const mount = document.getElementById('aiesk-portal-root');
    if (!mount || mount.dataset.aieskMounted === '1') return;
    const html = await fetchRenderBar();
    if (!html) return;
    mount.innerHTML = html;
    const reply = lastReply();
    if (!reply) return;
    reply.insertAdjacentElement('afterend', mount);
    mount.style.display = '';
    mount.dataset.aieskMounted = '1';
    initBar(mount.querySelector('[data-aiesk]') || mount);
  }

  function initBar(container) {
    if (!container) return;

    // Dropdown trigger (if present)
    const trigger = container.querySelector('.aiesk-trigger');
    const menu = container.querySelector('.aiesk-menu');
    if (trigger && menu) {
      trigger.addEventListener('click', function (e) {
        e.preventDefault();
        menu.classList.toggle('open');
      });
      document.addEventListener('click', function (e) {
        if (!container.contains(e.target)) menu.classList.remove('open');
      });
    }

    // Wire buttons
    container.querySelectorAll('.aiesk-btn').forEach(btn => {
      btn.addEventListener('click', async function (e) {
        e.preventDefault();
        const action = btn.dataset.action;
        try {
          const html = captureReplyHtml();
          switch (action) {
            case 'export-pdf':
              await exportFile('export-pdf', html);
              break;
            case 'export-docx':
              await exportFile('export-docx', html);
              break;
            case 'save-drive':
              await shareProvider('save-drive', html);
              break;
            case 'save-dropbox':
              await shareProvider('save-dropbox', html);
              break;
            case 'save-onedrive':
              await shareProvider('save-onedrive', html);
              break;
            default:
              console.warn('Unknown action:', action);
          }
        } catch (err) {
          alert('Error: ' + (err && err.message ? err.message : err));
        }
      });
    });
  }

  function captureReplyHtml() {
    const reply = lastReply();
    return reply ? reply.innerHTML : document.body.innerHTML;
  }

  async function exportFile(endpoint, html) {
    const res = await fetch(aieskVars.restUrl + endpoint, {
      method: 'POST',
      headers: {
        'X-WP-Nonce': aieskVars.nonce,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ html: html || '' })
    });
    if (!res.ok) {
      const text = await res.text();
      throw new Error(text || 'Export failed');
    }
    const data = await res.json();
    if (data.url) window.open(data.url, '_blank');
  }

  async function shareProvider(endpoint, html) {
    const body = {
      title: (document.querySelector('.mwai-discussion-title')?.textContent || document.title || '').trim(),
      html: html || '',
      path: '',
      format: 'pdf'
    };
    const res = await fetch(aieskVars.restUrl + endpoint, {
      method: 'POST',
      headers: {
        'X-WP-Nonce': aieskVars.nonce,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify(body)
    });

    if (res.status === 401) {
      const go = confirm('Please log in to use sharing. Go to login page now?');
      if (go) window.location.href = aieskVars.loginUrl;
      throw new Error('Login required');
    }

    if (!res.ok) {
      const text = await res.text();
      throw new Error(text || 'Sharing failed');
    }

    const data = await res.json();
    if (data.success) {
      alert('Shared successfully!');
    } else {
      alert('Sharing may have failed.');
    }
  }

  // Boot
  function boot() {
    // Prefer mountpoint + REST render if present
    if (document.getElementById('aiesk-portal-root')) {
      placeMountpointBar();
    } else {
      placeShortcodeBar();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})(jQuery);
