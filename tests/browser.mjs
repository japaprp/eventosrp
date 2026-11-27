import { createRequire } from 'node:module';
const { chromium }=createRequire(import.meta.url)('playwright');
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
const root=new URL('../public/',import.meta.url);
const server=createServer(async(req,res)=>{
 try{const path=new URL(req.url,'http://localhost').pathname;const file=path==='/'?'index.html':path.slice(1);if(file.includes('..'))throw Error();const data=await readFile(new URL(file,root));res.setHeader('Content-Type',file.endsWith('.js')||file.endsWith('.mjs')?'text/javascript':file.endsWith('.css')?'text/css':'text/html');res.end(data)}catch{res.statusCode=404;res.end()}
});
await new Promise(resolve=>server.listen(0,'127.0.0.1',resolve));
const origin=`http://127.0.0.1:${server.address().port}`;
const browser=await chromium.launch({headless:true,executablePath:process.env.CHROMIUM_EXECUTABLE_PATH});
const space={id:1,name:'Área "Família" <teste>',city:'Ribeirão Preto',neighborhood:'Centro',address:'Rua de teste, 1',type:'Salão',description:'Espaço de teste autorizado',rules:'Sem vidro na piscina',capacity:150,price:1800,weekendPrice:null,photos:['https://example.test/space.jpg','https://example.test/second.jpg'],amenities:['Piscina','Churrasqueira'],events:['Aniversário'],checkInTime:'09:00',checkOutTime:'09:00',minimumDays:1,cleanupMinutes:0,active:true};
let checks=0;
const uploadUrl='https://res.cloudinary.com/reservasrp-test/image/upload/v1760000000/eventosrp/spaces/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.jpg';
const imageBuffer=Buffer.from('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAYEBQYFBAYGBwYIChAKCgkJChQODwwQFxQYGBcUFhYaHSUfGhsjHBYWICwgIyYnKSopGR8tMC0oMCUoKSj/2wBDAQcHBwoIChMKChMoGhYaKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCgoKCj/wAARCAAIAAgDASIAAhEBAxEB/8QAHwAAAQUBAQEBAQEAAAAAAAAAAAECAwQFBgcICQoL/8QAtRAAAgEDAwIEAwUFBAQAAAF9AQIDAAQRBRIhMUEGE1FhByJxFDKBkaEII0KxwRVS0fAkM2JyggkKFhcYGRolJicoKSo0NTY3ODk6Q0RFRkdISUpTVFVWV1hZWmNkZWZnaGlqc3R1dnd4eXqDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uHi4+Tl5ufo6erx8vP09fb3+Pn6/8QAHwEAAwEBAQEBAQEBAQAAAAAAAAECAwQFBgcICQoL/8QAtREAAgECBAQDBAcFBAQAAQJ3AAECAxEEBSExBhJBUQdhcRMiMoEIFEKRobHBCSMzUvAVYnLRChYkNOEl8RcYGRomJygpKjU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6goOEhYaHiImKkpOUlZaXmJmaoqOkpaanqKmqsrO0tba3uLm6wsPExcbHyMnK0tPU1dbX2Nna4uPk5ebn6Onq8vP09fb3+Pn6/9oADAMBAAIRAxEAPwDqaKKKxPnz/9k=','base64');
try{
 for(const width of [390,1280]){
  const page=await browser.newPage({viewport:{width,height:900}});
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  let saved=null,reserve=null,chatMessage=null,failReserve=false;
  await page.route('**/*',async route=>{
   const url=new URL(route.request().url());
   if(url.hostname==='www.gstatic.com'){
    const body=url.pathname.endsWith('firebase-app.js')?'export const initializeApp=()=>({});':url.pathname.endsWith('firebase-auth.js')?`export const getAuth=()=>({});export const onAuthStateChanged=(auth,fn)=>fn({uid:'test-user',email:'test@example.com',getIdToken:async()=> 'fake-token'});`:`export const getDatabase=()=>({});export const ref=(db,path)=>path;export const query=(ref)=>ref;export const limitToLast=()=>100;export const onValue=(ref,fn)=>{fn({val:()=>({one:{sender:'test-user',text:'Olá, proprietário!',createdAt:1}})});return ()=>{}};export const serverTimestamp=()=>1;export const push=async(ref,data)=>{window.testMessage=data};`;
    return route.fulfill({contentType:'text/javascript',body});
   }
   if(url.origin!==origin)return route.abort();
   if(url.pathname==='/config.php')return route.fulfill({json:{firebase:{apiKey:'test-key',projectId:'test',databaseURL:'https://example.test'}}});
   if(url.pathname==='/api.php'){
    const action=url.searchParams.get('action');
    if(action==='spaces')return route.fulfill({json:[space]});
    if(action==='my-spaces')return route.fulfill({json:[space]});
    if(action==='host-reservations')return route.fulfill({json:[]});
    if(action==='reservations')return route.fulfill({json:[{id:'abcdef1234567890abcdef1234567890',name:space.name,check_in:'2030-01-04 09:00:00',check_out:'2030-01-05 09:00:00',total:'1800.00',status:'approved'}]});
    if(action==='upload-space-photo')return route.fulfill({json:{url:uploadUrl},status:201});
    if(action==='delete-space-photo')return route.fulfill({json:{deleted:true}});
    if(action==='save-space'){saved=route.request().postDataJSON();return route.fulfill({json:space,status:201})}
    if(action==='reserve'){reserve=route.request().postDataJSON();return route.fulfill({status:failReserve?503:409,json:{error:failReserve?'O período foi mantido reservado para conferência.':'Há uma solicitação ativa neste período.'}})}
    return route.fulfill({json:{name:'Pessoa de teste',phone:'16999999999'}});
   }
   return route.continue();
  });
  await page.goto(origin);
  await page.locator('#count').filter({hasText:'1 espaço encontrado'}).waitFor();checks++;
  await page.locator('#list-space').click();await page.locator('#new-space').click();
  assert.equal(await page.locator('#space-photos').getAttribute('type'),'file');checks++;
  await page.locator('#space-name').fill('Chácara de teste');await page.locator('#space-city').fill('Ribeirão Preto');await page.locator('#space-address').fill('Rua de teste, 1');await page.locator('#space-type').selectOption({label:'Salão'});await page.locator('#space-capacity').fill('150');await page.locator('#space-description').fill('Espaço para eventos de teste');await page.locator('#space-price').fill('1800.50');await page.locator('#space-in').fill('09:00');await page.locator('#space-out').fill('09:00');await page.locator('[data-space-event]').first().check();
  await page.locator('#space-photos').setInputFiles({name:'space.jpg',mimeType:'image/jpeg',buffer:imageBuffer});await page.locator('#photo-upload-status').filter({hasText:'1 foto(s) adicionada(s)'}).waitFor();
  assert.equal(await page.locator('#space-photo-preview img').getAttribute('src'),uploadUrl);checks++;
  await page.locator('#space-owner-authorized').check();await page.locator('#save-space').click();
  await page.locator('#new-space').waitFor();
  assert.equal(saved.photos[0],uploadUrl);checks++;assert.equal(saved.daily_price,'1800.50');assert.equal(saved.weekend_price,null);assert.equal(saved.check_in_time,'09:00');assert.equal(saved.minimum_days,1);assert.equal(saved.owner_authorized,true);assert.equal(saved.active,false);assert.ok(!('price' in saved));checks+=7;
  await page.locator('#modal .close').click();await page.locator('[data-detail="1"]').click();
  assert.equal(await page.locator('#modal h2').textContent(),space.name);checks++;
  await page.getByRole('button',{name:'Ver foto 2',exact:true}).click();assert.equal(await page.locator('#gallery-main').getAttribute('src'),'https://example.test/second.jpg');assert.equal(await page.locator('#gallery-counter').textContent(),'Foto 2 de 2');checks+=2;
  await page.locator('#request-reservation').click();
  await page.locator('#check-in').fill('2030-01-04T09:00');await page.locator('#check-out').fill('2030-01-05T09:00');await page.locator('#contact-name').fill('Pessoa de teste');await page.locator('#guests').fill('100');await page.locator('#phone').fill('(16) 99999-9999');await page.locator('#message').fill('Festa de aniversário de teste');await page.locator('#reservation-form input[type=checkbox]').check();
  await page.locator('#reservation-form button[type=submit]').click();await page.getByRole('alert').filter({hasText:'Há uma solicitação ativa'}).waitFor();
  assert.equal(reserve.space_id,1);assert.equal(reserve.terms_accepted,true);checks++;
  failReserve=true;await page.locator('#reservation-form button[type=submit]').click();await page.getByRole('alert').filter({hasText:'mantido reservado'}).waitFor();checks++;
  await page.locator('#modal .close').click();await page.locator('nav [data-page=reservas]').click();
  await page.getByText('Pagamento aprovado',{exact:true}).waitFor();checks++;
  await page.locator('[data-chat]').click();await page.getByText('Olá, proprietário!',{exact:true}).waitFor();
  await page.locator('#chat-text').fill('Posso visitar antes?');await page.locator('#chat-form button').click();
  await page.waitForFunction(()=>window.testMessage?.text==='Posso visitar antes?');chatMessage=await page.evaluate(()=>window.testMessage);assert.equal(chatMessage.sender,'test-user');checks++;
  assert.deepEqual(errors,[]);checks++;
  assert.ok(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth),`Sem rolagem horizontal em ${width}px`);checks++;
  await page.close();
 }
 console.log(`${checks} verificações em navegador passaram em celular e desktop (Firebase/API simulados).`);
}finally{await browser.close();await new Promise(resolve=>server.close(resolve))}