import { localDateTime, parseBookingDate, quoteBooking, formatBookingDate } from './booking.mjs';
let spaces = [
 {id:1,name:'Casa Jardim',city:'São Paulo',neighborhood:'Vila Mariana',type:'Ao ar livre',events:['Casamento','Aniversário'],capacity:150,price:2800,photo:'photo-1511795409834-ef04bbd61622',description:'Um espaço ao ar livre perfeito para casamentos e aniversários em São Paulo. Com capacidade para até 150 pessoas.'},
 {id:2,name:'Espaço Aurora',city:'São Paulo',neighborhood:'Pinheiros',type:'Salão',events:['Casamento','Aniversário','Corporativo'],capacity:300,price:4500,photo:'photo-1519167758481-83f550bb49b3',description:'Salão de eventos moderno em Pinheiros com até 300 pessoas. Ideal para casamentos, aniversários e eventos corporativos.'},
 {id:3,name:'Terraço Horizonte',city:'São Paulo',neighborhood:'Vila Olímpia',type:'Rooftop',events:['Corporativo','Aniversário'],capacity:100,price:3200,photo:'photo-1514933651103-005eec06c04b',description:'Rooftop com vista panorâmica em Vila Olímpia. Perfeito para eventos corporativos e aniversários (até 100 pessoas).'},
 {id:4,name:'Estação Criativa',city:'Campinas',neighborhood:'Cambuí',type:'Corporativo',events:['Corporativo'],capacity:50,price:1200,photo:'photo-1497366754035-f200968a6e72',description:'Espaço corporativo moderno em Campinas para reuniões e eventos até 50 pessoas.'},
 {id:5,name:'Recanto das Oliveiras',city:'São José dos Campos',neighborhood:'Urbanova',type:'Ao ar livre',events:['Casamento','Aniversário'],capacity:200,price:3800,photo:'photo-1464366400600-71-a36270b8c16',description:'Recanto ao ar livre em São José dos Campos, capacidade para 200 convidados.'},
 {id:6,name:'Salão Essência',city:'Campinas',neighborhood:'Taquaral',type:'Salão',events:['Casamento','Aniversário'],capacity:200,price:2500,photo:'photo-1519741497674-611481863552',description:'Salão elegante em Campinas para casamentos e aniversários até 200 pessoas.'},
 {id:7,name:'Recanto Ribeirão',city:'Ribeirão Preto',neighborhood:'Ribeirão Preto',type:'Ao ar livre',events:['Casamento','Aniversário'],capacity:150,price:1800,photo:'photo-1511795409834-ef04bbd61622',description:'Espaço ao ar livre em Ribeirão Preto para eventos especiais até 150 pessoas.'},
 {id:8,name:'Espaço Ipê',city:'Ribeirão Preto',neighborhood:'Ribeirão Preto',type:'Salão',events:['Casamento','Corporativo','Aniversário'],capacity:200,price:2300,photo:'photo-1519167758481-83f550bb49b3',description:'Salão versátil em Ribeirão Preto para qualquer tipo de evento até 200 pessoas.'},
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
let type='', user=null, auth=null, db=null, sdk=null, unsubscribeChat=null, config={}, firebaseStatus='loading', firebaseFailureDetail='';
function firebaseUnavailableMessage(){if(firebaseStatus==='loading')return 'Conectando ao Firebase. Aguarde alguns segundos e tente novamente.';if(firebaseStatus==='missing')return 'Firebase ainda não configurado.';return 'Não foi possível carregar o Firebase. Diagnóstico: '+(firebaseFailureDetail||'erro sem detalhes')+'.'}
function firebaseErrorDetail(error){const parts=[];if(typeof error?.code==='string')parts.push(error.code);else if(typeof error?.name==='string')parts.push(error.name);if(typeof error?.message==='string')parts.push(error.message);return parts.join(': ').replace(/AIza[0-9A-Za-z_-]{20,}/g,'[chave oculta]').replace(/\\s+/g,' ').slice(0,220)||'erro sem detalhes'}
let favorites;try{favorites=JSON.parse(localStorage.getItem('encontro-favorites')||'[]')}catch{favorites=[]}if(!Array.isArray(favorites))favorites=[];
function toast(message){$('#toast').textContent=message;$('#toast').style.display='block';setTimeout(()=>$('#toast').style.display='none',3500)}
function render(){const list=spaces.filter(s=>(!type||s.type===type)&&(!$('#location').value||s.city===$('#location').value)&&(!$('#event').value||s.events.includes($('#event').value))&&s.capacity>=Number($('#capacity').value||'0'));$('#cards').innerHTML=list.length?list.map(s=>`<div class="card" data-detail="${s.id}"><img src="${escapeHtml(photo(s))}" alt=""><button data-favorite="${s.id}" aria-label="Adicionar aos favoritos" class="${favorites.includes(s.id)?'favorited':''}">♡</button><h3>${escapeHtml(s.name)}</h3><p>${escapeHtml(s.neighborhood||s.city)} · ${escapeHtml(s.type)} · até ${s.capacity} pessoas</p><p class="price">${money(s.price)}<span>/diária</span></p></div>`).join(''):'<p style="grid-column:1/-1;text-align:center;color:var(--muted)">Nenhum espaço encontrado com esses filtros.</p>'}
function modal(html){if(unsubscribeChat){unsubscribeChat();unsubscribeChat=null}$('#modal-content').innerHTML=html;if(!$('#modal').open)$('#modal').showModal()}
$('#modal .close').onclick=()=>$('#modal').close();$('#modal').addEventListener('close',()=>{unsubscribeChat?.();unsubscribeChat=null});$('#modal').addEventListener('click',e=>{if(e.target===$('#modal'))$('#modal').close()});
$('#search').onclick=()=>{render();$('#espacos').scrollIntoView({behavior:'smooth'})};document.querySelectorAll('.category-tabs button').forEach(b=>b.onclick=()=>{type=b.dataset.type;document.querySelectorAll('.category-tabs button').forEach(i=>i.classList.remove('active'));b.classList.add('active');render()});
$('#cards').onclick=e=>{const f=e.target.closest('[data-favorite]'),d=e.target.closest('[data-detail]');if(f){const id=Number(f.dataset.favorite);favorites=favorites.includes(id)?favorites.filter(i=>i!==id):[...favorites,id];localStorage.setItem('encontro-favorites',JSON.stringify(favorites));f.classList.toggle('favorited');return}if(d)detail(Number(d.dataset.detail))};
function detail(id){const s=spaces.find(s=>s.id===id);modal(`<img class="detail-image" src="${escapeHtml(photo(s,1200))}" alt="${escapeHtml(s.name)}"><span class="eyebrow" style="margin-top:20px">${escapeHtml(s.type)}</span><h2>${escapeHtml(s.name)}</h2><p class="muted">${escapeHtml(s.neighborhood||s.city)}</p><h3>Sobre este espaço</h3><p>${escapeHtml(s.description)}</p><h3>Informações</h3><ul><li>Capacidade: até ${s.capacity} pessoas</li><li>Diária: ${money(s.price)}</li></ul><h3>Comodidades</h3><p>${s.amenities.length?s.amenities.join(', '):'Consulte o host para mais informações'}</p>${s.rules?'<h3>Regras e cancelamento</h3><p>'+escapeHtml(s.rules)+'</p>':''}<button class="primary" onclick="reservation({...${JSON.stringify(s)}})">Solicitar reserva →</button>`)}
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
      user=result.user;
      profile={name:user.displayName||'',phone:''};
      try{await api('profile',{method:'POST',body:JSON.stringify(profile)})}
      catch{toast('Conectado via Google. Complete seus dados de contato na reserva.')}
      $('#modal').close();toast('Bem-vindo '+user.displayName+'!');after?.();
    }catch(e){
      const errorMessages={'auth/popup-closed-by-user':'Você fechou a janela de login.','auth/popup-blocked':'Abra a janela de pop-up para continuar.','auth/account-exists-with-different-credential':'Este e-mail já existe com outro método.'};
      $('.form-error').textContent=errorMessages[e.code]||'Não foi possível conectar com Google. Tente novamente.';button.disabled=false;
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
      const result=await (creating?sdk.createUserWithEmailAndPassword:sdk.signInWithEmailAndPassword)(auth,$('#email').value.trim(),$('#password').value);user=result.user;
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

async function api(path,options={}){const token=await user.getIdToken();const r=await fetch(`api.php?action=${path}`,{...options,headers:{'Content-Type':'application/json','Authorization':`Bearer ${token}`,...(options.headers||{})}});if(!r.ok)throw new Error(r.statusText);return r.json()}
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
  $('#period-help').insertAdjacentHTML?.('beforebegin',`<p class="notice">Entrada às ${escapeHtml(s.checkInTime)} e saída às ${escapeHtml(s.checkOutTime)}, horário de Brasília. Mínimo de ${escapeHtml(String(s.minimumDays))} diárias.</p>`);
  function updateQuote(){
    const plan=$('#plan').value;
    $('#period-help').textContent=plan==='weekend'?'Horários de Brasília. Entrada na sexta ou no sábado, com pelo menos 48 horas. O pacote cobre duas diárias; dias extras seguem a diária do pacote.':'Horários de Brasília. Cada diária cobre até 24 horas; frações adicionais contam como outra diária.';
    if(!$('#check-in').value||!$('#check-out').value){$('#quote').textContent='Selecione a entrada e a saída para calcular o período.';return}
    try{enforceHours();const q=quoteBooking($('#check-in').value,$('#check-out').value,plan,s.price,s.weekendPrice);if(q.days<s.minimumDays)throw new Error('O mínimo deste espaço é '+s.minimumDays+' diárias.');$('#quote').innerHTML=`<span>${q.days} diária${q.days>1?'s':''}</span><span class="price">${money(q.total)}</span>`}
    catch(e){$('#quote').textContent=e.message}
  }
  function suggestOut(){
    if($('#check-in').value){
      const value=$('#check-in').value.slice(0,10)+'T'+s.checkInTime;$('#check-in').value=value;
      try{const start=parseBookingDate(value),date=new Date(start.getTime());date.setUTCDate(date.getUTCDate()+Math.max(s.minimumDays,$('#plan').value==='weekend'?2:1));let end=localDateTime(date).slice(0,16);if(!changedOut)$('#check-out').value=end}catch{}
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
      const data=await api('reserve',{method:'POST',body:JSON.stringify({space_id:s.id,check_in:$('#check-in').value,check_out:$('#check-out').value,plan:$('#plan').value,contact_name:$('#contact-name').value.trim(),guests:Number($('#guests').value),phone:$('#phone').value.trim(),message:$('#message').value.trim()})});
      const target=new URL(data.checkout_url);if(target.protocol!=='https:'||!/(^|\.)mercadopago\.(com|com\.br)$/.test(target.hostname))throw new Error('Endereço de pagamento inválido.');location.href=data.checkout_url;
    }catch(err){$('.form-error').textContent=err.message;button.disabled=false}
  };
}

async function account(page){if(!user){login(()=>account(page));return}modal(`<h2>${page==='reservas'?'Minhas reservas':'Meu painel'}</h2><p id="account-email"></p><div id="reservation-list">Carregando...</div><button class="login" id="space-mgmt">Meus espaços ↗</button><button class="login" id="account-logout">Sair</button>`);$('#account-email').textContent=user.email;const [owned,guest]=await Promise.allSettled([api('my-spaces'),api('reservations')]);if(!$('#reservation-list'))return;if(guest.status==='rejected'||!guest.value.length){$('#reservation-list').innerHTML='<p>Você ainda não tem reservas. <a href="javascript:location.reload()">Voltar ao catálogo</a>'}else{$('#reservation-list').innerHTML=guest.value.map(r=>`<div class="reservation-item"><p><strong>${escapeHtml(r.name)}</strong> · ${escapeHtml(r.city)} · ${formatBookingDate(r.check_in)}</p><p class="muted">${r.status==='confirmed'?'Confirmada':r.status==='pending'?'Aguardando confirmação':'Cancelada'}</p><button class="detail-button" onclick="chat(${r.id})">Ver conversa</button></div>`).join('')}$('#space-mgmt').onclick=()=>spaceManager();$('#account-logout').onclick=()=>{sdk.signOut(auth);user=null;location.reload()}}
function chat(id){modal('<h2>Conversa da reserva</h2><div class="chat-messages" id="messages" aria-live="polite"></div><form class="form" id="chat-form"><label>Sua mensagem<input id="chat-text" maxlength="2000" placeholder="Envie sua mensagem..." required></label><div class="form-error" role="alert"></div><button class="primary" type="submit">Enviar</button></form>');const messagesDiv=$('#messages');function refresh(){api('chat',{method:'GET',body:JSON.stringify({booking_id:id})}).then(msgs=>{messagesDiv.innerHTML=msgs.map(m=>`<div class="${m.user_id===user.uid?'my-message':'their-message'}"><p>${escapeHtml(m.text)}</p><small>${new Date(m.created_at).toLocaleString('pt-BR')}</small></div>`).join('');messagesDiv.scrollTop=messagesDiv.scrollHeight}).catch(()=>messagesDiv.innerHTML='<p class="muted">Erro ao carregar mensagens.</p>')}refresh();unsubscribeChat=setInterval(refresh,3000);$('#chat-form').onsubmit=async e=>{e.preventDefault();try{await api('chat',{method:'POST',body:JSON.stringify({booking_id:id,text:$('#chat-text').value.trim()})});$('#chat-text').value='';refresh()}catch(e){$('.form-error').textContent=e.message}}}
const legal={terms:['Termos de uso','Esta plataforma apresenta espaços e permite solicitar locações. O usuário deve fornecer informações corretas, respeitar a capacidade e as regras do local, e aceitar os termos de serviço. Pagamentos são processados via Mercado Pago. Cancelamentos devem ser feitos conforme as regras do host.'],privacy:['Política de privacidade','Seus dados de perfil, reservas e comunicações são armazenados para gerenciar sua conta e reservas. Não compartilhamos dados com terceiros, exceto para pagamento (Mercado Pago) e autenticação (Firebase). Você pode solicitar exclusão a qualquer momento.']};
$('#login-button').onclick=()=>user?account('reservas'):login();
document.querySelectorAll('[data-legal]').forEach(b=>b.onclick=()=>{const [title,text]=legal[b.dataset.legal];modal(`<h2>${title}</h2><p>${text}</p>`)});document.querySelectorAll('[data-page]').forEach(b=>b.onclick=()=>{const page=b.dataset.page;if(page==='login')login();else if(page==='account')account('painel');else if(page==='spaces')spaceManager()});
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
async function refreshCatalogue(){
  try{
    const response=await fetch('api.php?action=spaces');if(!response.ok)return;
    const data=await response.json();if(!Array.isArray(data))return;
    const samples=new Map(spaces.filter(s=>s.isDemo).map(s=>[s.id,s]));
    spaces=data.map(s=>{const demo=s.isDemo?samples.get(s.id):null;return {...demo,...s,description:s.description||demo?.description||'',neighborhood:s.neighborhood||demo?.neighborhood||'',photo:s.photo||demo?.photo||'photo-1519167758481-83f550bb49b3',amenities:s.amenities||demo?.amenities||[],rules:s.rules||demo?.rules||''}});
    const selected=$('#location').value,cities=[...new Set(['Ribeirão Preto',...spaces.map(s=>s.city)])].sort((a,b)=>a.localeCompare(b,'pt-BR'));
    $('#location').innerHTML='<option value="">Todas as localizações</option>'+cities.map(c=>`<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('');$('#location').value=cities.includes(selected)?selected:'';
    $('#catalog-notice').hidden=!spaces.some(s=>s.isDemo);render();
  }catch{}
}
async function spaceManager(){
  if(!user){login(()=>spaceManager());return}
  modal('<h2>Meus espaços.</h2><div class="panel-actions"><button class="primary" id="new-space">Cadastrar espaço ↗</button><button class="login" id="back-account">Minhas reservas</button></div><div id="owned-spaces">Carregando...</div><h3 style="margin-top:30px">Reservas recebidas</h3><div id="host-reservations">Carregando...</div>');
  $('#new-space').onclick=()=>spaceForm();$('#back-account').onclick=()=>account('reservas');
  const results=await Promise.allSettled([api('my-spaces'),api('host-reservations')]);
  if(!$('#owned-spaces')||!$('#host-reservations'))return;
  const [owned,received]=results;
  if(owned.status==='rejected')$('#owned-spaces').textContent=owned.reason.message;
  else{
    const box=$('#owned-spaces');box.textContent=owned.value.length?'':'Você ainda não cadastrou espaços.';
    for(const s of owned.value){const item=document.createElement('div');item.className='reservation-item';const text=document.createElement('p');text.textContent=`${s.name} · ${s.city} · ${s.address}`;item.appendChild(text);const button=document.createElement('button');button.className='detail-button';button.textContent='Editar';button.onclick=()=>spaceForm(s);item.appendChild(button);box.appendChild(item)}
  }
  if(received.status==='rejected')$('#host-reservations').textContent=received.reason.message;
  else{
    const box=$('#host-reservations');box.textContent=received.value.length?'':'Nenhuma reserva recebida.';
    for(const r of received.value){const item=document.createElement('div');item.className='reservation-item';const text=document.createElement('p');text.textContent=`${r.name} · ${r.contact_name} · ${formatBookingDate(r.check_in)}`;item.appendChild(text);const button=document.createElement('button');button.className='detail-button';button.textContent='Ver conversa';button.onclick=()=>chat(r.id);item.appendChild(button);box.appendChild(item)}
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
   <div class="form-row"><label>Tipo<select id="space-type"><option>Salão</option><option>Ao ar livre</option><option>Corporativo</option><option>Rooftop</option></select></label><label>Capacidade<input type="number" id="space-capacity" min="1" max="9999" required></label></div>
   <label>Descrição<textarea id="space-description" minlength="2" maxlength="4000" required></textarea></label>
   <fieldset><legend>Eventos permitidos</legend><div class="checkbox-grid">${['Casamento','Corporativo','Aniversário'].map(e=>`<label><input type="checkbox" data-space-event value="${e}">${e}</label>`).join('')}</div></fieldset>
   <div class="form-row"><label>Valor da diária (R$)<input type="number" id="space-price" min="0.01" max="9999999.99" step="0.01" required></label><label>Pacote de fim de semana — 48h (R$)<input type="number" id="space-weekend-price" min="0.01" max="9999999.99" step="0.01" required></label></div>
   <div class="form-row"><label>Horário de entrada<input type="time" id="space-in" required></label><label>Horário de saída<input type="time" id="space-out" required></label></div>
   <div class="form-row"><label>Mínimo de diárias<input type="number" id="space-minimum" min="1" max="30" required></label><label>Preparação entre reservas (minutos)<input type="number" id="space-cleanup" min="0" max="1440" required></label></div>
   <p class="notice">Cada diária cobre até 24 horas. Se a saída for mais tarde que a entrada, o período pode gerar uma diária adicional. Esses horários serão exigidos na reserva. O intervalo de preparação ajuda a evitar conflitos entre reservas.</p>
   <fieldset><legend>Comodidades</legend><div class="checkbox-grid">${amenities.map(a=>`<label><input type="checkbox" data-space-amenity value="${a}">${a}</label>`).join('')}</div></fieldset>
   <label>Fotos — uma URL HTTPS por linha<textarea id="space-photos" placeholder="https://seu-site.com/foto.jpg" required></textarea><small>De uma a cinco fotos. Use imagens autorizadas e links diretos (HTTPS).</small></label>
   <label>Regras do local e condições de cancelamento<textarea id="space-rules" maxlength="4000" placeholder="Ex.: limite de som, horários, uso da piscina, limpeza e condições de cancelamento..."></textarea></label>
   <label class="inline-check"><input type="checkbox" id="space-active"> Publicar anúncio e aceitar novas reservas</label>
   <label class="inline-check"><input type="checkbox" required> Sou responsável pelo espaço e tenho autorização para publicar estas informações e imagens.</label>
   <div class="form-error" role="alert"></div><button class="primary" id="save-space">Salvar espaço ↗</button><button class="login" type="button" id="cancel-space">Voltar aos meus espaços</button>
  </form>`);
  const values={'name':space?.name||'','city':space?.city||'Ribeirão Preto','neighborhood':space?.neighborhood||'','address':space?.address||'','type':space?.type||'Salão','capacity':space?.capacity||'150','description':space?.description||'','price':space?.price||'2500','weekend_price':space?.weekendPrice||'4500','in':space?.checkInTime||'09:00','out':space?.checkOutTime||'18:00','minimum':space?.minimumDays||'1','cleanup':space?.cleanupMinutes||'0','photos':space?.photos?.join('\n')||'https://images.unsplash.com/photo-1519167758481-83f550bb49b3?w=800','rules':space?.rules||''};
  for(const [key,value] of Object.entries(values))$('#space-'+key).value=value;
  $('#space-active').checked=space?.active??true;
  document.querySelectorAll('[data-space-event]').forEach(e=>e.checked=(space?.events||['Aniversário']).includes(e.value));
  document.querySelectorAll('[data-space-amenity]').forEach(e=>e.checked=(space?.amenities||[]).includes(e.value));
  $('#cancel-space').onclick=()=>spaceManager();
  $('#space-form').onsubmit=async e=>{
    e.preventDefault();const button=$('#save-space');$('.form-error').textContent='';
    const payload={id:space?.id??null,name:$('#space-name').value.trim(),city:$('#space-city').value.trim(),neighborhood:$('#space-neighborhood').value.trim(),address:$('#space-address').value.trim(),type:$('#space-type').value,capacity:Number($('#space-capacity').value),description:$('#space-description').value.trim(),price:Number($('#space-price').value),weekendPrice:Number($('#space-weekend-price').value),checkInTime:$('#space-in').value,checkOutTime:$('#space-out').value,minimumDays:Number($('#space-minimum').value),cleanupMinutes:Number($('#space-cleanup').value),photos:$('#space-photos').value.split('\n').filter(p=>p.trim()),amenities:[...document.querySelectorAll('[data-space-amenity]:checked')].map(e=>e.value),events:[...document.querySelectorAll('[data-space-event]:checked')].map(e=>e.value),rules:$('#space-rules').value.trim(),active:$('#space-active').checked};
    if(!payload.events.length){$('.form-error').textContent='Selecione pelo menos um tipo de evento.';return}
    if(!payload.photos.length||payload.photos.length>5||payload.photos.some(p=>{try{return new URL(p).protocol!=='https:'}catch{return true}})){$('.form-error').textContent='Informe de uma a cinco fotos com URLs HTTPS válidas.';return}
    button.disabled=true;
    try{await api('save-space',{method:'POST',body:JSON.stringify(payload)});await refreshCatalogue();toast('Espaço salvo.');await spaceManager()}
    catch(err){$('.form-error').textContent=err.message;button.disabled=false}
  };
}

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
    sdk.onAuthStateChanged(auth, (u) => { user = u; });

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
