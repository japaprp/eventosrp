# Encontro — locação de espaços

Aplicação responsiva em português, com catálogo público e backend PHP 8.2+, MySQL, Firebase Authentication, Firebase Realtime Database e Mercado Pago Checkout Pro. Não exige uma skill ou extensão adicional.

## Executar

1. Instale PHP com extensões PDO MySQL, cURL e mbstring, Composer e MySQL 8.
2. Execute `composer install` na raiz.
3. Copie `.env.example` para `.env` e configure os valores.
4. Importe `database/schema.sql` no MySQL. Os nomes das tabelas são `users`, `spaces` e `reservations`.
5. Execute `php -S localhost:8080 -t public` e abra http://localhost:8080.

Para visualizar apenas o catálogo, abra `public/index.html` ou sirva a pasta `public` com qualquer servidor estático. Login e API dependem do PHP e das credenciais.

## Integrações

- Firebase: habilite e-mail/senha no Authentication, autorize o domínio, preencha os valores públicos `FIREBASE_*` e mantenha o JSON da conta de serviço **fora de public**, apontado por `FIREBASE_CREDENTIALS`. Publique `firebase.rules.json` nas regras do Realtime Database. A API cria a associação entre a reserva e o titular; somente titular e atendentes com claim `admin` acessam o chat.
- Mercado Pago: configure token e segredo do webhook. Use `APP_URL` HTTPS acessível externamente e webhook `https://seu-dominio/api.php?action=webhook`. Use evento de pagamento. O backend valida HMAC e consulta o pagamento na API antes de confirmar valor e moeda. `MP_SANDBOX=true` seleciona checkout de teste. Teste com contas e credenciais de teste antes de habilitar produção.
- MySQL: use usuário de aplicação com privilégios mínimos. Credenciais não são enviadas ao navegador. Consultas são parametrizadas e reservas são consultadas por titular autenticado.

## Fluxos implementados

Filtros combinados por localização, evento, capacidade e categoria; favoritos locais; modal de detalhes; criação de conta, login, recuperação de senha e logout; formulário de reserva; bloqueio concorrente por espaço e data; checkout; confirmação por webhook; painel de reservas do titular; chat por reserva; FAQ e minutas legais. Não há avaliações inventadas.

## Antes de publicar

O catálogo contém **dados de demonstração e fotografias ilustrativas do Unsplash**, sem relação comprovada com os locais descritos. Substitua por espaços autorizados e mantenha os dados de `public/app.js` e `database/schema.sql` sincronizados; o preço e a capacidade usados na reserva sempre vêm do MySQL. O catálogo estático não é um painel de administração de espaços.

Adapte as minutas legais com dados da operadora, canal de privacidade, retenção e condições de cancelamento reais. Cancelamentos/reembolsos e administração de espaços são atendidos operacionalmente, não têm automação nesta versão. Reservas pendentes bloqueiam a data até conciliação operacional; não há expiração automática. Implemente essa rotina antes de operar em escala, sem liberar uma data cujo checkout ainda possa receber pagamento.

Use HTTPS, limites de requisições no proxy, backups protegidos e criptografia de disco no servidor/MySQL. Criptografia em repouso depende da infraestrutura; esta versão não implementa criptografia de campos. A plataforma não coleta número de cartão. O retorno do checkout nunca é usado para confirmar pagamento. Dependências e serviços externos precisam de conectividade. Configure monitoramento e rotina de conciliação de webhooks/pagamentos. Os dados de contato são acessíveis apenas à API autenticada e ao banco protegido.

## Referências

- [Verificação de tokens Firebase](https://firebase.google.com/docs/auth/admin/verify-id-tokens)
- [Regras do Realtime Database](https://firebase.google.com/docs/database/security/rules-conditions)
- [Preferências Checkout Pro](https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/create-preference/post)
- [Assinaturas de webhooks](https://www.mercadopago.com.br/developers/pt/docs/subscriptions/additional-content/your-integrations/notifications/webhooks)
