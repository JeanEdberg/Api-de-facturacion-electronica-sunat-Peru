<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeInterface;

/** Días hábiles = lunes a viernes, excluyendo los feriados que se le pasen. Sin dependencias. */
final class DiasHabiles
{
    /** El n-ésimo día hábil POSTERIOR a $desde. */
    public static function sumar(DateTimeInterface $desde, int $n, array $feriados = []): DateTimeImmutable
    {
        $idx = array_flip($feriados);
        $d = DateTimeImmutable::createFromInterface($desde)->setTime(0, 0);
        while ($n > 0) {
            $d = $d->modify('+1 day');
            if (self::esHabil($d, $idx)) {
                $n--;
            }
        }
        return $d;
    }

    public static function esHabil(DateTimeInterface $d, array $feriadosIdx = []): bool
    {
        return (int) $d->format('N') < 6 && !isset($feriadosIdx[$d->format('Y-m-d')]);
    }
}
