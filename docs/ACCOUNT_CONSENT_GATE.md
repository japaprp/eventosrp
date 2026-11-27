# Controle de cadastro e consentimento — especificação técnica

**Status:** implementação na branch de homologação; não publicada em produção  
**Data:** 27/11/2026  
**Escopo:** cadastro, autenticação, consentimento de Termos de Uso e Política de Privacidade no Reservas RP.

## 1. Estado inicial observado no código (antes da implementação)

A inspeção foi feita na branch `delivery/2026-11-27-production-readiness`, HEAD `5d23b547aab28e9e77fb14161558e07455921ae3`.

- A tela de cadastro exibe o checkbox `#accept-account` como obrigatório, mas o fluxo de criação de conta por e-mail não envia esse valor à API nem registra uma versão documental ou timestamp.
- A autenticação Google executa `signInWithPopup` diretamente e chama `profile`; não existe um passo equivalente de consentimento para contas novas.
- A API cria/atualiza a linha do usuário em `users` após validar o token Firebase. Não existe tabela de histórico de consentimento da conta.
- O aceite da reserva é independente: a API exige `terms_accepted === true` e registra `terms_version`, `terms_accepted_at` e um snapshot da reserva.
- O site apresenta Termos de Uso e Privacidade como avisos provisórios. Eles próprios informam que faltam identificação/contato do responsável, finalidades e bases legais, compartilhamentos/transferências, retenção e canal de direitos.

## 2. Decisão de segurança

**Não coletar aceite de documentos provisórios como se fossem termos definitivos.** Até a publicação dos documentos finais e a confirmação de suas versões, o cadastro/uso comercial deve permanecer fechado.

O checkbox no navegador não é uma fronteira de segurança. A validação precisa ocorrer no servidor e ser vinculada ao UID autenticado e às versões vigentes dos documentos.

## 3. Implementação técnica na branch de homologação

A primeira implementação foi adicionada à branch `delivery/2026-11-27-production-readiness`:

- `src/account_consent.php` valida versões documentais limitadas e URLs HTTPS sem credenciais ou fragmentos; requisitos ausentes/inválidos retornam configuração indisponível.
- `database/migrations/010_account_consents.sql` cria tabela de auditoria com FK, unicidade por usuário/versões e índice temporal. O código usa `INSERT IGNORE` e não oferece atualização/exclusão do histórico.
- `public/api.php` expõe `GET account-consent-requirements`, `GET account-consent-status` e `POST account-consent`; registra timestamp do MySQL e aplica bloqueio centralizado antes de rotas de negócio, inclusive upload de fotos.
- `public/app.js` solicita confirmação dos documentos vigentes tanto no login por e-mail/senha quanto no Google; o antigo checkbox que apontava para avisos provisórios foi removido.
- O adaptador HTTP isolado usa versões e URLs sintéticas `.invalid` somente quando `eventosrpHttpTestMode()` confirma o banco descartável e os demais marcadores de teste.

**Validação automatizada:** seis workflows no HEAD `77da025b0e1bcb2fa66548e7152ac54e8b5bd54e` concluíram PASS: PHP + frontend, regressão geral, HTTP isolado, MySQL booking concurrency, MySQL payment lifecycle e Firebase rules. Os testes HTTP exercitam bloqueio sem aceite, aceite falso, versão antiga, timestamp do servidor e idempotência. A migration está preparada, não aplicada em nenhum banco.

## 4. Contrato e decisões técnicas

### 4.1 Fonte de verdade no servidor

Configuração privada, definida somente após a aprovação/publicação dos documentos:

- `ACCOUNT_TERMS_VERSION`
- `ACCOUNT_PRIVACY_VERSION`
- `ACCOUNT_TERMS_URL` (HTTPS)
- `ACCOUNT_PRIVACY_URL` (HTTPS)

Se qualquer valor estiver ausente, inválido ou não HTTPS, a API deve responder em modo fechado: não aceitar consentimento e não liberar ações autenticadas que criem/alterem dados ou iniciem uma reserva. A resposta deve informar indisponibilidade de cadastro por configuração documental pendente, sem expor valores internos.

### 4.2 Persistência auditável

A migration aditiva `010_account_consents.sql` e o schema `database/schema.sql` já incluem a tabela `account_consents`:
- `id`: chave primária;
- `user_id`: FK para `users.id`;
- `terms_version` e `privacy_version`: versões aceitas, recebidas e comparadas com a configuração do servidor;
- `accepted_at`: timestamp gerado exclusivamente pelo servidor;
- restrição única para impedir duplicação da mesma combinação de usuário e versões;
- índice por usuário e data.

Não registrar IP, localização ou outros dados de rastreamento sem necessidade demonstrada. O histórico deve ser append-only para a aplicação: nova versão gera novo registro, sem sobrescrever aceite anterior.

### 4.3 Contrato da API

- `GET account-consent-requirements`: exige token Firebase; retorna versões e URLs HTTPS vigentes somente quando a configuração estiver completa.
- `POST account-consent`: exige token Firebase, confirmação booleana explícita e versões exatas devolvidas pelo servidor. O servidor registra `accepted_at`; não aceita timestamp enviado pelo cliente.
- `GET account-consent-status`: informa se o UID possui aceite correspondente às versões vigentes.
- Todas as rotas autenticadas de negócio devem verificar o status antes de executar operações. Somente os endpoints de requisitos, status e gravação do aceite podem ser acessados enquanto faltar consentimento.
- Repetir o mesmo aceite deve ser idempotente. Versão antiga, ausente, adulterada ou checkbox falso deve ser recusado.
- O aceite dos termos específicos de uma reserva permanece separado e não substitui o consentimento de conta.

### 4.4 Fluxos de autenticação

- E-mail/senha: validar os documentos e o aceite antes de liberar o uso das rotas de negócio.
- Google: distinguir conta nova de conta existente e executar o mesmo fluxo de consentimento quando não houver registro vigente.
- Login de conta existente sem consentimento vigente: autenticação pode ocorrer, mas a API mantém as operações bloqueadas até o aceite válido.
- Não confiar em campos enviados pelo cliente para determinar a versão vigente.

**Limite conhecido:** Firebase Authentication Blocking Functions podem impedir a criação antes de o usuário ser salvo, mas a documentação oficial exige upgrade para Firebase Authentication with Identity Platform. A função `beforeUserCreated` também afeta os fluxos de criação de usuários do projeto Firebase. Portanto, não ativar nem solicitar upgrade sem avaliação de custo, impacto em todos os provedores e autorização explícita. A barreira de negócio no backend continua obrigatória mesmo se esse recurso vier a ser adotado.

## 5. Testes e critérios de aceite

1. Sem versões/URLs documentais configuradas: API falha fechada e não libera rotas de negócio.
2. URL HTTP, vazia ou inválida: configuração recusada.
3. Sem token ou token inválido: 401.
4. Aceite ausente ou falso: 422/403, sem registro.
5. Versões enviadas diferentes das versões do servidor: recusado.
6. Aceite válido: grava UID, versões e timestamp gerado pelo servidor.
7. Timestamp arbitrário enviado pelo cliente: ignorado ou recusado; nunca armazenado como timestamp de aceite.
8. Reenvio idêntico: idempotente, sem duplicar registro.
9. Nova versão de qualquer documento: exige novo aceite antes de operações de negócio.
10. Testar o fluxo de e-mail/senha e Google, incluindo conta nova e conta existente.
11. Usuário sem consentimento não consegue reservar, publicar/editar espaço, enviar foto, acessar chat ou consultar dados privados.
12. Aceite de reserva continua exigido separadamente.

## 6. Dependências e sequência segura

1. Confirmar operador legal, contato, conteúdo final dos documentos, bases legais, finalidades, dados, compartilhamentos/transferências, retenção, direitos, cancelamento e reembolso.
2. Revisar e aprovar os documentos; publicar URLs HTTPS e fixar versões imutáveis.
3. Recuperar acesso autorizado ao MySQL de produção, executar preflight somente leitura, obter backup lógico e comprovar restauração isolada.
4. Implementação inicial concluída na branch de homologação; concluir a validação automatizada e a revisão do fluxo de UI antes de considerar a etapa técnica homologada.
5. Validar migration, endpoints e bloqueio em banco descartável, incluindo regressões e concorrência; os testes estão em execução no CI desta revisão.
6. Configurar versões/URLs somente após os documentos estarem efetivamente publicados e aprovados.
7. Fazer homologação completa; produção somente após backup restaurável, preflight e autorização de release.

## 7. Restrições atuais

- Não há confirmação documental suficiente para definir o operador legal ou publicar termos finais.
- Não há backup lógico/restauração comprovados do MySQL de produção; as ferramentas de backup do Railway não estão habilitadas para a conta.
- Não aplicar migration 010 em produção, não publicar documentos provisórios como definitivos e não habilitar Firebase Identity Platform nesta etapa.
- Esta especificação não declara conformidade LGPD nem substitui revisão jurídica.
