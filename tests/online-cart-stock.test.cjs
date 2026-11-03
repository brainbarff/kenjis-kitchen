'use strict';
// Runs the real storefront cart functions with an isolated DOM/storage stub.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const nodes = new Map();
function node(id) {
  if (!nodes.has(id)) nodes.set(id, {textContent:'', innerHTML:'', style:{}, classList:{add(){},remove(){},contains(){return false;}}, disabled:false});
  return nodes.get(id);
}
const context = vm.createContext({
  console, setTimeout:()=>0, clearTimeout(){}, setInterval:()=>0, clearInterval(){},
  window:{location:{port:'80',pathname:'/modules/online/'}},
  localStorage:{getItem:()=>null,setItem(){},removeItem(){}},
  document:{addEventListener(){}, createElement:()=>({}), head:{appendChild(){}}, body:{classList:{remove(){}}}, getElementById:node, querySelector:node, querySelectorAll:()=>[]}
});
vm.runInContext(fs.readFileSync(require.resolve('../assets/js/ol.app.js'), 'utf8'), context);
const run = source => vm.runInContext(source, context);
function reset(stock=5) {
  run(`appState.cart=[]; appState.currentModalItem=null; appState.isBilaoModal=false; appState.catalogLoaded=true; appState.menuItems=[{id:113,name:'Coca-Cola',stock:${stock},availability:'Available',price:40}];`);
}
reset();
assert.equal(run("addItemToCart(113,'Coca-Cola',40,5,'')"),true);
assert.equal(run("addItemToCart(113,'Coca-Cola',40,1,'')"),false);
assert.equal(run('quantityInCart(113)'),5);
run('changeCartItemQty(0,1)');
assert.equal(run('quantityInCart(113)'),5);
run('changeCartItemQty(0,-1)');
assert.equal(run('remainingStock(113)'),1);
reset();
assert.equal(run("addItemToCart(113,'Coca-Cola',40,3,'less ice')"),true);
assert.equal(run("addItemToCart(113,'Coca-Cola',40,3,'no note')"),false);
assert.equal(run("addItemToCart(113,'Coca-Cola',40,2,'no note')"),true);
assert.equal(run('appState.cart.length'),2);
assert.equal(run('quantityInCart(113)'),5);
reset();
run("addItemToCart(113,'Shared product',40,3,'','S','Tray','Sprite')");
assert.equal(run("addItemToCart(113,'Shared product',40,3,'','L','Bilao','Coca-Cola')"),false);
run('appState.currentModalItem=appState.menuItems[0];appState.modalQty=6;appState.modalUnitPrice=40;syncModalStockControls()');
assert.equal(run('appState.modalQty'),2);
assert.equal(node('#itemDetailModal [aria-label="Increase quantity"]').disabled,true);
run("appState.isBilaoModal=true;appState.currentBilaoGroup={variants:{S:{id:113},L:{id:114}}};appState.menuItems.push({id:114,name:'Large tray',stock:1,availability:'Available'});appState.selectedBilaoSize='L';appState.modalQty=4;syncModalStockControls()");
assert.equal(run('appState.modalQty'),1);
run("appState.selectedBilaoSize='S';appState.modalQty=8;syncModalStockControls()");
assert.equal(run('appState.modalQty'),2);
run('appState.menuItems[0].stock=2');
assert.match(run('cartStockProblem()'),/only 2 available/);
run('renderCartDrawerItems()');
assert.equal(node('btnProceedCheckout').disabled,true);
reset(0);
assert.equal(run("addItemToCart(113,'Coca-Cola',40,1,'')"),false);
reset();
run('appState.menuItems[0].is_archived=true');
assert.equal(run("addItemToCart(113,'Coca-Cola',40,1,'')"),false);
reset(200);
assert.equal(run("addItemToCart(113,'Coca-Cola',40,99,'')"),true);
assert.equal(run("addItemToCart(113,'Coca-Cola',40,1,'')"),false);
for(const qty of [0,-1,1.5,NaN,100]) assert.equal(run(`addItemToCart(113,'Coca-Cola',40,${qty},'other')`),false);
run('removeCartItem(0)');
assert.equal(run('quantityInCart(113)'),0);
assert.equal(node('drawerTotal').textContent,'₱0.00');
console.log('PASS: stock caps, repeat additions, basket increments, notes/flavors/packaging sharing stock, modal limits, stale carts, archived/zero-stock items, invalid quantities and removal.');
