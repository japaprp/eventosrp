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
const element=selector=>{if(!elements.has(selector))elements.set(selector,{value:'',textContent:'',innerHTML:'',hidden:false,style:{},dataset:{},classList:{toggle(){}},addEventListener(){},scrollIntoView(){},querySelectorAll(){return []},setAttribute(){},showModal(){this.open=true},close(){this.open=false},reportValidity(){return true}});return elements.get(selector)};
const context=vm.createContext({document:{querySelector:element,querySelectorAll:()=>[]},localStorage:{getItem:()=>null},location:{search:''},URLSearchParams,Intl,URL,Date,console,setTimeout,localDateTime,parseBookingDate,quoteBooking,formatBookingDate,fetch:async()=>({ok:false})});
let source=fs.readFileSync(new URL('../public/app.js',import.meta.url),'utf8').replace(/^import .*;\n/,'').split("try {\n  const response = await fetch('config.php');")[0];
vm.runInContext(source,context);
const fixtures=[
 {id:1,name:'Espaço Ribeirão',city:'Ribeirão Preto',type:'Ao ar livre',events:['Aniversário'],capacity:150,price:1800,weekendPrice:null,photos:[],amenities:[],checkInTime:'09:00',checkOutTime:'09:00',minimumDays:1},
 {id:2,name:'Salão Ribeirão',city:'Ribeirão Preto',type:'Salão',events:['Corporativo'],capacity:200,price:2300,weekendPrice:null,photos:[],amenities:[],checkInTime:'09:00',checkOutTime:'09:00',minimumDays:1},
 {id:3,name:'Espaço Campinas',city:'Campinas',type:'Corporativo',events:['Corporativo'],capacity:100,price:1200,weekendPrice:null,photos:[],amenities:[],checkInTime:'09:00',checkOutTime:'09:00',minimumDays:1}
];
assert.equal(element('#count').textContent, '');
assert.match(element('#catalog-message').textContent, /Carregando/);
assert.equal(element('#retry-catalogue').hidden, true);
await vm.runInContext('refreshCatalogue()', context);
assert.equal(element('#count').textContent, '');
assert.equal(element('#cards').innerHTML, '');
assert.equal(element('#retry-catalogue').hidden, false);
assert.match(element('#catalog-message').textContent, /Não foi possível/);
context.fetch = async () => ({ok:true, json:async()=>[]});
await element('#retry-catalogue').onclick();
assert.equal(element('#retry-catalogue').hidden, true);
assert.equal(element('#count').textContent, '0 espaços encontrados');
assert.match(element('#cards').innerHTML, /Ainda não há espaços publicados/);
context.fetch = async () => ({ok:true, json:async()=>fixtures});
await vm.runInContext('refreshCatalogue()', context);
assert.equal(element('#count').textContent,'3 espaços encontrados');assert.ok(element('#location').innerHTML.includes('Ribeirão Preto'));assert.ok(element('#location').innerHTML.includes('Campinas'));assert.ok(!element('#location').innerHTML.includes('São Paulo'));
element('#location').value='Ribeirão Preto';vm.runInContext('render()',context);assert.equal(element('#count').textContent,'2 espaços encontrados');
element('#capacity').value='200';vm.runInContext('render()',context);assert.equal(element('#count').textContent,'1 espaço encontrado');
vm.runInContext("type='Ao ar livre';render()",context);assert.equal(element('#empty').hidden,false);
element('#location').value='Campinas';element('#capacity').value='0';element('#event').value='Corporativo';vm.runInContext("type='';render()",context);assert.equal(element('#count').textContent,'1 espaço encontrado');
vm.runInContext('login()',context);assert.match(element('#modal-content').innerHTML,/autocomplete="name"/);assert.match(element('#modal-content').innerHTML,/id="signup-phone"/);
element('#signup').onclick();assert.equal(element('#signup-name').required,true);assert.equal(element('#signup-name').disabled,false);assert.ok(!element('#modal-content').innerHTML.includes('id="accept-account"'));assert.ok(!source.includes('signup-consent'));assert.equal(element('#password').autocomplete,'new-password');
element('#signup').onclick();assert.equal(element('#signup-name').disabled,true);assert.equal(element('#password').autocomplete,'current-password');
assert.ok(source.includes('async function ensureAccountConsent()'));assert.ok(source.includes("api('account-consent',{method:'POST'"));assert.equal(vm.runInContext("validPhone('(16) 99999-9999')",context),true);assert.equal(vm.runInContext("validPhone('123')",context),false);
vm.runInContext("user={uid:'titular'};profile={name:'Cliente de teste',phone:'(16) 99999-9999'};reservation(spaces[0])",context);
assert.equal(element('#contact-name').value,'Cliente de teste');assert.equal(element('#phone').value,'(16) 99999-9999');
element('#plan').value='daily';element('#check-in').value='2030-01-04T09:00';element('#check-in').oninput();assert.equal(element('#check-out').value,'2030-01-05T09:00');assert.match(element('#quote').innerHTML,/1 diária/);
element('#plan').value='weekend';element('#plan').onchange();assert.equal(element('#check-out').value,'2030-01-06T09:00');assert.match(element('#quote').innerHTML,/2 diárias/);
element('#check-out').value='2030-01-07T09:00';element('#check-out').oninput();assert.match(element('#quote').innerHTML,/3 diárias/);assert.match(element('#quote').innerHTML,/5\.400/);
element('#check-out').value='2030-01-05T18:00';element('#check-out').oninput();assert.equal(element('#check-out').value,'2030-01-05T09:00');assert.match(element('#quote').textContent,/pelo menos 48 horas/);
const html=fs.readFileSync(new URL('../public/index.html',import.meta.url),'utf8');assert.ok(html.includes('<select id="location"><option value="">Todas as localizações</option></select>'));assert.ok(!html.includes('<option>Ribeirão Preto</option>'));assert.ok(!html.includes('São Paulo</option>'));assert.ok(!html.includes('Campinas</option>'));assert.ok(!html.includes('São José dos Campos</option>'));assert.ok(!html.includes('images.unsplash.com'));assert.ok(!fs.readFileSync(new URL('../public/app.js',import.meta.url),'utf8').includes('images.unsplash.com'));assert.ok(source.includes('upload-space-photo'));assert.ok(source.includes('delete-space-photo'));assert.ok(source.includes('accept-reservation-terms'));
assert.ok(!source.includes('onrender.com'),'Firebase errors must not direct users to the retired Render domain');
assert.ok(source.includes('eventosrp-production.up.railway.app'),'Firebase errors must identify the current Railway domain');
const firebaseRules = JSON.parse(fs.readFileSync(new URL('../database.rules.json', import.meta.url), 'utf8'));
const chatRules = firebaseRules.rules.chats.$reservationId.messages;
assert.ok(chatRules['.read'].includes('members'),'Chat reads must require reservation membership');
assert.ok(chatRules.$messageId['.write'].includes('!data.exists()'),'Chat messages must be append-only');
assert.ok(chatRules.$messageId['.write'].includes('auth.uid'),'Chat writes must require an authenticated member');
assert.ok(chatRules.$messageId['.validate'].includes('sender'),'Chat messages must require the sender field');
assert.ok(chatRules.$messageId.sender['.validate'].includes('auth.uid'),'Chat messages must validate the authenticated sender');
assert.ok(chatRules.$messageId.text['.validate'].includes('1000'),'Chat messages must enforce the 1000-character limit');
assert.equal(chatRules.$messageId.$other['.validate'],false,'Chat messages must reject unknown fields');
assert.ok(source.includes('terms_accepted:'));assert.ok(source.includes('Termos de uso — aviso provisório'));assert.ok(source.includes('Privacidade — aviso provisório'));assert.ok(!source.includes('Pagamentos são processados via Mercado Pago.'));assert.ok(!source.includes('Não compartilhamos dados com terceiros'));
vm.runInContext('passwordReset("cliente@example.com")',context);assert.equal(element('#reset-email').value,'cliente@example.com');assert.match(element('#modal-content').innerHTML,/Enviar link/);assert.ok(!element('#modal-content').innerHTML.includes('type="password"'));
let resetEmail=null;context.recordReset=email=>{resetEmail=email};vm.runInContext('auth={};sdk={sendPasswordResetEmail:async(a,email)=>recordReset(email)}',context);await element('#reset-form').onsubmit({preventDefault(){}});assert.equal(resetEmail,'cliente@example.com');
vm.runInContext("user={uid:'titular'};spaceForm()",context);assert.equal(element('#space-city').value,'');assert.equal(element('#space-in').value,'');assert.equal(element('#space-out').value,'');assert.equal(element('#space-active').checked,false);assert.equal(element('#space-type').value,'');assert.equal(element('#space-capacity').value,'');assert.equal(element('#space-price').value,'');assert.match(element('#modal-content').innerHTML,/Preparação entre reservas/);assert.match(element('#modal-content').innerHTML,/data-space-amenity/);assert.match(element('#modal-content').innerHTML,/type=\"file\"/);assert.match(element('#modal-content').innerHTML,/space-owner-authorized/);
vm.runInContext("spaceForm({...spaces[0],name:'Novo espaço',checkInTime:'10:00',checkOutTime:'08:00',active:false})",context);assert.equal(element('#space-name').value,'Novo espaço');assert.equal(element('#space-in').value,'10:00');assert.equal(element('#space-active').checked,false);
assert.equal(vm.runInContext("escapeHtml('<img src=x onerror=alert(1)>')",context),'&lt;img src=x onerror=alert(1)&gt;');
vm.runInContext("reservation({...spaces[0],checkInTime:'10:00',checkOutTime:'08:00',minimumDays:2})",context);element('#plan').value='weekend';element('#check-in').value='2030-01-04T13:00';element('#check-in').oninput();assert.equal(element('#check-in').value,'2030-01-04T10:00');assert.equal(element('#check-out').value,'2030-01-07T08:00');assert.match(element('#quote').innerHTML,/3 diárias/);
assert.equal(vm.runInContext("reservationStatus('approved')",context),'Pagamento aprovado');
assert.equal(vm.runInContext("reservationStatus('requested')",context),'Aguardando confirmação do proprietário');
assert.equal(vm.runInContext("reservationStatus('confirmed')",context),'Reserva confirmada pelo proprietário');
assert.equal(vm.runInContext("reservationStatus('declined')",context),'Solicitação recusada pelo proprietário');

assert.equal(vm.runInContext("reservationStatus('creating')",context),'Em conferência');assert.equal(vm.runInContext("reservationStatus('pending','approved','none')",context),'Pagamento aprovado — atualização da reserva em processamento');assert.equal(vm.runInContext("reservationStatus('pending','rejected','refund_required')",context),'Pagamento aprovado fora da reserva — análise de reembolso necessária');
let capturedPayload=null;
context.capturePayload=value=>{capturedPayload=JSON.parse(JSON.stringify(value))};
context.document.querySelectorAll=selector=>selector==='[data-space-event]:checked'?[{value:'Aniversário'}]:[];
vm.runInContext("spaceForm({...spaces[0],photos:['https://example.test/foto.jpg'],checkInTime:'09:00',checkOutTime:'09:00'});api=async(path,options)=>{if(path==='save-space'){capturePayload(JSON.parse(options.body));return {}}return []}",context);
for(const [selector,value] of Object.entries({'#space-name':'Chácara de teste','#space-address':'Rua de teste, 1','#space-description':'Área de lazer de teste','#space-price':'1800.50','#space-capacity':'150','#space-in':'09:00','#space-out':'09:00'}))element(selector).value=value;
element('#space-owner-authorized').checked=true;await element('#space-form').onsubmit({preventDefault(){}});
assert.equal(capturedPayload.daily_price,'1800.50');assert.equal(capturedPayload.weekend_price,null);assert.equal(capturedPayload.check_in_time,'09:00');assert.equal(capturedPayload.minimum_days,1);assert.equal(capturedPayload.owner_authorized,true);assert.deepEqual(Array.from(capturedPayload.photos),['https://example.test/foto.jpg']);assert.equal('price' in capturedPayload,false);
vm.runInContext('user=null;reservation(spaces[0])',context);assert.match(element('#modal-content').innerHTML,/id="auth-form"/);
context.photosInput='  https://example.test/photo.jpg  \n\nhttps://example.test/two.jpg';
assert.deepEqual(Array.from(vm.runInContext('parsePhotoUrls(photosInput)',context)),['https://example.test/photo.jpg','https://example.test/two.jpg']);
for(const photos of ['', 'http://example.test/photo.jpg','javascript:alert(1)','https://user:password@example.test/photo.jpg',Array(6).fill('https://example.test/photo.jpg').join('\n')]){context.invalidPhotos=photos;assert.throws(()=>vm.runInContext('parsePhotoUrls(invalidPhotos)',context));}
console.log(`${cases.length} cenários de preço/período e verificações de filtros, cadastro, recuperação de senha e formulário de espaços passaram.`);
