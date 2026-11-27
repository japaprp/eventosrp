-- Executar somente após backup restaurável e preflight do schema real.
-- Solicitações sem cobrança têm estados próprios; não reutilizam "pending" de pagamento.
ALTER TABLE reservations
    MODIFY COLUMN status ENUM(
        'creating',
        'requested',
        'confirmed',
        'declined',
        'pending',
        'approved',
        'failed',
        'cancelled',
        'expired'
    ) NOT NULL DEFAULT 'creating';
