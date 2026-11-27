<?php
declare(strict_types=1);

function isTransientDatabaseConnectionError(PDOException $error): bool {
    $sqlState = (string) $error->getCode();
    if (in_array($sqlState, ['08001', '08S01'], true)) return true;

    $driverCode = isset($error->errorInfo[1]) ? (int) $error->errorInfo[1] : 0;
    if ($driverCode === 0 && ctype_digit($sqlState)) $driverCode = (int) $sqlState;

    return $sqlState === 'HY000'
        && in_array($driverCode, [1042, 1053, 2002, 2003, 2005, 2006, 2013], true);
}
