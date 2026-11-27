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
- [ ] Política de privacidade LGPD com controlador, operadores, finalidades, bases legais, direitos, retenção, transferências internacionais e canal do titular.
- [ ] Corrigir o texto provisório atual: ele menciona Firebase e Mercado Pago, mas não descreve Cloudinary nem hospedagem; também promete exclusão sob solicitação sem um fluxo operacional definido.
- [ ] Persistir a aceitação dos termos/privacidade da conta (versão, data/hora e snapshot). Hoje o checkbox de cadastro é validado pelo navegador, mas não é enviado nem persistido pelo backend; o login Google também não comprova esse aceite.
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
- [ ] Confirmar que o cadastro Firebase, perfil MySQL, aceite de termos da conta e recuperação de senha funcionam ponta a ponta.
- [ ] Homologar aprovação administrativa de proprietários, sem possibilidade de autoaprovação.
- [ ] Homologar solicitações de reserva sem pagamento, confirmação/recusa pelo proprietário e isolamento entre contas.

## Evidência técnica mínima antes de abrir

- [ ] MySQL responde `SELECT 1` a partir do serviço PHP.
- [ ] Backup do banco concluído e restauração testada em ambiente separado.
- [ ] Schema real comparado com as migrações pendentes; migrations aplicadas uma única vez e verificadas.
- [ ] Firebase Authentication e validação de ID token testados com duas contas.
- [ ] Upload Cloudinary testado com foto real e tentativa de acesso cruzado entre proprietários rejeitada.
- [ ] Reserva e concorrência testadas no ambiente de homologação.
- [ ] Checkout, webhook e conciliação testados em sandbox (etapa final de pagamento).
- [ ] Logs não expõem tokens, senhas, dados de cartão ou credenciais.
- [ ] Smoke test no domínio público e confirmação de que o catálogo não apresenta placeholders como anúncios.

## Limites operacionais verificados em 27/11/2026

- O serviço MySQL Railway está online, com MySQL 8.4.13 e volume original de 500 MB preservado. O log `ready for connections` não comprova autenticação, schema íntegro ou consulta bem-sucedida.
- As ferramentas de banco Railway não estão habilitadas para esta conta. Handshake autenticado, `SELECT 1`, inventário, backup lógico e restauração permanecem BLOCKED/NOT RUN.
- Backup manual de volume Railway exigiria plano Pro e não foi autorizado. Não executar migrations antes de backup restaurável e preflight.
- O serviço PHP `eventosrp` está online no Railway pela branch `main`, commit `c1431ba4327bb3f35419a3328ea2bcdb7d966a1c`; não contém o PR #19. O PR permanece draft e não foi implantado.
- Não há evidência de teste HTTP externo concluído nesta sessão. Os endpoints `health.php` e `ready.php` existem no PR, mas não na versão atual de `main`.
- O serviço não apresentou variáveis de pagamento `MP_ACCESS_TOKEN` e `MP_WEBHOOK_SECRET`; a integração real permanece reservada para a última etapa.
- O Cloudinary exige três variáveis privadas no ambiente de hospedagem. A conta Free listou 64 assets de amostra e nenhum asset com prefixo `eventosrp`; não reutilizar assets de amostra como conteúdo do cliente.
- A conciliação agendada precisa do mesmo segredo privado no serviço PHP e nos GitHub Actions secrets.
- O aceite de termos da conta ainda não é persistido no backend; os textos legais permanecem provisórios e precisam de dados da operadora e revisão jurídica. O aceite da declaração de autorização do proprietário é um registro separado.
- A aprovação de proprietários usa `PLATFORM_ADMIN_FIREBASE_UID`; essa variável privada precisa ser configurada no servidor antes de aprovar contas.
- O modo de reservas padrão é `request`, sem cobrança. Mercado Pago permanece desativado até a última etapa.
- Cloudflare não tem zona/domínio conectado. WAF/proxy não pode ser habilitado sem domínio próprio; RLS/Hyperdrive não se aplica ao MySQL Railway.
- Não há split, comissão, repasse nem reembolso automático.
