const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const { webcrypto } = require('node:crypto');
const source = fs.readFileSync(require('node:path').join(__dirname,'../assets/js/checkout-retry.js'),'utf8');
const storage = new Map();
const sessionStorage = { getItem:k=>storage.get(k)||null, setItem:(k,v)=>storage.set(k,v), removeItem:k=>storage.delete(k) };
function client(store=sessionStorage) { const ctx=vm.createContext({ crypto:webcrypto,sessionStorage:store }); vm.runInContext(source,ctx); return ctx.KKCheckoutRetry; }
let api=client();
const payload={cart:[{id:1,qty:2}],cash:100};
const key=api.key('pos:2',payload);
assert.match(key,/^[a-f0-9]{64}$/);
assert.equal(api.key('pos:2',payload),key);
api=client(); // A page reload must not turn an uncertain checkout into a new order.
assert.equal(api.key('pos:2',payload),key);
assert.equal(api.key('pos:2',{...payload,csrf_token:'renewed'}),key);
assert.notEqual(api.key('pos:3',payload),key);
assert.notEqual(api.key('pos:2',{...payload,cash:200}),key);
const changed=api.key('pos:2',{...payload,cash:200});
api.complete('pos:2');
assert.notEqual(api.key('pos:2',{...payload,cash:200}),changed);
const file={name:'proof.png',size:123,lastModified:10};
const online=api.key('online:2',payload,file);
assert.notEqual(api.key('online:2',payload,{...file,size:124}),online);
api=client({getItem(){throw Error('disabled')},setItem(){throw Error('disabled')},removeItem(){throw Error('disabled')}});
assert.equal(api.key('pos:2',payload),api.key('pos:2',payload));
console.log('PASS: 9 checkout retry checks.');
