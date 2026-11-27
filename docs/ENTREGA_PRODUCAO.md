# Checklist técnico de entrega — Reservas RP

Atualizado em 27/11/2026. Este documento distingue testes automatizados do pacote e homologação real de produção.

## Estado confirmado

- PR #19 está aberto, em draft, na branch `delivery/2026-11-27-production-readiness`; não foi integrado.
- Cinco workflows associados ao HEAD atual concluíram com sucesso: `Tests`, `Isolated HTTP booking concurrency`, `MySQL payment lifecycle race tests`, `MySQL booking concurrency integration` e `Reservas RP checks`.
- Serviço PHP `eventosrp` está configurado no Railway, branch `main`, commit `c1431ba4327bb3f35419a3328ea2bcdb7d966a1c`; estado atual `SLEEPING`, `sleepApplication=true`. O deployment não contém o PR #19.
- Serviço MySQL está atualmente `SLEEPING`, com `sleepApplication=true`. O deployment anterior confirmou MySQL 8.4.13 e `ready for connections`, mas o log também mostra shutdown posterior. O volume original de 500 MB continua anexado em `/var/lib/mysql`.
- O start command foi ajustado para buffer pool 128 MiB e máximo de 20 conexões.
- O proxy TCP está ativo em `switchyard.proxy.rlwy.net:48927`, destino porta 3306.
- As ferramentas de banco Railway não estão habilitadas para esta conta. Handshake autenticado, `SELECT 1`, inventário SQL, backup lógico e restauração de teste permanecem BLOCKED/NOT RUN.
- Backup manual de volume Railway exigiria plano Pro; não foi contratado nem autorizado.
- Não houve teste HTTP externo confirmado nesta sessão. O domínio existe, mas a verificação externa retornou erro interno e os logs não registraram requisições. Smoke test, cold start e fluxo real de reservas continuam NOT RUN.
- O ambiente atual da aplicação não tem variáveis `MP_ACCESS_TOKEN` e `MP_WEBHOOK_SECRET`; pagamento real e webhook não estão homologados.
- As variáveis privadas Cloudinary ainda precisam ser configuradas no ambiente que receberá o PR. Firebase também precisa de teste real com contas controladas.
- Nenhuma migration foi executada no banco de produção.

## Próximas ações — ordem de execução

1. [ ] Confirmar por caminho alternativo autorizado uma conexão MySQL autenticada, sem compartilhar credenciais no chat.
2. [ ] Executar `database/preflight_readonly.sql` e salvar evidência sem dados pessoais.
3. [ ] Gerar backup lógico, calcular SHA-256 e testar restauração em banco separado.
4. [ ] Comparar schema real com migrations 002–007; aplicar somente as migrations realmente pendentes, após backup restaurável e autorização operacional.
5. [ ] Homologar o endpoint `/health.php` e `/ready.php` após publicação autorizada do PR.
6. [ ] Configurar e testar Firebase com duas contas controladas.
7. [ ] Configurar Cloudinary e testar upload, validação e isolamento entre proprietários.
8. [ ] Cadastrar um espaço real com autorização, endereço, capacidade, preço, horários, regras e fotos reais.
9. [ ] Validar cadastro, catálogo, autorização, reservas e concorrência em ambiente de homologação.
10. [ ] Revisar os dados da operadora, termos, privacidade, cancelamentos e atendimento.
11. [ ] Deixar Mercado Pago, credenciais reais, webhook e homologação de pagamento para a etapa final, conforme decisão do responsável.

## Bloqueios que não podem ser mascarados

- MySQL `ready for connections` em log histórico não significa serviço ativo agora: estado atual `SLEEPING`. Também não prova autenticação nem schema válido.
- Sem backup restaurável, migrations em banco existente permanecem bloqueadas.
- O serviço está configurado na branch `main`, mas atualmente `SLEEPING`; o PR #19 não está em produção. Não fazer merge ou deploy sem autorização específica.
- Não foi confirmada resposta HTTP pública nesta sessão; logs sem exceções não substituem smoke test.
- Sem Cloudinary configurado, upload real não está disponível.
- Sem teste Firebase real, autenticação ponta a ponta não está homologada.
- Pagamentos e webhooks reais estão intencionalmente reservados para a última etapa.
- Termos e privacidade são provisórios e não podem ser apresentados como documentos jurídicos finais sem dados reais da operadora e revisão apropriada.

## Critério de aceite técnico

A entrega técnica só pode ser marcada como concluída quando os fluxos funcionais forem testados no ambiente destinado ao cliente, com evidência de API, banco, autenticação, armazenamento, isolamento entre proprietários e reservas concorrentes. Os testes CI aprovados são evidência do código em ambiente isolado, não prova de funcionamento da produção.

## Build reproduzível

- [ ] Confirmar e versionar `composer.lock` em ambiente PHP/Composer controlado. Sem lockfile, `composer install` pode resolver versões diferentes em execuções futuras.
- [ ] Definir, após conferir custo/plano, se `sleepApplication` deve ser desabilitado nos serviços app e MySQL para a operação real. A configuração atual deixa ambos `SLEEPING`; não alterar sem autorização de custo.
