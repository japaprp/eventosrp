import fs from 'node:fs';
import vm from 'node:vm';
import assert from 'node:assert/strict';
import { localDateTime, parseBookingDate, quoteBooking, formatBookingDate } from '../public/booking.mjs';
const cases=JSON.parse(fs.readFileSync(new URL('./booking-cases.json',import.meta.url),'utf8'));
for(const c of cases){
 const quote=()=>quoteBooking(c.start,c.end,c.plan,c.daily,c.weekend,new Date('2026-10-01T08:00:00-03:00'));
 if(c.error)assert.throws(quote,Error,c.label);
 else{const result=quote();assert.equal(result.days,c.days,c.label);assert.equal(result.total.toFixed(2),c.total,c.label)}
}
assert.equal(localDateTime(new Date('2026-10-02T02:30:00Z')),'2026-10-01T23:30');
assert.match(formatBookingDate('2026-10-02 09:00:00'),/02\/10\/2026/);
const elements=new Map();
const element=selector=>{if(!elements.has(selector))elements.set(selector,{value:'',textContent:'',innerHTML:'',hidden:false,style:{},dataset:{},classList:{toggle(){}},addEventListener(){},scrollIntoView(){},showModal(){this.open=true},close(){this.open=false},reportValidity(){return true}});return elements.get(selector)};
const context=vm.createContext({document:{querySelector:element,querySelectorAll:()=>[]},localStorage:{getItem:()=>null},location:{search:''},URLSearchParams,Intl,URL,Date,console,setTimeout,localDateTime,parseBookingDate,quoteBooking,formatBookingDate,fetch:async()=>({ok:false})});
let source=fs.readFileSync(new URL('../public/app.js',import.meta.url),'utf8').replace(/^import .*;\n/,'').split('try{const response=await fetch')[0];
vm.runInContext(source,context);
assert.equal(element('#count').textContent,'8 espaços encontrados');
element('#location').value='Ribeirão Preto';vm.runInContext('render()',context);assert.equal(element('#count').textContent,'2 espaços encontrados');
element('#capacity').value='200';vm.runInContext('render()',context);assert.equal(element('#count').textContent,'1 espaços encontrados');
vm.runInContext("type='Ao ar livre';render()",context);assert.equal(element('#empty').hidden,false);
element('#location').value='Campinas';element('#capacity').value='0';element('#event').value='Corporativo';vm.runInContext("type='';render()",context);assert.equal(element('#count').textContent,'1 espaços encontrados');
vm.runInContext('login()',context);assert.match(element('#modal-content').innerHTML,/autocomplete="name"/);assert.match(element('#modal-content').innerHTML,/id="signup-phone"/);
element('#signup').onclick();assert.equal(element('#signup-name').required,true);assert.equal(element('#signup-name').disabled,false);assert.equal(element('#accept-account').required,true);assert.equal(element('#password').autocomplete,'new-password');
element('#signup').onclick();assert.equal(element('#signup-name').disabled,true);assert.equal(element('#password').autocomplete,'current-password');
assert.equal(vm.runInContext("validPhone('(16) 99999-9999')",context),true);assert.equal(vm.runInContext("validPhone('123')",context),false);
vm.runInContext("profile={name:'Cliente de teste',phone:'(16) 99999-9999'};reservation(spaces[6])",context);
assert.equal(element('#contact-name').value,'Cliente de teste');assert.equal(element('#phone').value,'(16) 99999-9999');
element('#plan').value='daily';element('#check-in').value='2030-01-04T09:00';element('#check-in').oninput();assert.equal(element('#check-out').value,'2030-01-05T09:00');assert.match(element('#quote').textContent,/1 diária/);
element('#plan').value='weekend';element('#plan').onchange();assert.equal(element('#check-out').value,'2030-01-06T09:00');assert.match(element('#quote').textContent,/2 diárias/);
element('#check-out').value='2030-01-07T09:00';element('#check-out').oninput();assert.match(element('#quote').textContent,/3 diárias/);assert.match(element('#quote').textContent,/5\.400/);
element('#check-out').value='2030-01-04T18:00';element('#check-out').oninput();assert.match(element('#quote').textContent,/pelo menos 48 horas/);
assert.ok(fs.readFileSync(new URL('../public/index.html',import.meta.url),'utf8').includes('<option>Ribeirão Preto</option>'));
console.log(`${cases.length} cenários de preço/período e verificações de filtro, cadastro e formulário passaram.`);
