<?php

namespace Tests\Support;

use LogicException;
use PDO;

final class MigrationPreflight
{
    public static function requireZero(PDO $pdo, string $countSql, string $label): void
    {
        $count = (int) $pdo->query($countSql)->fetchColumn();
        if ($count !== 0) {
            throw new LogicException("Migration preflight {$label} requires zero unresolved rows; found {$count}.");
        }
    }
}
