import { localDateTime, parseBookingDate, quoteBooking, formatBookingDate } from './booking.mjs';
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
const escapeHtml = value => String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const photo = (s,w=800) => {
  const candidate=s.photos?.[0];
  if(candidate){try{const url=new URL(candidate);if(url.protocol==='https:')return url.href}catch{}}
  return `https://images.unsplash.com/${s.photo||'photo-1519167758481-83f550bb49b3'}?w=${w}&auto=format&fit=crop&q=85`;
};
spaces.forEach(s=>Object.assign(s,{isDemo:true,checkInTime:'09:00',checkOutTime:'09:00',minimumDays:1,cleanupMinutes:0,photos:[],amenities:[],rules:''}));
let profile={name:'',phone:''};
let type='', user=null, auth=null, db=null, sdk=null, unsubscribeChat=null, config={};
let favorites;try{favorites=JSON.parse(localStorage.getItem('encontro-favorites')||'[]')}catch{favorites=[]}if(!Array.isArray(favorites))favorites=[];
function toast(message){$('#toast').textContent=message;$('#toast').style.display='block';setTimeout(()=>$('#toast').style.display='none',3500)}
function render(){const list=spaces.filter(s=>(!type||s.type===type)&&(!$('#location').value||s.city===$('#location').value)&&(!$('#event').value||s.events.includes($('#event').value))&&s.capacity>=Number($('#capacity').value));$('#count').textContent=`${list.length} espaços encontrados`;$('#empty').hidden=!!list.length;$('#cards').innerHTML=list.map(s=>`<article class="card"><div class="card-photo"><img src="${escapeHtml(photo(s))}" alt="${escapeHtml(s.name)}" loading="lazy"><span class="badge">${escapeHtml(s.type)}</span><button class="favorite ${favorites.includes(s.id)?'saved':''}" data-favorite="${s.id}" aria-label="Favoritar ${escapeHtml(s.name)}" aria-pressed="${favorites.includes(s.id)}">${favorites.includes(s.id)?'♥':'♡'}</button></div><div class="card-body"><div class="card-title"><h3>${escapeHtml(s.name)}</h3><span class="rating">Novo no catálogo</span></div><p class="place">⌖ ${escapeHtml(s.neighborhood)}, ${escapeHtml(s.city)}</p><div class="features"><span>♙ Até ${s.capacity} pessoas</span><span>◇ ${s.events[0]}</span></div><div class="card-bottom"><div class="price">${money(s.price)} <small>/ diária</small></div><button class="detail-button" data-detail="${s.id}">Ver espaço ↗</button></div></div></article>`).join('')}
function modal(html){if(unsubscribeChat){unsubscribeChat();unsubscribeChat=null}$('#modal-content').innerHTML=html;if(!$('#modal').open)$('#modal').showModal()}
$('#modal .close').onclick=()=>$('#modal').close();$('#modal').addEventListener('close',()=>{unsubscribeChat?.();unsubscribeChat=null});$('#modal').addEventListener('click',e=>{if(e.target===$('#modal')){const r=$('#modal').getBoundingClientRect();if(e.clientX<r.left||e.clientX>r.right||e.clientY<r.top||e.clientY>r.bottom)$('#modal').close()}});
$('#search').onclick=()=>{render();$('#espacos').scrollIntoView({behavior:'smooth'})};document.querySelectorAll('.category-tabs button').forEach(b=>b.onclick=()=>{type=b.dataset.type;document.querySelectorAll('.category-tabs button').forEach(x=>x.classList.toggle('selected',x===b));render()});
$('#cards').onclick=e=>{const f=e.target.closest('[data-favorite]'),d=e.target.closest('[data-detail]');if(f){const id=Number(f.dataset.favorite);favorites=favorites.includes(id)?favorites.filter(x=>x!==id):[...favorites,id];try{localStorage.setItem('encontro-favorites',JSON.stringify(favorites))}catch{}render()}if(d)detail(Number(d.dataset.detail))};
function detail(id){const s=spaces.find(s=>s.id===id);modal(`<img class="detail-image" src="${escapeHtml(photo(s,1200))}" alt="${escapeHtml(s.name)}"><span class="eyebrow" style="margin-top:20px">${escapeHtml(s.type)} · ${escapeHtml(s.city)}</span><h2>${escapeHtml(s.name)}</h2><p>${escapeHtml(s.description)}</p>${s.address?'<p>Endereço: '+escapeHtml(s.address)+'</p>':''}<p>Até ${s.capacity} convidados · ${escapeHtml(s.events.join(' / '))}</p><p><strong>${money(s.price)} / diária de até 24 horas</strong><br>Pacote de fim de semana: <strong>${money(s.weekendPrice??s.price*2)} / 48 horas</strong>. Entrada na sexta ou no sábado; dias extras pela diária do espaço.</p><p>Entrada: <strong>${escapeHtml(s.checkInTime)}</strong> · Saída: <strong>${escapeHtml(s.checkOutTime)}</strong> (Brasília)<br>Mínimo: ${s.minimumDays} diária(s) · Preparação entre reservas: ${s.cleanupMinutes} minutos</p><p>${escapeHtml(s.amenities?.join(' · ')||'Consulte as comodidades com o responsável.')}</p><p style="white-space:pre-line">${escapeHtml(s.rules||'Consulte as regras do local antes de reservar.')}</p>${s.photos?.length>1?'<div class="photo-gallery">'+s.photos.slice(1).map(url=>'<img src="'+escapeHtml(url)+'" alt="Foto adicional do espaço" loading="lazy">').join('')+'</div>':''}${s.isDemo?'<p class="notice">Fotos ilustrativas e espaço de demonstração. Não há avaliações publicadas.</p>':''}<button class="primary" id="reserve">Consultar e reservar ↗</button>`);$('#reserve').onclick=()=>user?reservation(s):login(()=>reservation(s))}
function login(after) {
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
    </form>${!auth?'<p class="notice">O login ficará disponível após a configuração do Firebase. O catálogo pode ser explorado livremente.</p>':''}`);
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
    if(!auth){error.textContent='Firebase ainda não configurado.';return}
    const name=creating?$('#signup-name').value.trim():'',phone=creating?$('#signup-phone').value.trim():'';
    if(creating&&(name.length<2||!validPhone(phone))){error.textContent='Informe seu nome e um celular válido com DDD.';return}
    button.disabled=true;$('#signup').disabled=true;
    try{
      const result=await (creating?sdk.createUserWithEmailAndPassword:sdk.signInWithEmailAndPassword)(auth,$('#email').value.trim(),$('#password').value);user=result.user;
      if(creating){
        profile={name,phone};
        try{await sdk.updateProfile(user,{displayName:name});await api('profile',{method:'POST',body:JSON.stringify(profile)})}
        catch{toast('Conta criada. Confirme seus dados de contato na reserva.')}
      }else{try{profile=await api('profile')}catch{profile={name:user.displayName||'',phone:''}}}
      $('#modal').close();toast(creating?'Conta criada. Vamos planejar seu evento!':'Você entrou na sua conta.');after?.();
    }catch(e){
      const messages={'auth/email-already-in-use':'Este e-mail já tem uma conta. Use Entrar ou recupere a senha.','auth/weak-password':'Escolha uma senha mais forte.','auth/invalid-email':'Confira o endereço de e-mail.','auth/too-many-requests':'Muitas tentativas. Aguarde e tente novamente.'};
      error.textContent=messages[e.code]||'Não foi possível acessar. Verifique os dados ou tente novamente.';button.disabled=false;$('#signup').disabled=false;
    }
  };
  $('#reset').onclick=()=>passwordReset($('#email').value.trim());
}
function validPhone(value){return /^(?:55)?[1-9][0-9][0-9]{8,9}$/.test(value.replace(/\D/g,''))}

async function api(path,options={}){const token=await user.getIdToken();const r=await fetch(`api.php?action=${path}`,{...options,headers:{'Content-Type':'application/json','Authorization':`Bearer ${token}`}});let data;try{data=await r.json()}catch{throw new Error('O servidor PHP não está disponível.')}if(!r.ok)throw new Error(data.error||'Não foi possível concluir.');return data}
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
      <label><span><input type="checkbox" required style="width:auto"> Li os termos de uso e desejo solicitar a reserva.</span></label>
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
  $('#reservation-form').onsubmit=async e=>{
    e.preventDefault();const button=e.target.querySelector('button');$('.form-error').textContent='';
    try{
      enforceHours();const quote=quoteBooking($('#check-in').value,$('#check-out').value,$('#plan').value,s.price,s.weekendPrice);
      if(quote.days<s.minimumDays)throw new Error('O mínimo deste espaço é '+s.minimumDays+' diárias.');
      if(!validPhone($('#phone').value))throw new Error('Informe um celular válido com DDD.');
      if($('#contact-name').value.trim().length<2)throw new Error('Informe o nome do responsável.');
      button.disabled=true;
      const data=await api('reserve',{method:'POST',body:JSON.stringify({space_id:s.id,check_in:$('#check-in').value,check_out:$('#check-out').value,plan:$('#plan').value,contact_name:$('#contact-name').value.trim(),guests:Number($('#guests').value),phone:$('#phone').value.trim(),message:$('#message').value})});
      const target=new URL(data.checkout_url);if(target.protocol!=='https:'||!/(^|\.)mercadopago\.(com|com\.br)$/.test(target.hostname))throw new Error('Endereço de pagamento inválido.');location.href=target.href;
    }catch(err){$('.form-error').textContent=err.message;button.disabled=false}
  };
}

async function account(page){if(!user){login(()=>account(page));return}modal(`<h2>${page==='reservas'?'Minhas reservas':'Meu painel'}</h2><p id="account-email"></p><div id="reservation-list">Carregando reservas…</div><div class="panel-actions"><button class="primary" id="manage-spaces">Meus espaços e reservas recebidas</button><button class="login" id="logout">Sair da conta</button></div>`);$('#account-email').textContent=user.email;$('#manage-spaces').onclick=()=>spaceManager();$('#logout').onclick=async()=>{await sdk.signOut(auth);$('#modal').close();toast('Você saiu da conta.')};try{const data=await api('reservations');const list=$('#reservation-list');if(!list)return;list.textContent='';if(!data.length)list.textContent='Você ainda não tem reservas. Explore os espaços e encontre seu cenário.';for(const r of data){const item=document.createElement('div');item.className='reservation-item';const title=document.createElement('strong');title.textContent=r.name;const p=document.createElement('p');p.textContent=`Entrada: ${formatBookingDate(r.check_in)} · Saída: ${formatBookingDate(r.check_out)} · ${r.billable_days} diária(s) · ${r.plan==='weekend'?'Pacote de fim de semana':'Diárias'} · ${money(Number(r.total))} · ${r.status}`;const b=document.createElement('button');b.className='detail-button';b.textContent='Conversar sobre a reserva ↗';b.onclick=()=>chat(r.id);item.append(title,p,b);list.append(item)}}catch(e){if($('#reservation-list'))$('#reservation-list').textContent=e.message}}
function chat(id){modal('<h2>Conversa da reserva</h2><div class="chat-messages" id="messages" aria-live="polite"></div><form class="form" id="chat-form"><label>Sua mensagem<input id="chat-text" maxlength="1000" required></label><div class="form-error" role="alert"></div><button class="primary">Enviar mensagem</button></form>');if(!db){$('#messages').textContent='O chat ficará disponível após configurar o Realtime Database.';$('#chat-form').onsubmit=e=>{e.preventDefault();$('.form-error').textContent='Chat ainda não configurado.'};return}const ref=sdk.ref(db,`chats/${id}/messages`);unsubscribeChat=sdk.onValue(sdk.query(ref,sdk.limitToLast(100)),snap=>{const box=$('#messages');if(!box)return;box.textContent='';snap.forEach(child=>{const p=document.createElement('p');p.textContent=`${child.val().sender===user.uid?'Você':'Participante'}: ${child.val().text}`;box.append(p)});box.scrollTop=box.scrollHeight},()=>{if($('#messages'))$('#messages').textContent='Não foi possível acessar esta conversa.'});$('#chat-form').onsubmit=async e=>{e.preventDefault();const text=$('#chat-text').value.trim();if(!text)return;try{await sdk.push(ref,{sender:user.uid,text,createdAt:sdk.serverTimestamp()});$('#chat-text').value=''}catch{$('.form-error').textContent='Não foi possível enviar a mensagem.'}}}
const legal={terms:['Termos de uso','Esta plataforma apresenta espaços e permite solicitar locações. O usuário deve fornecer informações corretas, respeitar a capacidade e as regras do local. O responsável pelo espaço deve informar disponibilidade, serviços, condições de cancelamento e cumprir a proposta acordada. Nenhum serviço adicional está incluído sem previsão expressa. As condições específicas, incluindo cancelamento, precisam ser informadas antes da contratação. Este texto é uma minuta e deve ser adaptado com a identificação da empresa operadora antes do lançamento.'],privacy:['Política de privacidade','Para prestar o serviço, a plataforma utiliza nome, e-mail, identificador de autenticação, telefone e informações da reserva. Os anúncios publicados incluem as informações do espaço fornecidas pelo proprietário. A autenticação é processada pelo Firebase; reservas são armazenadas no MySQL; conversas, no Firebase; pagamentos, pelo Mercado Pago. A plataforma não recebe dados completos do cartão. Favoritos são armazenados neste navegador. Dados pessoais devem ser acessados apenas pelo titular e pessoal autorizado. Antes do lançamento, a operadora deve publicar sua identificação, contato para exercício de direitos, prazos de retenção e bases legais aplicáveis. Esta é uma minuta de política.'],security:['Segurança','A aplicação verifica o token de autenticação no servidor e restringe as consultas de reservas ao usuário autenticado. Pagamentos acontecem no checkout do Mercado Pago. O ambiente de produção deve usar HTTPS, manter credenciais fora da pasta pública, aplicar regras de acesso no Firebase, proteger backups e restringir o acesso ao MySQL. A confirmação de pagamento consulta diretamente a API do provedor e verifica valor e reserva.']};
document.querySelectorAll('[data-legal]').forEach(b=>b.onclick=()=>{const [title,text]=legal[b.dataset.legal];modal(`<h2>${title}</h2><p>${text}</p>`)});document.querySelectorAll('[data-page]').forEach(b=>b.onclick=()=>account(b.dataset.page));$('#login-button').onclick=()=>login();$('#list-space').onclick=()=>user?spaceForm():login(()=>spaceForm());render();
const paymentReturn=new URLSearchParams(location.search).get('payment');if(paymentReturn){toast('Consulte suas reservas para acompanhar a confirmação do pagamento.');history.replaceState(null,'',location.pathname+location.hash)}
function passwordReset(email='') {
  modal(`<h2>Recuperar minha senha.</h2><p>Informe o e-mail do cadastro para receber um link de recuperação.</p><form class="form" id="reset-form"><label>E-mail<input type="email" id="reset-email" autocomplete="email" required></label><div class="form-error" role="alert"></div><button class="primary" id="send-reset">Enviar link de recuperação</button><button type="button" class="login" id="back-login">Voltar ao login</button></form>`);
  $('#reset-email').value=email;$('#back-login').onclick=()=>login();
  $('#reset-form').onsubmit=async e=>{
    e.preventDefault();if(!auth){$('.form-error').textContent='Firebase ainda não configurado.';return}
    const button=$('#send-reset');button.disabled=true;
    try{await sdk.sendPasswordResetEmail(auth,$('#reset-email').value.trim());$('#modal').close();toast('Se houver uma conta com esse e-mail, enviaremos o link de recuperação.')}
    catch{$('.form-error').textContent='Não foi possível enviar o link. Confira o e-mail e tente novamente.';button.disabled=false}
  };
}
async function refreshCatalogue(){
  try{
    const response=await fetch('api.php?action=spaces');if(!response.ok)return;
    const data=await response.json();if(!Array.isArray(data))return;
    const samples=new Map(spaces.filter(s=>s.isDemo).map(s=>[s.id,s]));
    spaces=data.map(s=>{const demo=s.isDemo?samples.get(s.id):null;return {...demo,...s,description:s.description||demo?.description||'',neighborhood:s.neighborhood||demo?.neighborhood||'',photo:demo?.photo}});
    const selected=$('#location').value,cities=[...new Set(['Ribeirão Preto',...spaces.map(s=>s.city)])].sort((a,b)=>a.localeCompare(b,'pt-BR'));
    $('#location').innerHTML='<option value="">Todas as localizações</option>'+cities.map(c=>`<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('');$('#location').value=cities.includes(selected)?selected:'';
    $('#catalog-notice').hidden=!spaces.some(s=>s.isDemo);render();
  }catch{}
}
async function spaceManager(){
  if(!user){login(()=>spaceManager());return}
  modal('<h2>Meus espaços.</h2><div class="panel-actions"><button class="primary" id="new-space">Cadastrar espaço ↗</button><button class="login" id="back-account">Minhas reservas</button></div><div id="owned-spaces">Carregando espaços…</div><h3>Reservas recebidas</h3><div id="host-reservations">Carregando reservas…</div>');
  $('#new-space').onclick=()=>spaceForm();$('#back-account').onclick=()=>account('reservas');
  const results=await Promise.allSettled([api('my-spaces'),api('host-reservations')]);
  if(!$('#owned-spaces')||!$('#host-reservations'))return;
  const [owned,received]=results;
  if(owned.status==='rejected')$('#owned-spaces').textContent=owned.reason.message;
  else{
    const box=$('#owned-spaces');box.textContent=owned.value.length?'':'Você ainda não cadastrou espaços.';
    for(const s of owned.value){const item=document.createElement('div');item.className='reservation-item';const text=document.createElement('p');text.textContent=`${s.name} · ${s.city} · ${s.active?'Publicado':'Pausado'} · Entrada ${s.checkInTime} / saída ${s.checkOutTime}`;const button=document.createElement('button');button.className='login';button.textContent='Editar espaço';button.onclick=()=>spaceForm(s);item.append(text,button);box.append(item)}
  }
  if(received.status==='rejected')$('#host-reservations').textContent=received.reason.message;
  else{
    const box=$('#host-reservations');box.textContent=received.value.length?'':'Nenhuma reserva recebida.';
    for(const r of received.value){const item=document.createElement('div');item.className='reservation-item';const text=document.createElement('p');text.textContent=`${r.name} · ${r.contact_name} · ${r.guests} convidados · ${formatBookingDate(r.check_in)} a ${formatBookingDate(r.check_out)} · ${money(Number(r.total))} · ${r.status}`;const button=document.createElement('button');button.className='login';button.textContent='Abrir conversa';button.onclick=()=>chat(r.id);item.append(text,button);box.append(item)}
  }
}

function spaceForm(space=null){
  if(!user){login(()=>spaceForm(space));return}
  const amenities=['Piscina','Churrasqueira','Estacionamento','Wi-Fi','Cozinha','Ar-condicionado','Acessibilidade','Área coberta'];
  modal(`<h2>${space?'Editar espaço':'Cadastre seu espaço.'}</h2><p>Publique as informações que ajudam seus convidados a planejar. Horários de Brasília.</p>
  <form class="form" id="space-form">
   <label>Nome do espaço<input id="space-name" minlength="2" maxlength="120" required></label>
   <div class="form-row"><label>Cidade<input id="space-city" maxlength="120" required></label><label>Bairro<input id="space-neighborhood" maxlength="120"></label></div>
   <label>Endereço público do espaço<input id="space-address" maxlength="250" required><small>Este endereço aparecerá nos detalhes do anúncio.</small></label>
   <div class="form-row"><label>Tipo<select id="space-type"><option>Salão</option><option>Ao ar livre</option><option>Corporativo</option><option>Rooftop</option></select></label><label>Capacidade máxima<input type="number" id="space-capacity" min="1" max="10000" required></label></div>
   <label>Descrição<textarea id="space-description" minlength="2" maxlength="4000" required></textarea></label>
   <fieldset><legend>Eventos permitidos</legend><div class="checkbox-grid">${['Casamento','Corporativo','Aniversário'].map(e=>`<label><input type="checkbox" data-space-event value="${e}">${e}</label>`).join('')}</div></fieldset>
   <div class="form-row"><label>Valor da diária (R$)<input type="number" id="space-price" min="0.01" max="9999999.99" step="0.01" required></label><label>Pacote de fim de semana — 48h (R$)<input type="number" id="space-weekend" min="0.01" max="9999999.99" step="0.01"><small>Opcional. Vazio = duas diárias; dias extras pela diária.</small></label></div>
   <div class="form-row"><label>Horário de entrada<input type="time" id="space-in" required></label><label>Horário de saída<input type="time" id="space-out" required></label></div>
   <div class="form-row"><label>Mínimo de diárias<input type="number" id="space-minimum" min="1" max="30" required></label><label>Preparação entre reservas (minutos)<input type="number" id="space-cleanup" min="0" max="1440" required></label></div>
   <p class="notice">Cada diária cobre até 24 horas. Se a saída for mais tarde que a entrada, o período pode gerar uma diária adicional. Esses horários serão exigidos na reserva. O intervalo de preparação bloqueia novas entradas após a saída, sem cobrar esse tempo do cliente.</p>
   <fieldset><legend>Comodidades</legend><div class="checkbox-grid">${amenities.map(a=>`<label><input type="checkbox" data-space-amenity value="${a}">${a}</label>`).join('')}</div></fieldset>
   <label>Fotos — uma URL HTTPS por linha<textarea id="space-photos" placeholder="https://seu-site.com/foto.jpg" required></textarea><small>De uma a cinco fotos. Use imagens autorizadas e links diretos.</small></label>
   <label>Regras do local e condições de cancelamento<textarea id="space-rules" maxlength="4000" placeholder="Ex.: limite de som, horários, uso da piscina, limpeza e condições de cancelamento."></textarea></label>
   <label class="inline-check"><input type="checkbox" id="space-active"> Publicar anúncio e aceitar novas reservas</label>
   <label class="inline-check"><input type="checkbox" required> Sou responsável pelo espaço e tenho autorização para publicar estas informações e imagens.</label>
   <div class="form-error" role="alert"></div><button class="primary" id="save-space">Salvar espaço ↗</button><button class="login" type="button" id="cancel-space">Voltar aos meus espaços</button>
  </form>`);
  const values={'name':space?.name||'','city':space?.city||'Ribeirão Preto','neighborhood':space?.neighborhood||'','address':space?.address||'','type':space?.type||'Salão','capacity':space?.capacity||'','description':space?.description||'','price':space?.price||'','weekend':space?.weekendPrice??'','in':space?.checkInTime||'09:00','out':space?.checkOutTime||'09:00','minimum':space?.minimumDays||1,'cleanup':space?.cleanupMinutes||0,'photos':space?.photos?.join('\n')||'','rules':space?.rules||''};
  for(const [key,value] of Object.entries(values))$('#space-'+key).value=value;
  $('#space-active').checked=space?.active??true;
  document.querySelectorAll('[data-space-event]').forEach(e=>e.checked=(space?.events||['Aniversário']).includes(e.value));
  document.querySelectorAll('[data-space-amenity]').forEach(e=>e.checked=(space?.amenities||[]).includes(e.value));
  $('#cancel-space').onclick=()=>spaceManager();
  $('#space-form').onsubmit=async e=>{
    e.preventDefault();const button=$('#save-space');$('.form-error').textContent='';
    const payload={id:space?.id??null,name:$('#space-name').value.trim(),city:$('#space-city').value.trim(),neighborhood:$('#space-neighborhood').value.trim(),address:$('#space-address').value.trim(),description:$('#space-description').value.trim(),type:$('#space-type').value,capacity:Number($('#space-capacity').value),daily_price:$('#space-price').value,weekend_price:$('#space-weekend').value||null,check_in_time:$('#space-in').value,check_out_time:$('#space-out').value,minimum_days:Number($('#space-minimum').value),cleanup_minutes:Number($('#space-cleanup').value),photos:$('#space-photos').value.split('\n').map(x=>x.trim()).filter(Boolean),rules:$('#space-rules').value.trim(),events:[...document.querySelectorAll('[data-space-event]:checked')].map(x=>x.value),amenities:[...document.querySelectorAll('[data-space-amenity]:checked')].map(x=>x.value),active:$('#space-active').checked};
    if(!payload.events.length){$('.form-error').textContent='Selecione pelo menos um tipo de evento.';return}
    if(!payload.photos.length||payload.photos.length>5||payload.photos.some(p=>{try{return new URL(p).protocol!=='https:'}catch{return true}})){$('.form-error').textContent='Informe de uma a cinco URLs HTTPS válidas para as fotos.';return}
    button.disabled=true;
    try{await api('save-space',{method:'POST',body:JSON.stringify(payload)});await refreshCatalogue();toast('Espaço salvo.');await spaceManager()}
    catch(err){$('.form-error').textContent=err.message;button.disabled=false}
  };
}

try{const response=await fetch('config.php');if(response.ok)config=await response.json();if(config.firebase?.apiKey){const [app,a,d]=await Promise.all([import('https://www.gstatic.com/firebasejs/11.10.0/firebase-app.js'),import('https://www.gstatic.com/firebasejs/11.10.0/firebase-auth.js'),import('https://www.gstatic.com/firebasejs/11.10.0/firebase-database.js')]);sdk={...a,...d};const instance=app.initializeApp(config.firebase);auth=a.getAuth(instance);if(config.firebase.databaseURL)db=d.getDatabase(instance);a.onAuthStateChanged(auth,async u=>{user=u;$('#login-button').textContent=u?'Minha conta ↗':'Entrar ↗';if(!u){profile={name:'',phone:''};return}try{const saved=await api('profile');if(user?.uid===u.uid)profile={name:saved.name||profile.name||u.displayName||'',phone:saved.phone||profile.phone||''}}catch{}})}}catch{console.info('Modo catálogo: integrações ainda não configuradas.')}

refreshCatalogue();
