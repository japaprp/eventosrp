# Reservas RP — locação de espaços

Aplicação web em português com catálogo público, cadastro de clientes e proprietários, anúncios individuais, reservas por período, Firebase Authentication/Realtime Database, MySQL, Mercado Pago Checkout Pro e upload de imagens pelo Cloudinary.

## Estado operacional verificado — 27/11/2026

- O pacote de entrega está no PR #19, branch `delivery/2026-11-27-production-readiness`, mantido como draft.
- Cinco workflows GitHub Actions associados ao HEAD atual concluíram com sucesso: testes gerais, concorrência HTTP isolada, ciclo de vida de pagamento MySQL, concorrência de reservas MySQL e verificações do Reservas RP.
- O serviço PHP `eventosrp` está configurado no Railway, conectado à branch `main`, commit `c1431ba4327bb3f35419a3328ea2bcdb7d966a1c`. Na inspeção de 27/11/2026 às 17:31 UTC, o estado atual era `SLEEPING`; `sleepApplication=true`. O log comprova que Apache iniciou e foi encerrado pelo Railway após o período sem tráfego. Esse deployment não contém as alterações exclusivas do PR #19.
- O serviço MySQL Railway está configurado com `sleepApplication=true` e também estava `SLEEPING` na inspeção de 27/11/2026 às 17:31 UTC. O log do deployment comprova que MySQL 8.4.13 chegou a `ready for connections` e depois recebeu `SHUTDOWN` do Railway. O volume original de 500 MB permanece anexado; isso não comprova que o serviço esteja aceitando conexões agora.
- O estado online do processo MySQL não comprova autenticação, integridade do schema ou acesso aos dados. O conector de banco Railway não está habilitado nesta conta; `SELECT 1`, inventário SQL, backup lógico e restauração permanecem não executados.
- O domínio Railway existe, mas o teste HTTP externo não foi concluído (o acesso de verificação retornou erro interno) e não houve requisições HTTP nos logs consultados. Como o deployment está `SLEEPING`, a disponibilidade real e o cold start ainda precisam ser testados.
- O PR #19 não foi integrado nem implantado. Não executar migrations em produção antes de backup restaurável e preflight no banco real.

Não declare a aplicação pronta para abertura comercial até concluir as validações operacionais, de armazenamento, autenticação, conteúdo real e revisão legal descritas em `docs/ENTREGA_PRODUCAO.md` e `docs/LEGAL_OPERATIONS_RELEASE_CHECKLIST.md`.

## Executar localmente

1. Instale PHP 8.2+ com extensões PDO MySQL, cURL e mbstring, Composer e MySQL 8.
2. Execute `composer install`.
3. Copie `.env.example` para `.env` e configure os valores privados.
4. Para um banco novo, importe `database/schema.sql`.
5. Execute `php -S localhost:8080 -t public` e abra `http://localhost:8080`.

Não reimporte o schema em banco existente. Faça backup e aplique, uma única vez, as migrações pendentes: `002_booking_periods.sql`, `003_space_management.sql`, `004_reservation_agreement.sql`, `005_payment_lifecycle.sql`, `006_space_authorization.sql` e `007_hide_demo_spaces.sql`. Confirme o estado de cada tabela antes de executar. Nenhuma migração foi aplicada ao banco Railway de produção nesta entrega.

## Variáveis privadas

- MySQL: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` e, quando o provedor exigir CA privada, `DB_SSL_CA`.
- Firebase: `FIREBASE_CREDENTIALS_JSON` (JSON completo da conta de serviço) ou `FIREBASE_CREDENTIALS` (caminho privado para o arquivo JSON), além das variáveis públicas `FIREBASE_*`.
- Mercado Pago: `MP_ACCESS_TOKEN`, `MP_WEBHOOK_SECRET`, `MP_SANDBOX`, `APP_URL`.
- Cloudinary: `CLOUDINARY_CLOUD_NAME`, `CLOUDINARY_API_KEY`, `CLOUDINARY_API_SECRET`.
- Conciliação agendada: `RESERVATION_RECONCILE_SECRET` (mínimo 32 caracteres aleatórios).

Nunca coloque segredos em `public/`, no JavaScript, no repositório ou em mensagens. O frontend recebe somente URLs de imagens e configurações públicas do Firebase.

## Cadastro de clientes e proprietários

O cadastro e o login são feitos pelo Firebase Authentication. O backend valida o ID token antes de acessar dados privados. O perfil de nome e telefone é sincronizado com MySQL no primeiro acesso autenticado à API; se o banco estiver indisponível, a conta Firebase pode existir, mas o perfil e as reservas não estarão operacionais até a recuperação do banco.

O proprietário pode cadastrar mais de um espaço na mesma conta. Cada anúncio possui id e calendário de reservas próprios. A API filtra por `owner_id` e verifica a titularidade antes de permitir edição. O formulário não preenche cidade, capacidade, preços, tipo ou horários com valores fictícios. O anúncio começa pausado; o proprietário precisa confirmar os dados e publicar.

A declaração de responsabilidade/autorização do proprietário é obrigatória e fica registrada no MySQL com data/hora e versão do texto.

## Fotos dos espaços

O formulário permite selecionar imagens do dispositivo. O navegador otimiza a imagem para JPEG e o backend valida o arquivo e o envia ao Cloudinary. Cada foto é guardada em uma pasta vinculada ao hash da conta Firebase do proprietário. A API não aceita que uma conta utilize URLs Cloudinary de outra conta. O limite atual é cinco fotos por anúncio, até 2 MB cada após otimização.

Configure as três variáveis `CLOUDINARY_*` no serviço PHP antes de habilitar o upload. Se estiverem ausentes, o endpoint responde indisponível; não há fallback para fotografias de demonstração. Imagens antigas hospedadas em URLs HTTPS externas podem permanecer nos anúncios existentes, mas novos uploads devem usar o formulário.

Fotos removidas de um anúncio são excluídas do Cloudinary somente depois de confirmar que não estão associadas a outro anúncio do mesmo proprietário. Falhas de limpeza são registradas e não apagam imagens pertencentes a outros proprietários.

## Reservas e pagamentos

- O servidor calcula o preço usando o MySQL; o navegador apresenta apenas uma estimativa.
- Uma diária cobre até 24 horas; fração adicional conta como nova diária. O pacote de fim de semana exige entrada na sexta ou no sábado e pelo menos 48 horas.
- A criação usa transação e bloqueio da linha do espaço para impedir reservas simultâneas sobrepostas.
- O backend exige aceite explícito das condições e grava versão, data/hora e snapshot dos preços, datas, regras e capacidade aceitos. Dados pessoais de contato não são duplicados no snapshot.
- O webhook valida assinatura, referência, moeda, valor e modo de operação antes de atualizar a reserva.
- O ciclo de pagamentos registra eventos idempotentes, preserva reservas aprovadas e mantém bloqueios quando o estado do provedor é desconhecido.
- O workflow de conciliação pode tentar até cinco reservas por execução: reservas pendentes vencidas e tentativas de checkout que ficaram em `creating` sem preferência por 15 minutos, mas só funciona depois de configurar `EVENTOSRP_API_URL` e `RESERVATION_RECONCILE_SECRET` nos secrets do GitHub Actions e o mesmo segredo no servidor. Sem isso, ele não chama a API.
- Não existe reembolso automático, split, comissão ou repasse automático aos proprietários. Exceções de reembolso, contestação e chargeback exigem atendimento manual.
- O registro de aceite melhora a rastreabilidade, mas não é apresentado como assinatura eletrônica formal nem substitui revisão jurídica.

## Dados demonstrativos e privacidade

O catálogo público não apresenta espaços `is_demo=1`. O schema de banco novo e a migração 002 não inserem espaços fictícios. A migração 007 pausa registros demonstrativos existentes sem apagá-los. Não execute exclusões de dados sem backup e conferência prévia.

O site usa uma ilustração abstrata no hero e um placeholder identificado quando um anúncio ainda não possui foto. Não usa fotografias de banco de imagens como se fossem fotos de espaços cadastrados. Dados de clientes e proprietários devem ser coletados apenas para as funções necessárias; a política e os termos gerais ainda precisam receber a identificação real da operadora, canais de contato, retenção de dados, regras de cancelamento e revisão jurídica.

## Saúde da aplicação

- `GET /health.php` verifica somente se o processo PHP responde (liveness).
- `GET /ready.php` executa `SELECT 1` no MySQL e responde 503 se o banco não estiver disponível (readiness).
- Os endpoints existem no PR #19. A versão atualmente implantada no Railway vem da branch `main` e ainda não contém esses endpoints; eles só estarão disponíveis após uma publicação autorizada do pacote.

## Testes

```bash
node tests/frontend.mjs
php tests/booking.php
php tests/spaces.php
php tests/cloudinary.php
php tests/payment_lifecycle.php
```

Os workflows do GitHub também executam testes com MySQL descartável para concorrência HTTP, bloqueio de reconciliação e corrida entre webhook e expiração. Esses testes usam fixtures explicitamente isoladas; não criam clientes nem anúncios na produção.

## Referências de configuração

- Firebase ID tokens: https://firebase.google.com/docs/auth/admin/verify-id-tokens
- Firebase Authentication: https://firebase.google.com/docs/auth/web/manage-users
- Firebase Realtime Database rules: https://firebase.google.com/docs/database/security
- Mercado Pago Checkout Pro: https://www.mercadopago.com.br/developers/pt/docs/checkout-pro/landing
- Mercado Pago Webhooks: https://www.mercadopago.com.br/developers/pt/docs/your-integrations/notifications/webhooks
- Cloudinary Upload API: https://cloudinary.com/documentation/image_upload_api_reference
- Cloudinary PHP upload: https://cloudinary.com/documentation/php_image_and_video_upload

## Recuperação do banco de produção

Consulte [docs/RECUPERACAO_MYSQL_RAILWAY.md](docs/RECUPERACAO_MYSQL_RAILWAY.md) para as evidências do Railway, os pré-requisitos de backup e a sequência segura de restauração.

## Alternativas gratuitas de infraestrutura

Consulte [docs/ALTERNATIVAS_INFRAESTRUTURA.md](docs/ALTERNATIVAS_INFRAESTRUTURA.md) para a comparação de provedores e armazenamento.

## Checklist de liberação

Consulte [docs/ENTREGA_PRODUCAO.md](docs/ENTREGA_PRODUCAO.md) e [docs/LEGAL_OPERATIONS_RELEASE_CHECKLIST.md](docs/LEGAL_OPERATIONS_RELEASE_CHECKLIST.md) antes da abertura pública. O endpoint de reconciliação e o workflow agendado estão preparados, mas permanecem inativos até a configuração do segredo privado.

## Estado atual de execução — atualização 27/11/2026

Os serviços PHP e MySQL foram encontrados em `SLEEPING`, com `sleepApplication=true`. O MySQL iniciou corretamente e registrou `ready for connections`, mas recebeu sinal de shutdown após inatividade. Não afirmar que banco ou aplicação estão online enquanto o estado atual for `SLEEPING`. Antes de homologação comercial, revisar a política de sleep e o custo de manter os dois serviços ativos; não desabilitar o sleep nem alterar o plano sem autorização explícita. Consulte `docs/RECUPERACAO_MYSQL_RAILWAY.md`.
