<?php
declare(strict_types=1);
function bookingDate(mixed $value): DateTimeImmutable {
    if (!is_string($value)) throw new InvalidArgumentException('Informe a entrada e a saída.');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, new DateTimeZone('America/Sao_Paulo'));
    if (!$date || $date->format('Y-m-d\TH:i') !== $value) throw new InvalidArgumentException('Data ou horário inválido.');
    return $date;
}
function bookingQuote(mixed $checkIn, mixed $checkOut, mixed $plan, string $dailyPrice, ?string $weekendPrice, ?DateTimeImmutable $now = null): array {
    $start = bookingDate($checkIn); $end = bookingDate($checkOut);
    $now ??= new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));
    if ($start < $now) throw new InvalidArgumentException('A entrada precisa estar no futuro.');
    $seconds = $end->getTimestamp() - $start->getTimestamp();
    if ($seconds <= 0) throw new InvalidArgumentException('A saída precisa ser depois da entrada.');
    $days = (int) ceil($seconds / 86400);
    if ($days > 30) throw new InvalidArgumentException('Para períodos acima de 30 diárias, consulte o atendimento.');
    if (!in_array($plan, ['daily', 'weekend'], true)) throw new InvalidArgumentException('Escolha diária ou pacote de fim de semana.');
    $dailyCents = (int) round((float) $dailyPrice * 100);
    $totalCents = $days * $dailyCents;
    if ($plan === 'weekend') {
        if (!in_array((int) $start->format('N'), [5, 6], true) || $seconds < 172800) throw new InvalidArgumentException('O pacote exige entrada na sexta ou no sábado e pelo menos 48 horas.');
        $totalCents = ($weekendPrice === null ? 2 * $dailyCents : (int) round((float) $weekendPrice * 100)) + ($days - 2) * $dailyCents;
    }
    return ['check_in' => $start->format('Y-m-d H:i:s'), 'check_out' => $end->format('Y-m-d H:i:s'), 'event_date' => $start->format('Y-m-d'), 'days' => $days, 'total' => number_format($totalCents / 100, 2, '.', '')];
}
function bookingOverlaps(string $startA, string $endA, string $startB, string $endB): bool {
    return $startA < $endB && $endA > $startB;
}
