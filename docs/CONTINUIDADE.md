# Continuidade da plataforma Encontro

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
