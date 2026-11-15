// SnapCaption AI — Popup Script

const API_BASE = 'https://snapcaptionai.com/api';

// Platform detection map
const PLATFORM_MAP = {
  'instagram.com': { label: 'You\'re on Instagram', emoji: '📸', value: 'Instagram' },
  'tiktok.com':    { label: 'You\'re on TikTok',    emoji: '🎵', value: 'TikTok' },
  'youtube.com':   { label: 'You\'re on YouTube',   emoji: '▶️', value: 'YouTube' },
  'facebook.com':  { label: 'You\'re on Facebook',  emoji: '👥', value: 'Facebook' },
  'linkedin.com':  { label: 'You\'re on LinkedIn',  emoji: '💼', value: 'LinkedIn' },
  'twitter.com':   { label: 'You\'re on X (Twitter)', emoji: '🐦', value: 'X (Twitter)' },
  'x.com':         { label: 'You\'re on X (Twitter)', emoji: '🐦', value: 'X (Twitter)' },
  'pinterest.com': { label: 'You\'re on Pinterest', emoji: '📌', value: 'Pinterest' },
};

let _currentUser  = null;
let _currentToken = null;
let _contextImageUrl = null;
let _canInject    = false;   // true when content script detected a caption field
let _lastCaptions = [];

// ── Init ────────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', async () => {
  await loadAuth();
});

async function loadAuth() {
  const { token, user } = await msg({ type: 'GET_USER' });
  if (token && user) {
    _currentToken = token;
    _currentUser  = user;
    showMain();
  } else {
    showView('authView');
  }
}

// ── Auth ────────────────────────────────────────────────────────────────────
async function doLogin() {
  const email = document.getElementById('authEmail').value.trim();
  const pass  = document.getElementById('authPass').value;
  const errEl = document.getElementById('authErr');
  const btn   = document.getElementById('authBtn');

  errEl.style.display = 'none';
  if (!email || !pass) { showErr(errEl, 'Please enter email and password'); return; }

  btn.disabled    = true;
  btn.textContent = 'Signing in…';

  try {
    const resp = await fetch(`${API_BASE}/auth-login.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password: pass }),
    });
    const data = await resp.json();
    if (!resp.ok) throw new Error(data.error || 'Login failed');

    await msg({ type: 'SAVE_AUTH', token: data.token, user: data.user });
    _currentToken = data.token;
    _currentUser  = data.user;
    showMain();
  } catch (e) {
    showErr(errEl, e.message);
    btn.disabled    = false;
    btn.textContent = 'Sign In';
  }
}

async function doSignOut() {
  await msg({ type: 'CLEAR_AUTH' });
  _currentToken = null;
  _currentUser  = null;
  _lastCaptions = [];
  document.getElementById('captionsList').innerHTML = '';
  document.getElementById('resultsWrap').style.display = 'none';
  showView('authView');
}

// ── Main view setup ─────────────────────────────────────────────────────────
async function showMain() {
  // Header
  const hdr   = document.getElementById('headerRight');
  const plan  = _currentUser.plan || 'free';
  hdr.innerHTML = `
    <span class="plan-badge ${plan}">${plan}</span>
    <button class="signout-btn" onclick="doSignOut()">Sign out</button>
  `;

  // Check Pro gate
  if (!isPro(plan)) {
    showView('mainView');
    document.getElementById('upgradePrompt').style.display = 'block';
    // Hide the form controls but show upgrade
    document.getElementById('platformRow').style.display   = 'none';
    document.getElementById('ctxImageWrap').style.display  = 'none';
    document.querySelectorAll('.form-row, .opts-row').forEach(el => el.style.display = 'none');
    document.getElementById('generateBtn').style.display   = 'none';
    document.getElementById('usageRow').style.display      = 'none';
    document.getElementById('resultsWrap').style.display   = 'none';
    return;
  }

  showView('mainView');

  // Detect current tab platform
  detectPlatform();

  // Check for context menu image
  const { contextImageUrl } = await new Promise(resolve =>
    chrome.storage.session.get('contextImageUrl', resolve)
  );
  if (contextImageUrl) {
    _contextImageUrl = contextImageUrl;
    const wrap = document.getElementById('ctxImageWrap');
    document.getElementById('ctxImagePreview').src = contextImageUrl;
    wrap.style.display = 'block';
    chrome.storage.session.remove('contextImageUrl');
  }

  // Check if content script can inject
  checkInjectCapability();

  // Load usage
  loadUsage();
}

function detectPlatform() {
  chrome.tabs.query({ active: true, currentWindow: true }, tabs => {
    if (!tabs[0]) return;
    const url = tabs[0].url || '';
    for (const [host, info] of Object.entries(PLATFORM_MAP)) {
      if (url.includes(host)) {
        document.getElementById('platformRow').style.display = 'flex';
        document.getElementById('platformBadge').textContent = info.emoji;
        document.getElementById('platformLabel').textContent = info.label;
        // Auto-select platform
        const sel = document.getElementById('platformSel');
        for (const opt of sel.options) {
          if (opt.value === info.value) { sel.value = info.value; break; }
        }
        break;
      }
    }
  });
}

function checkInjectCapability() {
  chrome.tabs.query({ active: true, currentWindow: true }, tabs => {
    if (!tabs[0]) return;
    chrome.tabs.sendMessage(tabs[0].id, { type: 'CAN_INJECT' }, resp => {
      if (chrome.runtime.lastError) return; // content script not present
      if (resp && resp.canInject) {
        _canInject = true;
        // Will be shown after results render
      }
    });
  });
}

function loadUsage() {
  if (!_currentToken) return;
  fetch(`${API_BASE}/check-usage.php`, {
    headers: { Authorization: `Bearer ${_currentToken}` },
  })
  .then(r => r.json())
  .then(data => {
    const row = document.getElementById('usageRow');
    if (data.unlimited) {
      document.getElementById('usageText').textContent = '✨ Unlimited captions';
      row.style.display = 'flex';
      return;
    }
    const left = data.limit - (data.used || 0);
    document.getElementById('usageText').textContent = `${left} of ${data.limit} captions remaining today`;
    if (left <= 2) {
      document.getElementById('upgradeLink').style.display = 'inline';
    }
    row.style.display = 'flex';
  })
  .catch(() => {});
}

// ── Generate ────────────────────────────────────────────────────────────────
async function doGenerate() {
  const topic    = document.getElementById('topicInput').value.trim();
  const platform = document.getElementById('platformSel').value;
  const tone     = document.getElementById('toneSel').value;
  const count    = parseInt(document.getElementById('countSel').value, 10);
  const language = document.getElementById('langSel').value;

  if (!topic) {
    document.getElementById('topicInput').focus();
    showToast('Enter what your post is about');
    return;
  }

  setLoading(true, 'Generating captions…');

  try {
    const payload = { topic, platform, tone, count, language };
    if (_contextImageUrl) payload.image_url = _contextImageUrl;

    const data = await msg({ type: 'GENERATE_CAPTION', payload });
    _lastCaptions = data.captions || [];
    renderCaptions(_lastCaptions);
    loadUsage();
  } catch (e) {
    if (e.upgrade) {
      setLoading(false);
      document.getElementById('upgradePrompt').style.display = 'block';
      document.getElementById('generateBtn').style.display = 'none';
      return;
    }
    setLoading(false);
    showToast('Error: ' + e.message);
  } finally {
    setLoading(false);
  }
}

function renderCaptions(captions) {
  const list = document.getElementById('captionsList');
  list.innerHTML = '';

  captions.forEach((cap, i) => {
    const text = typeof cap === 'string' ? cap : (cap.caption || cap.text || '');
    const card = document.createElement('div');
    card.className = 'caption-card';
    card.innerHTML = `
      <div class="caption-num">Caption ${i + 1}</div>
      <div class="caption-text">${escHtml(text)}</div>
      <div class="caption-actions">
        ${_canInject ? `<button class="inject-btn visible" onclick="injectCaption(${i})">📋 Insert</button>` : ''}
        <button class="copy-btn" onclick="copyCaption(${i})">Copy</button>
      </div>
    `;
    list.appendChild(card);
  });

  document.getElementById('resultsWrap').style.display = 'block';
  // Scroll into view
  setTimeout(() => list.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 50);
}

// ── Copy / Inject ────────────────────────────────────────────────────────────
async function copyCaption(idx) {
  const cap = _lastCaptions[idx];
  const text = typeof cap === 'string' ? cap : (cap.caption || cap.text || '');
  await navigator.clipboard.writeText(text);
  showToast('Copied!');
}

async function copyAll() {
  const texts = _lastCaptions.map((c, i) => {
    const t = typeof c === 'string' ? c : (c.caption || c.text || '');
    return `Caption ${i + 1}:\n${t}`;
  });
  await navigator.clipboard.writeText(texts.join('\n\n'));
  showToast('All captions copied!');
}

function injectCaption(idx) {
  const cap  = _lastCaptions[idx];
  const text = typeof cap === 'string' ? cap : (cap.caption || cap.text || '');
  chrome.tabs.query({ active: true, currentWindow: true }, tabs => {
    if (!tabs[0]) return;
    chrome.tabs.sendMessage(tabs[0].id, { type: 'INJECT_CAPTION', text }, resp => {
      if (resp && resp.ok) {
        showToast('Inserted into caption field!');
        window.close();
      } else {
        showToast('Could not find a caption field on this page');
      }
    });
  });
}

// ── Context image ────────────────────────────────────────────────────────────
function clearContextImage() {
  _contextImageUrl = null;
  document.getElementById('ctxImageWrap').style.display = 'none';
}

// ── Utilities ────────────────────────────────────────────────────────────────
function isPro(plan) {
  return plan === 'pro';
}

function showView(id) {
  ['authView', 'mainView', 'errorView'].forEach(v => {
    document.getElementById(v).style.display = v === id ? 'block' : 'none';
  });
}

function setLoading(on, label = '') {
  const ov = document.getElementById('loadingOverlay');
  ov.style.display = on ? 'flex' : 'none';
  if (label) document.getElementById('loadingLabel').textContent = label;
  document.getElementById('generateBtn').disabled = on;
}

function resetError() {
  showView('mainView');
}

function showErr(el, text) {
  el.textContent   = text;
  el.style.display = 'block';
}

let _toastTimer = null;
function showToast(text) {
  const existing = document.querySelector('.sc-toast');
  if (existing) existing.remove();
  if (_toastTimer) clearTimeout(_toastTimer);
  const t = document.createElement('div');
  t.className   = 'sc-toast';
  t.textContent = text;
  document.body.appendChild(t);
  _toastTimer = setTimeout(() => t.remove(), 2000);
}

function escHtml(s) {
  return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function msg(payload) {
  return new Promise((resolve, reject) => {
    chrome.runtime.sendMessage(payload, resp => {
      if (chrome.runtime.lastError) return reject(new Error(chrome.runtime.lastError.message));
      if (resp && resp.ok === false) {
        const e = new Error(resp.error || 'Unknown error');
        e.upgrade = resp.upgrade || false;
        return reject(e);
      }
      resolve(resp);
    });
  });
}

// Expose globals for inline onclick
window.doLogin           = doLogin;
window.doSignOut         = doSignOut;
window.doGenerate        = doGenerate;
window.copyCaption       = copyCaption;
window.copyAll           = copyAll;
window.injectCaption     = injectCaption;
window.clearContextImage = clearContextImage;
window.resetError        = resetError;
