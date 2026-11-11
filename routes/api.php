<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\BoletaController;
use App\Http\Controllers\Api\CompraController;
use App\Http\Controllers\Api\NotificacionController;
use App\Http\Controllers\Api\PdfController;
use App\Http\Controllers\Api\CompanyConfigController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\ClientController;
use App\Http\Controllers\Api\CorrelativeController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ConsultaCpeController;
use App\Http\Controllers\Api\SetupController;
use App\Http\Controllers\Api\SireController;
use App\Http\Controllers\Api\SscoController;
use App\Http\Controllers\Api\UbigeoController;
use App\Http\Controllers\Api\CreditNoteController;
use App\Http\Controllers\Api\DebitNoteController;
use App\Http\Controllers\Api\DispatchGuideController;
use App\Http\Controllers\Api\GreCredentialsController;

// ========================
// RUTAS PÚBLICAS (SIN AUTENTICACIÓN)
// ========================

// Información del sistema
Route::get('/system/info', [AuthController::class, 'systemInfo']);

// Setup del sistema
Route::prefix('setup')->group(function () {
    Route::post('/migrate', [SetupController::class, 'migrate']);
    Route::post('/seed', [SetupController::class, 'seed']);
    Route::get('/status', [SetupController::class, 'status']);
});

// Inicialización del sistema
Route::post('/auth/initialize', [AuthController::class, 'initialize']);

// Autenticación
Route::post('/auth/login', [AuthController::class, 'login']);

// ========================
// RUTAS PROTEGIDAS (CON AUTENTICACIÓN)
// ========================
Route::prefix('v1')->middleware('auth:sanctum')->group(function () {

    // ========================
    // AUTENTICACIÓN Y USUARIO
    // ========================
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
    Route::post('/auth/create-user', [AuthController::class, 'createUser']);
    
    // Usuario autenticado
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // ========================
    // SETUP AVANZADO
    // ========================
    Route::prefix('setup')->group(function () {
        Route::post('/complete', [SetupController::class, 'setup']);
        Route::post('/configure-sunat', [SetupController::class, 'configureSunat']);
    });

    // ========================
    // GESTIÓN DE UBIGEOS
    // ========================
    Route::prefix('ubigeos')->group(function () {
        Route::get('/regiones', [UbigeoController::class, 'getRegiones']);
        Route::get('/provincias', [UbigeoController::class, 'getProvincias']);
        Route::get('/distritos', [UbigeoController::class, 'getDistritos']);
        Route::get('/search', [UbigeoController::class, 'searchUbigeo']);
        Route::get('/{id}', [UbigeoController::class, 'getUbigeoById']);
    });

    // ========================
    // EMPRESAS Y CONFIGURACIONES
    // ========================
    
    // Empresas
    Route::apiResource('companies', CompanyController::class);
    Route::post('/companies/{company}/activate', [CompanyController::class, 'activate']);
    Route::post('/companies/{company}/toggle-production', [CompanyController::class, 'toggleProductionMode']);

    // Configuraciones de empresas
    Route::prefix('companies/{company_id}/config')->group(function () {
        Route::get('/', [CompanyConfigController::class, 'show']);
        Route::get('/{section}', [CompanyConfigController::class, 'getSection']);
        Route::put('/{section}', [CompanyConfigController::class, 'updateSection']);
        Route::get('/validate/services', [CompanyConfigController::class, 'validateServices']);
        Route::post('/reset', [CompanyConfigController::class, 'resetToDefaults']);
        Route::post('/migrate', [CompanyConfigController::class, 'migrateCompany']);
        Route::delete('/cache', [CompanyConfigController::class, 'clearCache']);
    });

    // Configuraciones generales
    Route::prefix('config')->group(function () {
        Route::get('/defaults', [CompanyConfigController::class, 'getDefaults']);
        Route::get('/summary', [CompanyConfigController::class, 'getSummary']);
    });

    // ========================
    // SUCURSALES
    // ========================
    Route::apiResource('branches', BranchController::class);
    Route::post('/branches/{branch}/activate', [BranchController::class, 'activate']);
    Route::get('/companies/{company}/branches', [BranchController::class, 'getByCompany']);

    // ========================
    // CLIENTES
    // ========================
    Route::apiResource('clients', ClientController::class);
    Route::post('/clients/{client}/activate', [ClientController::class, 'activate']);
    Route::get('/companies/{company}/clients', [ClientController::class, 'getByCompany']);
    Route::post('/clients/search-by-document', [ClientController::class, 'searchByDocument']);

    // ========================
    // CORRELATIVOS
    // ========================
    Route::get('/branches/{branch}/correlatives', [CorrelativeController::class, 'index']);
    Route::post('/branches/{branch}/correlatives', [CorrelativeController::class, 'store']);
    Route::put('/branches/{branch}/correlatives/{correlative}', [CorrelativeController::class, 'update']);
    Route::delete('/branches/{branch}/correlatives/{correlative}', [CorrelativeController::class, 'destroy']);
    Route::post('/branches/{branch}/correlatives/batch', [CorrelativeController::class, 'createBatch']);
    Route::post('/branches/{branch}/correlatives/{correlative}/increment', [CorrelativeController::class, 'increment']);
    
    // Catálogos de correlativos
    Route::get('/correlatives/document-types', [CorrelativeController::class, 'getDocumentTypes']);

    // ========================
    // DOCUMENTOS ELECTRÓNICOS SUNAT
    // ========================

    // PDF Formatos
    Route::prefix('pdf')->group(function () {
        Route::get('/formats', [PdfController::class, 'getAvailableFormats']);
    });

    // Facturas
    Route::prefix('invoices')->group(function () {
        Route::get('/', [InvoiceController::class, 'index']);
        Route::post('/', [InvoiceController::class, 'store']);
        Route::get('/{id}', [InvoiceController::class, 'show']);
        Route::post('/{id}/send-sunat', [InvoiceController::class, 'sendToSunat']);
        Route::get('/{id}/download-xml', [InvoiceController::class, 'downloadXml']);
        Route::get('/{id}/download-cdr', [InvoiceController::class, 'downloadCdr']);
        Route::get('/{id}/download-pdf', [InvoiceController::class, 'downloadPdf']);
        Route::post('/{id}/generate-pdf', [InvoiceController::class, 'generatePdf']);
    });

    // Boletas
    Route::prefix('boletas')->group(function () {
        Route::get('/', [BoletaController::class, 'index']);
        Route::post('/', [BoletaController::class, 'store']);
        Route::get('/{id}', [BoletaController::class, 'show']);
        Route::post('/{id}/send-sunat', [BoletaController::class, 'sendToSunat']);
        Route::get('/{id}/download-xml', [BoletaController::class, 'downloadXml']);
        Route::get('/{id}/download-cdr', [BoletaController::class, 'downloadCdr']);
        Route::get('/{id}/download-pdf', [BoletaController::class, 'downloadPdf']);
        Route::post('/{id}/generate-pdf', [BoletaController::class, 'generatePdf']);
    });

    // ========================
    // REGISTRO DE COMPRAS (COMPROBANTES RECIBIDOS)
    // ========================
    // Comprobantes de proveedores: alta manual, importación del XML UBL y
    // artefactos (XML/CDR/PDF). No se envían a SUNAT; alimentan el Registro
    // de Compras (PLE 8.1 / RCE del SIRE).
    Route::prefix('compras')->group(function () {
        Route::get('/', [CompraController::class, 'index']);
        Route::post('/', [CompraController::class, 'store']);
        Route::post('/importar-xml', [CompraController::class, 'importarXml']);
        Route::get('/{id}', [CompraController::class, 'show']);
        Route::delete('/{id}', [CompraController::class, 'destroy']);
        Route::get('/{id}/download-xml', [CompraController::class, 'downloadXml']);
        Route::get('/{id}/download-cdr', [CompraController::class, 'downloadCdr']);
        Route::get('/{id}/download-pdf', [CompraController::class, 'downloadPdf']);
        Route::post('/{id}/adjuntos', [CompraController::class, 'adjuntar']);
    });

    // ========================
    // BUZÓN ELECTRÓNICO SUNAT / SUNAFIL
    // ========================
    // Notificaciones por empresa: alta manual, marcado como leída y
    // sincronización contra el endpoint configurado por empresa. El listado
    // añade `resumen` (pendientes y total) para el encabezado del panel.
    Route::prefix('notificaciones')->group(function () {
        Route::get('/', [NotificacionController::class, 'index']);
        Route::post('/', [NotificacionController::class, 'store']);
        Route::post('/sincronizar', [NotificacionController::class, 'sincronizar']);
        Route::post('/{id}/marcar-leida', [NotificacionController::class, 'marcarLeida']);
        Route::post('/{notificacion}/adjuntos', [NotificacionController::class, 'adjuntar']);
        Route::get('/{notificacion}/adjunto', [NotificacionController::class, 'descargarAdjunto']);
    });

    // ========================
    // CONSULTA DE COMPROBANTES ELECTRÓNICOS (CPE)
    // ========================
    Route::prefix('consulta-cpe')->group(function () {
        // Consultas individuales por tipo de documento
        Route::post('/factura/{id}', [ConsultaCpeController::class, 'consultarFactura']);
        Route::post('/boleta/{id}', [ConsultaCpeController::class, 'consultarBoleta']);

        // Consulta masiva
        Route::post('/masivo', [ConsultaCpeController::class, 'consultarDocumentosMasivo']);

        // Estadísticas de consultas
        Route::get('/estadisticas', [ConsultaCpeController::class, 'estadisticasConsultas']);
    });

    // ========================
    // PADRÓN SSCO (SUJETO SIN CAPACIDAD OPERATIVA)
    // ========================
    // Cruce de RUC contra la relación oficial de SUNAT. Los comprobantes
    // emitidos por un SSCO no dan crédito fiscal ni sustentan gasto.
    Route::prefix('padrones/ssco')->group(function () {
        Route::get('/estado', [SscoController::class, 'estado']);
        Route::post('/refrescar', [SscoController::class, 'refrescar']);
        Route::post('/subir', [SscoController::class, 'subir']);
        Route::post('/lote', [SscoController::class, 'lote']);
        Route::get('/{ruc}', [SscoController::class, 'consultar']);
    });

    // ========================
    // PROPUESTA SIRE (RVIE/RCE) Y REPOSITORIO POR PERIODO
    // ========================
    // Genera la propuesta del periodo y la archiva por (empresa, libro,
    // periodo): re-descargar reemplaza la anterior, sin duplicados.
    Route::prefix('sire')->group(function () {
        Route::get('/', [SireController::class, 'index']);
        Route::post('/subir', [SireController::class, 'subir']);
        Route::get('/{libro}', [SireController::class, 'descargar']);
        Route::get('/{libro}/{periodo}', [SireController::class, 'show']);
    });

    // ========================
    // NOTAS DE CRÉDITO
    // ========================
    Route::prefix('credit-notes')->group(function () {
        Route::get('/catalogs/motivos', [CreditNoteController::class, 'catalogsMotivos']);
        Route::get('/', [CreditNoteController::class, 'index']);
        Route::post('/', [CreditNoteController::class, 'store']);
        Route::get('/{id}', [CreditNoteController::class, 'show']);
        Route::get('/{id}/download-xml', [CreditNoteController::class, 'downloadXml']);
        Route::get('/{id}/download-cdr', [CreditNoteController::class, 'downloadCdr']);
        Route::get('/{id}/download-pdf', [CreditNoteController::class, 'downloadPdf']);
        Route::post('/{id}/generate-pdf', [CreditNoteController::class, 'generatePdf']);
        Route::post('/{id}/send-sunat', [CreditNoteController::class, 'sendToSunat']);
    });

    // ========================
    // NOTAS DE DÉBITO
    // ========================
    Route::prefix('debit-notes')->group(function () {
        Route::get('/catalogs/motivos', [DebitNoteController::class, 'catalogsMotivos']);
        Route::get('/', [DebitNoteController::class, 'index']);
        Route::post('/', [DebitNoteController::class, 'store']);
        Route::get('/{id}', [DebitNoteController::class, 'show']);
        Route::get('/{id}/download-xml', [DebitNoteController::class, 'downloadXml']);
        Route::get('/{id}/download-cdr', [DebitNoteController::class, 'downloadCdr']);
        Route::get('/{id}/download-pdf', [DebitNoteController::class, 'downloadPdf']);
        Route::post('/{id}/generate-pdf', [DebitNoteController::class, 'generatePdf']);
        Route::post('/{id}/send-sunat', [DebitNoteController::class, 'sendToSunat']);
    });

    // ========================
    // GUÍAS DE REMISIÓN
    // ========================
    Route::prefix('dispatch-guides')->group(function () {
        Route::get('/catalogs/transfer-reasons', [DispatchGuideController::class, 'catalogsTransferReasons']);
        Route::get('/catalogs/transport-modes', [DispatchGuideController::class, 'catalogsTransportModes']);
        Route::get('/', [DispatchGuideController::class, 'index']);
        Route::post('/', [DispatchGuideController::class, 'store']);
        Route::get('/{id}', [DispatchGuideController::class, 'show']);
        Route::get('/{id}/download-xml', [DispatchGuideController::class, 'downloadXml']);
        Route::get('/{id}/download-cdr', [DispatchGuideController::class, 'downloadCdr']);
        Route::get('/{id}/download-pdf', [DispatchGuideController::class, 'downloadPdf']);
        Route::post('/{id}/generate-pdf', [DispatchGuideController::class, 'generatePdf']);
        Route::post('/{id}/send-sunat', [DispatchGuideController::class, 'sendToSunat']);
    });

    // ========================
    // CREDENCIALES GRE (GUÍAS DE REMISIÓN)
    // ========================
    Route::get('/gre-credentials/defaults/{modo}', [GreCredentialsController::class, 'defaults'])
        ->whereIn('modo', ['beta', 'produccion']);
    Route::prefix('companies/{company_id}/gre-credentials')->group(function () {
        Route::get('/', [GreCredentialsController::class, 'show']);
        Route::put('/', [GreCredentialsController::class, 'update']);
        Route::post('/test-connection', [GreCredentialsController::class, 'testConnection']);
        Route::delete('/clear', [GreCredentialsController::class, 'clear']);
        Route::post('/copy', [GreCredentialsController::class, 'copy']);
    });
});
