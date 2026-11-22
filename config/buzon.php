<?php

/*
 * Plazos del buzón de SUNAFIL.
 *
 * Abrir la carta registra el acuse de recibo y arranca el plazo de respuesta.
 * Las fechas que calcula el ERP son ESTIMACIONES: la buena es la que publica
 * SUNAFIL en la casilla. Ante la duda, se avisa antes, no después.
 */
return [
    // Días hábiles desde los efectos del depósito para acusar manualmente.
    'dias_para_acuse' => (int) env('BUZON_DIAS_ACUSE', 5),

    // Avisar cuando falten estos días o menos para el último día de acuse.
    'dias_aviso' => (int) env('BUZON_DIAS_AVISO', 2),

    // Feriados que no cuentan como hábiles (formato Y-m-d). Los nacionales
    // cambian cada año: se configuran aquí en vez de fijarlos en el código.
    'feriados' => array_filter(array_map('trim', explode(',', (string) env('BUZON_FERIADOS', '')))),
];
