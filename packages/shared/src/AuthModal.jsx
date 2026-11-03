import { useEffect, useState } from 'react';
import { signInWithEmailAndPassword } from 'firebase/auth';
import { doc, setDoc, serverTimestamp } from 'firebase/firestore';
import { auth, db, firebaseConfigured, makeRecaptcha, sendEmailOtp, sendSmsOtp, validateEmailOtp, finalizeVerifiedRegistration } from './firebase';
import './styles.css';

export default function AuthModal({ onClose, onSignedIn }) {
  const [tab, setTab] = useState('signin'), [step, setStep] = useState('details'), [name, setName] = useState(''), [email, setEmail] = useState(''), [password, setPassword] = useState(''), [phone, setPhone] = useState(''), [method, setMethod] = useState('email'), [code, setCode] = useState(''), [confirmation, setConfirmation] = useState(null), [message, setMessage] = useState(''), [busy, setBusy] = useState(false);
  useEffect(() => { if (new URLSearchParams(location.search).has('finishEmailVerification')) { setTab('register'); setMethod('email'); setName(window.sessionStorage.getItem('pendingRegistrationName') || ''); finishEmail(); } }, []);
  async function finishEmail() {
    try { const user = await validateEmailOtp(); setStep('verified'); setMessage('Email verified. Set your password to finish registration.'); window.history.replaceState({}, '', location.pathname); if (user.email) setEmail(user.email); }
    catch (e) { setMessage(e.message); }
  }
  async function signIn(e) { e.preventDefault(); setBusy(true); setMessage(''); try { const result = await signInWithEmailAndPassword(auth, email, password); onSignedIn?.(result.user); onClose?.(); } catch (err) { setMessage(err.message); } finally { setBusy(false); } }
  async function requestCode(e) { e.preventDefault(); setBusy(true); setMessage(''); try {
    if (!firebaseConfigured) throw new Error('Firebase is not configured. Copy .env.firebase.example to .env and add your Firebase web app settings.');
    if (method === 'email') { window.sessionStorage.setItem('pendingRegistrationName', name); await sendEmailOtp(email); setStep('waiting-email'); setMessage('Verification link sent. Open it on this device, then return here.'); }
    else { const verifier = makeRecaptcha('recaptcha-container'); const result = await sendSmsOtp(phone, verifier); setConfirmation(result); setStep('sms-code'); setMessage('SMS code sent.'); }
  } catch (err) { setMessage(err.message); } finally { setBusy(false); } }
  async function finishRegistration(e) { e.preventDefault(); setBusy(true); setMessage(''); try {
    let user = auth.currentUser;
    if (method === 'sms' && confirmation) user = (await confirmation.confirm(code)).user;
    if (method === 'email' && !user) throw new Error('Verify your email link first.');
    if (method === 'email' || (user && !user.providerData.some(p => p.providerId === 'password'))) user = await finalizeVerifiedRegistration(user, { fullName: name, email, password });
    await setDoc(doc(db, 'users', user.uid), { uid: user.uid, fullName: name || user.displayName || '', email: user.email, phone: phone || user.phoneNumber || null, role: 'customer', createdAt: serverTimestamp() }, { merge: true });
    window.sessionStorage.removeItem('pendingRegistrationName');
    onSignedIn?.(user); onClose?.();
  } catch (err) { setMessage(err.message); } finally { setBusy(false); } }
  return <div className="modal-backdrop" role="presentation"><section className="auth-card" role="dialog" aria-modal="true" aria-label="Account"><button className="close" onClick={onClose} aria-label="Close">×</button><div className="tabs"><button className={tab==='signin'?'selected':''} onClick={()=>{setTab('signin');setStep('details');setMessage('');}}>Sign In</button><button className={tab==='register'?'selected':''} onClick={()=>{setTab('register');setStep('details');setMessage('');}}>Register</button></div>
    {tab==='signin' ? <form onSubmit={signIn}><h2>Welcome back</h2><label>Email<input type="email" autoComplete="email" required value={email} onChange={e=>setEmail(e.target.value)}/></label><label>Password<input type="password" autoComplete="current-password" required value={password} onChange={e=>setPassword(e.target.value)}/></label><button className="primary" disabled={busy}>{busy?'Signing in…':'Sign In'}</button></form> : <form onSubmit={step==='details'?requestCode:finishRegistration}><h2>Create your account</h2>
    {(step==='details'||step==='waiting-email'||step==='sms-code')&&<><label>Full name<input required autoComplete="name" value={name} onChange={e=>setName(e.target.value)}/></label><label>Email<input type="email" autoComplete="email" required value={email} onChange={e=>setEmail(e.target.value)}/></label><label>Password<input type="password" autoComplete="new-password" minLength="8" required value={password} onChange={e=>setPassword(e.target.value)}/></label><label>Verify by<select value={method} onChange={e=>setMethod(e.target.value)}><option value="email">Email link</option><option value="sms">SMS code</option></select></label>{method==='sms'&&<label>Phone number<input type="tel" placeholder="+639XXXXXXXXX" required value={phone} onChange={e=>setPhone(e.target.value)}/></label>}</>}
    {step==='details'&&<button className="primary" disabled={busy}>{busy?'Sending…':'Send verification'}</button>}
    {step==='waiting-email'&&<><p>Click the sign-in link in your email on this device. You can return here to complete your account.</p><button type="button" className="primary" onClick={finishEmail}>I verified my email</button></>}
    {step==='sms-code'&&<><label>SMS verification code<input inputMode="numeric" autoComplete="one-time-code" value={code} onChange={e=>setCode(e.target.value)} required/></label><button className="primary" disabled={busy}>{busy?'Verifying…':'Verify and create account'}</button></>}
    {step==='verified'&&<><p>{message}</p><label>Full name<input required value={name} onChange={e=>setName(e.target.value)}/></label><label>Create password<input type="password" minLength="8" required value={password} onChange={e=>setPassword(e.target.value)}/></label><button className="primary" disabled={busy}>Finish registration</button></>}
    <div id="recaptcha-container"/>
    </form>}{message&&<p className="form-message" role="status">{message}</p>}</section></div>;
}
