import React, { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { onAuthStateChanged, signInWithEmailAndPassword, signOut } from 'firebase/auth';
import { collection, onSnapshot, query, orderBy, doc, updateDoc, setDoc, addDoc, serverTimestamp, deleteField } from 'firebase/firestore';
import { auth, db, firebaseConfigured } from '@shared/firebase';
import '@shared/styles.css';

function AdminApp() {
  const [user, setUser] = useState(null), [isAdmin, setIsAdmin] = useState(false);
  const [email, setEmail] = useState(''), [password, setPassword] = useState(''), [error, setError] = useState('');
  const [dark, setDark] = useState(false), [orders, setOrders] = useState([]), [menu, setMenu] = useState([]);
  const [editing, setEditing] = useState(null), [form, setForm] = useState({ name: '', description: '', category: '', price: '', stock: '', variantsJson: '[]', active: true });
  const [storeOpen, setStoreOpen] = useState(true), [settingsMessage, setSettingsMessage] = useState('');

  useEffect(() => onAuthStateChanged(auth, async current => {
    setUser(current);
    if (!current) { setIsAdmin(false); return; }
    try { await current.getIdToken(true); setIsAdmin((await current.getIdTokenResult()).claims.admin === true); }
    catch { setIsAdmin(false); }
  }), []);
  useEffect(() => { document.body.classList.toggle('dark', dark); }, [dark]);
  useEffect(() => {
    if (!isAdmin) return;
    const stopOrders = onSnapshot(query(collection(db, 'orders'), orderBy('createdAt', 'desc')), snap => setOrders(snap.docs.map(d => ({ id: d.id, ...d.data() }))));
    const stopMenu = onSnapshot(collection(db, 'menuItems'), snap => setMenu(snap.docs.map(d => ({ id: d.id, ...d.data() }))));
    const stopSettings = onSnapshot(doc(db, 'settings', 'public'), snap => { if (snap.exists()) setStoreOpen(snap.data().orderingOpen !== false); });
    return () => { stopOrders(); stopMenu(); stopSettings(); };
  }, [isAdmin]);

  async function login(event) { event.preventDefault(); setError(''); try { await signInWithEmailAndPassword(auth, email, password); } catch (e) { setError(e.message); } }
  async function advance(order, status) { try { await updateDoc(doc(db, 'orders', order.id), { status, updatedAt: serverTimestamp() }); } catch (e) { setError(e.message); } }
  function startEdit(item = null) {
    setEditing(item?.id || 'new');
    setForm(item ? { name: item.name || '', description: item.description || '', category: item.category || '', price: String(item.price ?? ''), stock: item.stock == null ? '' : String(item.stock), variantsJson: JSON.stringify(item.variants || [], null, 2), active: item.active === true } : { name: '', description: '', category: '', price: '', stock: '', variantsJson: '[]', active: true });
  }
  async function saveMenu(event) {
    event.preventDefault(); setError('');
    const price = Number(form.price), stock = form.stock === '' ? null : Number(form.stock);
    let variants;
    try { variants = JSON.parse(form.variantsJson || '[]'); } catch { setError('Sizes/options must be valid JSON.'); return; }
    if (!Array.isArray(variants) || variants.some(option => typeof option.name !== 'string' || !option.name.trim() || !Number.isFinite(Number(option.price)) || Number(option.price) < 0)) { setError('Each size/option must have a name and a non-negative price.'); return; }
    if (!form.name.trim() || !Number.isFinite(price) || price < 0 || (stock !== null && (!Number.isInteger(stock) || stock < 0))) { setError('Enter a menu name, valid price, and whole-number stock.'); return; }
    const data = { name: form.name.trim(), description: form.description.trim(), category: form.category.trim(), price, variants: variants.map(option => ({ name: option.name.trim(), price: Number(option.price) })), active: form.active, updatedAt: serverTimestamp(), ...(stock === null ? (editing === 'new' ? {} : { stock: deleteField() }) : { stock }) };
    try { if (editing === 'new') await addDoc(collection(db, 'menuItems'), { ...data, createdAt: serverTimestamp() }); else await updateDoc(doc(db, 'menuItems', editing), data); setEditing(null); }
    catch (e) { setError(e.message); }
  }
  async function saveSettings() { setSettingsMessage(''); try { await setDoc(doc(db, 'settings', 'public'), { orderingOpen: storeOpen, updatedAt: serverTimestamp() }, { merge: true }); setSettingsMessage('Settings saved.'); } catch (e) { setSettingsMessage(e.message); } }

  if (!isAdmin) return <div className="app"><header className="topbar"><div className="brand">Kenji's <span>Kitchen Admin</span></div><button className="button secondary" onClick={() => setDark(!dark)}>{dark ? '☀ Day' : '☾ Night'}</button></header><main className="content"><section className="auth-card" style={{ margin: '8vh auto' }}><h2>Staff sign in</h2>{!firebaseConfigured && <p className="form-message">Configure Firebase in the root .env before signing in.</p>}{user ? <p className="form-message">This account does not have the administrator role. Contact the project owner.</p> : <form onSubmit={login}><label>Email<input type="email" required value={email} onChange={e => setEmail(e.target.value)} /></label><label>Password<input type="password" required value={password} onChange={e => setPassword(e.target.value)} /></label><button className="primary">Sign in</button>{error && <p className="form-message">{error}</p>}</form>}</section></main></div>;

  return <div className="app"><header className="topbar"><div className="brand">Kenji's <span>Kitchen Admin</span></div><div className="actions"><button className="button secondary" onClick={() => setDark(!dark)}>{dark ? '☀ Day' : '☾ Night'}</button><span>{user.email}</span><button className="button secondary" onClick={() => signOut(auth)}>Sign out</button></div></header><main className="content">
    <h1>Operations dashboard</h1><p className="muted">Menu, stock, and order data updates live.</p>{error && <p className="form-message">{error}</p>}
    <section className="grid"><article className="card"><strong>{orders.length}</strong><p className="muted">Orders</p></article><article className="card"><strong>{menu.length}</strong><p className="muted">Menu items</p></article></section>
    <div className="row"><h2>Orders</h2></div>{orders.map(order => <article className="card" key={order.id}><div className="row"><strong>{order.orderNumber || order.id}</strong><span className="accent">{order.status || 'Received'}</span></div><p>{order.customerName || order.customerId} · ₱{Number(order.total || 0).toFixed(2)}</p><div className="actions">{['Preparing', 'Ready', 'Completed'].map(status => <button className="button secondary" key={status} disabled={order.status === status} onClick={() => advance(order, status)}>{status}</button>)}</div></article>)}
    <div className="row"><h2>Menu & inventory</h2><button className="button" onClick={() => startEdit()}>Add menu item</button></div>
    {editing && <form className="card" onSubmit={saveMenu}><h3>{editing === 'new' ? 'New menu item' : 'Edit menu item'}</h3><div className="grid"><label>Name<input required value={form.name} onChange={e => setForm({ ...form, name: e.target.value })} /></label><label>Category<input value={form.category} onChange={e => setForm({ ...form, category: e.target.value })} /></label><label>Base price (₱)<input required type="number" min="0" step="0.01" value={form.price} onChange={e => setForm({ ...form, price: e.target.value })} /></label><label>Stock (blank = not tracked)<input type="number" min="0" step="1" value={form.stock} onChange={e => setForm({ ...form, stock: e.target.value })} /></label></div><label>Description<input value={form.description} onChange={e => setForm({ ...form, description: e.target.value })} /></label><label>Sizes/options with prices (JSON)<textarea rows="4" value={form.variantsJson} onChange={e => setForm({ ...form, variantsJson: e.target.value })} placeholder={'[{"name":"Large","price":450},{"name":"XL","price":600}]'} /></label><label><input type="checkbox" checked={form.active} onChange={e => setForm({ ...form, active: e.target.checked })} /> Published in online menu</label><div className="actions"><button className="button">Save item</button><button type="button" className="button secondary" onClick={() => setEditing(null)}>Cancel</button></div></form>}
    <section className="grid">{menu.map(item => <article className="card" key={item.id}><div className="row"><h3>{item.name}</h3><span className="muted">{item.active ? 'Published' : 'Hidden'}</span></div><p>₱{Number(item.price || 0).toFixed(2)} · Stock: {item.stock ?? 'Not tracked'}</p>{item.variants?.length > 0 && <p className="muted">{item.variants.map(option => `${option.name}: ₱${Number(option.price).toFixed(2)}`).join(' · ')}</p>}<button className="button secondary" onClick={() => startEdit(item)}>Edit item</button></article>)}</section>
    <section className="card" style={{ marginTop: 24 }}><h2>Online ordering</h2><label><input type="checkbox" checked={storeOpen} onChange={e => setStoreOpen(e.target.checked)} /> Accept online orders</label><div style={{ marginTop: 12 }}><button className="button" onClick={saveSettings}>Save setting</button></div>{settingsMessage && <p className="muted">{settingsMessage}</p>}</section>
  </main></div>;
}
createRoot(document.getElementById('root')).render(<AdminApp />);
