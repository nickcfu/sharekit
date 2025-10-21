/**
 * AI Share Kit — QR helper
 * Handles [data-action="qr"]: generates a QR PNG for the shared TTL link.
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

  async function createQr(title, html) {
    // Step 1: Ensure link exists
    const rLink = await fetch(aieskCore.rest + 'link', {
      method: 'POST',
      headers: {
        'X-WP-Nonce': aieskCore.nonce,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ title, html })
    });

    if (rLink.status === 401) {
      alert('Please log in to create QR links.');
      return;
    }
    if (!rLink.ok) {
      const text = await rLink.text();
      alert(text || 'Link creation failed.');
      return;
    }

    const linkData = await rLink.json();
    const linkUrl = linkData.url;
    if (!linkUrl) {
      alert('No link returned.');
      return;
    }

    // Step 2: Request QR PNG
    const rQr = await fetch(aieskCore.rest + 'qr', {
      method: 'POST',
      headers: {
        'X-WP-Nonce': aieskCore.nonce,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ url: linkUrl })
    });

    if (!rQr.ok) {
      const text = await rQr.text();
      alert(text || 'QR generation failed.');
      return;
    }

    const qrData = await rQr.json();
    if (qrData.url) {
      window.open(qrData.url, '_blank');
    } else {
      alert('QR code could not be created.');
    }
  }

  function onClick(e) {
    const btn = e.target.closest('[data-action="qr"]');
    if (!btn) return;
    e.preventDefault();

    const html = getReplyHtml();
    if (!html) {
      alert('No AI message found on the page.');
      return;
    }

    const title = getTitle();
    createQr(title, html);
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
