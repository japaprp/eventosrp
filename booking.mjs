const DAY = 86400000;
export function localDateTime(date = new Date()) {
  const parts = Object.fromEntries(new Intl.DateTimeFormat('en-CA', {
    timeZone: 'America/Sao_Paulo', year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit', hourCycle: 'h23'
  }).formatToParts(date).map(p => [p.type, p.value]));
  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
}
export function parseBookingDate(value) {
  if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(value)) throw new Error('Informe a entrada e a saída.');
  const date = new Date(`${value}:00-03:00`);
  if (!Number.isFinite(date.getTime()) || localDateTime(date) !== value) throw new Error('Data ou horário inválido.');
  return date;
}
export function quoteBooking(checkIn, checkOut, plan, dailyPrice, weekendPrice = null, now = new Date()) {
  const start = parseBookingDate(checkIn), end = parseBookingDate(checkOut);
  if (start < now) throw new Error('A entrada precisa estar no futuro.');
  const duration = end - start;
  if (duration <= 0) throw new Error('A saída precisa ser depois da entrada.');
  const days = Math.ceil(duration / DAY);
  if (days > 30) throw new Error('Para períodos acima de 30 diárias, consulte o atendimento.');
  if (!['daily', 'weekend'].includes(plan)) throw new Error('Escolha diária ou pacote de fim de semana.');
  const dailyCents = Math.round(Number(dailyPrice) * 100);
  let totalCents = dailyCents * days;
  if (plan === 'weekend') {
    const weekday = new Date(`${checkIn.slice(0, 10)}T12:00:00Z`).getUTCDay();
    if (![5, 6].includes(weekday) || duration < 2 * DAY) throw new Error('O pacote exige entrada na sexta ou no sábado e pelo menos 48 horas.');
    totalCents = (weekendPrice == null ? dailyCents * 2 : Math.round(Number(weekendPrice) * 100)) + (days - 2) * dailyCents;
  }
  return { days, total: totalCents / 100 };
}
export function formatBookingDate(value) {
  if (!value) return '';
  return new Intl.DateTimeFormat('pt-BR', { timeZone: 'America/Sao_Paulo', dateStyle: 'short', timeStyle: 'short' }).format(parseBookingDate(value.replace(' ', 'T').slice(0, 16)));
}
