<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Buzón electrónico SUNAT / SUNAFIL
    |--------------------------------------------------------------------------
    |
    | SUNAT y SUNAFIL no publican una API REST pública del buzón: el acceso se
    | hace con la credencial SOL en sus portales. Si se dispone de un endpoint
    | (propio o habilitado por la entidad) se configura aquí y el sistema
    | sincronizará las notificaciones automáticamente. Sin endpoint, las
    | notificaciones se pueden registrar manualmente.
    |
    | El endpoint recibe un GET con ?ruc=... y autenticación básica
    | (ruc+usuario_sol : clave_sol). Debe responder un JSON con una lista de
    | notificaciones o { "notificaciones": [ ... ] }. Campos reconocidos por
    | notificación: codigo|id, asunto|titulo, tipo, fecha, detalle|descripcion,
    | url|enlace, tiene_adjunto|adjunto.
    |
    */

    'buzon' => [
        'endpoints' => [
            'SUNAT' => env('SUNAT_BUZON_ENDPOINT'),
            'SUNAFIL' => env('SUNAFIL_BUZON_ENDPOINT'),
        ],
    ],

];
