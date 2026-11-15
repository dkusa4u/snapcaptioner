// SnapCaption AI — Content Script
// Runs on Instagram, TikTok, YouTube, etc.
// Detects caption/bio text fields and injects generated captions

(function () {
  'use strict';

  // ── Caption field selectors per platform ──────────────────────────────────
  const CAPTION_SELECTORS = [
    // Instagram: new post caption, bio edit, comment box
    '[aria-label="Write a caption…"]',
    '[aria-label="Write a caption"]',
    '[aria-label="Caption"]',
    '[data-lexical-editor="true"]',          // Lexical editor (IG new post)
    'textarea[placeholder*="caption" i]',
    'textarea[placeholder*="bio" i]',
    // TikTok: caption input
    '.DraftEditor-contenteditable[contenteditable]',
    '[data-contents="true"][contenteditable]',
    'div.public-DraftEditor-content',
    'textarea[placeholder*="Describe" i]',
    // YouTube: community post, description
    '#contenteditable-root[contenteditable]',
    'ytd-backstage-post-renderer div[contenteditable]',
    // LinkedIn: post composer
    'div.ql-editor[contenteditable]',
    'div[data-placeholder="What do you want to talk about?" i]',
    'div[data-placeholder*="Share" i]',
    // Generic textarea with common caption hints
    'textarea[placeholder*="caption" i]',
    'textarea[name="caption"]',
    'textarea[id*="caption" i]',
    'textarea[class*="caption" i]',
  ];

  // ── Respond to messages from popup ───────────────────────────────────────
  chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
    if (msg.type === 'CAN_INJECT') {
      sendResponse({ canInject: !!findCaptionField() });
    }

    if (msg.type === 'INJECT_CAPTION') {
      const field = findCaptionField();
      if (!field) return sendResponse({ ok: false });

      try {
        injectText(field, msg.text);
        sendResponse({ ok: true });
      } catch (e) {
        sendResponse({ ok: false, error: e.message });
      }
    }
  });

  // ── Find the best caption input on the page ──────────────────────────────
  function findCaptionField() {
    for (const sel of CAPTION_SELECTORS) {
      try {
        const el = document.querySelector(sel);
        if (el && isVisible(el)) return el;
      } catch (_) {}
    }
    // Fallback: any visible contenteditable or textarea that looks like a post composer
    const composerCandidates = [
      ...document.querySelectorAll('div[contenteditable="true"]'),
      ...document.querySelectorAll('textarea'),
    ];
    for (const el of composerCandidates) {
      if (isVisible(el) && isComposerLike(el)) return el;
    }
    return null;
  }

  function isVisible(el) {
    const r = el.getBoundingClientRect();
    return r.width > 0 && r.height > 0 &&
      window.getComputedStyle(el).visibility !== 'hidden' &&
      window.getComputedStyle(el).display !== 'none';
  }

  function isComposerLike(el) {
    const ph = (el.getAttribute('placeholder') || '').toLowerCase();
    const lbl = (el.getAttribute('aria-label') || '').toLowerCase();
    const cls = (el.className || '').toLowerCase();
    const keywords = ['caption', 'post', 'write', 'share', 'what', 'describe', 'tell'];
    return keywords.some(k => ph.includes(k) || lbl.includes(k) || cls.includes(k));
  }

  // ── Inject text into the found field ─────────────────────────────────────
  function injectText(field, text) {
    field.focus();

    // contenteditable div
    if (field.getAttribute('contenteditable') === 'true') {
      // Try execCommand first (older Draftjs / Quill editors)
      if (document.execCommand) {
        field.innerHTML = '';
        document.execCommand('insertText', false, text);
        // Fire input event for React / Vue / Angular
        field.dispatchEvent(new InputEvent('input', { bubbles: true, inputType: 'insertText', data: text }));
      } else {
        field.textContent = text;
        field.dispatchEvent(new InputEvent('input', { bubbles: true }));
      }
    }
    // Standard textarea / input
    else {
      const nativeInputValueSetter = Object.getOwnPropertyDescriptor(
        window.HTMLTextAreaElement.prototype, 'value'
      )?.set ||
      Object.getOwnPropertyDescriptor(
        window.HTMLInputElement.prototype, 'value'
      )?.set;

      if (nativeInputValueSetter) {
        // React-compatible
        nativeInputValueSetter.call(field, text);
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
      } else {
        field.value = text;
        field.dispatchEvent(new Event('input', { bubbles: true }));
      }
    }

    // Scroll the field into view
    field.scrollIntoView({ behavior: 'smooth', block: 'center' });
  }

})();
