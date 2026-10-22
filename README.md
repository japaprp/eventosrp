# Encontro — locação de espaços

Aplicação responsiva em português, com catálogo público e backend PHP 8.2+, MySQL, Firebase Authentication, Firebase Realtime Database e Mercado Pago Checkout Pro. Não exige uma skill ou extensão adicional.

## Executar

1. Instale PHP com extensões PDO MySQL, cURL e mbstring, Composer e MySQL 8.
2. Execute `composer install` na raiz.
3. Copie `.env.example` para `.env` e configure os valores.
4. Importe `database/schema.sql` no MySQL. Os nomes das tabelas são `users`, `spaces` e `reservations`.
5. Execute `php -S localhost:8080 -t public` e abra http://localhost:8080.

Se o banco já foi criado pela primeira versão, **não reimporte o schema**: aplique uma vez `database/migrations/002_booking_periods.sql`, após backup. Ela acrescenta os campos sem apagar usuários ou reservas. Reservas antigas passam a bloquear o dia inteiro. Se os IDs 7 ou 8 já estiverem ocupados por espaços reais, ajuste os exemplos e o catálogo para evitar divergência; a migração não substitui esses registros.

Depois, aplique uma vez `database/migrations/003_space_management.sql` para habilitar anúncios com proprietário, fotos, horários e preparação entre reservas.
Para registrar o aceite das condições nas novas reservas de bancos existentes, aplique uma vez `database/migrations/004_reservation_terms.sql`, depois da 003. A migração mantém reservas antigas e deixa os registros anteriores sem snapshot; bancos novos devem importar apenas o `schema.sql` atualizado. O schema atualizado foi importado e testado em MySQL local descartável; as migrações não foram aplicadas a um banco de operação.

Para visualizar apenas o catálogo, abra `public/index.html` ou sirva a pasta `public` com qualquer servidor estático. Login e API dependem do PHP e das credenciais.

## Integrações

- Firebase: habilite e-mail/senha no Authentication, autorize o domínio, preencha os valores públicos `FIREBASE_*` e mantenha o JSON da conta de serviço **fora de public**, apontado por `FIREBASE_CREDENTIALS`. Publique `firebase.rules.json` nas regras do Realtime Database. A API cria a associação entre a reserva e o titular; somente titular e atendentes com claim `admin` acessam o chat.
- Mercado Pago: configure token e segredo do webhook. Use `APP_URL` HTTPS acessível externamente e webhook `https://seu-dominio/api.php?action=webhook`. Use evento de pagamento. O backend valida HMAC e consulta o pagamento na API antes de confirmar valor e moeda. `MP_SANDBOX=true` seleciona checkout de teste. Teste com contas e credenciais de teste antes de habilitar produção.
- MySQL: use usuário de aplicação com privilégios mínimos. Credenciais não são enviadas ao navegador. Consultas são parametrizadas e reservas são consultadas por titular autenticado.

## Fluxos implementados

Uma única área reúne os filtros por localização, evento, capacidade, tipo de espaço, tipo de reserva, entrada, saída, preço máximo em slider único de R$ 0 a R$ 60 mil e comodidades; ordenação por preço, capacidade e novidade fica junto aos resultados. Também há filtros ativos removíveis, consulta de disponibilidade e cotação pelo período escolhido, incluindo tarifas de fim de semana; favoritos locais; comparação de até três espaços; compartilhamento de anúncios por link; modal de detalhes; criação de conta, login, recuperação de senha e logout; formulário de reserva; bloqueio concorrente por espaço e data; checkout; confirmação por webhook; painel de reservas do titular; chat por reserva; FAQ e minutas legais. Não há avaliações inventadas.

Na busca, selecione entrada, saída e diárias ou pacote de fim de semana para consultar disponibilidade e filtrar pelo total estimado. O único controle de preço define o máximo desejado: sem datas, compara o valor por diária ou pacote de 48 horas; com datas consultadas, compara o total estimado. O limite máximo em R$ 60 mil significa “sem limite”, para não ocultar espaços com preços acima desse valor. A consulta considera horários, mínimo de diárias, regras do pacote, reservas existentes e preparação entre reservas; disponibilidade e preço são validados novamente ao iniciar a reserva.

Antes de solicitar a reserva, o cliente revisa as regras cadastradas pelo anfitrião e as condições da locação, e seu aceite, horário, cotação e cópia dessas regras são registrados no banco. O servidor confere sob trava se nome, localização, regras e total ainda correspondem ao anúncio revisado. Se houver alteração, pede que o cliente atualize a página e revise novamente; o valor final continua calculado no servidor. O texto informa o papel de intermediação da plataforma sem pretender excluir responsabilidades legais. É um resumo operacional, não substitui contrato completo nem revisão jurídica; submeta os termos a um profissional antes do lançamento. Serviços pagos de elaboração ou gestão contratual podem ser avaliados separadamente após definir equipe, responsabilidades, cobertura e conformidade legal.

Cliente e anfitrião podem baixar, nas reservas com aceite registrado, um comprovante HTML autocontido com protocolo, status, período, valor, regras aceitas e horário do aceite; ele pode ser aberto no navegador e impresso ou salvo como PDF. O comprovante explicita que não confirma o pagamento por si só e não substitui contrato completo. Reservas anteriores à migração 004 não têm comprovante de aceite.

Ribeirão Preto está disponível no filtro, com dois espaços demonstrativos. O cadastro solicita nome, celular com DDD, e-mail e senha; nome e celular são reutilizados na próxima reserva e armazenados em perfil acessível apenas ao titular.

### Entrada, saída e pacotes

Reservas informam entrada e saída com data e horário de Brasília. Uma diária corresponde a até 24 horas; qualquer fração adicional conta como outra diária. O pacote de fim de semana exige entrada na sexta ou no sábado e no mínimo 48 horas. `spaces.weekend_price` define o preço das primeiras duas diárias; quando é NULL, usa duas vezes `daily_price`. Cada diária adicional usa `daily_price`. Não há desconto implícito. O catálogo pode informar `weekendPrice` para refletir uma tarifa especial; mantenha esse valor sincronizado com o banco. O formulário apresenta uma estimativa, enquanto o backend calcula o total com os preços do MySQL e envia esse total ao Mercado Pago. O limite online é 30 diárias; períodos maiores precisam de atendimento.

O bloqueio verifica sobreposição de todo o intervalo, com trava por espaço durante a criação, incluindo o tempo de preparação configurado. O painel mostra entrada, saída, plano e quantidade de diárias. O chat depende das credenciais e regras Firebase para operar.

### Cadastro de espaços e recuperação de senha

Use **Anunciar espaço** no topo ou **Área do anfitrião** no menu. Qualquer conta autenticada pode optar por anunciar e editar somente os próprios espaços. O cadastro inclui nome, localização, capacidade, descrição, eventos permitidos, preço, horários, mínimo de diárias, comodidades (aceita pets, TV, Wi-Fi, piscina, ar-condicionado, entre outras), até oito fotos com legendas e uma foto principal, além de regras e cancelamento. O proprietário pode pausar o anúncio sem excluir reservas.

O login é compartilhado: a mesma conta acessa **Minhas reservas** e **Área do anfitrião**; a segunda fica disponível quando a pessoa decide anunciar. Quem reserva pode manter uma única foto de perfil; ela é opcional, substituível e visível somente após autenticação.

O cadastro de espaços é dividido em quatro etapas, mostra o progresso e oferece prévia do anúncio. O anfitrião pode enviar até oito fotos direto do celular ou computador, escolher uma foto principal e adicionar legenda por imagem. As fotos são redimensionadas no navegador, validadas no servidor e armazenadas fora da pasta pública; a API as serve como imagens sem executar conteúdo enviado. O diretório `var/` precisa ser gravável pelo PHP e deve ficar fora do controle de versão. Fotos de espaços são adicionadas pelo fluxo autenticado de anúncio. Rascunhos de novos espaços são salvos automaticamente no navegador e vinculados à conta; não ficam disponíveis em outros dispositivos. O anfitrião pode revisar ou pausar o anúncio antes de publicar.

O catálogo público passa a ler os anúncios ativos do MySQL; sem backend, permanece no modo demonstrativo. A consulta por data considera os horários e o período mínimo do espaço; o preço exibido é uma estimativa para esse período. A disponibilidade final é validada novamente na solicitação da reserva. Os dados publicados são tratados como texto no navegador. Horários e mínimo de diárias são validados também na API. O tempo de preparação informado pelo proprietário bloqueia novas entradas após a saída, sem aumentar o preço; zero permite entrada exatamente na saída anterior. Reservas existentes preservam o intervalo de preparação gravado no momento da contratação.

O proprietário vê suas reservas recebidas e é incluído na conversa Firebase das novas reservas, junto com o cliente. Os pagamentos continuam direcionados à conta Mercado Pago da plataforma: não há repasse automático ou split para proprietários. Antes de operar como marketplace, estabeleça o fluxo de repasses e as condições comerciais. Anúncios de demonstração não pertencem a usuários cadastrados.

**Esqueci minha senha** abre um formulário separado que exige apenas e-mail. O Firebase envia o link e fornece a página para definir a nova senha. Configure o template, domínio autorizado e proteção contra enumeração de e-mails no Firebase. O aplicativo apresenta uma mensagem genérica de confirmação.

### Verificação

Execute `node tests/frontend.mjs` e `php tests/booking.php`. Os testes verificam Ribeirão Preto, filtros combinados, formulário de cadastro, pré-preenchimento de contato, estimativa, regras de diárias/pacotes, datas inválidas e sobreposição. Testes de login, MySQL, chat e checkout reais dependem de serviços configurados.

Execute também `php tests/spaces.php`, com mbstring habilitado, para validar preços, fotos, capacidade, horários, mínimos, consultas de disponibilidade e demais campos dos anúncios. Os testes frontend verificam filtros e disponibilidade, comparação de espaços, formulário de espaços e envio da recuperação de senha com Firebase simulado; envio real de e-mail depende da configuração.

Para verificar a persistência e a concorrência, use `php tests/mysql.php` com PDO MySQL e mbstring. A suíte exige `ENCONTRO_TEST_DATABASE=disposable`, `DB_HOST=127.0.0.1` ou `localhost`, `DB_NAME=encontro_test_*`, `DB_PORT`, `DB_USER` e `DB_PASSWORD`. Ela cria um banco exclusivo com nome aleatório, importa o schema e remove somente esse banco ao terminar, sem carregar `.env`. Confira os comandos e os resultados em [docs/CONTINUIDADE.md](docs/CONTINUIDADE.md). O teste usa callbacks simulados para chat e checkout, sem comprar ou enviar mensagens em serviços externos.

O `composer.lock` registra as versões PHP instaladas e verificadas nesta rodada. Mantenha-o junto do código para reproduzir a instalação.

## Antes de publicar

O catálogo inicial contém **dados de demonstração e fotografias ilustrativas do Unsplash**, sem relação comprovada com os locais descritos. Substitua por anúncios reais usando o cadastro e remova ou pause os exemplos no banco antes de publicar. Em operação, o catálogo e as reservas usam preços e capacidade do MySQL.

Adapte as minutas legais com dados da operadora, canal de privacidade, retenção e condições de cancelamento reais. Cancelamentos/reembolsos e administração de espaços são atendidos operacionalmente, não têm automação nesta versão. Reservas pendentes bloqueiam a data até conciliação operacional; não há expiração automática. Implemente essa rotina antes de operar em escala, sem liberar uma data cujo checkout ainda possa receber pagamento.

A configuração de HTTPS é conferida antes de gravar uma nova reserva. Se o chat falhar antes da tentativa de criar o checkout, a solicitação pode ser marcada como não concluída. Depois que a chamada ao Mercado Pago foi tentada, timeout, resposta inválida ou falha local deixam o período bloqueado para conferência, pois ainda pode existir uma preferência válida no provedor. A API informa o protocolo; consulte o painel e o atendimento antes de repetir a solicitação. A criação de preferência não tem repetição automática. Aprovação recebida antes do retorno do checkout é preservada.

Use HTTPS, limites de requisições no proxy, backups protegidos e criptografia de disco no servidor/MySQL. Criptografia em repouso depende da infraestrutura; esta versão não implementa criptografia de campos. A plataforma não coleta número de cartão. O retorno do checkout nunca é usado para confirmar pagamento. Dependências e serviços externos precisam de conectividade. Configure monitoramento e rotina de conciliação de webhooks/pagamentos. Os dados de contato são acessíveis apenas à API autenticada e ao banco protegido.

## Referências

- [Verificação de tokens Firebase](https://firebase.google.com/docs/auth/admin/verify-id-tokens)
- [Cadastro e atualização de perfil Firebase](https://firebase.google.com/docs/auth/web/manage-users)
- [Regras do Realtime Database](https://firebase.google.com/docs/database/security/rules-conditions)
- [Preferências Checkout Pro](https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/create-preference/post)
- [Assinaturas de webhooks](https://www.mercadopago.com.br/developers/pt/docs/subscriptions/additional-content/your-integrations/notifications/webhooks)
