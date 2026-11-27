# Continuidade da plataforma Encontro

## Estado atual verificado — 27/11/2026

Esta seção prevalece sobre notas históricas abaixo quando houver divergência de status. O repositório é `japaprp/eventosrp`; o trabalho de entrega está isolado em `delivery/2026-11-27-production-readiness`, PR #19 em rascunho. A branch `main` permanece no commit `3c0168ce938be20059aa8eb512a28fb7c75ee829`.

### Código e testes
- O pacote atual inclui cadastro de múltiplos espaços, aprovação administrativa de proprietários, fotos via upload assinado Cloudinary, solicitações de reserva sem cobrança por padrão, confirmação/recusa pelo proprietário, aceite das condições da reserva, ciclo de pagamentos desativado por configuração, reconciliação, endpoints health/readiness e retry PDO limitado.
- Os testes de PHP, frontend, navegador, concorrência MySQL, concorrência HTTP e ciclo de pagamentos passaram no último commit de código testado.
- O workflow HTTP também validou `database/preflight_readonly.sql`, os endpoints `/health.php` e `/ready.php), isolamento de três espaços por proprietário e proteção contra configuração insegura de teste.
- Os últimos commits de documentação não alteram código de produção.
- Não há anúncio real cadastrado ou foto real do proprietário fornecida. O catálogo deve permanecer vazio até haver cadastro real e autorizado.

### Banco e infraestrutura
- O MySQL original permanece cadastrado no Railway, com volume `mysql-volume` de 500 MB montado em `/var/lib/mysql`. Os deployments históricos estão removidos e não há deployment ativo.
- Métrica de disco observada: cerca de 0,156 GB. Isso mostra armazenamento ocupado, não prova integridade lógica.
- O proxy público aceita TCP, mas não envia greeting MySQL. O Render continua registrando HTTP 503 e `SQLSTATE[HY000] [2006] MySQL server has gone away`.
- O comando atual do MySQL usa buffer pool de 1 GiB, acima do limite de 0,5 GB de RAM por serviço do plano Railway Free. Foi preparado patch staged para 128 MiB e 20 conexões; não foi aplicado e nenhum deploy foi iniciado.
- Antes de aplicar o patch, confirmar no painel o plano ativo e o crédito disponível. Não fazer upgrade nem gerar cobrança.
- Aiven não pôde ser inventariado porque o conector retornou erro interno.
- Cloudinary está no plano Free, com 0,17/25 créditos usados e 64 assets de amostra; a busca por prefixo `eventosrp` retornou zero. Nenhuma imagem de amostra foi reutilizada.
- Cloudflare R2 ainda não está habilitado na conta. Nenhum bucket foi criado.

### Pendências comerciais e legais
- As variáveis privadas do Cloudinary, Firebase e Mercado Pago precisam de homologação nos ambientes reais.
- O checkbox de termos no cadastro de conta é validado no navegador, mas seu aceite não é persistido pelo backend; login Google também não registra esse aceite.
- Os textos de termos e privacidade exibidos atualmente são resumos provisórios, não documentos legais completos. Faltam dados da operadora, canal do titular, retenção, bases legais, transferências internacionais, cancelamentos e fluxo de exclusão.
- Não há split ou repasse automático. Reembolsos, chargebacks e contingências exigem processo operacional definido.
- O health check do Render está vazio. Configurar `/health.php` somente depois de publicar a branch; usar `/ready.php` apenas para diagnóstico de MySQL, não como health check de reinício.

### Próximas etapas obrigatórias
1. Confirmar no Railway plano Free/Trial e crédito de uso restante; sem upgrade.
2. Se a execução puder ocorrer dentro do limite gratuito, revisar e aplicar o único patch staged para iniciar o MySQL com memória compatível.
3. Fazer backup lógico, SHA-256 e restauração em banco descartável; executar `database/preflight_readonly.sql`.
4. Inspecionar schema real e comparar com migrations 002–009; aplicar somente as ausentes, depois do backup restaurável e autorização.
5. Corrigir/confirmar host e porta pública do Railway no Render; validar `SELECT 1` e catálogo.
6. Configurar `PLATFORM_ADMIN_FIREBASE_UID`, homologar Firebase e Cloudinary sem expor segredos; manter Mercado Pago para a última etapa.
7. Completar dados da operadora e textos legais, persistir aceite da conta e cadastrar pelo menos um espaço real com fotos autorizadas.
8. Só então revisar release, merge e deploy mediante autorização específica.

### Preservação do checkout local
O checkout local em `C:\Users\AMORIM\Documents\Council-Portfolio-Checkouts\eventosrp` está divergente do branch remoto e contém alterações locais não commitadas. Não executar reset, checkout forçado ou limpeza. O PR remoto é a fonte de verdade até reconciliar o checkout com backup das alterações locais.

**Estado de release: NO-GO / BLOCKED por indisponibilidade do MySQL e pendências de configuração/legal.** Nenhuma migração, merge, deploy, exclusão de dados, alteração de segredos ou upgrade pago foi executado.

---

Atualizado em 10/10/2026. Repositório: https://github.com/japaprp/eventosrp.

## Estado desta entrega

O projeto retomado é a plataforma de locação de espaços, separada do Perifa-Pro. Foram preservadas e verificadas as melhorias locais de busca, fotos, cadastro guiado e comprovante de aceite.

- Catálogo público com Ribeirão Preto, filtros por período, modalidade, capacidade, preço e comodidades; favoritos, comparação e compartilhamento de anúncios.
- Login, cadastro simples e recuperação de senha com Firebase; a configuração externa continua necessária.
- Cadastro de espaços em quatro etapas, horários de entrada/saída, diária/pacote, preparação, até oito fotos com capa e legendas, prévia e rascunho local por conta.
- Reserva registra regras, localização, cotação e horário do aceite. Cliente e anfitrião acessam o comprovante HTML para imprimir ou salvar como PDF.
- Correções desta rodada: reset da data mínima da busca, status de uploads incompletos, validação do limite monetário do banco e conferência das condições revisadas dentro da transação.
- Reservas concorrentes usam a mesma trava por espaço. Regras e preços alterados exigem nova revisão; parâmetros do navegador não definem o preço final.
- Falha de comunicação após iniciar o checkout mantém o período bloqueado até conferência. Uma aprovação antecipada não é substituída por status pendente.
- Dependências instaladas e registradas em `composer.lock`. Skill local de continuidade: `~/.codex/skills/eventosrp-development/SKILL.md`.

## Validação realizada

| Verificação | Resultado |
| --- | --- |
| Frontend | 18 cenários compartilhados de preço/período e verificações de filtros, cadastro, recuperação de senha simulada, comprovante, revisão e upload |
| PHP de reservas | 18 cenários de período/preço, quatro limites monetários e seis sobreposições |
| PHP de espaços | Campos, horários, mínimo, cotações, preparação, fotos e snapshot de condições aprovados |
| MySQL 8.4.11 | 41 verificações aprovadas em banco local exclusivo e descartável |
| Dependências PHP | Instalação, requisitos da plataforma e auditoria do Composer aprovados; nenhuma vulnerabilidade conhecida reportada na execução |
| HTTP local | Página respondeu 200; consulta de reservas sem autenticação respondeu 401 |

O teste MySQL disputa o mesmo período com dois processos PHP e confirma um único vencedor. Também verifica preparação e entrada no limite permitido, preço vindo do banco, alterações nas condições, capacidade, vínculos, preservação do snapshot e rollback por falha injetada. Checkout e chat são callbacks simulados: falha antes do provedor, timeout, resposta inválida, sucesso e aprovação antecipada.

Não foram feitas compras externas, publicação de regras Firebase, envio real de e-mail ou implantação do site. A inspeção visual pelo Edge foi bloqueada pela revisão automática da ferramenta (`blocked by policy`); a conferência visual responsiva permanece pendente.

## Reproduzir os testes

Com PHP 8.2+, PDO MySQL e mbstring e Node instalados:

```powershell
node tests/frontend.mjs
php tests/booking.php
php tests/spaces.php
```

Para MySQL, use uma instância local de teste. O usuário do banco precisa criar/remover o banco exclusivo da suíte; essas permissões não são uma recomendação para o usuário de produção.

```powershell
$env:ENCONTRO_TEST_DATABASE = 'disposable'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '3309'
$env:DB_NAME = 'encontro_test_validacao'
$env:DB_USER = 'root'
$env:DB_PASSWORD = ''
php tests/mysql.php
```

O nome informado precisa começar com `encontro_test_`; a suíte cria seu próprio banco com sufixo aleatório e remove apenas o banco que criou. Ausência da confirmação, host remoto ou nome `encontro` são recusados antes da conexão. Ela não lê `.env`. A porta 3309 e a conta local sem senha pertencem à instância descartável usada nesta rodada.

Neste computador, as ferramentas portáteis ficam em `tools/`, ignorada pelo Git. Use `tools/node.exe` e `tools/php82/php.exe -c tools/events-php.ini` nos comandos acima. A instância de teste deve ser iniciada para executar a suíte.

## Próximas etapas

1. Configurar o ambiente de homologação: MySQL com usuário restrito, domínio HTTPS e `.env` fora da pasta pública. Banco novo importa `schema.sql`; banco existente aplica 002, 003 e 004 em ordem, após backup. Nenhuma migração foi executada em dados de operação.
2. Configurar Firebase Authentication, recuperação de senha, domínios autorizados e regras do Realtime Database. Conferir acesso de cliente, anfitrião e conta sem vínculo às conversas e aos dados privados.
3. Configurar Mercado Pago com contas e credenciais de teste e segredo de webhook. Homologar compra, assinatura, confirmação do valor, repetição de notificações e pagamento aprovado após uma falha de comunicação. O retorno do navegador não confirma pagamento.
4. Definir rotina operacional de conciliação, cancelamentos, reembolsos, retenção de reservas pendentes e repasses a anfitriões. Não expirar reservas automaticamente enquanto houver um checkout que possa ser pago. A conta da plataforma recebe os pagamentos; não há split automático.
5. Conferir o layout em desktop, tablet e celular, substituir anúncios/fotos demonstrativos e revisar textos legais com os dados reais da operadora.

Continuam necessários limites de upload/armazenamento e limpeza de fotos órfãs, além de monitoramento, backup e limites de requisições na infraestrutura. O limite de oito fotos é por anúncio; não limita sozinho o armazenamento acumulado.

Publicar a branch ou a PR no GitHub disponibiliza o código para revisão; não implanta o site nem configura provedores.

## Referências para homologação

- [Contas de teste Mercado Pago](https://www.mercadopago.com.br/developers/pt/docs/checkout-pro-preferences/test-accounts)
- [Preferências Checkout Pro](https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/overview)
- [Configurar Firebase Authentication](https://firebase.google.com/docs/auth/web/start)
- [Regras do Realtime Database](https://firebase.google.com/docs/database/security/rules-conditions)


## Conta, galeria e reserva — 21/10/2026

- A conta exibe dados do perfil, reservas e espaços anunciados; o botão para reservar inicia o login e continua para a reserva após autenticar.
- O catálogo apresenta descrições e abre a galeria do anúncio. Anúncios legados usam a lista de fotos como fallback quando não há detalhes de capa.
- O login por e-mail e Google compartilha o fluxo de autenticação. O Firebase Authentication inicializa separado do Realtime Database, opcional para login/reserva e usado pelo chat.
- A integração foi reconciliada com a versão atual de `main` e as mudanças estão na PR #1. O Render acompanha `main`; migração 004 e verificações de configuração operacional precisam ocorrer antes de anunciar reservas e pagamentos reais.
- A API pública e a disponibilidade ignoram anúncios com `is_demo=1`, e a criação de reserva rejeita demonstrações no servidor. Os exemplos permanecem apenas na prévia; no site conectado, o catálogo inicia vazio até carregar anúncios reais.
- Produção ainda depende de verificar banco e aplicar migração com backup, configurar armazenamento durável para fotos e confirmar credenciais do Mercado Pago em modo de produção. Os anúncios existentes são todos demonstrativos; os anfitriões precisam cadastrar espaços e fotos reais autorizados.
- O teste `node tests/frontend.mjs` passou com 18 cenários, incluindo catálogo sem demos. `git diff --check` passou. Testes PHP/MySQL não foram executados: PHP e Composer não estão instalados neste ambiente.


## Proteção de anúncios demonstrativos em produção — 21/10/2026

A API pública lista somente espaços ativos com `is_demo=0`, e a criação de reserva recusa espaços demonstrativos no servidor. O catálogo informa quando não há anúncios publicados. Esta correção não exige migração e evita que os oito registros atuais, todos demonstrativos, sejam apresentados como ofertas reais. O site precisa de anfitriões cadastrando espaços, fotos e descrições reais autorizadas antes de abrir reservas reais.
