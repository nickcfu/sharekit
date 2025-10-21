/**
 * AI Share Kit — Email helper
 * Handles [data-action="email"] by prompting for recipient and sending via REST.
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

  async function sendEmail(to, title, html) {
    const res = await fetch(aieskCore.rest + 'email', {
      method: 'POST',
      headers: {
        'X-WP-Nonce': aieskCore.nonce,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ to, title, html })
    });
    if (res.status === 401) {
      alert('Please log in to use sharing.');
      return;
    }
    if (!res.ok) {
      const text = await res.text();
      alert(text || 'Email failed');
      return;
    }
    alert('Email sent!');
  }

  function onClick(e) {
    const btn = e.target.closest('[data-action="email"]');
    if (!btn) return;
    e.preventDefault();

    const to = prompt('Recipient email address:', '');
    if (!to) return;

    const html = getReplyHtml();
    if (!html) {
      alert('No AI message found on the page.');
      return;
    }

    const title = getTitle();
    sendEmail(to, title, html);
  }

  function mount() {
    // Delegate from the portal root so dynamically injected buttons work
    const portal = document.getElementById('aiesk-portal-root') || document.body;
    portal.addEventListener('click', onClick);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mount);
  } else {
    mount();
  }
})();
