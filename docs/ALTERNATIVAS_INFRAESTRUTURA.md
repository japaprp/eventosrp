# Alternativas de infraestrutura — Reservas RP

Data da avaliação: 27/11/2026. Comparação voltada a manter o custo inicial em zero sem sacrificar os dados existentes.

## Resumo executivo

A prioridade é recuperar o banco original e preservar os registros reais. Não criar um banco vazio e apontar a aplicação para ele antes de exportar e validar o banco atual.

### MySQL

| Provedor | Situação | Compatibilidade | Decisão |
|---|---|---|---|
| Railway MySQL original | Serviço e volume ainda existem; deployments históricos removidos; serviço offline; TCP aceita conexão mas não envia greeting MySQL | MySQL nativo; volume de 500 MB ainda montado em /var/lib/mysql | Recuperar o serviço existente e exportar os dados antes de qualquer migração; não criar outro serviço/volume |
| Aiven MySQL Free | Inventário da conta não pôde ser confirmado; encerramento do plano Free anunciado para 21/12/2026 | MySQL nativo | Se for o banco atual, exportar antes do encerramento; não usar como destino permanente gratuito |
| TiDB Cloud Starter | Plano gratuito disponível, sujeito a quotas | Protocolo MySQL e grande parte da sintaxe MySQL 8 | Melhor candidato gratuito para migração controlada, ainda não homologado pelo Reservas RP |
| PlanetScale | Não oferece plano gratuito | MySQL-compatible/Vitess, pago | Descartado para a exigência de custo zero |
| Render Postgres | Plano Free disponível, mas é PostgreSQL | Não é MySQL | Exige migração de schema, consultas e driver; não usar como correção emergencial |
| Supabase Free | PostgreSQL | Não é MySQL | Alternativa futura, não drop-in |
| Cloudflare D1 | SQLite distribuído | Não é MySQL | Exige reescrever a camada de persistência; não usar nesta entrega |

## TiDB Cloud Starter — candidato gratuito

A documentação oficial informa, para o modo MySQL-compatible Starter:

- Até cinco instâncias gratuitas por organização por padrão.
- Até 5 GiB de armazenamento row-based e 5 GiB columnar por instância.
- 50 milhões de Request Units (RUs) por mês.
- É possível manter o limite de gasto em zero; exceder a quota pode suspender novas conexões até a renovação ou aumento autorizado.
- Suporta o protocolo MySQL e as funções GET_LOCK/RELEASE_LOCK.
- Diferença relevante: o timeout mínimo de GET_LOCK é 1 segundo; um timeout 0 do MySQL é convertido para 1 segundo.

O Reservas RP usa PDO MySQL, transações, SELECT FOR UPDATE, JSON, ENUM e bloqueios GET_LOCK. A compatibilidade parece promissora, mas ainda precisa de teste de integração completo em instância descartável. A documentação de compatibilidade não substitui homologação do schema e das consultas do projeto.

**Regra de segurança:** se TiDB for escolhido, criar a instância somente com limite de gasto zero, exportar e validar backup do banco anterior, importar em ambiente isolado, executar testes de concorrência e só então preparar a troca de DB_HOST. Não migrar diretamente sem cópia restaurável.

Referências:
- https://docs.pingcap.com/tidbcloud/serverless-limitations/
- https://docs.pingcap.com/tidb/stable/locking-functions/
- https://docs.pingcap.com/tidb/stable/mysql-compatibility/

## Armazenamento de imagens

| Opção | Situação | Decisão |
|---|---|---|
| Cloudinary existente | Conta conectada e uso observado baixo | Usar no MVP; configurar cloud name, API key e API secret somente no backend |
| Cloudflare R2 | Conta conectada, mas a API respondeu 403 `Please enable R2 through the Cloudflare Dashboard`; nenhum bucket pode ser listado | Não ativado; exige habilitação no painel e configuração S3, CORS, upload e entrega de imagens |
| Render filesystem | Efêmero no plano Free | Nunca armazenar fotos de clientes ou espaços localmente |

O Cloudinary é o caminho de menor mudança porque a integração assinada já foi preparada. A conta está no plano Free, com 0,17/25 créditos usados e 64 assets de amostra (não são fotos reais do Reservas RP). Não copiar esses assets nem usar imagens genéricas como fotos de anúncios.

## Aplicação e pagamentos

- Render Free: adequado para preview/homologação, não para operação comercial com disponibilidade garantida; serviço pode dormir após 15 minutos e o filesystem é efêmero.
- Stripe: a conta conectada foi identificada em modo de teste; não substituir o Mercado Pago nem prometer split/repasse.
- Mercado Pago: manter a integração atual, homologar primeiro em sandbox e documentar que reembolso, chargeback e repasse aos proprietários continuam manuais.
- GitHub Actions: usar testes e conciliação agendada somente depois de configurar segredos privados e validar o endpoint.

## Plano recomendado

1. MySQL original localizado no Railway: serviço MySQL e volume mysql-volume (500 MB) permanecem no projeto; deployments estão removidos e serviço offline. Preservar o volume e não criar outro serviço.
2. Confirmar plano/limite de uso gratuito antes de iniciar o serviço. O comando atual reserva buffer pool de 1 GiB, acima dos 0,5 GB de RAM por serviço do plano Free; há uma alteração staged para 128 MiB e 20 conexões, ainda sem deploy. Depois de iniciar, exportar backup lógico e testar restauração isolada.
3. Se o banco original for recuperável, manter MySQL e corrigir somente host, rede, SSL/CA e credenciais no Render.
4. Se o banco original não puder ser recuperado, avaliar TiDB Cloud Starter com spending limit zero e teste de compatibilidade.
5. Migrar somente depois de comparar contagem de registros, chaves, índices, JSON, ENUM, transações e resultados dos testes concorrentes.
6. Depois, configurar Cloudinary e homologar cadastro, fotos, reserva, checkout e webhook.

Nenhuma instância, volume, bucket ou plano pago foi criado nesta avaliação.


## Experiência da comunidade (sinal anedótico, não substitui documentação)

- Uma discussão recente no Reddit relata cobrança acima do crédito mensal no Railway Hobby e recomenda conferir RAM/uso e política de sleep antes de migrar ou manter serviços ligados. Outro comentário observa que o primeiro request após sleep pode ter cold start. Isso reforça a decisão de não iniciar o MySQL antes de confirmar plano/crédito e de implementar retry limitado para falhas transitórias: https://www.reddit.com/r/microsaas/comments/1xj7d5a/railway_hobby_is_costing_me_1250month_any_cheaper/
- Discussões de hospedagem de imagens citam R2 como alternativa simples quando a quota gratuita atende, mas há custo de configuração de bucket, políticas e CORS; no nosso caso, R2 ainda não está habilitado, enquanto Cloudinary já tem conta Free e integração preparada: https://www.reddit.com/r/indiedev/comments/1s5q4wd/best_cloudinary_alternatives_for_simple_public/

Esses relatos são experiências individuais e podem estar sujeitos a planos/limites antigos. A decisão de infraestrutura continua baseada nos limites e preços publicados pelos provedores.
