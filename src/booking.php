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
    // reservations.total uses DECIMAL(10,2), including totals for multiple days.
    if ($totalCents > 9999999999) throw new InvalidArgumentException('O valor total excede o limite da reserva online. Consulte o atendimento.');
    return ['check_in' => $start->format('Y-m-d H:i:s'), 'check_out' => $end->format('Y-m-d H:i:s'), 'event_date' => $start->format('Y-m-d'), 'days' => $days, 'total' => number_format($totalCents / 100, 2, '.', '')];
}
function bookingOverlaps(string $startA, string $endA, string $startB, string $endB): bool {
    return $startA < $endB && $endA > $startB;
}
function reservationTermsSnapshot(array $space,array $quote,string $plan,int $guests,string $customerName,string $acceptedAt): string {
    return json_encode([
        'version'=>'1',
        'accepted_at'=>$acceptedAt,
        'space'=>['id'=>(int)$space['id'],'name'=>$space['name'],'city'=>$space['city'],'neighborhood'=>$space['neighborhood']],
        'period'=>['check_in'=>$quote['check_in'],'check_out'=>$quote['check_out'],'plan'=>$plan,'billable_days'=>$quote['days']],
        'customer_name'=>$customerName,
        'guests'=>$guests,
        'total'=>$quote['total'],
        'host_rules'=>trim((string)($space['rules']??''))?:'O anfitrião não informou regras adicionais.',
        'platform_role'=>'O anfitrião é responsável pelas informações do anúncio, pela autorização para locar o espaço, por informar suas regras e por prestar os serviços contratados. O cliente é responsável por fornecer dados corretos, respeitar a capacidade e cumprir as condições apresentadas. O Encontro facilita a divulgação e a solicitação da reserva; isso não afasta responsabilidades previstas em lei. O pagamento é processado pelo Mercado Pago.',
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
}
