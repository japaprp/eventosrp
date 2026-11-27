# Pendências para abertura comercial — Reservas RP

Este documento é uma lista operacional. Não substitui parecer jurídico nem confirma conformidade legal.

## Dados da operadora

- [ ] Razão social ou nome completo do responsável pela plataforma.
- [ ] CNPJ/CPF aplicável e endereço de contato.
- [ ] E-mail, telefone e canal de suporte.
- [ ] Definição contratual do papel da plataforma: intermediadora, marketplace ou outra estrutura.
- [ ] Definição de quem celebra o contrato de locação: plataforma, proprietário e cliente.

## Termos e transparência

- [ ] Termos de uso completos para clientes.
- [ ] Termos de adesão para proprietários/anfitriões.
- [ ] Política de privacidade LGPD com controlador, operadores, finalidades, bases legais, direitos, retenção e canal do titular.
- [ ] Política de cookies e tecnologias de terceiros, se aplicável.
- [ ] Regras de cancelamento, reembolso, no-show, alterações e disputas.
- [ ] Explicação clara sobre quando uma reserva fica confirmada e quando o pagamento é capturado.
- [ ] Regras de responsabilidade por capacidade, segurança, licenças, ruído, danos e autorização de uso do imóvel.
- [ ] Revisão jurídica dos textos antes de apresentá-los como termos definitivos.

## Pagamentos e atendimento

- [ ] Confirmar conta e credenciais de produção do Mercado Pago.
- [ ] Confirmar URL pública do webhook e assinatura.
- [ ] Definir quem responde por reembolsos, chargebacks e pagamentos aprovados após expiração.
- [ ] Definir comissão, repasses e prazos. Split e repasse automático não estão implementados.
- [ ] Publicar canal de suporte para clientes e proprietários.
- [ ] Definir processo manual de contingência quando o provedor de pagamento estiver indisponível.

## Conteúdo real

- [ ] Cadastrar pelo menos um espaço real, com autorização do proprietário.
- [ ] Conferir endereço, capacidade, horários, preço, regras e política de cancelamento.
- [ ] Enviar fotos reais autorizadas pelo proprietário.
- [ ] Confirmar que não há registros demonstrativos ativos no catálogo.
- [ ] Testar cadastro de cliente e proprietário com contas controladas pelo responsável do projeto.

## Evidência técnica mínima antes de abrir

- [ ] MySQL responde SELECT 1 a partir do serviço Render.
- [ ] Backup do banco concluído e restauração testada em ambiente separado.
- [ ] Migrações 004 a 007 aplicadas uma única vez e verificadas.
- [ ] Firebase Authentication e validação de ID token testados com duas contas.
- [ ] Upload Cloudinary testado com foto real e tentativa de acesso cruzado entre proprietários rejeitada.
- [ ] Reserva, checkout, webhook e conciliação testados em sandbox.
- [ ] Teste de concorrência confirma que somente uma reserva ocupa o mesmo intervalo.
- [ ] Logs não expõem tokens, senhas, dados de cartão ou credenciais.
- [ ] Smoke test no domínio público e confirmação de que o catálogo não apresenta placeholders como anúncios.

## Limites já conhecidos

- A conexão MySQL da API apresentou HTTP 503 e erro MySQL server has gone away.
- O serviço Render está no plano Free; não deve ser tratado como infraestrutura de produção com SLA.
- O Cloudinary precisa de três variáveis privadas no serviço PHP.
- A conciliação agendada precisa de segredo no Render e no GitHub Actions.
- Não há split, comissão, repasse nem reembolso automático.
- O snapshot de aceite registra versão, data/hora, condições do espaço, período e preço; não é assinatura eletrônica formal.
