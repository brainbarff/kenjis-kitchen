import React, { useEffect, useState } from 'react';
import { createRoot } from 'react-dom/client';
import { onAuthStateChanged, signOut } from 'firebase/auth';
import { collection, onSnapshot, query, where } from 'firebase/firestore';
import { auth, db, firebaseConfigured, placeOrder } from '@shared/firebase';
import AuthModal from '@shared/AuthModal';
import '@shared/styles.css';

function OrderingApp() {
  const [user, setUser] = useState(null), [showAuth, setShowAuth] = useState(false), [items, setItems] = useState([]);
  const [dark, setDark] = useState(false), [cart, setCart] = useState([]), [orders, setOrders] = useState([]);
  const [choices, setChoices] = useState({}), [checkoutMessage, setCheckoutMessage] = useState(''), [busy, setBusy] = useState(false);
  useEffect(() => {
    const unsubscribe = onAuthStateChanged(auth, setUser);
    if (new URLSearchParams(location.search).has('finishEmailVerification')) setShowAuth(true);
    return unsubscribe;
  }, []);
  useEffect(() => {
    if (!firebaseConfigured) return;
    return onSnapshot(query(collection(db, 'menuItems'), where('active', '==', true)), snap => setItems(snap.docs.map(d => ({ id: d.id, ...d.data() }))), () => setItems([]));
  }, []);
  useEffect(() => {
    if (!user) { setOrders([]); return; }
    return onSnapshot(query(collection(db, 'orders'), where('customerId', '==', user.uid)), snap => setOrders(snap.docs.map(d => ({ id: d.id, ...d.data() })).sort((a, b) => (b.createdAt?.seconds || 0) - (a.createdAt?.seconds || 0))));
  }, [user]);
  useEffect(() => { document.body.classList.toggle('dark', dark); }, [dark]);
  function selected(item) { return item.variants?.find(option => option.name === choices[item.id]) || item.variants?.[0] || null; }
  function price(item) { return Number(selected(item)?.price ?? item.price ?? 0); }
  function add(item) {
    const option = selected(item), variantName = option?.name || null, key = `${item.id}:${variantName || ''}`;
    setCart(current => {
      const found = current.find(line => line.key === key);
      return found ? current.map(line => line.key === key ? { ...line, quantity: line.quantity + 1 } : line) : [...current, { key, id: item.id, name: item.name, variantName, price: price(item), quantity: 1 }];
    });
  }
  async function checkout() {
    if (!user) { setShowAuth(true); return; }
    setBusy(true); setCheckoutMessage('');
    try { await placeOrder(cart.map(({ id, quantity, variantName }) => ({ id, quantity, variantName }))); setCart([]); setCheckoutMessage('Order received. You can track its status below.'); }
    catch (e) { setCheckoutMessage(e.message || 'Could not place order. Please try again.'); }
    finally { setBusy(false); }
  }
  const cartTotal = cart.reduce((sum, line) => sum + line.quantity * line.price, 0);
  return <div className="app">
    <header className="topbar"><div className="brand">Kenji's <span>Kitchen</span></div><div className="actions"><button className="button secondary" onClick={() => setDark(!dark)}>{dark ? '☀ Day' : '☾ Night'}</button>{user ? <><span className="muted">{user.displayName || user.email || user.phoneNumber}</span><button className="button secondary" onClick={() => signOut(auth)}>Sign out</button></> : <button className="button" onClick={() => setShowAuth(true)}>Sign In</button>}</div></header>
    <main className="content"><section className="hero"><p className="accent">Fresh from our kitchen</p><h1>Good food, made for sharing.</h1><p className="muted">Browse the menu and place your order online.</p></section>
      {!firebaseConfigured && <p className="form-message">Add Firebase web app settings to the root .env file to connect this menu.</p>}
      <div className="row"><h2>Menu</h2><strong>{cart.reduce((n, line) => n + line.quantity, 0)} in cart</strong></div>
      <section className="grid">{items.map(item => <article className="card" key={item.id}><h3>{item.name}</h3><p className="muted">{item.description || item.category || ''}</p>
        {item.variants?.length > 0 && <label className="option-label">Size / option<select value={choices[item.id] || item.variants[0].name} onChange={e => setChoices({ ...choices, [item.id]: e.target.value })}>{item.variants.map(option => <option key={option.name} value={option.name}>{option.name} — ₱{Number(option.price).toFixed(2)}</option>)}</select></label>}
        <div className="row"><span className="price">₱{price(item).toFixed(2)}</span><button className="button" onClick={() => add(item)}>Add</button></div></article>)}
        {items.length === 0 && <p className="muted">Menu items will appear here when the admin publishes them.</p>}</section>
      <section className="card" style={{ marginTop: 24 }}><div className="row"><h2>Your order</h2><strong>₱{cartTotal.toFixed(2)}</strong></div>{cart.map(line => <p key={line.key}>{line.quantity} × {line.name}{line.variantName ? ` (${line.variantName})` : ''} — ₱{(line.quantity * line.price).toFixed(2)}</p>)}
        {cart.length > 0 && <button className="button" disabled={busy} onClick={checkout}>{busy ? 'Placing order…' : user ? 'Place order' : 'Sign in to checkout'}</button>}{checkoutMessage && <p className="form-message">{checkoutMessage}</p>}</section>
      {user && <section><h2>Your orders</h2>{orders.map(order => <article className="card" key={order.id}><div className="row"><strong>Order {order.orderNumber || order.id}</strong><span className="accent">{order.status || 'Received'}</span></div><p>{order.items?.map(line => `${line.quantity} × ${line.name}${line.variantName ? ` (${line.variantName})` : ''}`).join(' · ')}</p><p>₱{Number(order.total || 0).toFixed(2)}</p></article>)}</section>}
    </main>{showAuth && <AuthModal onClose={() => setShowAuth(false)} onSignedIn={setUser} />}</div>;
}
createRoot(document.getElementById('root')).render(<OrderingApp />);
