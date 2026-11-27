# Recuperação do MySQL original — Railway

**Projeto:** Reservas RP  
**Data da inspeção:** 27/11/2026  
**Escopo:** leitura de configuração, estado do serviço, métricas, teste TCP e estado da API. Nenhum dado foi alterado.

## 1. Evidências verificadas

| Item | Evidência | Estado |
|---|---|---|
| Railway project | `zealous-creativity` | PASS |
| Environment | `production` | PASS |
| Serviço MySQL | ID `0d78fe7c-e239-477f-92c5-9fbaab0c4101`, imagem `mysql:8` | PASS — serviço cadastrado |
| Volume | `mysql-volume`, ID `324b8585-34c2-4826-93ad-09b47d0da25a`, 500 MB | PASS — volume existente e montado |
| Mount | `/var/lib/mysql` | PASS |
| Região | `iad` | PASS |
| Deployment MySQL | Nenhum deployment ativo; histórico com dois deployments `REMOVED` | BLOCKED |
| Métrica de disco | 0,156 GB de uso constante na janela de 7 dias | PASS — existe uso de armazenamento; não prova integridade lógica |
| Proxy TCP | `switchyard.proxy.rlwy.net:48927`, status ACTIVE | PASS — proxy configurado |
| Handshake MySQL | TCP conecta, mas leitura inicial não recebe greeting MySQL | FAIL |
| Render | `GET /api.php?action=spaces` respondeu HTTP 503 em 27/11/2026 às 13:25–13:26 UTC; log PHP: `SQLSTATE[HY000] [2006] MySQL server has gone away` | FAIL |
| Health check Render | Caminho de health check ainda vazio. No código de `main`, `/health.php` e `/ready.php` caem no fallback HTML da SPA (HTTP 200); os novos endpoints JSON existem somente na branch do PR | BLOCKED — configurar somente depois da publicação do código novo |
| Segredos | Nomes das variáveis visíveis; valores redigidos | PASS — segredos não foram lidos nem expostos |

O serviço MySQL continua existindo no Railway e o volume original permanece anexado. O que foi removido foram os deployments históricos; não há evidência de que o volume tenha sido apagado.

## 2. Causa provável a investigar

O comando de inicialização atual do MySQL reserva um buffer pool de 1 GiB:

```text
docker-entrypoint.sh mysqld --innodb-use-native-aio=0 --disable-log-bin --performance_schema=0 --innodb-buffer-pool-size=1G
```

A documentação oficial do Railway informa que o plano Free limita cada serviço a 0,5 GB de RAM e oferece um volume de 0,5 GB, além de US$ 1 em crédito mensal de uso. O buffer pool configurado é maior que a RAM máxima do plano Free e pode impedir a inicialização ou provocar encerramento por memória. A conta/plano efetivo e o crédito restante ainda não puderam ser confirmados pelo conector.

Referências oficiais:
- https://docs.railway.com/pricing/free-plan
- https://docs.railway.com/pricing/plans
- https://docs.railway.com/volumes/reference

## 2.1. Resiliência para cold start

A branch inclui uma tentativa limitada de reconexão PDO para erros transitórios de rede/servidor (SQLSTATE/driver codes 1042, 1053, 2002, 2003, 2005, 2006 e 2013, além de SQLSTATE 08001/08S01): até três tentativas, timeout de conexão de 5 segundos e pausas de 1 e 2 segundos. Erros de autenticação e SQL não são repetidos. Isso ajuda quando o Railway Free desperta após sleep, mas não substitui serviço MySQL ativo, configuração correta nem monitoramento.

## 3. Alteração segura preparada (ainda não aplicada)

Foi preparada uma única alteração **staged**, sem deploy:

```text
docker-entrypoint.sh mysqld --innodb-use-native-aio=0 --disable-log-bin --performance_schema=0 --innodb-buffer-pool-size=128M --max-connections=20
```

- Patch: `d7c1bac7-91ae-4c8c-af98-d7c1356cbe90`
- Alterações staged: 1
- Tipo: atualização de Start Command
- Destrutivo: não
- Volume alterado: não
- Deploy iniciado: não

A redução para 128 MiB é uma configuração conservadora para o limite de 512 MiB do plano Free. É uma hipótese de correção, não uma confirmação de que a memória seja a única causa.

**Não aceitar o deploy ainda** até confirmar que o workspace está no plano Free e que há crédito de uso disponível. O plano Free tem crédito mensal limitado; serviço ativo consome recursos. Não autorizar upgrade Hobby/Pro nem qualquer cobrança para esta recuperação.

## 4. Procedimento de recuperação e backup

1. No painel Railway, confirmar plano atual, crédito de uso restante e ausência de cobrança pendente. Não fazer upgrade.
2. Se o plano Free e o crédito permitirem uma execução temporária, revisar o único patch staged acima e então autorizar sua aplicação. O deploy deve usar o mesmo volume anexado em `/var/lib/mysql`.
3. Aguardar o serviço ficar online e confirmar o greeting MySQL na porta 3306 pelo proxy público.
4. Fazer exportação lógica completa para máquina controlada pelo proprietário, usando credenciais obtidas no painel Railway, nunca enviadas pelo chat:
   - `mysqldump --single-transaction --routines --triggers --events --hex-blob --default-character-set=utf8mb4`
   - guardar o arquivo fora do repositório;
   - calcular SHA-256;
   - enviar cópia para o backup privado do projeto;
   - confirmar tamanho, hash e restauração em MySQL descartável.
5. Executar primeiro `database/preflight_readonly.sql` (somente leitura) e comparar schema, tabelas, índices, chaves estrangeiras e contagens de registros na restauração.
6. Somente após backup restaurável, validar migrações 002–007 em banco descartável e depois planejar a aplicação controlada no banco real.
7. Configurar o Render para usar o endpoint público do proxy Railway, pois o Render não está dentro da rede privada Railway. Host e porta públicos atuais: `switchyard.proxy.rlwy.net:48927`. Não copiar senha para logs, issues ou chat.
8. Após publicar o código novo, configurar o health check Render para `/health.php` (não `/ready.php`, que depende do MySQL), validar JSON de liveness/readiness e então testar `SELECT 1`, catálogo, cadastro, upload, reserva e pagamento em sandbox.

A documentação do Railway confirma que o volume é persistente entre deployments; o deploy do container não deve ser confundido com apagar o volume. Ainda assim, o backup lógico é obrigatório antes de qualquer migração.

## 5. Limites desta execução

- Nenhuma variável secreta foi lida.
- Nenhum serviço ou volume foi criado, apagado, redimensionado ou desconectado.
- Nenhum plano foi alterado.
- Nenhum deploy/rollback foi iniciado.
- Nenhuma migração foi executada.
- O conector Aiven apresentou erro interno ao listar projetos e serviços; não foi possível confirmar se existe um banco Aiven alternativo.
- A conta Cloudinary está no plano Free, com 0,17/25 créditos usados e 64 assets de amostra. Nenhum asset foi removido ou reutilizado; os assets atuais não foram tratados como fotos reais do Reservas RP.

## 6. Próxima confirmação operacional

A única informação que falta para continuar sem gerar custos é a confirmação visual no painel Railway de:
- plano ativo (Free/Trial/Hobby/Pro);
- crédito de uso restante no ciclo;
- se a página do serviço MySQL permite iniciar o deployment sem solicitar upgrade.

Se o painel exigir plano pago ou se o crédito Free estiver esgotado, parar aqui. Não aceitar upgrade automaticamente.
