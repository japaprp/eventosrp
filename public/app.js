import { localDateTime, parseBookingDate, quoteBooking, formatBookingDate } from './booking.mjs';
const staticPreview = globalThis.ENCONTRO_PREVIEW === true;
let spaces = [
 {id:1,name:'Casa Jardim',city:'São Paulo',neighborhood:'Vila Mariana',type:'Ao ar livre',events:['Casamento','Aniversário'],capacity:150,price:2800,photo:'photo-1511795409834-ef04bbd61622',description:'Um jardim acolhedor para celebrações ao ar livre, com área coberta, cozinha de apoio e mobiliário. Consulte os serviços incluídos na proposta.'},
 {id:2,name:'Espaço Aurora',city:'São Paulo',neighborhood:'Pinheiros',type:'Salão',events:['Casamento','Aniversário','Corporativo'],capacity:300,price:4500,photo:'photo-1519167758481-83f550bb49b3',description:'Salão amplo com iluminação natural, climatização e estrutura versátil para transformar a sua ideia em um evento especial.'},
 {id:3,name:'Terraço Horizonte',city:'São Paulo',neighborhood:'Vila Olímpia',type:'Rooftop',events:['Corporativo','Aniversário'],capacity:100,price:3200,photo:'photo-1514933651103-005eec06c04b',description:'Ambiente contemporâneo com área de convivência para encontros, recepções e celebrações intimistas.'},
 {id:4,name:'Estação Criativa',city:'Campinas',neighborhood:'Cambuí',type:'Corporativo',events:['Corporativo'],capacity:50,price:1200,photo:'photo-1497366754035-f200968a6e72',description:'Espaço para workshops e reuniões com Wi-Fi, projetor e mesas modulares.'},
 {id:5,name:'Recanto das Oliveiras',city:'São José dos Campos',neighborhood:'Urbanova',type:'Ao ar livre',events:['Casamento','Aniversário'],capacity:200,price:3800,photo:'photo-1464366400600-7168b8af9bc3',description:'Natureza e tranquilidade para encontros especiais, com jardim e estrutura de apoio.'},
 {id:6,name:'Salão Essência',city:'Campinas',neighborhood:'Taquaral',type:'Salão',events:['Casamento','Aniversário'],capacity:200,price:2500,photo:'photo-1519741497674-611481863552',description:'Um salão elegante e versátil para celebrar com família e amigos.'},
 {id:7,name:'Recanto Ribeirão',city:'Ribeirão Preto',neighborhood:'Ribeirão Preto',type:'Ao ar livre',events:['Casamento','Aniversário'],capacity:150,price:1800,photo:'photo-1511795409834-ef04bbd61622',description:'Espaço demonstrativo em Ribeirão Preto, com jardim e área de apoio para celebrar. Fotos ilustrativas; confirme a estrutura na proposta.'},
 {id:8,name:'Espaço Ipê',city:'Ribeirão Preto',neighborhood:'Ribeirão Preto',type:'Salão',events:['Casamento','Corporativo','Aniversário'],capacity:200,price:2300,photo:'photo-1519167758481-83f550bb49b3',description:'Salão demonstrativo em Ribeirão Preto para encontros e celebrações. Fotos ilustrativas; confirme serviços e disponibilidade na proposta.'}
];
const $ = s => document.querySelector(s), money = n => new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL',minimumFractionDigits:0,maximumFractionDigits:2}).format(n);
if($('#preview-notice'))$('#preview-notice').hidden=!staticPreview;
const escapeHtml = value => String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const photo = (s,w=800) => {
  const candidate=s.photoDetails?.find(item=>item.cover)?.url||s.photoDetails?.[0]?.url||s.photos?.[0];
  if(candidate){try{const url=new URL(candidate,location.href);if(url.protocol==='https:'||url.origin===location.origin)return url.href}catch{}}
  return `https://images.unsplash.com/${s.photo||'photo-1519167758481-83f550bb49b3'}?w=${w}&auto=format&fit=crop&q=85`;
};
spaces.forEach(s=>Object.assign(s,{isDemo:true,checkInTime:'09:00',checkOutTime:'09:00',minimumDays:1,cleanupMinutes:0,photos:[],amenities:[],rules:''}));
let profile={name:'',phone:''};
let type='', user=null, auth=null, db=null, sdk=null, unsubscribeChat=null, config={}, availability=null, firebaseStatus='loading',firebaseFailureDetail='';
let compared=[];let availabilityRequest=0;
let favorites;try{favorites=JSON.parse(localStorage.getItem('encontro-favorites')||'[]')}catch{favorites=[]}if(!Array.isArray(favorites))favorites=[];
function toast(message){$('#toast').textContent=message;$('#toast').style.display='block';setTimeout(()=>$('#toast').style.display='none',3500)}
function firebaseUnavailableMessage(){if(firebaseStatus==='loading')return 'Conectando ao Firebase. Aguarde alguns segundos e tente novamente.';if(firebaseStatus==='missing')return 'Firebase ainda não configurado.';return 'Não foi possível carregar o Firebase. Diagnóstico: '+(firebaseFailureDetail||'erro sem detalhes')+'.'}
function firebaseErrorDetail(error){const parts=[];if(typeof error?.code==='string')parts.push(error.code);else if(typeof error?.name==='string')parts.push(error.name);if(typeof error?.message==='string')parts.push(error.message);return parts.join(': ').replace(/AIza[0-9A-Za-z_-]{20,}/g,'[chave oculta]').slice(0,220)||'erro sem detalhes'}
function setAuthenticatedUser(nextUser){user=nextUser;const button=$('#login-button');if(!button)return;button.textContent=user?'Minha conta ✓':'Entrar ↗';button.classList.toggle('is-authenticated',Boolean(user));button.setAttribute('aria-label',user?'Minha conta, sessão conectada':'Entrar na sua conta');button.title=user?'Sua sessão está ativa':''}
function hasSearchRange(){return Boolean($('#check-in-date').value&&$('#check-out-date').value)}
function searchQuotePrice(space){return Number(availability?.quotes?.[space.id]?.total??($('#search-plan').value==='weekend'?(space.weekendPrice??space.price*2):space.price))}
function render(){
 const maxPrice=Number($('#max-price').value),amenities=[...document.querySelectorAll('[data-filter-amenity]:checked')].map(input=>input.value),sort=$('#sort').value,hasRange=hasSearchRange(),quotedRange=hasRange&&availability?.checkIn===$('#check-in-date').value&&availability?.checkOut===$('#check-out-date').value&&availability?.plan===$('#search-plan').value;
 let list=spaces.filter(s=>(!type||s.type===type)&&(!$('#location').value||s.city===$('#location').value)&&(!$('#event').value||s.events.includes($('#event').value))&&s.capacity>=Number($('#capacity').value)&&((hasRange&&!quotedRange)||maxPrice>=60000||searchQuotePrice(s)<=maxPrice)&&amenities.every(amenity=>s.amenities?.includes(amenity))&&(!hasRange||!quotedRange||availability.ids.includes(s.id)));
 if(sort==='price')list.sort((a,b)=>searchQuotePrice(a)-searchQuotePrice(b));else if(sort==='capacity')list.sort((a,b)=>b.capacity-a.capacity);else if(sort==='newest')list.sort((a,b)=>b.id-a.id);
 $('#count').textContent=`${list.length} espaços encontrados`;$('#empty').hidden=!!list.length;
 $('#price-unit').textContent=quotedRange?'total estimado':$('#search-plan').value==='weekend'?'por pacote (48h)':'por diária';
 const filters=[['location',$('#location').value],['event',$('#event').value],['capacity',$('#capacity').value?`Mínimo de ${$('#capacity').value} convidados`:'' ],['max-price',maxPrice<60000?`Até ${money(maxPrice)}`:'' ],['date-range',hasRange?`${$('#check-in-date').value} a ${$('#check-out-date').value} · ${$('#search-plan').value==='weekend'?'Fim de semana':'Diárias'}`:'' ],['type',type]];
 $('#max-price-value').textContent=`Até ${money(maxPrice)}`;
 $('#amenity-filter-count').textContent=amenities.length?`(${amenities.length})`:'';
 $('#active-filters').innerHTML=filters.filter(([,value])=>value).map(([key,value])=>`<button type="button" class="filter-chip" data-remove-filter="${key}">${escapeHtml(value)} <span aria-hidden="true">×</span></button>`).join('')+amenities.map(value=>`<button type="button" class="filter-chip" data-remove-amenity="${escapeHtml(value)}">${escapeHtml(value)} <span aria-hidden="true">×</span></button>`).join('');
 $('#cards').classList.toggle('single-result',list.length===1);
 $('#cards').innerHTML=list.map(s=>{const estimate=quotedRange?availability?.quotes?.[s.id]:null,price=estimate?estimate.total:searchQuotePrice(s),priceLabel=estimate?`/ total estimado · ${estimate.days} diárias`:$('#search-plan').value==='weekend'?'/ pacote a partir de 48h':'/ diária',photoCount=s.photoDetails?.length||s.photos?.length||1;return `<article class="card"><div class="card-photo"><button type="button" class="photo-open" data-detail="${s.id}" aria-label="Ver ${photoCount} ${photoCount===1?'foto':'fotos'} de ${escapeHtml(s.name)}"><img src="${escapeHtml(photo(s))}" alt="${escapeHtml(s.name)}" loading="lazy" decoding="async"><span class="photo-prompt">Ver ${photoCount===1?'espaço':`fotos · ${photoCount}`} ↗</span></button><span class="badge">${escapeHtml(s.type)}</span><button class="favorite ${favorites.includes(s.id)?'saved':''}" data-favorite="${s.id}" aria-label="Favoritar ${escapeHtml(s.name)}" aria-pressed="${favorites.includes(s.id)}">${favorites.includes(s.id)?'♥':'♡'}</button></div><div class="card-body"><div class="card-title"><h3>${escapeHtml(s.name)}</h3><span class="rating">Novo no catálogo</span></div><p class="place">⌖ ${escapeHtml(s.neighborhood)}, ${escapeHtml(s.city)}</p>${s.description?`<p class="card-description">${escapeHtml(s.description)}</p>`:''}<div class="features"><span>♙ Até ${s.capacity} pessoas</span><span>◇ ${s.events[0]}</span></div><div class="card-bottom"><div class="price">${money(price)} <small>${priceLabel}</small></div><button class="detail-button" data-detail="${s.id}">Ver espaço ↗</button></div><div class="card-actions"><button class="detail-button" data-compare="${s.id}" aria-pressed="${compared.includes(s.id)}">${compared.includes(s.id)?'✓ Comparando':'⇄ Comparar'}</button><button class="detail-button" data-share="${s.id}">↗ Compartilhar</button></div></div></article>`}).join('');
 $('#compare-bar').hidden=compared.length===0;$('#compare-count').textContent=`${compared.length} de 3 espaços selecionados`;
}
async function checkAvailability(){
 const checkIn=$('#check-in-date').value,checkOut=$('#check-out-date').value,plan=$('#search-plan').value,request=++availabilityRequest;availability=null;
 if(!checkIn&&!checkOut){$('#availability-notice').hidden=true;render();return}
 if(!checkIn||!checkOut){$('#availability-notice').hidden=false;$('#availability-notice').textContent='Informe as datas de entrada e saída para consultar disponibilidade e o total estimado.';render();return}
 if(checkOut<=checkIn){$('#availability-notice').hidden=false;$('#availability-notice').textContent='A saída deve ser depois da entrada.';render();return}
 if(plan==='weekend'&&(![5,6].includes(new Date(`${checkIn}T12:00:00Z`).getUTCDay())||(new Date(`${checkOut}T12:00:00Z`)-new Date(`${checkIn}T12:00:00Z`))<172800000)){ $('#availability-notice').hidden=false;$('#availability-notice').textContent='O pacote de fim de semana exige entrada na sexta ou no sábado e pelo menos 48 horas.';render();return}
 const days=(new Date(`${checkOut}T12:00:00Z`)-new Date(`${checkIn}T12:00:00Z`))/86400000;
 if(days>30){$('#availability-notice').hidden=false;$('#availability-notice').textContent='Para períodos acima de 30 diárias, consulte o atendimento.';render();return}
 if(staticPreview){$('#availability-notice').hidden=false;$('#availability-notice').textContent='Este catálogo é demonstrativo. As datas não confirmam disponibilidade; reservas e pagamentos serão liberados após ativação dos serviços.';render();return}
 $('#availability-notice').hidden=false;$('#availability-notice').textContent='Consultando disponibilidade e valor total para o período escolhido…';render();
 try{const params=new URLSearchParams({check_in:checkIn,check_out:checkOut,plan});const response=await fetch(`api.php?action=availability&${params}`);const result=await response.json();if(!response.ok)throw new Error(result.error||'Não foi possível consultar a disponibilidade.');
  if(request!==availabilityRequest)return;availability={checkIn,checkOut,plan,ids:result.spaces.map(s=>Number(s.id)),quotes:Object.fromEntries(result.spaces.map(s=>[Number(s.id),s]))};$('#availability-notice').textContent=`${result.spaces.length} espaço(s) disponível(is) para ${new Date(checkIn+'T12:00:00').toLocaleDateString('pt-BR')} a ${new Date(checkOut+'T12:00:00').toLocaleDateString('pt-BR')}. O valor é estimado e será confirmado antes da reserva.`;render();
 }catch(error){if(request!==availabilityRequest)return;availability=null;$('#availability-notice').textContent=`Não foi possível confirmar as datas agora. ${error.message||'Tente novamente; a disponibilidade será verificada na reserva.'}`;render()}
}
function toggleCompare(id){if(compared.includes(id))compared=compared.filter(x=>x!==id);else if(compared.length>=3){toast('Compare até três espaços por vez.');return}else compared.push(id);render()}
function appendAcceptedTerms(container,snapshot){
 if(!snapshot)return;
 const details=document.createElement('details'),summary=document.createElement('summary');summary.textContent='Ver condições registradas no pedido';details.append(summary);
 try{
  const terms=JSON.parse(snapshot),entries=[
   `Espaço: ${terms.space?.name||'—'}`,
   `Entrada: ${terms.period?.check_in||'—'} · saída: ${terms.period?.check_out||'—'} · ${terms.period?.billable_days||'—'} diária(s)`,
   `Convidados: ${terms.guests??'—'} · total informado: ${terms.total?money(Number(terms.total)):'—'}`,
   `Regras do anfitrião: ${terms.host_rules||'Nenhuma regra adicional informada.'}`,
   terms.platform_role||'Condições sobre a intermediação não disponíveis.'
  ];
  for(const entry of entries){const paragraph=document.createElement('p');paragraph.textContent=entry;details.append(paragraph)}
 }catch{const paragraph=document.createElement('p');paragraph.textContent='Não foi possível ler este registro de condições. Entre em contato com o suporte.';details.append(paragraph)}
 container.append(details);
}
function buildReservationReceipt(reservation){
 const terms=JSON.parse(reservation.terms_snapshot||'{}');
 const period=terms.period||{},space=terms.space||{};
 const status=({approved:'Pagamento confirmado',pending:'Aguardando pagamento',creating:'Solicitação em processamento',failed:'Pagamento não concluído',cancelled:'Cancelada'})[reservation.status]||reservation.status||'Status não informado';
 const row=(label,value)=>`<tr><th>${escapeHtml(label)}</th><td>${escapeHtml(value||'Não informado')}</td></tr>`;
 const location=[space.neighborhood,space.city].filter(Boolean).join(', ');
 const plan=period.plan==='weekend'?'Pacote de fim de semana':'Diárias';
 return `<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Comprovante da solicitação ${escapeHtml(reservation.id)}</title><style>body{font:16px Arial,sans-serif;color:#24372f;max-width:760px;margin:36px auto;padding:0 20px;line-height:1.55}h1{font-size:25px}h2{font-size:17px;margin-top:28px}table{width:100%;border-collapse:collapse}th,td{text-align:left;vertical-align:top;padding:10px;border-bottom:1px solid #ddd}th{width:35%}.notice{margin-top:24px;padding:14px;background:#f4f5f0;font-size:13px}.print{padding:11px 18px;background:#254e40;color:white;border:0;border-radius:5px;cursor:pointer}@media print{body{margin:0 auto}.print{display:none}}</style></head><body><button class="print" onclick="window.print()">Imprimir comprovante</button><h1>Comprovante de solicitação de reserva</h1><p>Encontro · protocolo ${escapeHtml(reservation.id)}</p><table><tbody>${row('Status',status)}${row('Espaço',space.name)}${row('Localização',location)}${row('Cliente',terms.customer_name)}${row('Entrada',formatBookingDate(period.check_in))}${row('Saída',formatBookingDate(period.check_out))}${row('Modalidade',plan)}${row('Diárias',String(period.billable_days??''))}${row('Convidados',String(terms.guests??''))}${row('Total estimado',terms.total?money(Number(terms.total)):'')}${row('Aceite registrado em',formatBookingDate(terms.accepted_at))}</tbody></table><h2>Regras do anfitrião registradas</h2><p>${escapeHtml(terms.host_rules||'Nenhuma regra adicional informada.')}</p><h2>Condições da intermediação</h2><p>${escapeHtml(terms.platform_role||'Condições da intermediação não disponíveis.')}</p><p class="notice">Este comprovante resume os dados e as condições registrados no pedido. Não comprova, por si só, a aprovação do pagamento ou a conclusão da locação, e não substitui contrato completo nem revisão jurídica. Confirme o status na sua conta Encontro.</p></body></html>`;
}
function downloadReservationReceipt(reservation){
 if(!reservation.terms_snapshot){toast('Esta reserva é anterior ao registro digital das condições e não tem comprovante disponível.');return}
 let html;try{html=buildReservationReceipt(reservation)}catch{toast('Não foi possível preparar o comprovante desta reserva.');return}
 const blob=new Blob([html],{type:'text/html;charset=utf-8'}),url=URL.createObjectURL(blob),link=document.createElement('a');
 link.href=url;link.download=`comprovante-encontro-${String(reservation.id).replace(/[^a-zA-Z0-9-]/g,'')}.html`;document.body.append(link);link.click();link.remove();setTimeout(()=>URL.revokeObjectURL(url),1000);
}
function compareSpaces(){const items=compared.map(id=>spaces.find(s=>s.id===id)).filter(Boolean);if(items.length<2){toast('Selecione pelo menos dois espaços para comparar.');return}
 const fields=[['Preço da diária',s=>money(s.price)],['Preço do pacote de fim de semana',s=>money(s.weekendPrice??s.price*2)],['Localização',s=>`${s.neighborhood}, ${s.city}`],['Capacidade',s=>`${s.capacity} pessoas`],['Tipo',s=>s.type],['Eventos',s=>s.events.join(', ')],['Comodidades',s=>s.amenities?.join(', ')||'Consulte o responsável.'],['Horários',s=>`Entrada ${s.checkInTime} · saída ${s.checkOutTime}`]];
 modal(`<h2>Compare seus espaços.</h2><div class="comparison"><table><thead><tr><th></th>${items.map(s=>`<th>${escapeHtml(s.name)}</th>`).join('')}</tr></thead><tbody>${fields.map(([label,value])=>`<tr><th>${label}</th>${items.map(s=>`<td>${escapeHtml(value(s))}</td>`).join('')}</tr>`).join('')}</tbody></table></div>`);
}
async function shareSpace(id){try{const url=new URL(location.href);url.searchParams.set('space',id);if(globalThis.navigator?.share)await globalThis.navigator.share({title:'Espaço no Encontro',url:url.href});else if(globalThis.navigator?.clipboard){await globalThis.navigator.clipboard.writeText(url.href);toast('Link do espaço copiado.')}else throw new Error('Compartilhamento não disponível neste navegador.')}catch(error){if(error.name!=='AbortError')toast(error.message||'Não foi possível compartilhar este espaço.')}}
for(const selector of ['#location','#event','#capacity','#type-filter','#sort'])$(selector).onchange=()=>{if(selector==='#type-filter')type=$('#type-filter').value;render()};
$('#max-price').oninput=render;$('#max-price').onchange=render;
$('#search-panel').onchange=e=>{if(e.target.matches('[data-filter-amenity]'))render()};
function resetAvailability(){availabilityRequest++;availability=null;if(hasSearchRange()){$('#availability-notice').hidden=false;$('#availability-notice').textContent='Selecione “Buscar espaços” para consultar as novas datas e o valor total.'}else $('#availability-notice').hidden=true;render()}
for(const selector of ['#check-in-date','#check-out-date','#search-plan'])$(selector).onchange=resetAvailability;
$('#check-in-date').min=localDateTime().slice(0,10);$('#check-out-date').min=localDateTime().slice(0,10);
function updateCheckOutMinimum(){const start=$('#check-in-date').value;if(!start){$('#check-out-date').min=localDateTime().slice(0,10);return}const earliest=new Date(`${start}T12:00:00Z`);earliest.setUTCDate(earliest.getUTCDate()+($('#search-plan').value==='weekend'?2:1));$('#check-out-date').min=earliest.toISOString().slice(0,10)}
$('#check-in-date').onchange=()=>{updateCheckOutMinimum();resetAvailability()};
$('#search-plan').onchange=()=>{updateCheckOutMinimum();resetAvailability()};
$('#clear-filters').onclick=()=>{for(const selector of ['#location','#event','#capacity','#type-filter','#check-in-date','#check-out-date'])$(selector).value='';$('#max-price').value='60000';$('#search-plan').value='daily';document.querySelectorAll('[data-filter-amenity]').forEach(input=>input.checked=false);type='';availability=null;updateCheckOutMinimum();checkAvailability()};
$('#active-filters').onclick=e=>{const amenity=e.target.closest('[data-remove-amenity]');if(amenity){document.querySelectorAll('[data-filter-amenity]').forEach(input=>{if(input.value===amenity.dataset.removeAmenity)input.checked=false});render();return}const button=e.target.closest('[data-remove-filter]');if(!button)return;const key=button.dataset.removeFilter;if(key==='type'){type='';$('#type-filter').value=''}else if(key==='date-range'){ $('#check-in-date').value='';$('#check-out-date').value='';$('#search-plan').value='daily';availability=null;updateCheckOutMinimum();checkAvailability();return}else if(key==='max-price')$('#max-price').value='60000';else $('#'+key).value='';render()};
$('#compare-open').onclick=compareSpaces;$('#compare-clear').onclick=()=>{compared=[];render()};
function modal(html){if(unsubscribeChat){unsubscribeChat();unsubscribeChat=null}$('#modal-content').innerHTML=html;if(!$('#modal').open)$('#modal').showModal()}
$('#modal .close').onclick=()=>$('#modal').close();$('#modal').addEventListener('close',()=>{unsubscribeChat?.();unsubscribeChat=null});$('#modal').addEventListener('click',e=>{if(e.target===$('#modal')){const r=$('#modal').getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)$('#modal').close()}});
$('#search').onclick=()=>{$('#espacos').scrollIntoView({behavior:'smooth'});checkAvailability()};
$('#cards').onclick=e=>{const f=e.target.closest('[data-favorite]'),d=e.target.closest('[data-detail]'),c=e.target.closest('[data-compare]'),s=e.target.closest('[data-share]');if(f){const id=Number(f.dataset.favorite);favorites=favorites.includes(id)?favorites.filter(x=>x!==id):[...favorites,id];try{localStorage.setItem('encontro-favorites',JSON.stringify(favorites))}catch{}render()}if(d)detail(Number(d.dataset.detail));if(c)toggleCompare(Number(c.dataset.compare));if(s)shareSpace(Number(s.dataset.share))};
function detail(id){
  const s=spaces.find(item=>item.id===id),details=s.photoDetails?.length?s.photoDetails:s.photos?.map((url,index)=>({url,description:'',cover:index===0}))||[],cover=details.find(item=>item.cover)||details[0],otherPhotos=details.filter(item=>!item.cover);
  modal(`<img class="detail-image" src="${escapeHtml(photo(s,1200))}" alt="Foto principal de ${escapeHtml(s.name)}">${cover?.description?`<p class="photo-caption">${escapeHtml(cover.description)}</p>`:''}${otherPhotos.length?`<p class="photo-caption">Mais fotos do espaço</p><div class="photo-gallery" aria-label="Outras fotos do espaço">${otherPhotos.map(item=>`<figure><img src="${escapeHtml(item.url)}" alt="${escapeHtml(item.description||'Foto adicional do espaço')}" loading="lazy">${item.description?`<figcaption>${escapeHtml(item.description)}</figcaption>`:''}</figure>`).join('')}</div>`:''}<span class="eyebrow" style="margin-top:20px">${escapeHtml(s.type)} · ${escapeHtml(s.city)}</span><h2>${escapeHtml(s.name)}</h2><p>${escapeHtml(s.description)}</p>${s.address?'<p>Endereço: '+escapeHtml(s.address)+'</p>':''}<p>Até ${s.capacity} convidados · ${escapeHtml(s.events.join(' / '))}</p><p><strong>${money(s.price)} / diária de até 24 horas</strong><br>Pacote de fim de semana: <strong>${money(s.weekendPrice??s.price*2)} / 48 horas</strong>. Entrada na sexta ou no sábado; dias extras pela diária do espaço.</p><p>Entrada: <strong>${escapeHtml(s.checkInTime)}</strong> · Saída: <strong>${escapeHtml(s.checkOutTime)}</strong> (Brasília)<br>Mínimo: ${s.minimumDays} diária(s) · Preparação entre reservas: ${s.cleanupMinutes} minutos</p><p>${escapeHtml(s.amenities?.join(' · ')||'O anfitrião ainda não informou as comodidades deste espaço.')}</p><p style="white-space:pre-line">${escapeHtml(s.rules||'Consulte as regras do local antes de reservar.')}</p>${s.isDemo?'<p class="notice">Fotos ilustrativas e espaço de demonstração. Não há avaliações publicadas.</p>':''}<button class="primary" id="reserve">Consultar e reservar ↗</button>`);
  $('#reserve').onclick=()=>user?reservation(s):login(()=>reservation(s));
}
function login(after) {
  if(staticPreview){modal('<h2>Conheça o Encontro</h2><p>Você pode explorar os espaços, filtrar por localização, comparar opções e salvar seus favoritos neste navegador.</p><p>Este catálogo é demonstrativo. Cadastro, reservas, conversa com anfitriões e pagamentos serão liberados após ativação dos serviços.</p>');return}
  if(user){account('painel');return}
  modal(`<span class="eyebrow">BEM-VINDO AO ENCONTRO</span><h2 id="auth-title">Entre para reservar.</h2><p id="auth-description">Acesse com seu e-mail e senha.</p>
    <form class="form" id="auth-form">
      <div id="signup-fields" hidden class="form-fields">
        <label>Seu nome<input id="signup-name" autocomplete="name" minlength="2" maxlength="120" disabled></label>
        <label>Celular com DDD<input type="tel" id="signup-phone" autocomplete="tel" maxlength="25" placeholder="(16) 99999-9999" disabled></label>
      </div>
      <label>E-mail<input type="email" id="email" autocomplete="email" required></label>
      <label>Senha<input type="password" id="password" autocomplete="current-password" minlength="6" required></label>
      <label id="signup-consent" hidden><span><input type="checkbox" id="accept-account" disabled style="width:auto"> Li os termos de uso e a política de privacidade disponíveis no rodapé.</span></label>
      <div class="form-error" role="alert"></div><button class="primary" type="submit" id="auth-submit">Entrar</button>
      <button type="button" class="login" id="signup">Criar minha conta</button>
      <button type="button" class="detail-button" id="reset">Esqueci minha senha</button>
      <button type="button" class="login" id="google-signin">Entrar com Google</button>
    </form><p id="firebase-status" class="notice" ${firebaseStatus==='ready'?'hidden':''}>${escapeHtml(firebaseUnavailableMessage())}</p><p class="notice">Use a mesma conta para reservar espaços e acessar a Área do anfitrião. As fotos dos espaços são adicionadas apenas no cadastro de anfitrião; quem reserva pode manter uma foto de perfil.</p>`);
  let creating=false;
  $('#signup').onclick=()=>{
    creating=!creating;$('#signup-fields').hidden=!creating;$('#signup-consent').hidden=!creating;
    for(const selector of ['#signup-name','#signup-phone','#accept-account']){$(selector).disabled=!creating;$(selector).required=creating}
    $('#auth-title').textContent=creating?'Cadastro rápido.':'Entre para reservar.';
    $('#auth-description').textContent=creating?'Só precisamos do seu nome, celular, e-mail e senha.':'Acesse com seu e-mail e senha.';
    $('#auth-submit').textContent=creating?'Criar conta e continuar':'Entrar';
    $('#signup').textContent=creating?'Já tenho conta':'Criar minha conta';$('#reset').hidden=creating;
    $('#password').autocomplete=creating?'new-password':'current-password';$('.form-error').textContent='';
  };
  $('#auth-form').onsubmit=async e=>{
    e.preventDefault();const error=$('.form-error'),button=$('#auth-submit');
    if(!auth){error.textContent=firebaseUnavailableMessage();return}
    const name=creating?$('#signup-name').value.trim():'',phone=creating?$('#signup-phone').value.trim():'';
    if(creating&&(name.length<2||!validPhone(phone))){error.textContent='Informe seu nome e um celular válido com DDD.';return}
    button.disabled=true;$('#signup').disabled=true;$('#google-signin').disabled=true;
    try{
      const result=await (creating?sdk.createUserWithEmailAndPassword:sdk.signInWithEmailAndPassword)(auth,$('#email').value.trim(),$('#password').value);setAuthenticatedUser(result.user);
      if(creating){
        profile={name,phone};
        try{await sdk.updateProfile(user,{displayName:name});await api('profile',{method:'POST',body:JSON.stringify(profile)})}
        catch{toast('Conta criada. Confirme seus dados de contato na reserva.')}
      }else{try{profile=await api('profile')}catch{profile={name:user.displayName||'',phone:''}}}
      $('#modal').close();toast(creating?'Conta criada. Vamos planejar seu evento!':'Você entrou na sua conta.');after?.();
    }catch(e){
      const messages={'auth/email-already-in-use':'Este e-mail já tem uma conta. Use Entrar ou recupere a senha.','auth/weak-password':'Escolha uma senha mais forte.','auth/invalid-email':'Confira o endereço de e-mail.','auth/too-many-requests':'Muitas tentativas. Aguarde e tente novamente.'};
      error.textContent=messages[e.code]||'Não foi possível acessar. Verifique os dados ou tente novamente.';button.disabled=false;$('#signup').disabled=false;$('#google-signin').disabled=false;
    }
  };
  $('#google-signin').onclick=async()=>{
    const error=$('.form-error'),button=$('#google-signin');
    if(!auth){error.textContent=firebaseUnavailableMessage();return}
    button.disabled=true;
    try{const provider=new sdk.GoogleAuthProvider(),result=await sdk.signInWithPopup(auth,provider);setAuthenticatedUser(result.user);profile={name:result.user.displayName||'',phone:''};try{await api('profile',{method:'POST',body:JSON.stringify(profile)})}catch{toast('Conectado via Google. Complete seus dados de contato na reserva.')}$('#modal').close();toast('Bem-vindo '+(result.user.displayName||'')+'!');after?.()}
    catch(e){const messages={'auth/popup-closed-by-user':'A janela de login foi fechada antes de concluir.','auth/popup-blocked':'O navegador bloqueou a janela do Google. Permita pop-ups para este site e tente novamente.','auth/account-exists-with-different-credential':'Este e-mail já está cadastrado com outro método de login.','auth/unauthorized-domain':'Este domínio não está autorizado no Firebase Authentication.','auth/operation-not-allowed':'O provedor Google está desativado no Firebase Authentication.','auth/network-request-failed':'Falha de rede ao falar com o Firebase. Verifique a conexão e tente novamente.'};error.textContent=messages[e.code]||'Falha ao entrar com Google ('+firebaseErrorDetail(e)+').';button.disabled=false}
  };
  $('#reset').onclick=()=>passwordReset($('#email').value.trim());
}
function validPhone(value){return /^(?:55)?[1-9][0-9][0-9]{8,9}$/.test(value.replace(/\D/g,''))}

async function api(path,options={}){if(staticPreview)throw new Error('Cadastro, reservas e pagamentos serão liberados após ativação dos serviços.');const token=await user.getIdToken();const r=await fetch(`api.php?action=${path}`,{...options,headers:{'Content-Type':'application/json','Authorization':`Bearer ${token}`}});let data;try{data=await r.json()}catch{throw new Error('O servidor PHP não está disponível.')}if(!r.ok)throw new Error(data.error||'Não foi possível concluir.');return data}
function reservation(s) {
  modal(`<h2>Reserve seu momento.</h2><p>${escapeHtml(s.name)} · ${money(s.price)} / diária</p>
    <form class="form" id="reservation-form">
      <label>Tipo de locação<select id="plan"><option value="daily">Diárias — um ou mais dias</option><option value="weekend">Pacote de fim de semana — 2 ou mais dias</option></select></label>
      <div class="form-row"><label>Entrada — data e horário<input type="datetime-local" id="check-in" min="${localDateTime()}" required></label><label>Saída — data e horário<input type="datetime-local" id="check-out" required></label></div>
      <p class="notice" id="period-help">Horários de Brasília. Cada diária cobre até 24 horas; frações adicionais contam como outra diária.</p>
      <div class="quote" id="quote" role="status" aria-live="polite">Selecione a entrada e a saída para calcular o período.</div>
      <label>Nome do responsável<input id="contact-name" autocomplete="name" minlength="2" maxlength="120" required></label>
      <div class="form-row"><label>Número de convidados<input type="number" id="guests" min="1" max="${s.capacity}" required></label><label>Celular com DDD<input type="tel" id="phone" autocomplete="tel" maxlength="25" placeholder="(16) 99999-9999" required></label></div>
      <label>Conte sobre seu evento<textarea id="message" maxlength="2000" required></textarea></label>
      <section class="reservation-terms" aria-labelledby="reservation-terms-title">
       <h3 id="reservation-terms-title">Condições da locação</h3>
       <h4>Regras informadas pelo anfitrião</h4>
       <p>${escapeHtml(s.rules||'O anfitrião não informou regras adicionais. Confirme por escrito quaisquer serviços, condições ou restrições antes de pagar.')}</p>
       <h4>Como funciona a intermediação</h4>
       <p>O anfitrião é responsável pelas informações do anúncio, pela autorização para locar o espaço, por informar suas regras e por prestar os serviços contratados. Você é responsável por fornecer dados corretos, respeitar a capacidade e cumprir as condições apresentadas. O Encontro facilita a divulgação e a solicitação da reserva; isso não afasta responsabilidades previstas em lei. O pagamento é processado pelo Mercado Pago.</p>
       <p>Confira as datas, os horários, a quantidade de convidados e o total estimado acima. Qualquer serviço adicional precisa ser combinado e registrado antes do pagamento.</p>
      </section>
      <label><span><input type="checkbox" id="accept-reservation-terms" required style="width:auto"> Li e aceito as regras do anfitrião e as condições desta locação para solicitar a reserva.</span></label>
      <p class="notice">Total estimado para a locação. O servidor confirma preço e disponibilidade antes do pagamento no Mercado Pago. Serviços adicionais devem ser acordados previamente.</p>
      <div class="form-error" role="alert"></div><button class="primary" type="submit">Continuar para pagamento ↗</button>
    </form>`);
  $('#contact-name').value=profile.name||user?.displayName||'';$('#phone').value=profile.phone||'';
  let changedOut=false;
  function enforceHours(){
    const start=$('#check-in').value,end=$('#check-out').value;
    if(start&&start.slice(11)!==s.checkInTime)throw new Error('A entrada deste espaço é às '+s.checkInTime+'.');
    if(end&&end.slice(11)!==s.checkOutTime)throw new Error('A saída deste espaço é às '+s.checkOutTime+'.');
  }
  $('#period-help').insertAdjacentHTML?.('beforebegin',`<p class="notice">Entrada às ${escapeHtml(s.checkInTime)} e saída às ${escapeHtml(s.checkOutTime)}, horário de Brasília. Mínimo de ${s.minimumDays} diária(s).</p>`);
  function updateQuote(){
    const plan=$('#plan').value;
    $('#period-help').textContent=plan==='weekend'?'Horários de Brasília. Entrada na sexta ou no sábado, com pelo menos 48 horas. O pacote cobre duas diárias; dias extras seguem a diária do espaço. Sem tarifa especial, o pacote custa duas diárias.':'Horários de Brasília. Cada diária cobre até 24 horas; frações adicionais contam como outra diária.';
    if(!$('#check-in').value||!$('#check-out').value){$('#quote').textContent='Selecione a entrada e a saída para calcular o período.';return}
    try{enforceHours();const q=quoteBooking($('#check-in').value,$('#check-out').value,plan,s.price,s.weekendPrice);if(q.days<s.minimumDays)throw new Error('O mínimo deste espaço é '+s.minimumDays+' diárias.');$('#quote').textContent=`${q.days} diária${q.days===1?'':'s'} · Total estimado: ${money(q.total)}`}
    catch(e){$('#quote').textContent=e.message}
  }
  function suggestOut(){
    if($('#check-in').value){
      const value=$('#check-in').value.slice(0,10)+'T'+s.checkInTime;$('#check-in').value=value;
      try{const start=parseBookingDate(value),date=new Date(start.getTime());date.setUTCDate(date.getUTCDate()+Math.max(s.minimumDays,$('#plan').value==='weekend'?2:1));let end=localDateTime(date).slice(0,10)+'T'+s.checkOutTime;if($('#plan').value==='weekend'&&parseBookingDate(end)-start<172800000){date.setUTCDate(date.getUTCDate()+1);end=localDateTime(date).slice(0,10)+'T'+s.checkOutTime}$('#check-out').min=localDateTime(new Date(start.getTime()+60000));if(!changedOut)$('#check-out').value=end}catch{}
    }updateQuote();
  }
  $('#check-in').oninput=suggestOut;$('#plan').onchange=suggestOut;$('#check-out').oninput=()=>{changedOut=true;if($('#check-out').value)$('#check-out').value=$('#check-out').value.slice(0,10)+'T'+s.checkOutTime;updateQuote()};
  if(hasSearchRange()){
    changedOut=true;$('#plan').value=$('#search-plan').value;
    $('#check-in').value=$('#check-in-date').value+'T'+s.checkInTime;
    $('#check-out').value=$('#check-out-date').value+'T'+s.checkOutTime;
    updateQuote();
  }
  $('#reservation-form').onsubmit=async e=>{
    e.preventDefault();const button=e.target.querySelector('button');$('.form-error').textContent='';
    try{
      enforceHours();const quote=quoteBooking($('#check-in').value,$('#check-out').value,$('#plan').value,s.price,s.weekendPrice);
      if(quote.days<s.minimumDays)throw new Error('O mínimo deste espaço é '+s.minimumDays+' diárias.');
      if(!validPhone($('#phone').value))throw new Error('Informe um celular válido com DDD.');
      if($('#contact-name').value.trim().length<2)throw new Error('Informe o nome do responsável.');
      button.disabled=true;
      const data=await api('reserve',{method:'POST',body:JSON.stringify({space_id:s.id,check_in:$('#check-in').value,check_out:$('#check-out').value,plan:$('#plan').value,contact_name:$('#contact-name').value.trim(),guests:Number($('#guests').value),phone:$('#phone').value.trim(),message:$('#message').value,terms_accepted:$('#accept-reservation-terms').checked,terms_review:{name:String(s.name),city:String(s.city),neighborhood:String(s.neighborhood??''),rules:String(s.rules??''),total:quote.total.toFixed(2)}})});
      const target=new URL(data.checkout_url);if(target.protocol!=='https:'||!/(^|\.)mercadopago\.(com|com\.br)$/.test(target.hostname))throw new Error('Endereço de pagamento inválido.');location.href=target.href;
    }catch(err){$('.form-error').textContent=err.message;button.disabled=false}
  };
}

async function account(page){
  if(!user){login(()=>account(page));return}
  modal(`<h2>Minha conta</h2><section class="account-summary"><p><strong id="account-email"></strong></p><p id="account-role" role="status">Conferindo seus espaços…</p></section><h3>Seus dados</h3><form class="form" id="account-profile"><label>Nome completo<input id="account-name" autocomplete="name" minlength="2" maxlength="120" required></label><label>Celular com DDD<input id="account-phone" type="tel" autocomplete="tel" maxlength="25" required></label><div class="form-error" id="account-profile-error" role="alert"></div><button class="primary" type="submit" id="save-account-profile">Salvar meus dados</button></form><section class="profile-settings"><img id="profile-photo" alt="Sua foto de perfil" hidden><label>Foto de perfil (opcional; uma foto)<input id="profile-photo-input" type="file" accept="image/jpeg,image/png,image/webp"></label><small>Você pode substituir sua foto a qualquer momento.</small><span id="profile-photo-status" class="notice" hidden></span></section><h3>Minhas reservas</h3><div id="reservation-list">Carregando reservas…</div><div class="panel-actions"><button class="primary" id="manage-spaces">Meus espaços ↗</button><button class="login" id="logout">Sair da conta</button></div>`);
  $('#account-email').textContent=user.email||'E-mail não informado';
  $('#manage-spaces').onclick=()=>spaceManager();
  $('#logout').onclick=async()=>{await sdk.signOut(auth);$('#modal').close();toast('Você saiu da conta.')};
  $('#account-profile').onsubmit=async event=>{
    event.preventDefault();
    const name=$('#account-name').value.trim(),phone=$('#account-phone').value.trim(),button=$('#save-account-profile'),error=$('#account-profile-error');
    if(name.length<2){error.textContent='Informe seu nome completo.';return}
    if(!validPhone(phone)){error.textContent='Informe um celular válido com DDD.';return}
    button.disabled=true;error.textContent='';
    try{profile=await api('profile',{method:'POST',body:JSON.stringify({name,phone})});toast('Seus dados foram atualizados.')}
    catch(e){error.textContent=e.message}
    finally{button.disabled=false}
  };
  async function showProfilePhoto(){const image=$('#profile-photo');if(!image)return;try{const token=await user.getIdToken(),response=await fetch('api.php?action=profile-photo',{headers:{Authorization:`Bearer ${token}`}});if(!response.ok)return;image.src=URL.createObjectURL(await response.blob());image.hidden=false}catch{const status=$('#profile-photo-status');if(status){status.hidden=false;status.textContent='Não foi possível carregar a foto de perfil.'}}}
  showProfilePhoto();$('#profile-photo-input').onchange=async event=>{const file=event.target.files[0];if(!file)return;const status=$('#profile-photo-status');status.hidden=false;status.textContent='Preparando sua foto…';try{const compressed=await compressProfilePhoto(file),body=new FormData();body.append('photo',compressed);const token=await user.getIdToken(),response=await fetch('api.php?action=upload-profile-photo',{method:'POST',headers:{Authorization:`Bearer ${token}`},body}),result=await response.json();if(!response.ok)throw new Error(result.error||'Não foi possível salvar sua foto.');status.textContent='Foto de perfil atualizada.';await showProfilePhoto()}catch(error){status.textContent=error.message}event.target.value=''};
  const [profileResult,spacesResult,reservationsResult]=await Promise.allSettled([api('profile'),api('my-spaces'),api('reservations')]);
  if(!$('#account-profile'))return;
  if(profileResult.status==='fulfilled'){
    profile={name:profileResult.value.name||'',phone:profileResult.value.phone||''};
    $('#account-name').value=profile.name||user.displayName||'';$('#account-phone').value=profile.phone;
  }else{
    $('#account-name').value=profile.name||user.displayName||'';$('#account-phone').value=profile.phone||'';
    $('#account-profile-error').textContent='Não foi possível carregar os dados salvos. Você ainda pode tentar preencher e salvar.';
  }
  if(spacesResult.status==='fulfilled'){
    const count=spacesResult.value.length;
    $('#account-role').textContent=count?`Esta conta tem ${count} ${count===1?'espaço anunciado':'espaços anunciados'} e também pode fazer reservas.`:'Você ainda não anunciou espaços. A mesma conta pode anunciar e também fazer reservas.';
  }else $('#account-role').textContent='Não foi possível confirmar se há espaços anunciados nesta conta.';
  const list=$('#reservation-list');
  if(reservationsResult.status==='rejected'){list.textContent=reservationsResult.reason.message;return}
  const reservations=reservationsResult.value;list.textContent='';
  if(!reservations.length){list.textContent='Você ainda não tem reservas.';const explore=document.createElement('button');explore.type='button';explore.className='login';explore.textContent='Voltar ao catálogo';explore.onclick=()=>{$('#modal').close();location.hash='espacos';$('#espacos').scrollIntoView({behavior:'smooth'})};list.append(explore);return}
  for(const r of reservations){const item=document.createElement('div');item.className='reservation-item';const title=document.createElement('strong');title.textContent=r.name;const p=document.createElement('p');p.textContent=`Entrada: ${formatBookingDate(r.check_in)} · Saída: ${formatBookingDate(r.check_out)} · ${r.billable_days} diária(s) · ${r.plan==='weekend'?'Pacote de fim de semana':'Diárias'} · ${money(Number(r.total))} · ${r.status}${r.terms_accepted_at?' · Condições aceitas em '+formatBookingDate(r.terms_accepted_at):''}`;item.append(title,p);appendAcceptedTerms(item,r.terms_snapshot);if(r.terms_snapshot){const receipt=document.createElement('button');receipt.type='button';receipt.className='detail-button';receipt.textContent='Baixar comprovante / imprimir';receipt.onclick=()=>downloadReservationReceipt(r);item.append(receipt)}const chatButton=document.createElement('button');chatButton.className='detail-button';chatButton.textContent='Conversar sobre a reserva ↗';chatButton.onclick=()=>chat(r.id);item.append(chatButton);list.append(item)}
}
async function compressBrowserPhoto(file){if(!/^image\/(jpeg|png|webp)$/.test(file.type))throw new Error('Escolha uma foto JPEG, PNG ou WebP.');const bitmap=await createImageBitmap(file);const scale=Math.min(1,1600/Math.max(bitmap.width,bitmap.height));const canvas=document.createElement('canvas');canvas.width=Math.max(1,Math.round(bitmap.width*scale));canvas.height=Math.max(1,Math.round(bitmap.height*scale));const context=canvas.getContext('2d');if(!context){bitmap.close();throw new Error('Este navegador não consegue preparar a imagem.')}context.drawImage(bitmap,0,0,canvas.width,canvas.height);bitmap.close();for(const quality of [.84,.72,.6,.5]){const blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',quality));if(blob&&blob.size<=1500000)return new File([blob],'foto.jpg',{type:'image/jpeg'})}throw new Error('Não foi possível reduzir esta foto. Escolha outra imagem.')}
async function compressProfilePhoto(file){return compressBrowserPhoto(file)}
function chat(id){modal('<h2>Conversa da reserva</h2><div class="chat-messages" id="messages" aria-live="polite"></div><form class="form" id="chat-form"><label>Sua mensagem<input id="chat-text" maxlength="1000" required></label><div class="form-error" role="alert"></div><button class="primary">Enviar mensagem</button></form>');if(!db){$('#messages').textContent='O chat ficará disponível após configurar o Realtime Database.';$('#chat-form').onsubmit=e=>{e.preventDefault();$('.form-error').textContent='Chat ainda não configurado.'};return}const ref=sdk.ref(db,`chats/${id}/messages`);unsubscribeChat=sdk.onValue(sdk.query(ref,sdk.limitToLast(100)),snap=>{const box=$('#messages');if(!box)return;box.textContent='';snap.forEach(child=>{const p=document.createElement('p');p.textContent=`${child.val().sender===user.uid?'Você':'Participante'}: ${child.val().text}`;box.append(p)});box.scrollTop=box.scrollHeight},()=>{if($('#messages'))$('#messages').textContent='Não foi possível acessar esta conversa.'});$('#chat-form').onsubmit=async e=>{e.preventDefault();const text=$('#chat-text').value.trim();if(!text)return;try{await sdk.push(ref,{sender:user.uid,text,createdAt:sdk.serverTimestamp()});$('#chat-text').value=''}catch{$('.form-error').textContent='Não foi possível enviar a mensagem.'}}}
const legal={terms:['Termos de uso','Esta plataforma apresenta espaços e permite solicitar locações. O usuário deve fornecer informações corretas, respeitar a capacidade e as regras do local. O responsável pelo espaço deve informar disponibilidade, serviços, condições de cancelamento e cumprir a proposta acordada. Nenhum serviço adicional está incluído sem previsão expressa. As condições específicas, incluindo cancelamento, precisam ser informadas antes da contratação. Este texto é uma minuta e deve ser adaptado com a identificação da empresa operadora antes do lançamento.'],privacy:['Política de privacidade','Para prestar o serviço, a plataforma utiliza nome, e-mail, identificador de autenticação, telefone, foto de perfil e informações da reserva. A foto de perfil é opcional e acessível somente após autenticação. As fotos e legendas dos espaços são publicadas nos anúncios. A autenticação é processada pelo Firebase; reservas são armazenadas no MySQL; conversas, no Firebase; pagamentos, pelo Mercado Pago. A plataforma não recebe dados completos do cartão. Favoritos e rascunhos de anúncios são armazenados neste navegador. Dados pessoais devem ser acessados apenas pelo titular e pessoal autorizado. Antes do lançamento, a operadora deve publicar sua identificação, contato para exercício de direitos, prazos de retenção e bases legais aplicáveis. Esta é uma minuta de política.'],security:['Segurança','A aplicação verifica o token de autenticação no servidor e restringe as consultas de reservas ao usuário autenticado. Pagamentos acontecem no checkout do Mercado Pago. O ambiente de produção deve usar HTTPS, manter credenciais fora da pasta pública, aplicar regras de acesso no Firebase, proteger backups e restringir o acesso ao MySQL. A confirmação de pagamento consulta diretamente a API do provedor e verifica valor e reserva.']};
document.querySelectorAll('[data-legal]').forEach(b=>b.onclick=()=>{const [title,text]=legal[b.dataset.legal];modal(`<h2>${title}</h2><p>${text}</p>`)});document.querySelectorAll('[data-page]').forEach(b=>b.onclick=()=>b.dataset.page==='painel'?spaceManager():account('reservas'));$('#login-button').onclick=()=>user?account('reservas'):login();$('#list-space').onclick=()=>user?spaceForm():login(()=>spaceForm());render();
const paymentReturn=new URLSearchParams(location.search).get('payment');if(paymentReturn){toast('Consulte suas reservas para acompanhar a confirmação do pagamento.');history.replaceState(null,'',location.pathname+location.hash)}
function passwordReset(email='') {
  modal(`<h2>Recuperar minha senha.</h2><p>Informe o e-mail do cadastro para receber um link de recuperação.</p><form class="form" id="reset-form"><label>E-mail<input type="email" id="reset-email" autocomplete="email" required></label><div class="form-error" role="alert"></div><button class="primary" id="send-reset">Enviar link de recuperação</button><button type="button" class="login" id="back-login">Voltar ao login</button></form>`);
  $('#reset-email').value=email;$('#back-login').onclick=()=>login();
  $('#reset-form').onsubmit=async e=>{
    e.preventDefault();if(!auth){$('.form-error').textContent=firebaseUnavailableMessage();return}
    const button=$('#send-reset');button.disabled=true;
    try{await sdk.sendPasswordResetEmail(auth,$('#reset-email').value.trim());$('#modal').close();toast('Se houver uma conta com esse e-mail, enviaremos o link de recuperação.')}
    catch{$('.form-error').textContent='Não foi possível enviar o link. Confira o e-mail e tente novamente.';button.disabled=false}
  };
}
async function refreshCatalogue(){
  if(staticPreview)return;
  try{
    const response=await fetch('api.php?action=spaces');if(!response.ok)return;
    const data=await response.json();if(!Array.isArray(data))return;
    const samples=new Map(spaces.filter(s=>s.isDemo).map(s=>[s.id,s]));
    spaces=data.map(s=>{const demo=s.isDemo?samples.get(s.id):null;return {...demo,...s,description:s.description||demo?.description||'',neighborhood:s.neighborhood||demo?.neighborhood||'',photo:demo?.photo}});
    const selected=$('#location').value,cities=[...new Set(['Ribeirão Preto',...spaces.map(s=>s.city)])].sort((a,b)=>a.localeCompare(b,'pt-BR'));
    $('#location').innerHTML='<option value="">Todas as localizações</option>'+cities.map(c=>`<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('');$('#location').value=cities.includes(selected)?selected:'';
    $('#catalog-notice').hidden=!spaces.some(s=>s.isDemo);if(hasSearchRange())checkAvailability();else render();
  }catch{}
}
async function spaceManager(){
  if(!user){login(()=>spaceManager());return}
  modal('<h2>Área do anfitrião</h2><p>Cadastre e gerencie seus espaços e acompanhe as solicitações recebidas. A mesma conta também pode reservar espaços.</p><div class="panel-actions"><button class="primary" id="new-space">Cadastrar espaço ↗</button><button class="login" id="back-account">Minhas reservas</button></div><h3>Meus espaços</h3><div id="owned-spaces">Carregando espaços…</div><h3>Reservas recebidas</h3><div id="host-reservations">Carregando reservas…</div>');
  $('#new-space').onclick=()=>spaceForm();$('#back-account').onclick=()=>account('reservas');
  const results=await Promise.allSettled([api('my-spaces'),api('host-reservations')]);
  if(!$('#owned-spaces')||!$('#host-reservations'))return;
  const [owned,received]=results;
  if(owned.status==='rejected')$('#owned-spaces').textContent=owned.reason.message;
  else{
    const box=$('#owned-spaces');box.textContent=owned.value.length?'':'Você ainda não cadastrou espaços. Quando estiver pronto, anuncie o primeiro.';
    for(const s of owned.value){const item=document.createElement('div');item.className='reservation-item';const text=document.createElement('p');text.textContent=`${s.name} · ${s.city} · ${s.active?'Publicado':'Pausado'} · Entrada ${s.checkInTime} / saída ${s.checkOutTime}`;const button=document.createElement('button');button.className='login';button.textContent='Editar espaço';button.onclick=()=>spaceForm(s);item.append(text,button);box.append(item)}
  }
  if(received.status==='rejected')$('#host-reservations').textContent=received.reason.message;
  else{
    const box=$('#host-reservations');box.textContent=received.value.length?'':'Nenhuma reserva recebida.';
    for(const r of received.value){const item=document.createElement('div');item.className='reservation-item';const text=document.createElement('p');text.textContent=`${r.name} · ${r.contact_name} · ${r.guests} convidados · ${formatBookingDate(r.check_in)} a ${formatBookingDate(r.check_out)} · ${money(Number(r.total))} · ${r.status}${r.terms_accepted_at?' · Condições aceitas em '+formatBookingDate(r.terms_accepted_at):''}`;item.append(text);appendAcceptedTerms(item,r.terms_snapshot);if(r.terms_snapshot){const receipt=document.createElement('button');receipt.type='button';receipt.className='detail-button';receipt.textContent='Baixar comprovante / imprimir';receipt.onclick=()=>downloadReservationReceipt(r);item.append(receipt)}const button=document.createElement('button');button.className='login';button.textContent='Abrir conversa';button.onclick=()=>chat(r.id);item.append(button);box.append(item)}
  }
}

function spaceForm(space=null){
  if(!user){login(()=>spaceForm(space));return}
  const amenities=['Aceita pets','TV','Wi-Fi','Piscina','Ar-condicionado','Churrasqueira','Estacionamento','Cozinha','Acessibilidade','Área coberta'];
  modal(`<h2>${space?'Editar espaço':'Cadastre seu espaço.'}</h2><p>${space?'Revise as informações em quatro etapas. As alterações são aplicadas ao salvar.':'São quatro etapas rápidas. Seu progresso fica salvo neste navegador; publique quando estiver tudo pronto.'}</p>
  <div class="wizard-progress"><span id="space-progress-label"></span><progress id="space-progress" max="100" value="0"></progress></div>
  <div class="wizard-steps" aria-label="Etapas do cadastro"><span data-step-label="0">1. Básico</span><span data-step-label="1">2. Preço</span><span data-step-label="2">3. Detalhes</span><span data-step-label="3">4. Revisão</span></div>
  <form class="form" id="space-form" novalidate>
   <section class="host-step" data-space-step="0"><h3>Sobre o espaço</h3>
    <label>Nome do espaço<input id="space-name" minlength="2" maxlength="120" required></label>
    <div class="form-row"><label>Cidade<input id="space-city" maxlength="120" required></label><label>Bairro<input id="space-neighborhood" maxlength="120"></label></div>
    <label>Endereço público do espaço<input id="space-address" maxlength="250" required><small>Este endereço aparecerá nos detalhes do anúncio.</small></label>
    <div class="form-row"><label>Tipo<select id="space-type"><option>Salão</option><option>Ao ar livre</option><option>Corporativo</option><option>Rooftop</option></select></label><label>Capacidade máxima<input type="number" id="space-capacity" min="1" max="10000" required></label></div>
    <label>Descrição<textarea id="space-description" minlength="2" maxlength="4000" required placeholder="Descreva o espaço e o que o torna especial. Informe apenas o que realmente oferece."></textarea></label>
    <fieldset><legend>Quais eventos você aceita?</legend><div class="checkbox-grid">${['Casamento','Corporativo','Aniversário'].map(e=>`<label><input type="checkbox" data-space-event value="${e}">${e}</label>`).join('')}</div><small>Selecione pelo menos uma opção.</small></fieldset>
   </section>
   <section class="host-step" data-space-step="1" hidden><h3>Preço e horários</h3>
    <div class="form-row"><label>Valor da diária (R$)<input type="number" id="space-price" min="0.01" max="9999999.99" step="0.01" required></label><label>Pacote de fim de semana — 48h (R$)<input type="number" id="space-weekend" min="0.01" max="9999999.99" step="0.01"><small>Opcional. Sem valor, o pacote custa duas diárias; dias extras seguem a diária.</small></label></div>
    <p class="notice">Exemplo: com diária de R$ 1.000, o pacote de 48 horas custa R$ 2.000 se você não definir outra tarifa. A plataforma não cria descontos automaticamente.</p>
    <div class="form-row"><label>Horário de entrada<input type="time" id="space-in" required></label><label>Horário de saída<input type="time" id="space-out" required></label></div>
    <div class="form-row"><label>Mínimo de diárias<input type="number" id="space-minimum" min="1" max="30" required></label><label>Preparação entre reservas (minutos)<input type="number" id="space-cleanup" min="0" max="1440" required></label></div>
    <p class="notice">Cada diária cobre até 24 horas. Horários e período mínimo serão exigidos na reserva. O tempo de preparação bloqueia a entrada seguinte sem aumentar o preço.</p>
   </section>
   <section class="host-step" data-space-step="2" hidden><h3>Detalhes para seus convidados</h3>
    <fieldset><legend>Comodidades disponíveis</legend><div class="checkbox-grid">${amenities.map(a=>`<label><input type="checkbox" data-space-amenity value="${a}">${a}</label>`).join('')}</div></fieldset>
    <label>Fotos do espaço<input id="space-photos" type="file" accept="image/jpeg,image/png,image/webp" multiple><small>Envie até oito fotos pelo celular ou computador. A primeira será a principal; você pode escolher outra e escrever uma legenda curta para cada imagem. JPEG, PNG ou WebP; até 2 MB por foto.</small></label><div id="photo-upload-status" class="notice" hidden></div><div class="photo-preview" id="space-photo-preview" aria-live="polite"></div>
    <label>Regras do local e condições de cancelamento<textarea id="space-rules" maxlength="4000" placeholder="Ex.: limite de som, horários, uso da piscina, limpeza e condições de cancelamento."></textarea><small>Descreva regras e condições antes da publicação. Se não houver informação, deixe em branco.</small></label>
   </section>
   <section class="host-step" data-space-step="3" hidden><h3>Revise antes de publicar</h3>
    <p>Confira como o anúncio aparecerá para quem procura. Você pode voltar e alterar qualquer informação.</p><button type="button" class="login" id="preview-space">Ver prévia do anúncio</button><div id="space-preview" class="listing-preview" hidden></div>
    <label class="inline-check"><input type="checkbox" id="space-active"> Publicar anúncio e aceitar novas reservas</label>
    <label class="inline-check"><input type="checkbox" id="space-consent" required> Sou responsável pelo espaço e tenho autorização para publicar estas informações e imagens.</label>
    <p class="notice">Depois de publicar, você poderá editar ou pausar o anúncio no painel. As reservas e pagamentos continuam sujeitos à confirmação e às regras apresentadas aos clientes.</p>
   </section>
   <div class="form-error" role="alert"></div><div class="wizard-actions"><button type="button" class="login" id="space-previous">Voltar</button><button type="button" class="login" id="space-draft">Salvar rascunho</button><button type="button" class="primary" id="space-next">Próxima etapa</button><button type="button" class="primary" id="save-space" hidden>Publicar espaço ↗</button></div>
   <button class="detail-button" type="button" id="cancel-space">Voltar aos meus espaços</button>
  </form>`);
  const values={'name':space?.name||'','city':space?.city||'Ribeirão Preto','neighborhood':space?.neighborhood||'','address':space?.address||'','type':space?.type||'Salão','capacity':space?.capacity||'','description':space?.description||'','price':space?.price||'','weekend':space?.weekendPrice??'','in':space?.checkInTime||'09:00','out':space?.checkOutTime||'09:00','minimum':space?.minimumDays||1,'cleanup':space?.cleanupMinutes||0,'rules':space?.rules||''};
  for(const [key,value] of Object.entries(values))$('#space-'+key).value=value;
  $('#space-active').checked=space?.active??true;
  document.querySelectorAll('[data-space-event]').forEach(e=>e.checked=(space?.events||['Aniversário']).includes(e.value));
  document.querySelectorAll('[data-space-amenity]').forEach(e=>e.checked=(space?.amenities||[]).includes(e.value));
  const form=$('#space-form'),formUser=user,draftKey=space?null:`encontro-space-draft-${user.uid}`;let step=0,uploadingPhotos=false;
  const formIsCurrent=()=>$('#space-form')===form&&user?.uid===formUser.uid;
  let photos=(space?.photoDetails||space?.photos?.map((url,index)=>({url,description:'',cover:index===0}))||[]).map((item,index)=>typeof item==='string'?{url:item,description:'',cover:index===0}:{url:item.url,description:item.description||'',cover:!!item.cover});
  if(photos.length&&!photos.some(item=>item.cover))photos[0].cover=true;
  if(draftKey){try{const draft=JSON.parse(localStorage.getItem(draftKey)||'null');if(draft?.values&&typeof draft.values==='object'){for(const [id,value] of Object.entries(draft.values)){const field=$('#space-'+id);if(field)field.value=value}$('#space-active').checked=draft.active??true;document.querySelectorAll('[data-space-event]').forEach(e=>e.checked=(draft.events||[]).includes(e.value));document.querySelectorAll('[data-space-amenity]').forEach(e=>e.checked=(draft.amenities||[]).includes(e.value));if(Array.isArray(draft.photos))photos=draft.photos;step=Number.isInteger(draft.step)?Math.min(3,Math.max(0,draft.step)):0;toast('Rascunho restaurado. Continue de onde parou.')}}catch{toast('Não foi possível restaurar o rascunho salvo.')}}
  const fieldIds=['name','city','neighborhood','address','type','capacity','description','price','weekend','in','out','minimum','cleanup','rules'];
  const requiredIds=['name','city','address','capacity','description','price','in','out','minimum','cleanup'];
  function updateCompletion(){let complete=requiredIds.filter(id=>String($('#space-'+id).value??'').trim()).length+(document.querySelectorAll('[data-space-event]:checked').length?1:0)+(photos.length?1:0);const percent=Math.round(100*complete/(requiredIds.length+2));$('#space-progress').value=percent;$('#space-progress-label').textContent=`Anúncio ${percent}% completo`}
  function saveDraft(showMessage=false){if(!draftKey)return;const draft={values:Object.fromEntries(fieldIds.map(id=>[id,$('#space-'+id).value])),active:$('#space-active').checked,events:[...document.querySelectorAll('[data-space-event]:checked')].map(e=>e.value),amenities:[...document.querySelectorAll('[data-space-amenity]:checked')].map(e=>e.value),photos,step};try{localStorage.setItem(draftKey,JSON.stringify(draft));if(showMessage)toast('Rascunho salvo neste navegador.')}catch{if(showMessage)toast('Não foi possível salvar o rascunho neste navegador.')}}
  function showStep(next){step=Math.max(0,Math.min(3,next));document.querySelectorAll('[data-space-step]').forEach(section=>section.hidden=Number(section.dataset.spaceStep)!==step);document.querySelectorAll('[data-step-label]').forEach(label=>{label.classList.toggle('current',Number(label.dataset.stepLabel)===step);label.classList.toggle('complete',Number(label.dataset.stepLabel)<step)});$('#space-previous').hidden=step===0;$('#space-next').hidden=step===3;$('#save-space').hidden=step!==3;$('.form-error').textContent='';$('#modal').scrollTop=0}
  function stepIsValid(){const section=document.querySelector(`[data-space-step="${step}"]`);for(const field of section.querySelectorAll('input,textarea,select'))if(field.required&&!field.checkValidity()){field.reportValidity();return false}if(step===0&&!document.querySelector('[data-space-event]:checked')){$('.form-error').textContent='Selecione pelo menos um tipo de evento.';return false}return true}
  function setPhotoUploadBusy(busy){uploadingPhotos=busy;for(const selector of ['#space-photos','#space-previous','#space-next','#space-draft','#preview-space','#save-space'])$(selector).disabled=busy;$('#space-photo-preview').querySelectorAll('input,button').forEach(control=>control.disabled=busy)}
  function updatePhotoPreview(){const box=$('#space-photo-preview');box.innerHTML=photos.length?photos.map((item,index)=>`<article class="host-photo"><img src="${escapeHtml(item.url)}" alt="${escapeHtml(item.description||'Prévia da foto '+(index+1))}" loading="lazy"><label><input type="radio" name="space-cover" data-photo-cover="${index}" ${item.cover?'checked':''}> Foto principal</label><label>Legenda da foto<input type="text" maxlength="240" data-photo-description="${index}" value="${escapeHtml(item.description)}" placeholder="Ex.: salão principal decorado"></label><button type="button" class="detail-button" data-remove-photo="${index}">Remover foto</button></article>`).join(''):'<span>As fotos escolhidas aparecerão aqui. A primeira será principal.</span>';if(uploadingPhotos)box.querySelectorAll('input,button').forEach(control=>control.disabled=true)}
  async function uploadPhoto(file){const compressed=await compressBrowserPhoto(file);if(!formIsCurrent())return null;const body=new FormData();body.append('photo',compressed);const token=await formUser.getIdToken();if(!formIsCurrent())return null;const response=await fetch('api.php?action=upload-space-photo',{method:'POST',headers:{Authorization:`Bearer ${token}`},body});const result=await response.json();if(!response.ok)throw new Error(result.error||'Não foi possível enviar a foto.');return result.url}
  function previewListing(){const selectedEvents=[...document.querySelectorAll('[data-space-event]:checked')].map(e=>e.value),selectedAmenities=[...document.querySelectorAll('[data-space-amenity]:checked')].map(e=>e.value),cover=photos.find(item=>item.cover)||photos[0];$('#space-preview').hidden=false;$('#space-preview').innerHTML=`${cover?`<img src="${escapeHtml(cover.url)}" alt="Foto principal de ${escapeHtml($('#space-name').value)}">`:''}<h3>${escapeHtml($('#space-name').value||'Nome do espaço')}</h3><p>${escapeHtml($('#space-neighborhood').value)}, ${escapeHtml($('#space-city').value)}</p><p>${escapeHtml($('#space-description').value||'Adicione uma descrição para mostrar aos convidados.')}</p><strong>${escapeHtml($('#space-price').value?money(Number($('#space-price').value)):'Defina o valor')} / diária</strong><p>Até ${escapeHtml($('#space-capacity').value||'—')} pessoas · ${escapeHtml(selectedEvents.join(', ')||'Selecione os eventos')}</p><p>${escapeHtml(selectedAmenities.join(' · ')||'Nenhuma comodidade selecionada')}</p><div>${photos.map(item=>`<p>${escapeHtml(item.description)}</p>`).join('')}</div><p style="white-space:pre-line">${escapeHtml($('#space-rules').value||'Regras e cancelamento: consulte o responsável.')}</p>`}
  function handleFormChange(event){if(event.target.matches('[data-photo-description]'))photos[Number(event.target.dataset.photoDescription)].description=event.target.value;if(event.target.matches('[data-photo-cover]'))photos.forEach((item,index)=>item.cover=index===Number(event.target.dataset.photoCover));saveDraft();updateCompletion();if(event.target.matches('[data-photo-cover]'))updatePhotoPreview()}
  form.oninput=handleFormChange;form.onchange=handleFormChange;
  $('#space-photos').onchange=async event=>{
    if(uploadingPhotos||!formIsCurrent())return;
    const files=[...event.target.files];event.target.value='';if(!files.length)return;
    if(photos.length+files.length>8){$('.form-error').textContent='Você pode adicionar até oito fotos ao anúncio.';return}
    const status=$('#photo-upload-status');let uploaded=0;$('.form-error').textContent='';status.hidden=false;setPhotoUploadBusy(true);
    try{
      for(let index=0;index<files.length;index++){
        if(!formIsCurrent())return;
        status.textContent=`Preparando e enviando foto ${index+1} de ${files.length}…`;
        const url=await uploadPhoto(files[index]);if(!formIsCurrent())return;
        photos.push({url,description:'',cover:photos.length===0});uploaded++;updatePhotoPreview();saveDraft();updateCompletion();
      }
      status.textContent=`Envio concluído: ${uploaded} foto(s). As fotos serão publicadas ao salvar o anúncio.`;
    }catch(error){
      if(!formIsCurrent())return;
      $('.form-error').textContent=error.message||'Não foi possível enviar a foto.';
      status.textContent=uploaded?`Envio interrompido: ${uploaded} de ${files.length} foto(s) enviada(s). Escolha novamente as fotos que faltaram.`:'Nenhuma foto foi enviada. Corrija o problema e tente novamente.';
    }finally{if(formIsCurrent())setPhotoUploadBusy(false)}
  };
  $('#space-photo-preview').onclick=event=>{const button=event.target.closest('[data-remove-photo]');if(button){photos.splice(Number(button.dataset.removePhoto),1);if(photos.length&&!photos.some(item=>item.cover))photos[0].cover=true;updatePhotoPreview();saveDraft();updateCompletion()}};
  $('#space-previous').onclick=()=>showStep(step-1);$('#space-next').onclick=()=>{if(stepIsValid())showStep(step+1)};$('#space-draft').onclick=()=>saveDraft(true);$('#preview-space').onclick=previewListing;
  showStep(step);updateCompletion();updatePhotoPreview();
  $('#cancel-space').onclick=()=>spaceManager();
  $('#save-space').onclick=async()=>{
    const button=$('#save-space');$('.form-error').textContent='';
    for(let index=0;index<4;index++){step=index;const section=document.querySelector(`[data-space-step="${step}"]`),field=[...section.querySelectorAll('input,textarea,select')].find(control=>control.required&&!control.checkValidity());if(field){showStep(index);field.reportValidity();return}if(index===0&&!document.querySelector('[data-space-event]:checked')){showStep(index);$('.form-error').textContent='Selecione pelo menos um tipo de evento.';return}}
    const payload={id:space?.id??null,name:$('#space-name').value.trim(),city:$('#space-city').value.trim(),neighborhood:$('#space-neighborhood').value.trim(),address:$('#space-address').value.trim(),description:$('#space-description').value.trim(),type:$('#space-type').value,capacity:Number($('#space-capacity').value),daily_price:$('#space-price').value,weekend_price:$('#space-weekend').value||null,check_in_time:$('#space-in').value,check_out_time:$('#space-out').value,minimum_days:Number($('#space-minimum').value),cleanup_minutes:Number($('#space-cleanup').value),photos,rules:$('#space-rules').value.trim(),events:[...document.querySelectorAll('[data-space-event]:checked')].map(x=>x.value),amenities:[...document.querySelectorAll('[data-space-amenity]:checked')].map(x=>x.value),active:$('#space-active').checked};
    if(!$('#space-consent').checked){step=3;showStep(step);$('.form-error').textContent='Confirme que tem autorização para publicar as informações e fotos.';return}
    if(!payload.events.length){$('.form-error').textContent='Selecione pelo menos um tipo de evento.';return}
    if(!payload.photos.length||payload.photos.length>8||payload.photos.filter(item=>item.cover).length!==1){step=2;showStep(step);$('.form-error').textContent='Adicione de uma a oito fotos e escolha exatamente uma foto principal.';return}
    button.disabled=true;
    try{await api('save-space',{method:'POST',body:JSON.stringify(payload)});if(draftKey)localStorage.removeItem(draftKey);await refreshCatalogue();toast(payload.active?'Anúncio publicado.':'Espaço salvo como pausado.');await spaceManager()}
    catch(err){$('.form-error').textContent=err.message;button.disabled=false}
  };
}

// Inicializar Auth primeiro: o login não deve aguardar o módulo opcional do Realtime Database.
if(!staticPreview){
  try{
    const response=await fetch('config.php');
    if(!response.ok)throw new Error(`config.php respondeu HTTP ${response.status}`);
    config=await response.json();
    if(config.firebase?.apiKey){
      const [app,a]=await Promise.all([
        import('https://www.gstatic.com/firebasejs/11.10.0/firebase-app.js'),
        import('https://www.gstatic.com/firebasejs/11.10.0/firebase-auth.js')
      ]);
      const instance=app.initializeApp(config.firebase);
      sdk={...a};
      auth=a.getAuth(instance);
      firebaseStatus='ready';
      a.onAuthStateChanged(auth,async u=>{setAuthenticatedUser(u);if(!u){profile={name:'',phone:''};return}try{const saved=await api('profile');if(user?.uid===u.uid)profile={name:saved.name||profile.name||u.displayName||'',phone:saved.phone||profile.phone||''}}catch{}});
      if(config.firebase.databaseURL){
        try{
          const d=await import('https://www.gstatic.com/firebasejs/11.10.0/firebase-database.js');
          sdk={...sdk,...d};
          db=d.getDatabase(instance);
        }catch(error){console.warn('Firebase Realtime Database indisponível; autenticação permanece ativa.',error)}
      }
    }else firebaseStatus='missing';
  }catch(error){
    firebaseStatus='error';
    firebaseFailureDetail=firebaseErrorDetail(error);
    console.error('Falha ao inicializar Firebase Authentication.',error);
    const notice=$('#firebase-status');if(notice){notice.hidden=false;notice.textContent=firebaseUnavailableMessage()}
  }
}

refreshCatalogue().then(()=>{const id=Number(new URLSearchParams(location.search).get('space'));if(id&&spaces.some(s=>s.id===id))detail(id)});
