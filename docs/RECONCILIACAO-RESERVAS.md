# Reconciliação de pagamentos e reservas

## Escopo

O workflow consulta a API autenticada a cada 15 minutos e tenta reconciliar até cinco reservas por execução: pendências com prazo de pagamento vencido e tentativas de criação sem preferência de checkout há pelo menos 15 minutos. Ele não faz deploy, não cria checkout, não transfere dinheiro e não emite reembolsos.

A reconciliação consulta pagamentos e a expiração da preferência no Mercado Pago. Quando os dados do provedor estão incompletos, indisponíveis ou não são conclusivos, a reserva permanece bloqueada. A expiração é serializada com o webhook usando bloqueio por reserva e bloqueio da linha do espaço.

## Ativação controlada

1. Aplicar as migrações 004, 005 e 006 somente depois de backup verificado e conferência do schema atual.
2. Configurar RESERVATION_RECONCILE_SECRET no ambiente privado da API e EVENTOSRP_API_URL e RESERVATION_RECONCILE_SECRET nos secrets do GitHub Actions.
3. O segredo deve ter pelo menos 32 caracteres aleatórios e nunca deve ser colocado no repositório ou no frontend.
4. Fazer primeiro uma execução manual do workflow e conferir os logs e estados das reservas.
5. Só depois confiar na agenda automática.

Sem os secrets do GitHub, o workflow pula a execução sem chamar a API. Sem o segredo no servidor, a API retorna 503. A rota exige HTTPS e rejeita bearer token inválido.

## Limites operacionais

- Máximo de cinco reservas por execução.
- O agendamento do GitHub Actions é best-effort e pode atrasar; não é garantia de tempo real.
- Estados desconhecidos, pendentes, em processamento, aprovados, reembolsados, contestados ou respostas incompletas do provedor nunca liberam automaticamente uma data.
- Pagamento aprovado depois de expiração ou em conflito exige análise e eventual reembolso manual. O código não emite reembolsos.
- Reservas antigas sem hold_expires_at não são expiradas retroativamente.
- Monitorar falhas do workflow e logs da API. Este job não comprova sozinho a homologação financeira de ponta a ponta.
