# Checklist técnico de entrega — Reservas RP

## Bloqueadores de abertura

1. Recuperar o MySQL usado pelo Render. O endpoint de catálogo apresentou HTTP 503. GET /ready.php foi criado para executar SELECT 1; confirmar apenas o host/porta (sem senha) do DB_HOST no Render e comparar com o provedor realmente ativo. GET /health.php verifica somente o processo PHP.
2. O serviço MySQL Railway consultado está sem deployment ativo. A porta TCP pública aceita conexão, mas não envia handshake MySQL. Não redeployar sem confirmar custo e persistência do volume.
4. Aiven não pôde ser inventariado pelo conector; se o banco atual estiver lá, verificar o aviso de encerramento da oferta gratuita em 21/12/2026. Não assumir continuidade gratuita.
5. Antes de qualquer migração, exportar backup, calcular SHA-256 e testar restauração em banco separado. Executar `database/preflight_readonly.sql` e inspecionar o schema real antes de decidir quais migrations 002–007 faltam.
6. Configurar Cloudinary no serviço PHP. Sem CLOUDINARY_CLOUD_NAME, CLOUDINARY_API_KEY e CLOUDINARY_API_SECRET, o upload não funciona. A conta Free tem 64 assets de amostra; não há assets com prefixo `eventosrp`.
7. Configurar Firebase service account JSON em FIREBASE_CREDENTIALS_JSON ou manter caminho privado válido em FIREBASE_CREDENTIALS.
8. Validar Mercado Pago em sandbox e depois em produção. Não usar token de teste com MP_SANDBOX=false nem token live com MP_SANDBOX=true.
9. Completar os dados da operadora e a revisão dos termos conforme LEGAL_OPERATIONS_RELEASE_CHECKLIST.md. O aceite de termos da conta ainda não é persistido pelo backend.

## Homologação funcional

- [ ] Após publicar o pacote, configurar o health check do Render para /health.php. Não usar /ready.php como health check de reinício; usar /ready.php para diagnóstico do banco. No `main` atual, esses endpoints ainda não existem e o caminho cai no fallback HTML.

- [ ] Cadastro real de cliente e login.
- [ ] Cadastro real de proprietário.
- [ ] Um proprietário cria três espaços independentes.
- [ ] Um proprietário edita e pausa apenas seus espaços.
- [ ] Outro proprietário não consegue ler ou alterar esses espaços.
- [ ] Upload de fotos e rejeição de URL Cloudinary de outro proprietário.
- [ ] Catálogo público mostra somente espaços ativos e não demonstrativos.
- [ ] Cliente solicita reserva e aceita condições.
- [ ] Servidor recalcula preço e valida horários/capacidade.
- [ ] Duas solicitações concorrentes não ocupam o mesmo intervalo.
- [ ] Checkout e webhook são confirmados no Mercado Pago sandbox.
- [ ] Expiração de pending e limpeza segura de creating parado são testadas em sandbox.
- [ ] Pagamento rejeitado, cancelado, aprovado, reembolsado e contestado são tratados sem liberar indevidamente a data.
- [ ] Workflow de conciliação executa com segredo válido.
- [ ] Atendimento manual e contingência documentados.

## Decisão de release

**NO-GO enquanto MySQL/API estiverem indisponíveis.** O CI aprovado comprova os testes do código, não a operação em produção. Não declarar a entrega concluída até que os itens de bloqueio e os smoke tests acima tenham evidência.


## Build reproduzível

- [ ] Gerar e versionar composer.lock em ambiente PHP/Composer controlado. Hoje o arquivo não está presente, então composer install resolve versões compatíveis no momento do build e a reprodução exata do ambiente ainda não está garantida.
