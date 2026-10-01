# Encontro — locação de espaços

Aplicação responsiva em português, com catálogo público e backend PHP 8.2+, MySQL, Firebase Authentication, Firebase Realtime Database e Mercado Pago Checkout Pro. Não exige uma skill ou extensão adicional.

## Executar

1. Instale PHP com extensões PDO MySQL, cURL e mbstring, Composer e MySQL 8.
2. Execute `composer install` na raiz.
3. Copie `.env.example` para `.env` e configure os valores.
4. Importe `database/schema.sql` no MySQL. Os nomes das tabelas são `users`, `spaces` e `reservations`.
5. Execute `php -S localhost:8080 -t public` e abra http://localhost:8080.

Se o banco já foi criado pela primeira versão, **não reimporte o schema**: aplique uma vez `database/migrations/002_booking_periods.sql`, após backup. Ela acrescenta os campos sem apagar usuários ou reservas. Reservas antigas passam a bloquear o dia inteiro. Se os IDs 7 ou 8 já estiverem ocupados por espaços reais, ajuste os exemplos e o catálogo para evitar divergência; a migração não substitui esses registros.

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

O bloqueio verifica sobreposição de todo o intervalo, com trava por espaço durante a criação. Uma nova entrada exatamente no horário de saída de outra reserva é permitida; não há intervalo de limpeza automático. O painel mostra entrada, saída, plano e quantidade de diárias. O chat existente foi preservado e depende das credenciais e regras Firebase para operar.

### Verificação

Execute `node tests/frontend.mjs` e `php tests/booking.php`. Os testes verificam Ribeirão Preto, filtros combinados, formulário de cadastro, pré-preenchimento de contato, estimativa, regras de diárias/pacotes, datas inválidas e sobreposição. Testes de login, MySQL, chat e checkout reais dependem de serviços configurados.

## Antes de publicar

O catálogo contém **dados de demonstração e fotografias ilustrativas do Unsplash**, sem relação comprovada com os locais descritos. Substitua por espaços autorizados e mantenha os dados de `public/app.js` e `database/schema.sql` sincronizados; o preço e a capacidade usados na reserva sempre vêm do MySQL. O catálogo estático não é um painel de administração de espaços.

Adapte as minutas legais com dados da operadora, canal de privacidade, retenção e condições de cancelamento reais. Cancelamentos/reembolsos e administração de espaços são atendidos operacionalmente, não têm automação nesta versão. Reservas pendentes bloqueiam a data até conciliação operacional; não há expiração automática. Implemente essa rotina antes de operar em escala, sem liberar uma data cujo checkout ainda possa receber pagamento.

Use HTTPS, limites de requisições no proxy, backups protegidos e criptografia de disco no servidor/MySQL. Criptografia em repouso depende da infraestrutura; esta versão não implementa criptografia de campos. A plataforma não coleta número de cartão. O retorno do checkout nunca é usado para confirmar pagamento. Dependências e serviços externos precisam de conectividade. Configure monitoramento e rotina de conciliação de webhooks/pagamentos. Os dados de contato são acessíveis apenas à API autenticada e ao banco protegido.

## Referências

- [Verificação de tokens Firebase](https://firebase.google.com/docs/auth/admin/verify-id-tokens)
- [Cadastro e atualização de perfil Firebase](https://firebase.google.com/docs/auth/web/manage-users)
- [Regras do Realtime Database](https://firebase.google.com/docs/database/security/rules-conditions)
- [Preferências Checkout Pro](https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/create-preference/post)
- [Assinaturas de webhooks](https://www.mercadopago.com.br/developers/pt/docs/subscriptions/additional-content/your-integrations/notifications/webhooks)
