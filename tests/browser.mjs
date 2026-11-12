import fs from 'node:fs/promises';
import vm from 'node:vm';
import assert from 'node:assert/strict';
const checks=[];
const check=(name,condition)=>{assert.ok(condition,name);checks.push({name,ok:true});};
const source=await fs.readFile(new URL('../assets/form-guard.js',import.meta.url),'utf8');
const makeForm=(complete=true)=>{
 const controls={as_js:{value:'0'},as_interactions:{value:'0'},as_trap:{value:'native-nonce'}};
 if(!complete)delete controls.as_trap;
 const listeners=new Map();
 return {controls,listeners,elements:{namedItem:name=>controls[name]??null},querySelector:()=>true,
  addEventListener(type,handler,options){const list=listeners.get(type)??[];list.push({handler,capture:options===true});listeners.set(type,list);},
  emit(type,isTrusted=true){const list=[...(listeners.get(type)??[])].sort((a,b)=>Number(b.capture)-Number(a.capture));for(const {handler}of list)handler({type,isTrusted});}};
};
const first=makeForm(),second=makeForm(),broken=makeForm(false);
let clock=0;
const window={};
vm.runInNewContext(source,{window,WeakMap,performance:{now:()=>clock},document:{readyState:'interactive',querySelectorAll:()=>[broken,first,second]}});
check('incomplete markup does not break other forms',first.controls.as_js.value==='1'&&second.controls.as_js.value==='1');
check('native trap cleared only for initialized forms',first.controls.as_trap.value===''&&broken.controls.as_js.value==='0');
first.emit('input',false);check('synthetic events ignored',first.controls.as_interactions.value==='0');
first.emit('keydown');check('keyboard event counted',first.controls.as_interactions.value==='1');
first.emit('pointermove');check('events debounced',first.controls.as_interactions.value==='1');
clock=301;first.emit('input');check('later input counted',first.controls.as_interactions.value==='2');
check('forms have independent counters',second.controls.as_interactions.value==='0');
window.FormGuard.initialize(first);check('initialization is idempotent',first.listeners.get('submit').length===1);
first.emit('submit');check('submission capture updates activity before application handler',first.controls.as_interactions.value==='3');
first.controls.as_trap={value:'renewed-nonce'};first.controls.as_js={value:'0'};first.controls.as_interactions={value:'999'};
window.FormGuard.refresh(first);check('AJAX replacement controls initialized',first.controls.as_js.value==='1'&&first.controls.as_trap.value===''&&first.controls.as_interactions.value==='0');
first.emit('submit');check('renewed form remains usable without mouse events',first.controls.as_interactions.value==='1');
delete first.controls.as_js;first.emit('click');window.FormGuard.refresh(first);check('temporarily missing controls do not throw',true);
const dynamic=makeForm();window.FormGuard.initialize(dynamic);check('dynamically inserted form can be initialized',dynamic.controls.as_js.value==='1');
const loadingForm=makeForm();let onReady;
vm.runInNewContext(source,{window:{},WeakMap,performance:{now:()=>0},document:{readyState:'loading',querySelectorAll:()=>[loadingForm],addEventListener:(type,handler)=>{onReady=handler;}}});
check('initialization waits for loading DOM',loadingForm.controls.as_js.value==='0');onReady();
check('loading DOM initializes after readiness',loadingForm.controls.as_js.value==='1');
await fs.mkdir(new URL('../build/',import.meta.url),{recursive:true});
await fs.writeFile(new URL('../build/browser-helper-results.json',import.meta.url),JSON.stringify(checks,null,2));
console.log(`${checks.length} JavaScript lifecycle checks passed (DOM harness, not a full browser audit).`);
