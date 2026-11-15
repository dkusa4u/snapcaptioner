// SnapCaption AI — Background Service Worker
// Handles context menu, auth state, and API proxying

const API_BASE = 'https://snapcaptionai.com/api';

// ── Context menu setup ──────────────────────────────────────────────────────
chrome.runtime.onInstalled.addListener(() => {
  chrome.contextMenus.create({
    id: 'snapcaption-generate',
    title: 'Generate caption with SnapCaption AI',
    contexts: ['image'],
  });
});

chrome.contextMenus.onClicked.addListener((info, tab) => {
  if (info.menuItemId === 'snapcaption-generate' && info.srcUrl) {
    // Store the image URL, then open popup
    chrome.storage.session.set({ contextImageUrl: info.srcUrl });
    chrome.action.openPopup();
  }
});

// ── Message router ──────────────────────────────────────────────────────────
chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
  if (msg.type === 'GENERATE_CAPTION') {
    handleGenerate(msg.payload).then(sendResponse).catch(err =>
      sendResponse({ ok: false, error: err.message })
    );
    return true; // keep channel open for async
  }

  if (msg.type === 'GET_USER') {
    chrome.storage.local.get(['sc_token', 'sc_user'], data => {
      sendResponse({ token: data.sc_token || null, user: data.sc_user || null });
    });
    return true;
  }

  if (msg.type === 'SAVE_AUTH') {
    chrome.storage.local.set({ sc_token: msg.token, sc_user: msg.user }, () => {
      sendResponse({ ok: true });
    });
    return true;
  }

  if (msg.type === 'CLEAR_AUTH') {
    chrome.storage.local.remove(['sc_token', 'sc_user'], () => {
      sendResponse({ ok: true });
    });
    return true;
  }
});

// ── Caption generation ──────────────────────────────────────────────────────
async function handleGenerate(payload) {
  const { token } = await getAuth();
  if (!token) throw new Error('Not signed in');

  const resp = await fetch(`${API_BASE}/extension-caption.php`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`,
    },
    body: JSON.stringify(payload),
  });

  const data = await resp.json();
  if (!resp.ok) {
    const err = new Error(data.error || 'Generation failed');
    err.upgrade = data.upgrade || false;
    throw err;
  }
  return data;
}

function getAuth() {
  return new Promise(resolve => {
    chrome.storage.local.get(['sc_token', 'sc_user'], d =>
      resolve({ token: d.sc_token || null, user: d.sc_user || null })
    );
  });
}
