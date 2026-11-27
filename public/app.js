import { localDateTime, parseBookingDate, quoteBooking, formatBookingDate } from './booking.mjs';
let spaces = [];
let catalogueStatus = 'loading', catalogueRequest = 0;
const $ = s => document.querySelector(s), money = n => new Intl.NumberFormat('pt-BR',{style:'currency',currency:'BRL',minimumFractionDigits:0,maximumFractionDigits:2}).format(n);
const escapeHtml = value => String(value??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const photo = (s) => {
  const candidate=s.photos?.[0];
  if(candidate){try{const url=new URL(candidate);if(url.protocol==='https:')return url.href}catch{}}
  const placeholder='<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 500"><rect width="800" height="500" fill="#e9edf1"/><path d="M0 390L220 210l150 120 120-150 310 210v110H0z" fill="#d5dde5"/><circle cx="400" cy="180" r="42" fill="#b6c3cf"/><text x="400" y="445" text-anchor="middle" font-family="Arial,sans-serif" font-size="28" fill="#526273">Foto ainda não enviada</text></svg>';
  return 'data:image/svg+xml;charset=UTF-8,'+encodeURIComponent(placeholder);
};
let profile={name:'',phone:''};
let type='', user=null, auth=null, db=null, sdk=null, unsubscribeChat=null, config={}, firebaseStatus='loading', firebaseFailureDetail='';
function setAuthenticatedUser(nextUser){
  user=nextUser;
  const button=$('#login-button');
  if(!button)return;
  button.innerHTML=user?'Minha conta <span>↗</span>':'Entrar <span>↗</span>';
  button.setAttribute('aria-label',user?'Minha conta':'Entrar');
  button.title=user?'Conta conectada':'';
}
function firebaseUnavailableMessage(){if(firebaseStatus==='loading')return 'Conectando ao Firebase. Aguarde alguns segundos e tente novamente.';if(firebaseStatus==='missing')return 'Firebase ainda não configurado.';return 'Não foi possível carregar o Firebase. Diagnóstico: '+(firebaseFailureDetail||'erro sem detalhes')+'.'}
function firebaseErrorDetail(error){const parts=[];if(typeof error?.code==='string')parts.push(error.code);else if(typeof error?.name==='string')parts.push(error.name);if(typeof error?.message==='string')parts.push(error.message);return parts.join(': ').replace(/AIza[0-9A-Za-z_-]{20,}/g,'[chave oculta]').slice(0,220)||'erro sem detalhes'}
let favorites;try{favorites=JSON.parse(localStorage.getItem('encontro-favorites')||'[]')}catch{favorites=[]}if(!Array.isArray(favorites))favorites=[];
function toast(message){$('#toast').textContent=message;$('#toast').style.display='block';setTimeout(()=>$('#toast').style.display='none',3500)}
function render(){
  const ready = catalogueStatus === 'ready';
  $('#catalog-notice').hidden = ready;
  $('#catalog-message').textContent = catalogueStatus === 'loading'
    ? 'Carregando espaços…'
    : 'Não foi possível carregar os espaços agora.';
  $('#retry-catalogue').hidden = catalogueStatus !== 'unavailable';
  if (!ready) {
    $('#count').textContent = '';
    $('#cards').innerHTML = '';
    $('#empty').hidden = true;
    return;
  }
  const list=spaces.filter(s=>(!type||s.type===type)&&(!$('#location').value||s.city===$('#location').value)&&(!$('#event').value||s.events.includes($('#event').value))&&s.capacity>=Number($('#capacity').value||'0'));
  $('#count').textContent=`${list.length} espaço${list.length===1?'':'s'} encontrado${list.length===1?'':'s'}`;
  $('#empty').hidden=list.length>0||spaces.length===0;
  $('#cards').innerHTML=list.length?list.map(s=>`<article class="card"><button class="card-open" data-detail="${s.id}" aria-label="Ver ${escapeHtml(s.name)}"><img src="${escapeHtml(photo(s))}" alt="${s.photos?.length?escapeHtml(s.name):'Foto ainda não enviada'}" loading="lazy"><h3>${escapeHtml(s.name)}</h3></button><button data-favorite="${s.id}" aria-label="${favorites.includes(s.id)?'Remover dos':'Adicionar aos'} favoritos" aria-pressed="${favorites.includes(s.id)}" class="${favorites.includes(s.id)?'favorited':''}">♡</button><p>${escapeHtml(s.neighborhood||s.city)} · ${escapeHtml(s.type)} · até ${s.capacity} pessoas</p><p class="price">${money(s.price)}<span>/diária</span></p></article>`).join(''):spaces.length?'':'<p class="notice">Ainda não há espaços publicados. Anuncie seu espaço para começar a receber solicitações.</p>';
}
function modal(html){if(unsubscribeChat){unsubscribeChat();unsubscribeChat=null}$('#modal-content').innerHTML=html;if(!$('#modal').open)$('#modal').showModal()}
$('#modal .close').onclick=()=>$('#modal').close();$('#modal').addEventListener('close',()=>{unsubscribeChat?.();unsubscribeChat=null});$('#modal').addEventListener('click',e=>{if(e.target===$('#modal'))$('#modal').close()});
$('#search').onclick=()=>{render();$('#espacos').scrollIntoView({behavior:'smooth'})};document.querySelectorAll('.category-tabs button').forEach(b=>b.onclick=()=>{type=b.dataset.type;document.querySelectorAll('.category-tabs button').forEach(i=>i.classList.remove('selected'));b.classList.add('selected');render()});
$('#cards').onclick=e=>{const f=e.target.closest('[data-favorite]'),d=e.target.closest('[data-detail]');if(f){const id=Number(f.dataset.favorite);favorites=favorites.includes(id)?favorites.filter(i=>i!==id):[...favorites,id];localStorage.setItem('encontro-favorites',JSON.stringify(favorites));render();return}if(d)detail(Number(d.dataset.detail))};
function parsePhotoUrls(value){
  const lines=String(value).split('\n').map(line=>line.trim()).filter(Boolean);
  if(lines.length<1||lines.length>5)throw new Error('Informe de uma a cinco fotos com links HTTPS.');
  return lines.map(line=>{let url;try{url=new URL(line)}catch{throw new Error('Confira os links das fotos. Use um link HTTPS por linha.');}
    if(url.protocol!=='https:'||url.username||url.password||line.length>2048)throw new Error('Use links HTTPS de fotos, sem usuário ou senha no endereço.');
    return url.href;
  });
}
function detail(id){
  const s=spaces.find(s=>s.id===id);if(!s)return;
  let photos;try{photos=parsePhotoUrls((s.photos||[]).join('\n'))}catch{photos=[photo(s,1200)]}
  modal(`<img id="gallery-main" class="detail-image" src="${escapeHtml(photos[0])}" alt="${escapeHtml(s.name)} — foto 1 de ${photos.length}"><p id="gallery-counter" role="status">Foto 1 de ${photos.length}</p>${photos.length>1?`<div class="photo-gallery" id="photo-thumbnails" role="group" aria-label="Fotos do espaço">${photos.map((url,index)=>`<button type="button" data-photo="${index}" aria-label="Ver foto ${index+1}" aria-pressed="${index===0}"><img src="${escapeHtml(url)}" alt="" loading="lazy"></button>`).join('')}</div>`:''}<span class="eyebrow" style="margin-top:20px">${escapeHtml(s.type)}</span><h2>${escapeHtml(s.name)}</h2><p class="muted">${escapeHtml(s.city)}${s.neighborhood?' · '+escapeHtml(s.neighborhood):''}</p>${s.address?'<p>'+escapeHtml(s.address)+'</p>':''}<h3>Sobre este espaço</h3><p>${escapeHtml(s.description)}</p><h3>Informações</h3><ul><li>Capacidade: até ${s.capacity} pessoas</li><li>Diária: ${money(s.price)}</li>${s.weekendPrice!==null&&s.weekendPrice!==undefined?'<li>Pacote de fim de semana (48h): '+money(s.weekendPrice)+'</li>':''}<li>Entrada às ${escapeHtml(s.checkInTime)} e saída às ${escapeHtml(s.checkOutTime)}, horário de Brasília</li><li>Mínimo de ${s.minimumDays} diária${s.minimumDays===1?'':'s'}</li></ul><h3>Comodidades</h3><p>${s.amenities.length?s.amenities.map(escapeHtml).join(', '):'Consulte o proprietário para mais informações'}</p>${s.rules?'<h3>Regras e cancelamento</h3><p>'+escapeHtml(s.rules)+'</p>':''}<button class="primary" id="request-reservation">Solicitar reserva →</button>`);
  $('#request-reservation').onclick=()=>user?reservation(s):login(()=>reservation(s));
  document.querySelectorAll('[data-photo]').forEach(button=>button.onclick=()=>{
    const index=Number(button.dataset.photo);$('#gallery-main').src=photos[index];$('#gallery-main').alt=s.name+' — foto '+(index+1)+' de '+photos.length;
    $('#gallery-counter').textContent='Foto '+(index+1)+' de '+photos.length;
    document.querySelectorAll('[data-photo]').forEach(item=>item.setAttribute('aria-pressed',String(item===button)));
  });
}

function login(after) {
  if(user){account('painel');return}
  modal(`<span class="eyebrow">BEM-VINDO AO RESERVAS RP</span><h2 id="auth-title">Entre para reservar.</h2><p id="auth-description">Acesse com seu e-mail e senha.</p>
    <form class="form" id="auth-form">
      <div id="signup-fields" hidden class="form-fields">
        <label>Seu nome<input id="signup-name" autocomplete="name" minlength="2" maxlength="120" disabled></label>
        <label>Celular com DDD<input type="tel" id="signup-phone" autocomplete="tel" maxlength="25" placeholder="(16) 99999-9999" disabled></label>
      </div>
      <label>E-mail<input type="email" id="email" autocomplete="email" required></label>
      <label>Senha<input type="password" id="password" autocomplete="current-password" minlength="6" required></label>
      <label id="signup-consent" hidden><span><input type="checkbox" id="accept-account" disabled style="width:auto"> Li os termos de uso e a política de privacidade disponíveis no rodapé.</span></label>
      <div class="form-error" role="alert"></div><button class="primary" type="submit" id="auth-submit">Entrar</button>
      <button type="button" class="login" id="google-signin" style="background:#4285F4;color:white;gap:8px;display:flex;align-items:center;justify-content:center"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z"/></svg> Entrar com Google</button>
      <button type="button" class="login" id="signup">Criar minha conta</button>
      <button type="button" class="detail-button" id="reset">Esqueci minha senha</button>
    </form>${firebaseStatus!=='ready'?'<p id="firebase-status" class="notice">'+firebaseUnavailableMessage()+'</p>':''}`);
  let creating=false;
  $('#google-signin').onclick=async (e)=>{
    e.preventDefault();
    if(!auth){$('.form-error').textContent=firebaseUnavailableMessage();return}
    const button=$('#google-signin');button.disabled=true;
    try{
      const provider=new sdk.GoogleAuthProvider();
      const result=await sdk.signInWithPopup(auth,provider);
      setAuthenticatedUser(result.user);
      profile={name:user.displayName||'',phone:''};
      try{await api('profile',{method:'POST',body:JSON.stringify(profile)})}
      catch{toast('Conectado via Google. Complete seus dados de contato na reserva.')}
      $('#modal').close();toast('Bem-vindo '+user.displayName+'!');after?.();
    }catch(e){
      const errorMessages={'auth/popup-closed-by-user':'A janela de login foi fechada antes de concluir.','auth/popup-blocked':'O navegador bloqueou a janela do Google. Permita pop-ups para este site e tente novamente.','auth/account-exists-with-different-credential':'Este e-mail já está cadastrado com outro método de login.','auth/unauthorized-domain':'O domínio eventosrp-production.up.railway.app não está autorizado. No Firebase, abra Authentication → Settings → Authorized domains e adicione eventosrp-production.up.railway.app.','auth/operation-not-allowed':'O provedor Google está desativado. No Firebase, abra Authentication → Sign-in method e habilite Google.','auth/network-request-failed':'Falha de rede ao falar com o Firebase. Verifique a conexão e tente novamente.','auth/operation-not-supported-in-this-environment':'O navegador não permite este método de login. Use um navegador atualizado em HTTPS.'};
      $('.form-error').textContent=errorMessages[e.code]||'Falha ao entrar com Google ('+(e.code||e.name||'erro sem código')+').';button.disabled=false;
    }
  };
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
      const messages={'auth/email-already-in-use':'Este e-mail já tem uma conta. Use Entrar ou recupere a senha.','auth/weak-password':'Escolha uma senha mais forte.','auth/invalid-email':'Confirme o e-mail.','auth/user-not-found':'Conta não encontrada.','auth/wrong-password':'Senha incorreta.'};
      error.textContent=messages[e.code]||'Não foi possível acessar. Verifique os dados ou tente novamente.';button.disabled=false;$('#signup').disabled=false;$('#google-signin').disabled=false;
    }
  };
  $('#reset').onclick=()=>passwordReset($('#email').value.trim());
}
function validPhone(value){return /^(?:55)?[1-9][0-9][0-9]{8,9}$/.test(value.replace(/\D/g,''))}

async function api(path,options={}){
  if(!user)throw new Error('Entre na sua conta para continuar.');
  const token=await user.getIdToken();
  const response=await fetch(`api.php?action=${path}`,{...options,headers:{'Content-Type':'application/json','Authorization':`Bearer ${token}`,...(options.headers||{})}});
  let data;try{data=await response.json()}catch{throw new Error('O servidor não respondeu como esperado. Tente novamente.');}
  if(!response.ok)throw new Error(typeof data?.error==='string'?data.error:'Não foi possível concluir. Tente novamente.');
  return data;
}
function reservation(s) {
  if(!user){login(()=>reservation(s));return}
  modal(`<h2>Reserve seu momento.</h2><p>${escapeHtml(s.name)} · ${money(s.price)} / diária</p>
    <form class="form" id="reservation-form">
      <label>Tipo de locação<select id="plan"><option value="daily">Diárias — um ou mais dias</option><option value="weekend">Pacote de fim de semana — 2 ou mais dias</option></select></label>
      <div class="form-row"><label>Entrada — data e horário<input type="datetime-local" id="check-in" min="${localDateTime()}" required></label><label>Saída — data e horário<input type="datetime-local" id="check-out" required></label></div>
      <p class="notice" id="period-help">Horários de Brasília. Cada diária cobre até 24 horas; frações adicionais contam como outra diária.</p>
      <div class="quote" id="quote" role="status" aria-live="polite">Selecione a entrada e a saída para calcular o período.</div>
      <label>Nome do responsável<input id="contact-name" autocomplete="name" minlength="2" maxlength="120" required></label>
      <div class="form-row"><label>Número de convidados<input type="number" id="guests" min="1" max="${s.capacity}" required></label><label>Celular com DDD<input type="tel" id="phone" autocomplete="tel" maxlength="25" placeholder="(16) 99999-9999" required></label></div>
      <label>Conte sobre seu evento<textarea id="message" maxlength="2000" required></textarea></label>
      <label><span><input type="checkbox" id="accept-reservation-terms" required style="width:auto"> Li e aceito as condições desta reserva e as regras do espaço.</span></label>
      <p class="notice">${config.paymentsEnabled?'O servidor confirma preço e disponibilidade antes do pagamento no Mercado Pago.':'O envio não gera cobrança nem confirma a locação. O proprietário analisará sua solicitação e você poderá acompanhar a resposta no painel.'} Serviços adicionais devem ser acordados previamente.</p>
      <div class="form-error" role="alert"></div><button class="primary" type="submit">${config.paymentsEnabled?'Continuar para pagamento ↗':'Enviar solicitação ↗'}</button>
    </form>`);
  $('#contact-name').value=profile.name||user?.displayName||'';$('#phone').value=profile.phone||'';
  let changedOut=false;
  function enforceHours(){
    const start=$('#check-in').value,end=$('#check-out').value;
    if(start&&start.slice(11)!==s.checkInTime)throw new Error('A entrada deste espaço é às '+s.checkInTime+'.');
    if(end&&end.slice(11)!==s.checkOutTime)throw new Error('A saída deste espaço é às '+s.checkOutTime+'.');
  }
  $('#period-help').insertAdjacentHTML?.('beforebegin',`<p class="notice">Entrada às ${escapeHtml(s.checkInTime)} e saída às ${escapeHtml(s.checkOutTime)}, horário de Brasília. Mínimo de ${escapeHtml(String(s.minimumDays))} diárias.</p>`);
  function updateQuote(){
    const plan=$('#plan').value;
    $('#period-help').textContent=plan==='weekend'?'Horários de Brasília. Entrada na sexta ou no sábado, com pelo menos 48 horas. O pacote cobre duas diárias; dias extras seguem a diária do espaço.':'Horários de Brasília. Cada diária cobre até 24 horas; frações adicionais contam como outra diária.';
    if(!$('#check-in').value||!$('#check-out').value){$('#quote').textContent='Selecione a entrada e a saída para calcular o período.';return}
    try{enforceHours();const q=quoteBooking($('#check-in').value,$('#check-out').value,plan,s.price,s.weekendPrice);if(q.days<s.minimumDays)throw new Error('O mínimo deste espaço é '+s.minimumDays+' diárias.');$('#quote').innerHTML=`<span>${q.days} diária${q.days>1?'s':''}</span><span class="price">${money(q.total)}</span>`}
    catch(e){$('#quote').textContent=e.message}
  }
  function suggestOut(){
    if($('#check-in').value){
      const value=$('#check-in').value.slice(0,10)+'T'+s.checkInTime;$('#check-in').value=value;
      try{const start=parseBookingDate(value),date=new Date(start.getTime());date.setUTCDate(date.getUTCDate()+Math.max(s.minimumDays,$('#plan').value==='weekend'?2:1));let end=localDateTime(date).slice(0,10)+'T'+s.checkOutTime;const minimumHours=Math.max(s.minimumDays*24,$('#plan').value==='weekend'?48:0);if((parseBookingDate(end)-start)/3600000<minimumHours&&$('#plan').value==='weekend'){date.setUTCDate(date.getUTCDate()+1);end=localDateTime(date).slice(0,10)+'T'+s.checkOutTime}if(!changedOut)$('#check-out').value=end}catch{}
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
      const data=await api('reserve',{method:'POST',body:JSON.stringify({space_id:s.id,check_in:$('#check-in').value,check_out:$('#check-out').value,plan:$('#plan').value,contact_name:$('#contact-name').value.trim(),guests:Number($('#guests').value),phone:$('#phone').value.trim(),message:$('#message').value.trim(),terms_accepted:$('#accept-reservation-terms').checked})});
      if(config.paymentsEnabled){
        const target=new URL(data.checkout_url);if(target.protocol!=='https:'||!/(^|\.)mercadopago\.(com|com\.br)$/.test(target.hostname))throw new Error('Endereço de pagamento inválido.');location.href=data.checkout_url;
      }else{
        if(data.status!=='requested'||data.checkout_url)throw new Error('O servidor retornou um estado de solicitação inesperado.');
        modal('<h2>Solicitação enviada.</h2><p>O proprietário recebeu seu pedido. Não houve cobrança. Acompanhe a resposta em Minhas reservas e use a conversa para esclarecer os detalhes.</p><button class="primary" id="view-requests">Ver minhas solicitações</button>');
        $('#view-requests').onclick=()=>account('reservas');
        toast('Solicitação enviada sem cobrança.');
      }
    }catch(err){$('.form-error').textContent=err.message;button.disabled=false}
  };
}

function reservationStatus(status,paymentStatus=null,paymentAttention='none'){if(paymentAttention==='refund_required')return 'Pagamento aprovado fora da reserva — análise de reembolso necessária';if(paymentAttention==='chargeback')return 'Pagamento contestado — atendimento necessário';if(paymentAttention==='review_required')return 'Pagamento em revisão';if(status==='pending'&&paymentStatus==='approved')return 'Pagamento aprovado — atualização da reserva em processamento';return {creating:'Em conferência',requested:'Aguardando confirmação do proprietário',confirmed:'Reserva confirmada pelo proprietário',declined:'Solicitação recusada pelo proprietário',pending:'Aguardando pagamento',approved:'Pagamento aprovado',failed:'Solicitação não concluída',cancelled:'Cancelada',expired:'Reserva expirada'}[status]||'Status em atualização'}
async function account(page){
  if(!user){login(()=>account(page));return}
  modal(`<h2>${page==='reservas'?'Minhas reservas':'Meu painel'}</h2><p id="account-email"></p><div id="reservation-list" role="status">Carregando...</div><button class="login" id="space-mgmt">Meus espaços ↗</button><button class="login" id="account-logout">Sair</button>`);
  $('#account-email').textContent=user.email;
  $('#space-mgmt').onclick=()=>spaceManager();
  $('#account-logout').onclick=async()=>{await sdk.signOut(auth);setAuthenticatedUser(null);location.reload()};
  const box=$('#reservation-list');
  try{
    const rows=await api('reservations');
    if($('#reservation-list')!==box)return;
    box.innerHTML=rows.length?rows.map(r=>`<div class="reservation-item"><p><strong>${escapeHtml(r.name)}</strong> · ${formatBookingDate(r.check_in)}</p><p class="muted">${escapeHtml(reservationStatus(r.status,r.payment_status,r.payment_attention))}</p><p>Saída: ${formatBookingDate(r.check_out)} · ${money(r.total)}</p><button class="detail-button" data-chat="${escapeHtml(r.id)}">Ver conversa</button></div>`).join(''):'<p>Você ainda não tem reservas.</p>';
    box.querySelectorAll('[data-chat]').forEach(button=>button.onclick=()=>chat(button.dataset.chat));
  }catch(error){if($('#reservation-list')===box)box.textContent=error.message}
}
async function chat(id){
  if(!user){login(()=>chat(id));return}
  if(!db||!sdk?.onValue){modal('<h2>Conversa da reserva</h2><p class="notice">A conversa está temporariamente indisponível. Sua reserva continua no painel.</p>');return}
  modal('<h2>Conversa da reserva</h2><div class="chat-messages" id="messages" aria-live="polite"></div><form class="form" id="chat-form"><label>Sua mensagem<input id="chat-text" maxlength="1000" required></label><div class="form-error" role="alert"></div><button class="primary" type="submit">Enviar</button></form>');
  const messagesDiv=$('#messages'),error=$('.form-error'),form=$('#chat-form');
  try{await api('chat-access',{method:'POST',body:JSON.stringify({reservation_id:id})})}catch{error.textContent='Não foi possível acessar esta conversa. Tente novamente.';return}
  if($('#chat-form')!==form)return;
  const messageRef=sdk.ref(db,'chats/'+id+'/messages');
  unsubscribeChat=sdk.onValue(sdk.query(messageRef,sdk.limitToLast(100)),snapshot=>{
    const messages=Object.values(snapshot.val()||{});
    messagesDiv.innerHTML=messages.length?messages.map(m=>`<div class="${m.sender===user.uid?'my-message':'their-message'}"><p>${escapeHtml(m.text)}</p><small>${typeof m.createdAt==='number'?new Date(m.createdAt).toLocaleString('pt-BR'):''}</small></div>`).join(''):'<p>Nenhuma mensagem. Tire suas dúvidas sobre a reserva por aqui.</p>';
    messagesDiv.scrollTop=messagesDiv.scrollHeight;
  },()=>{error.textContent='Não foi possível acessar esta conversa. Confira a conexão ou tente novamente.'});
  $('#chat-form').onsubmit=async e=>{
    e.preventDefault();const text=$('#chat-text').value.trim();if(!text)return;
    const input=$('#chat-text'),button=e.target.querySelector('button');button.disabled=true;error.textContent='';
    try{await sdk.push(messageRef,{sender:user.uid,text,createdAt:sdk.serverTimestamp()});input.value=''}
    catch{error.textContent='Não foi possível enviar. Tente novamente.'}finally{button.disabled=false}
  };
}
const legal={terms:['Termos de uso','Esta plataforma apresenta espaços e permite solicitar locações. O usuário deve fornecer informações corretas, respeitar a capacidade e as regras do local, e aceitar os termos de serviço. Pagamentos são processados via Mercado Pago. Cancelamentos devem ser feitos conforme as regras do host.'],privacy:['Política de privacidade','Seus dados de perfil, reservas e comunicações são armazenados para gerenciar sua conta e reservas. Não compartilhamos dados com terceiros, exceto para pagamento (Mercado Pago) e autenticação (Firebase). Você pode solicitar exclusão a qualquer momento.']};
$('#list-space').onclick=()=>spaceManager();
$('#login-button').onclick=()=>user?account('reservas'):login();
document.querySelectorAll('[data-legal]').forEach(b=>b.onclick=()=>{const [title,text]=legal[b.dataset.legal]||['Segurança','Sua conta é autenticada pelo Firebase. O pagamento ocorre no Mercado Pago. Nunca informe senhas ou dados de cartão no chat da reserva.'];modal(`<h2>${title}</h2><p>${text}</p>`)});document.querySelectorAll('[data-page]').forEach(b=>b.onclick=()=>{const page=b.dataset.page;if(page==='painel'||page==='spaces')spaceManager();else if(page==='reservas'||page==='account')account('reservas');else if(page==='login')login()});
const paymentReturn=new URLSearchParams(location.search).get('payment');if(paymentReturn){toast('Consulte suas reservas para acompanhar a confirmação do pagamento.');history.replaceState(null,'',location.pathname)}
function passwordReset(email='') {
  modal(`<h2>Recuperar minha senha.</h2><p>Informe o e-mail do cadastro para receber um link de recuperação.</p><form class="form" id="reset-form"><label>E-mail<input type="email" id="reset-email" autocomplete="email" required></label><div class="form-error" role="alert"></div><button class="primary" type="submit" id="send-reset">Enviar link</button><button type="button" class="login" id="back-login">Voltar</button></form>`);
  $('#reset-email').value=email;$('#back-login').onclick=()=>login();
  $('#reset-form').onsubmit=async e=>{
    e.preventDefault();if(!auth){$('.form-error').textContent=firebaseUnavailableMessage();return}
    const button=$('#send-reset');button.disabled=true;
    try{await sdk.sendPasswordResetEmail(auth,$('#reset-email').value.trim());$('#modal').close();toast('Se houver uma conta com esse e-mail, enviaremos o link de recuperação.')}
    catch{$('.form-error').textContent='Não foi possível enviar o link. Confira o e-mail e tente novamente.';button.disabled=false}
  };
}
async function refreshCatalogue() {
  const request = ++catalogueRequest;
  catalogueStatus = 'loading';
  render();
  try {
    const response = await fetch('api.php?action=spaces');
    if (!response.ok) throw new Error('Catálogo indisponível');
    const data = await response.json();
    if (!Array.isArray(data)) throw new Error('Catálogo inválido');
    if (request !== catalogueRequest) return;
    spaces = data.map(space => ({
      ...space,
      description: space.description || '',
      neighborhood: space.neighborhood || '',
      amenities: space.amenities || [],
      rules: space.rules || '',
    }));
    const location = $('#location');
    const selected = location.value;
    const cities = [...new Set(['Ribeirão Preto', ...spaces.map(space => space.city)])]
      .sort((a, b) => a.localeCompare(b, 'pt-BR'));
    location.innerHTML = '<option value="">Todas as localizações</option>'
      + cities.map(city => `<option value="${escapeHtml(city)}">${escapeHtml(city)}</option>`).join('');
    location.value = cities.includes(selected) ? selected : '';
    catalogueStatus = 'ready';
  } catch {
    if (request !== catalogueRequest) return;
    catalogueStatus = 'unavailable';
  }
  render();
}
$('#retry-catalogue').onclick = refreshCatalogue;
async function ownerApprovalPanel(){
  if(!user)return;
  modal('<h2>Solicitações de proprietários.</h2><p>Somente contas autorizadas pela administração podem publicar espaços.</p><div id="owner-approval-list" role="status">Carregando solicitações...</div>');
  const box=$('#owner-approval-list');
  try{
    const rows=await api('owner-requests');
    if($('#owner-approval-list')!==box)return;
    box.textContent=rows.length?'':'Nenhuma solicitação pendente.';
    for(const owner of rows){
      const item=document.createElement('div');item.className='reservation-item';
      const detail=document.createElement('p');detail.textContent=`${owner.name||'Sem nome'} · ${owner.email} · ${owner.spaces_count} espaço(s)`;item.appendChild(detail);
      const actions=document.createElement('div');actions.className='panel-actions';
      for(const decision of ['approved','rejected']){
        const button=document.createElement('button');button.className=decision==='approved'?'primary':'login';button.textContent=decision==='approved'?'Aprovar proprietário':'Recusar';
        button.onclick=async()=>{button.disabled=true;try{await api('review-owner',{method:'POST',body:JSON.stringify({firebase_uid:owner.firebase_uid,decision})});toast(decision==='approved'?'Proprietário aprovado.':'Solicitação recusada.');ownerApprovalPanel()}catch(error){button.disabled=false;toast(error.message)}};
        actions.appendChild(button);
      }
      item.appendChild(actions);box.appendChild(item);
    }
  }catch(error){if($('#owner-approval-list')===box)box.textContent=error.message}
}
async function spaceManager(){
  if(!user){login(()=>spaceManager());return}
  modal('<h2>Meus espaços.</h2><p id="owner-approval-status" class="notice">Verificando situação do cadastro...</p><div class="panel-actions"><button class="primary" id="new-space">Cadastrar espaço ↗</button><button class="login" id="back-account">Minhas reservas</button><button class="login" id="admin-owner-review" hidden>Solicitações de proprietários</button></div><div id="owned-spaces">Carregando...</div><h3 style="margin-top:30px">Reservas recebidas</h3><div id="host-reservations">Carregando...</div>');
  $('#new-space').onclick=()=>spaceForm();$('#back-account').onclick=()=>account('reservas');
  const results=await Promise.allSettled([api('my-spaces'),api('host-reservations'),api('profile')]);
  if(!$('#owned-spaces')||!$('#host-reservations'))return;
  const [owned,received,accountProfile]=results;
  if(accountProfile.status==='fulfilled'){
    profile={...profile,...accountProfile.value};
    const status=accountProfile.value.owner_status;
    $('#owner-approval-status').textContent=status==='approved'?'Cadastro aprovado. Você pode ativar ou pausar cada anúncio.':status==='pending'?'Sua solicitação de proprietário está em análise. Os anúncios permanecem ocultos até a aprovação.':status==='rejected'?'Sua solicitação anterior foi recusada. Atualize os dados do espaço para solicitar uma nova análise.':'Cadastre seu espaço para solicitar autorização de publicação.';
    $('#admin-owner-review').hidden=!accountProfile.value.is_platform_admin;
    $('#admin-owner-review').onclick=ownerApprovalPanel;
  }else $('#owner-approval-status').textContent='Não foi possível verificar a situação da aprovação.';
  if(owned.status==='rejected')$('#owned-spaces').textContent=owned.reason.message;
  else{
    const box=$('#owned-spaces');box.textContent=owned.value.length?'':'Você ainda não cadastrou espaços.';
    for(const s of owned.value){const item=document.createElement('div');item.className='reservation-item';const text=document.createElement('p');text.textContent=`${s.name} · ${s.city} · ${s.address} · ${s.active?'Publicado':'Pausado'}`;item.appendChild(text);const button=document.createElement('button');button.className='detail-button';button.textContent='Editar';button.onclick=()=>spaceForm(s);item.appendChild(button);box.appendChild(item)}
  }
  if(received.status==='rejected')$('#host-reservations').textContent=received.reason.message;
  else{
    const box=$('#host-reservations');box.textContent=received.value.length?'':'Nenhuma reserva recebida.';
    for(const r of received.value){const item=document.createElement('div');item.className='reservation-item';const text=document.createElement('p');text.textContent=`${r.name} · ${r.contact_name} · ${formatBookingDate(r.check_in)} · ${reservationStatus(r.status,r.payment_status,r.payment_attention)}`;item.appendChild(text);if(r.status==='requested'){for(const decision of ['confirmed','declined']){const action=document.createElement('button');action.className=decision==='confirmed'?'primary':'detail-button';action.textContent=decision==='confirmed'?'Confirmar solicitação':'Recusar solicitação';action.onclick=async()=>{action.disabled=true;try{await api('review-reservation',{method:'POST',body:JSON.stringify({reservation_id:r.id,decision})});toast(decision==='confirmed'?'Reserva confirmada.':'Solicitação recusada.');spaceManager()}catch(error){action.disabled=false;toast(error.message)}};item.appendChild(action)}}const button=document.createElement('button');button.className='detail-button';button.textContent='Ver conversa';button.onclick=()=>chat(r.id);item.appendChild(button);box.appendChild(item)}
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
   <div class="form-row"><label>Tipo<select id="space-type" required><option value="">Selecione o tipo</option><option>Salão</option><option>Ao ar livre</option><option>Corporativo</option><option>Rooftop</option></select></label><label>Capacidade<input type="number" id="space-capacity" min="1" max="9999" required></label></div>
   <label>Descrição<textarea id="space-description" minlength="2" maxlength="4000" required></textarea></label>
   <fieldset><legend>Eventos permitidos</legend><div class="checkbox-grid">${['Casamento','Corporativo','Aniversário'].map(e=>`<label><input type="checkbox" data-space-event value="${e}">${e}</label>`).join('')}</div></fieldset>
   <div class="form-row"><label>Valor da diária (R$)<input type="number" id="space-price" min="0.01" max="9999999.99" step="0.01" required></label><label>Pacote de fim de semana — 48h (R$)<input type="number" id="space-weekend-price" min="0.01" max="9999999.99" step="0.01"></label></div>
   <div class="form-row"><label>Horário de entrada<input type="time" id="space-in" required></label><label>Horário de saída<input type="time" id="space-out" required></label></div>
   <div class="form-row"><label>Mínimo de diárias<input type="number" id="space-minimum" min="1" max="30" required></label><label>Preparação entre reservas (minutos)<input type="number" id="space-cleanup" min="0" max="1440" required></label></div>
   <p class="notice">Cada diária cobre até 24 horas. Se a saída for mais tarde que a entrada, o período pode gerar uma diária adicional. Esses horários serão exigidos na reserva. O intervalo de preparação ajuda a evitar conflitos entre reservas.</p>
   <fieldset><legend>Comodidades</legend><div class="checkbox-grid">${amenities.map(a=>`<label><input type="checkbox" data-space-amenity value="${a}">${a}</label>`).join('')}</div></fieldset>
   <label>Fotos reais do espaço<input type="file" id="space-photos" accept="image/*" multiple><small>Escolha de uma a cinco fotos no celular ou computador. As imagens são otimizadas antes do envio.</small></label>
   <p id="photo-upload-status" class="notice" role="status" aria-live="polite" hidden></p><div id="space-photo-preview" class="space-photo-grid" aria-live="polite"></div>
   <label>Regras do local e condições de cancelamento<textarea id="space-rules" maxlength="4000" placeholder="Ex.: limite de som, horários, uso da piscina, limpeza e condições de cancelamento..."></textarea></label>
   <label class="inline-check"><input type="checkbox" id="space-active"> Publicar anúncio e aceitar novas reservas</label>
   <label class="inline-check"><input type="checkbox" id="space-owner-authorized" required> Sou responsável pelo espaço e tenho autorização para publicar estas informações e imagens.</label>
   <div class="form-error" role="alert"></div><button class="primary" id="save-space">Salvar espaço ↗</button><button class="login" type="button" id="cancel-space">Voltar aos meus espaços</button>
  </form>`);
  const values={'name':space?.name||'','city':space?.city||'','neighborhood':space?.neighborhood||'','address':space?.address||'','type':space?.type||'','capacity':space?.capacity??'','description':space?.description||'','price':space?.price??'','weekend-price':space?.weekendPrice??'','in':space?.checkInTime||'','out':space?.checkOutTime||'','minimum':space?.minimumDays||'1','cleanup':space?.cleanupMinutes||'0','rules':space?.rules||''};
  for(const [key,value] of Object.entries(values))$('#space-'+key).value=value;
  let photos=Array.isArray(space?.photos)?space.photos.filter(url=>typeof url==='string'):[];const pendingUploads=new Set();
  const photoForm=$('#space-form'),photoUser=user;
  const photoFormIsCurrent=()=>$('#space-form')===photoForm&&$('#modal').open&&user?.uid===photoUser.uid;
  async function discardPendingUploads(keepUrls=[]){const keep=new Set(keepUrls);for(const url of [...pendingUploads])if(!keep.has(url)){await api('delete-space-photo',{method:'POST',body:JSON.stringify({url})});pendingUploads.delete(url)}}
  const cleanupOnClose=()=>{ $('#modal').removeEventListener('close',cleanupOnClose);void discardPendingUploads().catch(()=>{}) };
  $('#modal').addEventListener('close',cleanupOnClose);
  function updateSpacePhotoPreview(){
    const box=$('#space-photo-preview');
    box.innerHTML=photos.length?photos.map((url,index)=>`<article class="space-photo-card"><img src="${escapeHtml(url)}" alt="Foto ${index+1} do espaço" loading="lazy"><button class="detail-button" type="button" data-remove-space-photo="${index}">Remover</button></article>`).join(''):'<small>As fotos selecionadas aparecerão aqui.</small>';
  }
  async function compressSpacePhoto(file){
    if(!file.type.startsWith('image/'))throw new Error('Selecione um arquivo de imagem.');
    const source=URL.createObjectURL(file);
    try{
      const image=await new Promise((resolve,reject)=>{const item=new Image();item.onload=()=>resolve(item);item.onerror=()=>reject(new Error('Este formato não pôde ser aberto. Tente uma foto JPEG ou PNG.'));item.src=source});
      let scale=Math.min(1,1600/Math.max(image.naturalWidth,image.naturalHeight));let blob=null;
      for(let pass=0;pass<4;pass++){
        const canvas=document.createElement('canvas');canvas.width=Math.max(1,Math.round(image.naturalWidth*scale));canvas.height=Math.max(1,Math.round(image.naturalHeight*scale));
        const context=canvas.getContext('2d');if(!context)throw new Error('Não foi possível preparar esta foto.');
        context.drawImage(image,0,0,canvas.width,canvas.height);
        for(const quality of [.82,.7,.58,.46]){
          blob=await new Promise(resolve=>canvas.toBlob(resolve,'image/jpeg',quality));
          if(blob&&blob.size<=2*1024*1024)break;
        }
        if(blob&&blob.size<=2*1024*1024)break;
        scale*=.75;
      }
      if(!blob||blob.size>2*1024*1024)throw new Error('A foto é muito grande. Escolha uma imagem menor.');
      return blob;
    }finally{URL.revokeObjectURL(source)}
  }
  async function uploadSpacePhoto(file){
    const image=await compressSpacePhoto(file),body=new FormData();body.append('photo',image,'foto.jpg');
    const token=await photoUser.getIdToken(),response=await fetch('api.php?action=upload-space-photo',{method:'POST',headers:{Authorization:`Bearer ${token}`},body});
    const result=await response.json().catch(()=>({}));
    if(!response.ok)throw new Error(result.error||'Não foi possível enviar esta foto.');
    if(typeof result.url!=='string')throw new Error('O servidor não confirmou o envio da foto.');
    if(!photoFormIsCurrent()){await fetch('api.php?action=delete-space-photo',{method:'POST',headers:{Authorization:`Bearer ${token}`,'Content-Type':'application/json'},body:JSON.stringify({url:result.url})}).catch(()=>{});return null}
    return result.url;
  }
  updateSpacePhotoPreview();
  $('#space-photo-preview').onclick=async event=>{const button=event.target.closest('[data-remove-space-photo]');if(!button)return;const [removed]=photos.splice(Number(button.dataset.removeSpacePhoto),1);updateSpacePhotoPreview();if(pendingUploads.has(removed)){try{await api('delete-space-photo',{method:'POST',body:JSON.stringify({url:removed})});pendingUploads.delete(removed)}catch(error){$('.form-error').textContent='A foto foi retirada do formulário; a limpeza no armazenamento será tentada novamente ao fechar.'}}};
  $('#space-photos').onchange=async event=>{
    const input=event.currentTarget,files=[...input.files];input.value='';if(!files.length)return;
    if(photos.length+files.length>5){$('.form-error').textContent='Você pode adicionar até cinco fotos ao espaço.';return}
    const status=$('#photo-upload-status');status.hidden=false;$('.form-error').textContent='';input.disabled=true;$('#save-space').disabled=true;$('#cancel-space').disabled=true;
    let uploaded=0;
    try{
      for(let index=0;index<files.length;index++){
        if(!photoFormIsCurrent())return;
        status.textContent=`Otimizando e enviando foto ${index+1} de ${files.length}…`;
        const url=await uploadSpacePhoto(files[index]);if(!url)return;photos.push(url);pendingUploads.add(url);uploaded++;updateSpacePhotoPreview();
      }
      status.textContent=`${uploaded} foto(s) adicionada(s).`;
    }catch(error){
      $('.form-error').textContent=error.message;
      status.textContent=uploaded?`${uploaded} foto(s) enviada(s). Escolha novamente as que faltaram.`:'Não foi possível enviar as fotos.';
    }finally{input.disabled=false;$('#save-space')&&($('#save-space').disabled=false);$('#cancel-space')&&($('#cancel-space').disabled=false)}
  };
  $('#space-active').checked=space?.active??false;
  document.querySelectorAll('[data-space-event]').forEach(e=>e.checked=(space?.events||[]).includes(e.value));
  document.querySelectorAll('[data-space-amenity]').forEach(e=>e.checked=(space?.amenities||[]).includes(e.value));
  $('#cancel-space').onclick=async()=>{await discardPendingUploads().catch(()=>{});$('#modal').removeEventListener('close',cleanupOnClose);spaceManager()};
  $('#space-form').onsubmit=async e=>{
    e.preventDefault();const button=$('#save-space');$('.form-error').textContent='';
    const payload={id:space?.id??null,name:$('#space-name').value.trim(),city:$('#space-city').value.trim(),neighborhood:$('#space-neighborhood').value.trim(),address:$('#space-address').value.trim(),type:$('#space-type').value,capacity:Number($('#space-capacity').value),description:$('#space-description').value.trim(),daily_price:$('#space-price').value,weekend_price:$('#space-weekend-price').value||null,check_in_time:$('#space-in').value,check_out_time:$('#space-out').value,minimum_days:Number($('#space-minimum').value),cleanup_minutes:Number($('#space-cleanup').value),photos:photos,amenities:[...document.querySelectorAll('[data-space-amenity]:checked')].map(e=>e.value),events:[...document.querySelectorAll('[data-space-event]:checked')].map(e=>e.value),rules:$('#space-rules').value.trim(),active:$('#space-active').checked,owner_authorized:$('#space-owner-authorized').checked};
    if(!payload.events.length){$('.form-error').textContent='Selecione pelo menos um tipo de evento.';return}
    if(!payload.photos.length||payload.photos.length>5){$('.form-error').textContent='Adicione de uma a cinco fotos do espaço.';return}
    button.disabled=true;
    try{await discardPendingUploads(photos);await api('save-space',{method:'POST',body:JSON.stringify(payload)});pendingUploads.clear();$('#modal').removeEventListener('close',cleanupOnClose);await refreshCatalogue();toast('Espaço salvo.');await spaceManager()}
    catch(err){$('.form-error').textContent=err.message;button.disabled=false}
  };
}

render();

try {
  const response = await fetch('config.php');
  if (!response.ok) throw new Error('config.php respondeu HTTP ' + response.status);
  config = await response.json();

  if (config.firebase?.apiKey) {
    const [appModule, authModule] = await Promise.all([
      import('https://www.gstatic.com/firebasejs/10.12.4/firebase-app.js'),
      import('https://www.gstatic.com/firebasejs/10.12.4/firebase-auth.js')
    ]);

    const firebaseApp = appModule.initializeApp(config.firebase);
    auth = authModule.getAuth(firebaseApp);
    sdk = { ...authModule };
    firebaseStatus = 'ready';
    document.querySelector('#firebase-status')?.remove();
    sdk.onAuthStateChanged(auth, (u) => { setAuthenticatedUser(u); });

    if (config.firebase.databaseURL) {
      try {
        const dbModule = await import('https://www.gstatic.com/firebasejs/10.12.4/firebase-database.js');
        sdk = { ...sdk, ...dbModule };
        db = dbModule.getDatabase(firebaseApp);
      } catch (err) {
        console.warn('Firebase Realtime Database indisponível; autenticação permanece ativa.', err);
      }
    }
  } else {
    firebaseStatus = 'missing';
  }
} catch (err) {
  firebaseStatus = 'error';
  firebaseFailureDetail = firebaseErrorDetail(err);
  console.error('Erro ao inicializar Firebase Authentication:', err);
  config.firebase = null;
  const notice = document.querySelector('#firebase-status');
  if (notice) notice.textContent = firebaseUnavailableMessage();
}

refreshCatalogue();