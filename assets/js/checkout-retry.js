/* One checkout key per unchanged attempt, retained across failed requests/reloads. */
(function (global) {
  'use strict';
  const memory = new Map();
  // This fingerprint is only a local change detector. The server uses SHA-256.
  function fingerprint(text) {
    let a = 2166136261, b = 5381;
    for (let i = 0; i < text.length; i++) {
      a = Math.imul(a ^ text.charCodeAt(i), 16777619);
      b = Math.imul(b, 33) ^ text.charCodeAt(i);
    }
    return `${text.length}:${a >>> 0}:${b >>> 0}`;
  }
  global.KKCheckoutRetry = {
    key(scope, payload, file) {
      const storageKey = 'kk_checkout_retry:' + scope;
      const clean = { ...payload };
      delete clean.csrf_token;
      delete clean.request_key;
      delete clean.verification_token;
      const signature = fingerprint(JSON.stringify([clean, file ? [file.name, file.size, file.lastModified] : null]));
      let saved = memory.get(storageKey);
      try { saved = JSON.parse(sessionStorage.getItem(storageKey)) || saved; } catch (_) { /* memory fallback */ }
      if (!saved || saved.signature !== signature || !/^[a-f0-9]{64}$/.test(saved.key)) {
        const bytes = new Uint8Array(32);
        global.crypto.getRandomValues(bytes);
        saved = { signature, key: Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('') };
      }
      memory.set(storageKey, saved);
      try { sessionStorage.setItem(storageKey, JSON.stringify(saved)); } catch (_) { /* memory fallback */ }
      return saved.key;
    },
    complete(scope) {
      const storageKey = 'kk_checkout_retry:' + scope;
      memory.delete(storageKey);
      try { sessionStorage.removeItem(storageKey); } catch (_) { /* memory fallback */ }
    }
  };
})(typeof window !== 'undefined' ? window : globalThis);
