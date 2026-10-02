# Encontro — locação de espaços

Aplicação responsiva em português, com catálogo público e backend PHP 8.2+, MySQL, Firebase Authentication, Firebase Realtime Database e Mercado Pago Checkout Pro. Não exige uma skill ou extensão adicional.

## Executar

1. Instale PHP com extensões PDO MySQL, cURL e mbstring, Composer e MySQL 8.
2. Execute `composer install` na raiz.
3. Copie `.env.example` para `.env` e configure os valores.
4. Importe `database/schema.sql` no MySQL. Os nomes das tabelas são `users`, `spaces` e `reservations`.
5. Execute `php -S localhost:8080 -t public` e abra http://localhost:8080.

Se o banco já foi criado pela primeira versão, **não reimporte o schema**: aplique uma vez `database/migrations/002_booking_periods.sql`, após backup. Ela acrescenta os campos sem apagar usuários ou reservas. Reservas antigas passam a bloquear o dia inteiro. Se os IDs 7 ou 8 já estiverem ocupados por espaços reais, ajuste os exemplos e o catálogo para evitar divergência; a migração não substitui esses registros.

Depois, aplique uma vez `database/migrations/003_space_management.sql` para habilitar anúncios com proprietário, fotos, horários e preparação entre reservas. Bancos novos devem importar apenas o `schema.sql` atualizado. As migrações não foram executadas neste computador, que não possui MySQL configurado.

Para visualizar apenas o catálogo, abra `public/index.html` ou sirva a pasta `public` com qualquer servidor estático. Login e API dependem do PHP e das credenciais.

## Integrações

- Firebase: habilite e-mail/senha no Authentication, autorize o domínio, preencha os valores públicos `FIREBASE_*` e mantenha o JSON da conta de serviço **fora de public**, apontado por `FIREBASE_CREDENTIALS`. Publique `firebase.rules.json` nas regras do Realtime Database. A API cria a associação entre a reserva e o titular; somente titular e atendentes com claim `admin` acessam o chat.
- Mercado Pago: configure token e segredo do webhook. Use `APP_URL` HTTPS acessível externamente e webhook `https://seu-dominio/api.php?action=webhook`. Use evento de pagamento. O backend valida HMAC e consulta o pagamento na API antes de confirmar valor e moeda. `MP_SANDBOX=true` seleciona checkout de teste. Teste com contas e credenciais de teste antes de habilitar produção.
- MySQL: use usuário de aplicação com privilégios mínimos. Credenciais não são enviadas ao navegador. Consultas são parametrizadas e reservas são consultadas por titular autenticado.

## Fluxos implementados

Filtros combinados por localização, evento, capacidade e categoria; favoritos locais; modal de detalhes; criação de conta, login, recuperação de senha e logout; formulário de reserva; bloqueio concorrente por espaço e data; checkout; confirmação por webhook; painel de reservas do titular; chat por reserva; FAQ e minutas legais. Não há avaliações inventadas.

Ribeirão Preto está disponível no filtro, com dois espaços demonstrativos. O cadastro solicita nome, celular com DDD, e-mail e senha; nome e celular são reutilizados na próxima reserva e armazenados em perfil acessível apenas ao titular.

### Entrada, saída e pacotes

Reservas informam entrada e saída com data e horário de Brasília. Uma diária corresponde a até 24 horas; qualquer fração adicional conta como outra diária. O pacote de fim de semana exige entrada na sexta ou no sábado e no mínimo 48 horas. `spaces.weekend_price` define o preço das primeiras duas diárias; quando é NULL, usa duas vezes `daily_price`. Cada diária adicional usa `daily_price`. Não há desconto implícito. O catálogo pode informar `weekendPrice` para refletir uma tarifa especial; mantenha esse valor sincronizado com o banco. O formulário apresenta uma estimativa, enquanto o backend calcula o total com os preços do MySQL e envia esse total ao Mercado Pago. O limite online é 30 diárias; períodos maiores precisam de atendimento.

O bloqueio verifica sobreposição de todo o intervalo, com trava por espaço durante a criação, incluindo o tempo de preparação configurado. O painel mostra entrada, saída, plano e quantidade de diárias. O chat depende das credenciais e regras Firebase para operar.

### Cadastro de espaços e recuperação de senha

Use **Anunciar espaço** no topo ou **Meus espaços e reservas recebidas** no Painel. Qualquer usuário autenticado pode cadastrar anúncios e editar somente os seus. O cadastro inclui nome, cidade, bairro, endereço público, capacidade, descrição, eventos permitidos, diária, pacote opcional, horários obrigatórios de entrada e saída, mínimo de diárias, comodidades, fotos por URLs HTTPS (até cinco) e regras/cancelamento. O proprietário pode pausar o anúncio sem excluir reservas. Não há upload de arquivos nesta versão.

O catálogo público passa a ler os anúncios ativos do MySQL; sem backend, permanece no modo demonstrativo. Os dados publicados são tratados como texto no navegador. Horários e mínimo de diárias são validados também na API. O tempo de preparação informado pelo proprietário bloqueia novas entradas após a saída, sem aumentar o preço; zero permite entrada exatamente na saída anterior. Reservas existentes preservam o intervalo de preparação gravado no momento da contratação.

O proprietário vê suas reservas recebidas e é incluído na conversa Firebase das novas reservas, junto com o cliente. Os pagamentos continuam direcionados à conta Mercado Pago da plataforma: não há repasse automático ou split para proprietários. Antes de operar como marketplace, estabeleça o fluxo de repasses e as condições comerciais. Anúncios de demonstração não pertencem a usuários cadastrados.

**Esqueci minha senha** abre um formulário separado que exige apenas e-mail. O Firebase envia o link e fornece a página para definir a nova senha. Configure o template, domínio autorizado e proteção contra enumeração de e-mails no Firebase. O aplicativo apresenta uma mensagem genérica de confirmação.

### Verificação

Execute `node tests/frontend.mjs` e `php tests/booking.php`. Os testes verificam Ribeirão Preto, filtros combinados, formulário de cadastro, pré-preenchimento de contato, estimativa, regras de diárias/pacotes, datas inválidas e sobreposição. Testes de login, MySQL, chat e checkout reais dependem de serviços configurados.

Execute também `php tests/spaces.php`, com mbstring habilitado, para validar preços, fotos, capacidade, horários, mínimos e demais campos dos anúncios. Os testes frontend verificam o formulário de espaços e o envio da recuperação de senha com Firebase simulado; envio real de e-mail depende da configuração.

## Antes de publicar

O catálogo inicial contém **dados de demonstração e fotografias ilustrativas do Unsplash**, sem relação comprovada com os locais descritos. Substitua por anúncios reais usando o cadastro e remova ou pause os exemplos no banco antes de publicar. Em operação, o catálogo e as reservas usam preços e capacidade do MySQL.

Adapte as minutas legais com dados da operadora, canal de privacidade, retenção e condições de cancelamento reais. Cancelamentos/reembolsos e administração de espaços são atendidos operacionalmente, não têm automação nesta versão. Reservas pendentes bloqueiam a data até conciliação operacional; não há expiração automática. Implemente essa rotina antes de operar em escala, sem liberar uma data cujo checkout ainda possa receber pagamento.

Use HTTPS, limites de requisições no proxy, backups protegidos e criptografia de disco no servidor/MySQL. Criptografia em repouso depende da infraestrutura; esta versão não implementa criptografia de campos. A plataforma não coleta número de cartão. O retorno do checkout nunca é usado para confirmar pagamento. Dependências e serviços externos precisam de conectividade. Configure monitoramento e rotina de conciliação de webhooks/pagamentos. Os dados de contato são acessíveis apenas à API autenticada e ao banco protegido.

## Referências

- [Verificação de tokens Firebase](https://firebase.google.com/docs/auth/admin/verify-id-tokens)
- [Cadastro e atualização de perfil Firebase](https://firebase.google.com/docs/auth/web/manage-users)
- [Regras do Realtime Database](https://firebase.google.com/docs/database/security/rules-conditions)
- [Preferências Checkout Pro](https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/create-preference/post)
- [Assinaturas de webhooks](https://www.mercadopago.com.br/developers/pt/docs/subscriptions/additional-content/your-integrations/notifications/webhooks)
