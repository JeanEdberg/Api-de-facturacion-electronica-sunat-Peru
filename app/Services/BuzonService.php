<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Notificacion;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Support\DiasHabiles;
use Illuminate\Support\Facades\Storage;

/**
 * Buzón electrónico SUNAT (SIE) / SUNAFIL.
 *
 * SUNAT no expone un API público del buzón, así que la sincronización se apoya
 * en un endpoint configurado por empresa (`buzon_sunat_endpoint` /
 * `buzon_sunafil_endpoint`). Si la empresa no define uno propio se usan los
 * endpoints por entorno (`services.buzon.*`). Sin fuente alguna la
 * sincronización no falla: informa que no hay fuente y el usuario sigue
 * registrando notificaciones a mano.
 *
 * Ese endpoint normalmente es el puente SOL (`tools/buzon-bridge/sol_buzon.py`),
 * que entra al Menú SOL con el RUC y la Clave SOL de la empresa y devuelve el
 * buzón como JSON.
 */
class BuzonService
{
    public const ENTIDADES = ['SUNAT', 'SUNAFIL'];

    /** Los tres documentos que ofrece una fila de la casilla de SUNAFIL. */
    public const TIPOS = ['carta', 'constancia_deposito', 'constancia_acuse'];

    /**
     * Registra una notificación manual. Si llega código repetido para la misma
     * empresa y entidad se actualiza en vez de duplicar.
     *
     * @param  array<string, mixed>  $datos
     */
    public function registrar(array $datos): Notificacion
    {
        $datos['origen'] = $datos['origen'] ?? 'manual';
        $datos['estado'] = $datos['estado'] ?? 'pendiente';

        if (empty($datos['codigo'])) {
            return Notificacion::create($datos);
        }

        $clave = [
            'company_id' => $datos['company_id'],
            'entidad' => $datos['entidad'],
            'codigo' => $datos['codigo'],
        ];

        // Volver a sincronizar no debe deshacer lo que hizo el usuario: sin
        // esto, cada sincronización devolvía a «pendiente» las notificaciones
        // ya leídas y el panel las volvía a marcar como nuevas.
        $existente = Notificacion::where($clave)->first();

        if ($existente) {
            unset($datos['estado'], $datos['leida_en']);

            $existente->update($datos);

            return $existente;
        }

        return Notificacion::create($clave + $datos);
    }

    public function marcarLeida(Notificacion $notificacion): Notificacion
    {
        $notificacion->update(['estado' => 'leida', 'leida_en' => now()]);

        return $notificacion->refresh();
    }

    /**
     * Adjunta (o reemplaza) el expediente PDF/imagen de una notificación.
     */
    public function adjuntar(Notificacion $notificacion, UploadedFile $archivo): Notificacion
    {
        $anterior = $notificacion->adjunto_path;

        $ruta = $this->guardarAdjunto($notificacion, $archivo);
        $notificacion->update(['adjunto_path' => $ruta, 'tiene_adjunto' => true]);

        if ($anterior && $anterior !== $ruta) {
            Storage::disk('public')->delete($anterior);
        }

        return $notificacion->refresh();
    }

    /**
     * Descarga del puente el documento de una notificación y lo guarda.
     *
     * El puente no expone una URL por documento: hay que decirle la bandeja y
     * la fila, que se guardan en `detalle` al sincronizar. Si la notificación
     * no trae esos datos no se intenta nada, porque adivinar la fila bajaría
     * el documento de otra notificación.
     *
     * @return array{ok: bool, archivo?: string, bytes?: int, message?: string}
     */
    public function descargarDocumento(Notificacion $notificacion, string $tipo = 'carta'): array
    {
        if (! in_array($tipo, self::TIPOS, true)) {
            return ['ok' => false, 'message' => "Tipo de documento desconocido: {$tipo}."];
        }

        $origen = $this->origenDocumento($notificacion);

        if (! $origen) {
            return [
                'ok' => false,
                'message' => 'La notificación no dice de qué bandeja ni de qué fila salió, así que no se puede pedir el documento al puente.',
            ];
        }

        $company = $notificacion->company;
        $endpoint = $this->endpointDocumento($company, $origen['entidad']);

        if (! $endpoint) {
            return [
                'ok' => false,
                'message' => "No hay endpoint del puente configurado para {$origen['entidad']}.",
            ];
        }

        try {
            $respuesta = Http::timeout(180)
                ->acceptJson()
                ->withHeaders(['User-Agent' => 'BuzonSUNAT/1.0'])
                ->when($company->buzon_token ?: config('services.buzon.token'), fn ($http, $valor) => $http->withToken($valor))
                ->post($endpoint, [
                    'ruc' => $company->ruc,
                    'usuario' => $company->usuario_sol,
                    'clave' => $company->clave_sol,
                    'bandeja' => $origen['bandeja'],
                    'indice' => $origen['indice'],
                    'tipo' => $tipo,
                    // El puente compara esto con la fila antes de pulsar: si la
                    // bandeja se reordenó, se niega en vez de abrir otra.
                    'registro_esperado' => $notificacion->codigo,
                ]);
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return [
                'ok' => false,
                'message' => "No se pudo llegar al puente en {$endpoint}. Revisa que esté levantado.",
            ];
        }

        // 409 = la fila del índice ya es otra notificación. No es un fallo del
        // puente: hay que re-sincronizar para refrescar el índice.
        if ($respuesta->status() === 409) {
            return [
                'ok' => false,
                'conflicto' => true,
                'message' => $respuesta->json('error')
                    ?: 'La bandeja cambió de orden. Vuelve a sincronizar antes de abrir.',
            ];
        }

        $error = $respuesta->json('error');
        if (is_string($error) && $error !== '') {
            return ['ok' => false, 'message' => $error];
        }

        if (! $respuesta->successful()) {
            return [
                'ok' => false,
                'message' => "El puente respondió HTTP {$respuesta->status()} al pedir el documento.",
            ];
        }

        $contenido = $respuesta->body();
        if (! str_starts_with($contenido, '%PDF')) {
            return [
                'ok' => false,
                'message' => 'El puente no devolvió un PDF. Suele pasar cuando SUNAFIL abre un visor en vez del archivo.',
            ];
        }

        $ruta = $this->guardarDocumento($notificacion, $tipo, $contenido);

        Log::info('Documento del buzón descargado', [
            'notificacion_id' => $notificacion->id,
            'tipo' => $tipo,
            'bytes' => strlen($contenido),
        ]);

        return ['ok' => true, 'archivo' => $ruta, 'bytes' => strlen($contenido)];
    }

    /**
     * Guarda el PDF en su ruta propia y la anota en la notificación.
     *
     * Cada documento tiene su columna: la carta y las constancias se piden por
     * separado y cualquiera puede faltar. `adjunto_path` se mantiene apuntando
     * a la carta para que la descarga genérica siga funcionando.
     */
    private function guardarDocumento(Notificacion $notificacion, string $tipo, string $contenido): string
    {
        $ruta = "buzon/{$notificacion->company_id}/{$notificacion->id}-{$tipo}.pdf";
        Storage::disk('public')->put($ruta, $contenido);

        $cambios = ['ruta_'.$tipo => $ruta];

        if ($tipo === 'carta') {
            $cambios['adjunto_path'] = $ruta;
            $cambios['tiene_adjunto'] = true;
        }

        $notificacion->update($cambios);

        return $ruta;
    }

    /**
     * «Abrir y notificarse»: baja la carta y registra el acuse de recibo.
     *
     * Abrir la carta en la casilla de SUNAFIL deja constancia de que la empresa
     * se dio por notificada y arranca el plazo para responder. Por eso esto no
     * lo hace nunca el cron: lo pide una persona con permiso y confirmándolo.
     *
     * La carta va primero y sola: si falla, no se intenta nada más, porque el
     * acuse pudo quedar registrado igual y hay que mirar la casilla antes de
     * reintentar. Las constancias se piden después; si aún no están publicadas,
     * quedan pendientes sin invalidar el acuse.
     *
     * @return array{ok: bool, message: string, archivos?: array<int, string>, pendientes?: array<string, string>}
     */
    public function abrir(Notificacion $notificacion, int $usuarioId): array
    {
        if ($notificacion->con_acuse) {
            return [
                'ok' => false,
                'message' => 'Esta notificación ya tiene acuse registrado. Usa la descarga normal.',
            ];
        }

        // Se anota ANTES de pulsar: si la descarga falla a medias, el acuse
        // pudo quedar registrado y tiene que quedar rastro de quién lo pidió.
        $notificacion->update([
            'apertura_solicitada_por' => $usuarioId,
            'apertura_solicitada_en' => now(),
            'apertura_resultado' => 'en_curso',
        ]);

        Log::info('Buzón: apertura solicitada', [
            'notificacion_id' => $notificacion->id,
            'registro' => $notificacion->codigo,
            'usuario' => $usuarioId,
        ]);

        $carta = $this->descargarDocumento($notificacion, 'carta');

        if (! $carta['ok']) {
            $notificacion->update(['apertura_resultado' => 'error: '.$carta['message']]);

            return [
                'ok' => false,
                'message' => $carta['message'],
                'aviso' => 'El acuse pudo haberse registrado igual. Revisa la casilla en SUNAFIL antes de reintentar.',
            ];
        }

        // A partir de aquí el acuse está hecho: las constancias son un extra.
        $notificacion->update([
            'con_acuse' => true,
            'fecha_acuse' => $notificacion->fecha_acuse ?: now(),
            'apertura_resultado' => 'ok',
        ]);

        $archivos = ['carta'];
        $pendientes = [];

        foreach (['constancia_deposito', 'constancia_acuse'] as $tipo) {
            $resultado = $this->descargarDocumento($notificacion, $tipo);

            if ($resultado['ok']) {
                $archivos[] = $tipo;
            } else {
                $pendientes[$tipo] = $resultado['message'];
            }
        }

        Log::info('Buzón: notificación abierta', [
            'notificacion_id' => $notificacion->id,
            'archivos' => $archivos,
            'pendientes' => array_keys($pendientes),
        ]);

        return [
            'ok' => true,
            'message' => 'Carta abierta y acuse registrado.',
            'archivos' => $archivos,
            'pendientes' => $pendientes,
        ];
    }

    /**
     * True si la notificación salió de la casilla y por tanto abrirla puede
     * registrar un acuse. Las de alta manual no tienen nada que abrir.
     */
    public function puedeAbrirse(Notificacion $notificacion): bool
    {
        return $this->origenDocumento($notificacion) !== null;
    }

    /**
     * Bandeja y fila de las que salió la notificación, leídas de `detalle`.
     *
     * @return array{bandeja: string, indice: int, entidad: string}|null
     */
    private function origenDocumento(Notificacion $notificacion): ?array
    {
        $detalle = json_decode((string) $notificacion->detalle, true);

        if (! is_array($detalle) || ! isset($detalle['bandeja'], $detalle['indice'])) {
            return null;
        }

        if (! is_numeric($detalle['indice'])) {
            return null;
        }

        return [
            'bandeja' => (string) $detalle['bandeja'],
            'indice' => (int) $detalle['indice'],
            'entidad' => strtoupper((string) $notificacion->entidad),
        ];
    }

    /**
     * URL del endpoint de documentos. El puente sirve el buzón en `/buzon` y
     * los archivos en `/documento`, así que se deriva del de sincronización.
     */
    private function endpointDocumento(Company $company, string $entidad): ?string
    {
        $buzon = $this->fuentes($company)[$entidad] ?? null;

        if (! $buzon) {
            return null;
        }

        return preg_replace('#/buzon/?$#', '/documento', $buzon);
    }

    /**
     * Ruta guardada del documento pedido, o null si aún no se ha bajado.
     *
     * Sin tipo devuelve el adjunto genérico (la carta), que es lo que espera
     * la descarga del listado.
     */
    public function rutaAdjunto(Notificacion $notificacion, ?string $tipo = null): ?string
    {
        $ruta = $tipo === null
            ? $notificacion->adjunto_path
            : $notificacion->rutaDocumento($tipo);

        // Un PDF subido a mano también es la carta: si no se bajó de la
        // casilla, vale el adjunto genérico.
        if (! $ruta && $tipo === 'carta') {
            $ruta = $notificacion->adjunto_path;
        }

        if (! $ruta || ! Storage::disk('public')->exists($ruta)) {
            return null;
        }

        return $ruta;
    }

    private function guardarAdjunto(Notificacion $notificacion, UploadedFile $archivo): string
    {
        $extension = strtolower($archivo->getClientOriginalExtension() ?: $archivo->extension() ?: 'pdf');
        $ruta = "buzon/{$notificacion->company_id}/{$notificacion->id}.{$extension}";

        Storage::disk('public')->put($ruta, file_get_contents($archivo->getRealPath()));

        return $ruta;
    }

    /**
     * Resumen para el encabezado del panel: pendientes y total de la empresa.
     *
     * @return array{pendientes: int, total: int}
     */
    public function resumen(int $companyId, ?string $entidad = null): array
    {
        $query = Notificacion::where('company_id', $companyId);
        if ($entidad) {
            $query->where('entidad', $entidad);
        }

        return [
            'pendientes' => (clone $query)->where('estado', 'pendiente')->count(),
            'total' => (clone $query)->count(),
            'por_entidad' => $this->conteoPorEntidad($companyId),
        ];
    }

    /**
     * Total de avisos por entidad, para que el panel muestre los dos buzones
     * con su propio contador sin pedir dos veces.
     *
     * @return array<string, int>
     */
    private function conteoPorEntidad(int $companyId): array
    {
        $conteo = array_fill_keys(self::ENTIDADES, 0);

        $filas = Notificacion::where('company_id', $companyId)
            ->selectRaw('entidad, count(*) as total')
            ->groupBy('entidad')
            ->pluck('total', 'entidad');

        foreach ($filas as $nombre => $total) {
            $conteo[$nombre] = (int) $total;
        }

        return $conteo;
    }

    /**
     * Endpoints efectivos de la empresa, entidad por entidad. La fuente de la
     * empresa gana sobre la del entorno.
     *
     * @return array<string, string>
     */
    public function fuentes(Company $company): array
    {
        return array_filter([
            'SUNAT' => $company->buzon_sunat_endpoint ?: config('services.buzon.sunat_endpoint'),
            'SUNAFIL' => $company->buzon_sunafil_endpoint ?: config('services.buzon.sunafil_endpoint'),
        ]);
    }

    /**
     * Estado de la fuente para el panel. No expone los secretos, solo si están
     * puestos, y de dónde sale cada endpoint.
     *
     * @return array{sunat: bool, sunafil: bool, configurado: bool, token: bool, sol: bool, origenes: array<int, string>}
     */
    public function estadoFuente(Company $company): array
    {
        $fuentes = $this->fuentes($company);
        $origenes = [];

        foreach (self::ENTIDADES as $entidad) {
            if (! isset($fuentes[$entidad])) {
                continue;
            }

            $propia = (bool) ($entidad === 'SUNAT' ? $company->buzon_sunat_endpoint : $company->buzon_sunafil_endpoint);
            $origenes[] = $propia ? 'empresa' : 'entorno';
        }

        return [
            'sunat' => isset($fuentes['SUNAT']),
            'sunafil' => isset($fuentes['SUNAFIL']),
            'configurado' => ! empty($fuentes),
            'token' => filled($company->buzon_token) || filled(config('services.buzon.token')),
            'sol' => filled($company->clave_sol),
            'origenes' => array_values(array_unique($origenes)),
        ];
    }

    /**
     * Sincroniza el buzón de la empresa contra sus endpoints. Con `$entidad`
     * solo se consulta ese buzón, para que SUNAT y SUNAFIL se puedan
     * sincronizar por separado y un fallo en uno no arrastre al otro.
     *
     * @return array{importadas: int, fuentes: array<int, string>}
     */
    public function sincronizar(Company $company, ?string $entidad = null): array
    {
        $fuentes = $this->fuentes($company);

        if ($entidad !== null) {
            $fuentes = array_intersect_key($fuentes, [$entidad => true]);
        }

        if (empty($fuentes)) {
            return ['importadas' => 0, 'fuentes' => []];
        }

        $importadas = 0;
        foreach ($fuentes as $nombre => $endpoint) {
            $importadas += $this->importarDesde($company, $nombre, $endpoint);
        }

        // Sincronizar sólo LEE la bandeja: no pulsa ningún botón. Abrir la
        // carta de SUNAFIL registra el acuse de recibo y arranca un plazo
        // legal, así que esa decisión es del responsable, no del cron.

        Log::info('Buzón sincronizado', [
            'company_id' => $company->id,
            'entidad' => $entidad,
            'importadas' => $importadas,
        ]);

        return [
            'importadas' => $importadas,
            'fuentes' => array_values($fuentes),
        ];
    }

    /**
     * Prueba la fuente sin importar nada, para que el panel diga si la
     * configuración (endpoint, token y Clave SOL) realmente funciona.
     *
     * @return array{conectado: bool, total: int, message: string}
     */
    public function probar(Company $company, string $entidad = 'SUNAT'): array
    {
        $endpoint = $this->fuentes($company)[$entidad] ?? null;

        if (! $endpoint) {
            return [
                'conectado' => false,
                'total' => 0,
                'message' => "No hay endpoint configurado para {$entidad}. Define uno en la fuente del buzón.",
            ];
        }

        try {
            $items = $this->consultar($company, $entidad, $endpoint);
        } catch (\RuntimeException $e) {
            return ['conectado' => false, 'total' => 0, 'message' => $e->getMessage()];
        } catch (\Illuminate\Http\Client\ConnectionException) {
            return [
                'conectado' => false,
                'total' => 0,
                'message' => "No se pudo llegar al endpoint {$entidad}. Revisa que el puente esté levantado y que la URL sea correcta.",
            ];
        }

        return [
            'conectado' => true,
            'total' => count($items),
            'message' => sprintf(
                'Conexión correcta con %s: %d notificación(es) disponibles.',
                $entidad,
                count($items),
            ),
        ];
    }

    /**
     * Consulta un endpoint del buzón y registra las notificaciones recibidas.
     */
    private function importarDesde(Company $company, string $entidad, string $endpoint): int
    {
        $items = $this->consultar($company, $entidad, $endpoint);
        $importadas = 0;

        foreach ($items as $item) {
            $detalle = $this->detalleConOrigen($item);

            $notificacion = $this->registrar([
                'company_id' => $company->id,
                'entidad' => strtoupper($item['entidad'] ?? $entidad),
                'codigo' => $item['codigo'] ?? $item['numero'] ?? $item['registro'] ?? null,
                'asunto' => $item['asunto'] ?? $item['titulo'] ?? $item['tipo_requerimiento'] ?? 'Notificación',
                'tipo' => $item['tipo'] ?? null,
                'fecha' => $this->fechaNormalizada(
                    $item['fecha'] ?? $item['fecha_notificacion'] ?? $item['fecha_deposito'] ?? null
                ),
                'detalle' => $detalle,
                'url' => $item['url'] ?? null,
                'origen' => 'sincronizada',
            ] + $this->datosDeAcuse($item));

            if ($notificacion->wasRecentlyCreated) {
                $importadas++;
            }
        }

        return $importadas;
    }

    /**
     * Fechas, plazo y documentos de acuse que manda el puente de SUNAFIL.
     *
     * Sólo se acepta como «con acuse» lo que SUNAFIL confirma: fecha de acuse
     * Y constancia publicada. Ante la duda se trata como sin acuse, porque un
     * falso positivo cerraría el plazo sin que nadie se haya notificado.
     *
     * @return array<string, mixed>
     */
    private function datosDeAcuse(array $item): array
    {
        if (! isset($item['registro']) && ! isset($item['fecha_acuse'])) {
            return [];
        }

        $documentos = array_values((array) ($item['documentos'] ?? []));
        $acuse = $this->fechaNormalizada($item['fecha_acuse'] ?? null);
        $efectos = $this->fechaNormalizada($item['fecha_efectos'] ?? null);
        $conAcuse = $acuse !== null && in_array('constancia_acuse', $documentos, true);

        $datos = [
            'tipo_requerimiento' => $item['tipo'] ?? null,
            'fecha_deposito' => $this->fechaNormalizada($item['fecha_deposito'] ?? null),
            'fecha_efectos_deposito' => $efectos,
            'fecha_acuse' => $acuse,
            'plazo_dias' => is_numeric($item['plazo'] ?? null) ? (int) $item['plazo'] : null,
            'fecha_limite' => $this->fechaNormalizada($item['fecha_limite'] ?? null),
            'con_acuse' => $conAcuse,
            'documentos' => $documentos,
            'buzon_indice' => is_numeric($item['indice'] ?? null) ? (int) $item['indice'] : null,
        ];

        // Estimación del último día de acuse manual: días hábiles desde los
        // efectos del depósito. La fecha buena es la que publique SUNAFIL.
        if (! $conAcuse && $efectos) {
            $dias = (int) config('buzon.dias_para_acuse', 5);
            $desde = new \DateTimeImmutable($efectos);
            $datos['ultimo_dia_acuse_manual'] = DiasHabiles::sumar(
                $desde, $dias, (array) config('buzon.feriados', [])
            )->format('Y-m-d');
            $datos['acuse_automatico_el'] = DiasHabiles::sumar(
                $desde, $dias + 1, (array) config('buzon.feriados', [])
            )->format('Y-m-d');
        }

        return $datos;
    }

    /**
     * Fecha en formato ISO, o `null` si no se puede interpretar.
     *
     * SUNAFIL devuelve `d/m/Y` y la columna es un `date` de Eloquent, que
     * espera ISO: pasarle `23/10/2026` lanzaba una excepción y tumbaba la
     * sincronización entera con un 502.
     */
    private function fechaNormalizada(mixed $valor): ?string
    {
        if (! is_string($valor) || trim($valor) === '') {
            return null;
        }

        $texto = trim($valor);

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $formato) {
            $fecha = \DateTimeImmutable::createFromFormat('!'.$formato, $texto);

            if ($fecha && $fecha->format($formato) === $texto) {
                return $fecha->format('Y-m-d');
            }
        }

        try {
            return (new \DateTimeImmutable($texto))->format('Y-m-d');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * `detalle` de la notificación, con la bandeja y la fila de origen.
     *
     * Las acciones previas de SUNAFIL sólo se pueden pedir al puente por
     * bandeja e índice, así que esos dos datos tienen que viajar con la
     * notificación; si se perdieran, el documento ya no se podría descargar.
     *
     * @param  array<string, mixed>  $item
     */
    private function detalleConOrigen(array $item): ?string
    {
        $detalle = $item['detalle'] ?? null;
        $datos = is_string($detalle) ? json_decode($detalle, true) : null;

        if (! is_array($datos)) {
            $datos = [];
        }

        if (isset($item['bandeja'])) {
            $datos['bandeja'] = $item['bandeja'];
        }

        if (isset($item['indice']) && is_numeric($item['indice'])) {
            $datos['indice'] = (int) $item['indice'];
        }

        return $datos === [] ? $detalle : json_encode($datos, JSON_UNESCAPED_UNICODE);
    }

    /**
     * Petición al endpoint del buzón, compartida por `sincronizar()` y
     * `probar()` para que ambos se comporten igual ante errores.
     *
     * La Clave SOL va en el cuerpo y no en la query: así no queda escrita en
     * los logs de acceso del puente.
     *
     * @return array<int, array<string, mixed>>
     */
    private function consultar(Company $company, string $entidad, string $endpoint): array
    {
        $token = $company->buzon_token ?: config('services.buzon.token');

        $respuesta = Http::timeout(120)
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'BuzonSUNAT/1.0'])
            ->when($token, fn ($http, $valor) => $http->withToken($valor))
            ->post($endpoint, [
                'ruc' => $company->ruc,
                'usuario' => $company->usuario_sol,
                'clave' => $company->clave_sol,
                'entidad' => $entidad,
            ]);

        // El puente devuelve `{ "error": "..." }` con un mensaje pensado para el
        // usuario cuando SUNAT rechaza las credenciales. Ese texto sirve más que
        // un "HTTP 502", que no explica nada.
        $error = $respuesta->json('error');
        if (is_string($error) && $error !== '') {
            throw new \RuntimeException($error);
        }

        if (! $respuesta->successful()) {
            throw new \RuntimeException(
                "El buzón {$entidad} de {$company->ruc} respondió HTTP {$respuesta->status()}"
            );
        }

        $items = $respuesta->json('data') ?? $respuesta->json() ?? [];

        if (! is_array($items)) {
            return [];
        }

        return $this->normalizarItems($items);
    }

    /**
     * Deja la respuesta del puente en una lista plana de notificaciones.
     *
     * SUNAT devuelve una lista. SUNAFIL devuelve un objeto con una clave por
     * bandeja (`notificaciones`, `acciones_previas`), así que sin aplanarlo el
     * `array_filter(is_array)` se quedaba con las dos listas y no con sus
     * filas: SUNAFIL nunca importaba nada.
     *
     * @param  array<mixed>  $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizarItems(array $items): array
    {
        $planos = [];

        foreach ($items as $clave => $valor) {
            if (! is_array($valor)) {
                continue;
            }

            // Lista de filas: SUNAT, o una bandeja de SUNAFIL (que también es
            // una lista). Si la clave es el nombre de la bandeja, cada fila la
            // hereda: es lo único que permite pedir luego su documento.
            if (array_is_list($valor)) {
                foreach ($valor as $fila) {
                    if (! is_array($fila)) {
                        continue;
                    }

                    if (is_string($clave)) {
                        $fila['bandeja'] = $fila['bandeja'] ?? $clave;
                    }

                    $planos[] = $fila;
                }

                continue;
            }

            // Un objeto suelto con datos: es una sola notificación.
            if (isset($valor['codigo']) || isset($valor['registro']) || isset($valor['asunto'])) {
                $planos[] = $valor;

                continue;
            }

            // Clave de bandeja (`notificaciones`, `acciones_previas`): cada
            // fila hereda la bandeja para poder pedir luego su documento.
            foreach ($valor as $fila) {
                if (! is_array($fila)) {
                    continue;
                }

                $fila['bandeja'] = $fila['bandeja'] ?? $clave;
                $planos[] = $fila;
            }
        }

        return array_values($planos);
    }
}
