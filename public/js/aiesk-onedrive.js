/**
 * AI Share Kit — OneDrive helper
 * Handles [data-action="onedrive"] for OneDrive OAuth + save.
 */
(function () {
  'use strict';

  const SELECTOR_REPLY = '.mwai-reply.mwai-ai.mwai-rendered';

  function lastReply() {
    const nodes = document.querySelectorAll(SELECTOR_REPLY);
    return nodes.length ? nodes[nodes.length - 1] : null;
  }

  function getReplyHtml() {
    const reply = lastReply();
    return reply ? reply.innerHTML : '';
  }

  function getTitle() {
    const t = document.querySelector('.mwai-discussion-title');
    return t ? (t.textContent || '').trim() : (document.title || '').trim();
  }

  async function saveToOneDrive(title, html) {
    const path = prompt('OneDrive folder path (optional):', '') || '';
    const name = prompt('File name (without extension):', title || 'shared-message') || 'shared-message';
    const format = prompt('Choose format: pdf or docx', 'pdf') || 'pdf';

    const res = await fetch(aieskCore.rest + 'onedrive/save', {
      method: 'POST',
      headers: {
        'X-WP-Nonce': aieskCore.nonce,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ title: name, html, path, format })
    });

    if (res.status === 401) {
      // Not authorized -> request OAuth URL
      const oauth = await fetch(aieskCore.rest + 'onedrive/oauth/start', {
        headers: { 'X-WP-Nonce': aieskCore.nonce }
      });
      const j = oauth.ok ? await oauth.json() : {};
      if (j.auth_url) {
        alert('You must authorize OneDrive first.');
        window.location.href = j.auth_url;
      } else {
        alert('OneDrive authorization URL not configured.');
      }
      return;
    }

    if (!res.ok) {
      const text = await res.text();
      alert(text || 'OneDrive save failed.');
      return;
    }

    alert('File sent to OneDrive (or queued).');
  }

  function onClick(e) {
    const btn = e.target.closest('[data-action="onedrive"]');
    if (!btn) return;
    e.preventDefault();

    const html = getReplyHtml();
    if (!html) {
      alert('No AI message found.');
      return;
    }

    const title = getTitle();
    saveToOneDrive(title, html);
  }

  function mount() {
    const portal = document.getElementById('aiesk-portal-root') || document.body;
    portal.addEventListener('click', onClick);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
})();
