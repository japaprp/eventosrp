import { readFileSync } from "node:fs";
import {
  initializeTestEnvironment,
  assertFails,
  assertSucceeds,
} from "@firebase/rules-unit-testing";
import { ref, get, set } from "firebase/database";

const projectId = "demo-reservas-rp";
const reservationId = "reservation-rules-test";
const validMessage = (sender, text = "Olá", createdAt = 1_795_000_000_000) => ({
  sender,
  text,
  createdAt,
});

const testEnv = await initializeTestEnvironment({
  projectId,
  database: {
    rules: readFileSync("database.rules.json", "utf8"),
    host: "127.0.0.1",
    port: 9000,
  },
});

let passed = 0;
async function check(name, assertion) {
  await assertion;
  passed += 1;
  process.stdout.write(`PASS ${passed}: ${name}\n`);
}

try {
  await testEnv.withSecurityRulesDisabled(async (context) => {
    const adminDb = context.database();
    await set(ref(adminDb, `chats/${reservationId}/members/member-a`), true);
    await set(ref(adminDb, `chats/${reservationId}/members/member-b`), true);
    await set(ref(adminDb, `chats/${reservationId}/messages/existing`), validMessage("member-a", "Mensagem existente"));
  });

  const memberA = testEnv.authenticatedContext("member-a").database();
  const memberB = testEnv.authenticatedContext("member-b").database();
  const outsider = testEnv.authenticatedContext("outsider").database();
  const anonymous = testEnv.unauthenticatedContext().database();

  await check("membro autorizado consegue ler mensagens", assertSucceeds(
    get(ref(memberA, `chats/${reservationId}/messages`))
  ));
  await check("segundo membro autorizado consegue ler mensagens", assertSucceeds(
    get(ref(memberB, `chats/${reservationId}/messages`))
  ));
  await check("usuário externo não consegue ler mensagens", assertFails(
    get(ref(outsider, `chats/${reservationId}/messages`))
  ));
  await check("usuário anônimo não consegue ler mensagens", assertFails(
    get(ref(anonymous, `chats/${reservationId}/messages`))
  ));
  await check("membro não consegue ler o nó privado completo da conversa", assertFails(
    get(ref(memberA, `chats/${reservationId}`))
  ));
  await check("membro consegue acrescentar mensagem válida", assertSucceeds(
    set(ref(memberA, `chats/${reservationId}/messages/new-valid`), validMessage("member-a"))
  ));
  await check("membro não consegue sobrescrever mensagem existente", assertFails(
    set(ref(memberA, `chats/${reservationId}/messages/existing`), validMessage("member-a", "Edição"))
  ));
  await check("usuário externo não consegue escrever", assertFails(
    set(ref(outsider, `chats/${reservationId}/messages/outsider`), validMessage("outsider"))
  ));
  await check("remetente não pode falsificar outro UID", assertFails(
    set(ref(memberA, `chats/${reservationId}/messages/spoof`), validMessage("member-b"))
  ));
  await check("mensagem sem campo obrigatório é rejeitada", assertFails(
    set(ref(memberA, `chats/${reservationId}/messages/missing`), { sender: "member-a", text: "Sem data" })
  ));
  await check("mensagem com texto vazio é rejeitada", assertFails(
    set(ref(memberA, `chats/${reservationId}/messages/empty`), validMessage("member-a", ""))
  ));
  await check("mensagem acima de 1000 caracteres é rejeitada", assertFails(
    set(ref(memberA, `chats/${reservationId}/messages/long`), validMessage("member-a", "x".repeat(1001)))
  ));
  await check("campos extras são rejeitados", assertFails(
    set(ref(memberA, `chats/${reservationId}/messages/extra`), { ...validMessage("member-a"), admin: true })
  ));
  await check("cliente não pode alterar a lista de membros", assertFails(
    set(ref(memberA, `chats/${reservationId}/members/outsider`), true)
  ));

  process.stdout.write(`Firebase Realtime Database rules: ${passed}/14 checks PASS\n`);
} finally {
  await testEnv.cleanup();
}
