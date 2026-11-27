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
| Deployment MySQL | `180017b3-c197-406e-8e8f-cf0962f324d7`, MySQL 8.4.13 chegou a `ready for connections`, depois recebeu `SHUTDOWN`; estado atual `SLEEPING` | BLOCKED — não está ativo agora |
| Métrica de disco | 0,156 GB de uso constante na janela de 7 dias | PASS — existe uso de armazenamento; não prova integridade lógica |
| Proxy TCP | `switchyard.proxy.rlwy.net:48927`, status ACTIVE | PASS — proxy configurado |
| Handshake MySQL | TCP conecta, mas leitura inicial não recebe greeting MySQL | FAIL |
| Railway app | Serviço `eventosrp`, deployment `324e440e-0830-4e44-89c9-7032235e559b`, branch `main`, estado atual `SLEEPING`; Apache iniciou e foi encerrado após inatividade | BLOCKED — domínio existe, mas smoke test externo não foi concluído |
| Health check Railway | `/health.php` e `/ready.php` existem no PR #19, não no commit implantado da `main`; o deployment atual está `SLEEPING` | BLOCKED — requer homologação após publicação autorizada |
| Segredos | Nomes das variáveis visíveis; valores redigidos | PASS — segredos não foram lidos nem expostos |

O serviço MySQL e o volume original continuam existindo. O deployment atual foi executado com sucesso, o log confirmou inicialização completa e `ready for connections`, mas o Railway enviou sinal de shutdown após inatividade. Na leitura mais recente, app e MySQL estavam `SLEEPING` com `sleepApplication=true` (Serverless). Isso é um estado de repouso, não remoção: o Railway acorda o serviço na próxima requisição/conexão. A primeira chamada pode ter cold start e ocasionalmente 502; homologar o comportamento antes da abertura comercial.

## 2. Estado observado e causa operacional atual

O Start Command aplicado é `docker-entrypoint.sh mysqld --innodb-use-native-aio=0 --disable-log-bin --performance_schema=0 --innodb-buffer-pool-size=128M --max-connections=20`. O deployment iniciou MySQL 8.4.13, completou a inicialização InnoDB e registrou `ready for connections`. Cerca de nove minutos depois, o log mostra `Received SHUTDOWN from user <via user signal>` e `Shutdown complete`. A configuração Railway dos dois serviços indica `sleepApplication=true`; ambos aparecem atualmente como `SLEEPING`. Portanto, a redução de memória resolveu a inicialização observada, mas a disponibilidade contínua não está resolvida. A política de sleep/custo deve ser revisada antes de manter a aplicação e o banco ativos para clientes.

O Railway documenta o TCP Proxy para conexões externas e `railway connect`/túnel SSH como alternativa para clientes MySQL; o tráfego por TCP Proxy pode gerar cobrança de egress. Prefira rede privada Railway para app→MySQL quando ambos estiverem ativos no mesmo projeto, e use proxy/túnel apenas para diagnóstico externo autorizado. (https://docs.railway.com/networking/tcp-proxy ; https://docs.railway.com/cli/connect)

Referências oficiais:
- https://docs.railway.com/pricing/free-plan
- https://docs.railway.com/pricing/plans
- https://docs.railway.com/volumes/reference

## 2.1. Resiliência para cold start

A branch inclui uma tentativa limitada de reconexão PDO para erros transitórios de rede/servidor (SQLSTATE/driver codes 1042, 1053, 2002, 2003, 2005, 2006 e 2013, além de SQLSTATE 08001/08S01): até três tentativas, timeout de conexão de 5 segundos e pausas de 1 e 2 segundos. Erros de autenticação e SQL não são repetidos. Isso ajuda quando o Railway Free desperta após sleep, mas não substitui serviço MySQL ativo, configuração correta nem monitoramento.

## 3. Configuração de memória aplicada

Foi preparada uma única alteração **staged**, sem deploy:

```text
docker-entrypoint.sh mysqld --innodb-use-native-aio=0 --disable-log-bin --performance_schema=0 --innodb-buffer-pool-size=128M --max-connections=20
```

- O Start Command atual foi aplicado e o deployment `180017b3-c197-406e-8e8f-cf0962f324d7` iniciou MySQL 8.4.13 com sucesso.
- Buffer pool: 128 MiB; conexões máximas: 20.
- Volume original: preservado e anexado em `/var/lib/mysql`.
- O serviço posteriormente entrou em `SLEEPING` por política de sleep. Não alterar `sleepApplication` nem o plano antes de confirmar custo e obter autorização.

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
7. Para app→MySQL no mesmo projeto/ambiente, usar rede privada Railway. O endpoint privado configurado para MySQL é `mysql`, portanto o hostname interno esperado é `mysql.railway.internal` na porta `3306`; confirmar resolução e handshake antes de ajustar `DB_HOST`. Preferir referências Railway `MYSQLHOST`, `MYSQLPORT`, `MYSQLDATABASE`, `MYSQLUSER` e `MYSQLPASSWORD` para as variáveis `DB_*`. Para cliente externo, preferir `railway connect MySQL --tunnel-only` e usar o túnel local; o TCP Proxy `switchyard.proxy.rlwy.net:48927` fica como alternativa de diagnóstico e pode gerar egress. Nunca registrar senha em logs/issues/chat.
8. Após publicar o PR em ambiente de homologação autorizado e resolver a política de sleep, testar `/health.php` (liveness) e `/ready.php` (readiness MySQL), `SELECT 1`, catálogo, cadastro, upload e reservas. Pagamentos permanecem reservados para a última etapa.

A documentação do Railway confirma que o volume é persistente entre deployments; o deploy do container não deve ser confundido com apagar o volume. Ainda assim, o backup lógico é obrigatório antes de qualquer migração.

## 5. Limites desta execução

- Nenhuma variável secreta foi lida.
- Nenhum serviço ou volume foi criado, apagado, redimensionado ou desconectado.
- Nenhum plano foi alterado.
- O deployment de recuperação do MySQL foi iniciado e concluiu a inicialização; nenhum deploy do PR #19 foi iniciado.
- Nenhuma migração foi executada.
- O conector Aiven apresentou erro interno ao listar projetos e serviços; não foi possível confirmar se existe um banco Aiven alternativo.
- A conta Cloudinary está no plano Free, com 0,17/25 créditos usados e 64 assets de amostra. Nenhum asset foi removido ou reutilizado; os assets atuais não foram tratados como fotos reais do Reservas RP.

## 6. Próxima confirmação operacional

Para tornar a infraestrutura utilizável pelo cliente, ainda é necessário confirmar no painel Railway:
- plano ativo (Free/Trial/Hobby/Pro);
- crédito de uso restante no ciclo;
- se a página do serviço MySQL permite iniciar o deployment sem solicitar upgrade.

Se a operação exigir disponibilidade sem cold start, desabilitar Serverless ou mudar o plano pode gerar custo; não fazer sem autorização. No plano Free, o Railway oferece crédito mensal limitado e coloca serviços inativos em sleep; a primeira requisição acorda o serviço. Verifique Usage e limites antes de qualquer alteração.
