<?php
declare(strict_types=1);

function isTransientDatabaseConnectionError(PDOException $error): bool {
    $info = $error->errorInfo ?? null;
    $sqlState = is_array($info) && isset($info[0])
        ? (string) $info[0]
        : (string) $error->getCode();
    $driverCode = is_array($info) && isset($info[1]) ? (int) $info[1] : 0;

    if (ctype_digit($sqlState)) {
        if ($driverCode === 0) $driverCode = (int) $sqlState;
        $sqlState = 'HY000';
    }
    if (in_array($sqlState, ['08001', '08S01'], true)) return true;

    return $sqlState === 'HY000'
        && in_array($driverCode, [1042, 1053, 2002, 2003, 2005, 2006, 2013], true);
}
