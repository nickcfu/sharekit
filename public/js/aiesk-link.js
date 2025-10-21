/**
 * AI Share Kit — TTL Link helper
 * Handles [data-action="link"] to create a TTL share link for guests.
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

  async function createLink(title, html) {
    const amountStr = prompt('How long should this link stay active? (Enter number):', '');
    const unitStr = prompt('Choose unit: days, weeks, or months (blank = default):', '');
    const amount = amountStr ? parseInt(amountStr, 10) : undefined;
    const unit = unitStr || undefined;

    const res = await fetch(aieskCore.rest + 'link', {
      method: 'POST',
      headers: {
        'X-WP-Nonce': aieskCore.nonce,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ title, html, amount, unit })
    });

    if (res.status === 401) {
      alert('Please log in to create a share link.');
      return;
    }

    if (!res.ok) {
      const text = await res.text();
      alert(text || 'Link creation failed.');
      return;
    }

    const data = await res.json();
    if (data.url) {
      copyToClipboard(data.url);
      alert('Link created and copied:\n' + data.url);
    } else {
      alert('No link returned.');
    }
  }

  function copyToClipboard(text) {
    if (navigator.clipboard) {
      navigator.clipboard.writeText(text).catch(() => {});
    } else {
      const el = document.createElement('textarea');
      el.value = text;
      document.body.appendChild(el);
      el.select();
      document.execCommand('copy');
      document.body.removeChild(el);
    }
  }

  function onClick(e) {
    const btn = e.target.closest('[data-action="link"]');
    if (!btn) return;
    e.preventDefault();

    const html = getReplyHtml();
    if (!html) {
      alert('No AI message found on the page.');
      return;
    }

    const title = getTitle();
    createLink(title, html);
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
