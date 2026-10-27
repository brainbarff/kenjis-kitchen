// Run from a trusted machine with GOOGLE_APPLICATION_CREDENTIALS pointing to a
// Firebase service-account JSON file. Never expose that file to a browser/client.
const { createRequire } = require('node:module');
const requireFunctions = createRequire(require.resolve('../functions/package.json'));
const { initializeApp, applicationDefault } = requireFunctions('firebase-admin/app');
const { getAuth } = requireFunctions('firebase-admin/auth');
const uid = process.argv[2];
if (!uid) throw new Error('Usage: node tools/set-admin.js <firebase-auth-uid>');
initializeApp({ credential: applicationDefault() });
(async () => {
  const auth = getAuth();
  const user = await auth.getUser(uid);
  await auth.setCustomUserClaims(uid, { ...(user.customClaims || {}), admin: true });
  console.log(`Granted the admin custom claim to ${user.email || uid}. Sign out and back in to refresh the ID token.`);
})().catch(error => { console.error(error.message); process.exitCode = 1; });
