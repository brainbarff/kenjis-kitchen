import { initializeApp, getApps } from 'firebase/app';
import { getAuth, sendSignInLinkToEmail, isSignInWithEmailLink, signInWithEmailLink, RecaptchaVerifier, signInWithPhoneNumber, EmailAuthProvider, linkWithCredential, updateProfile, updatePassword } from 'firebase/auth';
import { getFirestore } from 'firebase/firestore';
import { initializeAppCheck, ReCaptchaV3Provider } from 'firebase/app-check';
import { getFunctions, httpsCallable } from 'firebase/functions';

const config = {
  apiKey: import.meta.env.VITE_FIREBASE_API_KEY,
  authDomain: import.meta.env.VITE_FIREBASE_AUTH_DOMAIN,
  projectId: import.meta.env.VITE_FIREBASE_PROJECT_ID,
  storageBucket: import.meta.env.VITE_FIREBASE_STORAGE_BUCKET,
  messagingSenderId: import.meta.env.VITE_FIREBASE_MESSAGING_SENDER_ID,
  appId: import.meta.env.VITE_FIREBASE_APP_ID
};
export const firebaseConfigured = Boolean(config.apiKey && config.authDomain && config.projectId && config.appId);
export const app = getApps()[0] ?? initializeApp(firebaseConfigured ? config : { apiKey: 'missing', authDomain: 'missing.invalid', projectId: 'missing' });
export const auth = getAuth(app);
export const db = getFirestore(app);
export const functions = getFunctions(app);
if (firebaseConfigured && import.meta.env.VITE_FIREBASE_APPCHECK_SITE_KEY) initializeAppCheck(app, { provider: new ReCaptchaV3Provider(import.meta.env.VITE_FIREBASE_APPCHECK_SITE_KEY), isTokenAutoRefreshEnabled: true });
export async function placeOrder(items) { return (await httpsCallable(functions, 'placeOrder')({ items })).data; }

// Firebase Auth has no native numeric email OTP. Email is verified by a one-time sign-in link.
export async function sendEmailOtp(email) {
  const actionCodeSettings = { url: `${window.location.origin}/?finishEmailVerification=1`, handleCodeInApp: true };
  await sendSignInLinkToEmail(auth, email, actionCodeSettings);
  window.sessionStorage.setItem('pendingEmailVerification', email);
}
export async function validateEmailOtp() {
  if (!isSignInWithEmailLink(auth, window.location.href)) throw new Error('Open the verification link sent to your email first.');
  const email = window.sessionStorage.getItem('pendingEmailVerification') || window.prompt('Confirm your email address');
  if (!email) throw new Error('Email address is required to finish verification.');
  const result = await signInWithEmailLink(auth, email, window.location.href);
  window.sessionStorage.removeItem('pendingEmailVerification');
  return result.user;
}
export function makeRecaptcha(containerId) {
  return new RecaptchaVerifier(auth, containerId, { size: 'invisible' });
}
export async function sendSmsOtp(phoneNumber, verifier) {
  return signInWithPhoneNumber(auth, phoneNumber, verifier);
}
export async function finalizeVerifiedRegistration(user, { fullName, email = user.email, password }) {
  if (password && user.email === email) await updatePassword(user, password);
  else if (password && !user.providerData.some(provider => provider.providerId === 'password')) await linkWithCredential(user, EmailAuthProvider.credential(email, password));
  await updateProfile(user, { displayName: fullName });
  return user;
}
