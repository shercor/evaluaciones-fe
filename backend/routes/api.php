<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\CatalogController;
use App\Http\Controllers\Admin\EvaluationController;
use App\Http\Controllers\Admin\FormsPreviewController;
use App\Http\Controllers\Admin\GroupController;
use App\Http\Controllers\Admin\ResultsController;
use App\Http\Controllers\Admin\EvaluationWizardController;
use App\Http\Controllers\Admin\ImportController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Portal\PortalController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Resources\UserResource;
use App\Support\E360\E360Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API del BFF
|--------------------------------------------------------------------------
|
| Todo lo que consume Angular entra por acá. La API de Evaluación 360 nunca
| se llama desde el navegador: el token del tenant vive solo en este servicio.
|
*/

/**
 * Estado del servicio. Sin autenticación a propósito: sirve para comprobar
 * que el proxy del SPA llega hasta acá y para el healthcheck del despliegue.
 * Informa si falta configuración, nunca los valores.
 */
Route::get('/health', function (E360Client $client) {
    return response()->json([
        'service' => 'evaluacion-personal-bff',
        'status' => 'ok',
        'e360' => [
            'configured' => $client->missingConfiguration() === [],
        ],
    ]);
});

// -- Acceso -----------------------------------------------------------

Route::prefix('auth')->group(function () {
    // Sin middleware `guest`: en una API redirige (302) en vez de responder
    // JSON, y volver a iniciar sesión teniendo una abierta es inofensivo —
    // simplemente se regenera la sesión.
    Route::post('login', [LoginController::class, 'login']);
    Route::post('forgot-password', [PasswordController::class, 'forgot']);
    Route::post('reset-password', [PasswordController::class, 'reset']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [LoginController::class, 'me']);
        Route::post('logout', [LoginController::class, 'logout']);
        Route::post('change-password', [PasswordController::class, 'change']);
    });
});

// -- Zona autenticada -------------------------------------------------

Route::middleware('auth:sanctum')->group(function () {

    Route::get('/user', fn (Request $request) => new UserResource($request->user()));

    /*
     * Portal del colaborador.
     *
     * Sin middleware de rol: cualquiera con sesión responde su propia
     * evaluación, incluidos los administradores. La persona sale siempre de
     * la sesión, nunca de un parámetro.
     */
    Route::prefix('portal')->group(function () {
        Route::get('aviso', [PortalController::class, 'pendingNotice']);
        Route::get('evaluaciones', [PortalController::class, 'myEvaluations']);
        Route::get('evaluaciones/{evaluationId}/tareas', [PortalController::class, 'tasks'])->whereNumber('evaluationId');
        Route::get('tareas/{taskId}', [PortalController::class, 'questions'])->whereNumber('taskId');
        Route::post('tareas/{taskId}/respuestas', [PortalController::class, 'answer'])->whereNumber('taskId');

        // Resultados propios y de las personas a cargo.
        Route::get('evaluaciones/{id}/resultados', [PortalController::class, 'myResults'])->whereNumber('id');
        Route::get('evaluaciones/{id}/supervisados', [PortalController::class, 'mySupervisees'])->whereNumber('id');
        Route::get('evaluaciones/{id}/supervisados/{userId}', [PortalController::class, 'superviseeResults'])->whereNumber(['id', 'userId']);
    });

    /*
     * Portal de administración.
     *
     * El middleware `role` es la defensa real: los guards de Angular solo
     * evitan mostrar lo que no corresponde.
     */
    Route::prefix('admin')
        ->middleware('role:super_admin,admin')
        ->group(function () {
            Route::get('/ping', fn (Request $request) => response()->json([
                'message' => 'Acceso administrativo confirmado.',
                'role' => $request->user()->role->value,
            ]));

            // -- Directorio: personas --------------------------------
            Route::get('users', [UserController::class, 'index']);
            Route::post('users', [UserController::class, 'store']);
            Route::get('users/{user}', [UserController::class, 'show']);
            Route::put('users/{user}', [UserController::class, 'update']);
            Route::post('users/{user}/toggle-active', [UserController::class, 'toggleActive']);
            Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword']);
            Route::post('users/{user}/resend-invitation', [UserController::class, 'resendInvitation']);

            // -- Directorio: catálogos -------------------------------
            // {tipo} es «sucursales» o «cargos»: comparten forma y controlador.
            Route::get('catalogos/{tipo}', [CatalogController::class, 'index']);
            Route::post('catalogos/{tipo}', [CatalogController::class, 'store']);
            Route::put('catalogos/{tipo}/{id}', [CatalogController::class, 'update']);
            Route::post('catalogos/{tipo}/{id}/toggle-active', [CatalogController::class, 'toggleActive']);

            // -- Evaluaciones ----------------------------------------
            Route::get('evaluaciones', [EvaluationController::class, 'index']);
            Route::get('evaluaciones/{id}', [EvaluationController::class, 'show'])->whereNumber('id');
            Route::get('evaluaciones/{id}/estado', [EvaluationController::class, 'status'])->whereNumber('id');
            Route::post('evaluaciones/{id}/abrir', [EvaluationController::class, 'open'])->whereNumber('id');
            Route::post('evaluaciones/{id}/cerrar', [EvaluationController::class, 'close'])->whereNumber('id');
            Route::post('evaluaciones/{id}/publicar', [EvaluationController::class, 'publish'])->whereNumber('id');
            Route::post('evaluaciones/{id}/desactivar', [EvaluationController::class, 'destroy'])->whereNumber('id');
            Route::post('evaluaciones/{id}/reactivar', [EvaluationController::class, 'restore'])->whereNumber('id');

            // -- Asistente de creación (los 6 pasos) -----------------
            Route::prefix('asistente')->group(function () {
                Route::get('opciones', [EvaluationWizardController::class, 'options']);
                Route::get('periodo', [EvaluationWizardController::class, 'period']);
                Route::post('evaluaciones', [EvaluationWizardController::class, 'store']);

                Route::prefix('{id}')->whereNumber('id')->group(function () {
                    // Paso 1 sobre un proceso ya creado: volver y corregir.
                    Route::get('definicion', [EvaluationWizardController::class, 'definition']);
                    Route::post('definicion', [EvaluationWizardController::class, 'updateDefinition']);

                    Route::get('sucursales', [EvaluationWizardController::class, 'branchOffices']);
                    Route::post('sucursales', [EvaluationWizardController::class, 'saveBranchOffices']);

                    Route::get('participantes', [EvaluationWizardController::class, 'participants']);
                    Route::post('participantes/participacion', [EvaluationWizardController::class, 'setParticipation']);
                    Route::post('participantes/detalle', [EvaluationWizardController::class, 'updateParticipant']);
                    Route::get('participantes/supervisores', [EvaluationWizardController::class, 'supervisorOptions']);

                    Route::get('previsualizacion', [EvaluationWizardController::class, 'preview']);
                    Route::post('previsualizacion/excluir', [EvaluationWizardController::class, 'excludeOrphans']);

                    Route::post('enviar', [EvaluationWizardController::class, 'submit']);
                    Route::post('deshacer', [EvaluationWizardController::class, 'undoChanges']);
                });
            });

            // -- Resultados, tableros y monitoreo --------------------
            Route::prefix('evaluaciones/{id}')->whereNumber('id')->group(function () {
                Route::get('tablero', [ResultsController::class, 'dashboard']);
                Route::get('tablero/personas', [ResultsController::class, 'people']);
                Route::get('tablero/persona/{userId}', [ResultsController::class, 'person'])->whereNumber('userId');
                Route::get('categorias', [ResultsController::class, 'categories']);
                Route::get('preguntas/{questionId}', [ResultsController::class, 'question'])->whereNumber('questionId');
                Route::get('monitoreo', [ResultsController::class, 'monitor']);
                Route::get('monitoreo/personas', [ResultsController::class, 'monitorPeople']);
            });

            // -- Grupos de evaluación --------------------------------
            Route::get('grupos', [GroupController::class, 'index']);
            Route::post('grupos', [GroupController::class, 'store']);
            Route::put('grupos/{id}', [GroupController::class, 'update'])->whereNumber('id');
            Route::post('grupos/{id}/estado', [GroupController::class, 'toggleActive'])->whereNumber('id');

            // -- Previsualización de formularios ---------------------
            Route::get('previsualizacion/evaluacion/{id}', [FormsPreviewController::class, 'forEvaluation'])->whereNumber('id');
            Route::get('previsualizacion/plantilla/{id}', [FormsPreviewController::class, 'forTemplate'])->whereNumber('id');

            // -- Importación de nómina -------------------------------
            Route::get('importaciones', [ImportController::class, 'index']);
            Route::post('importaciones', [ImportController::class, 'store']);
            Route::get('importaciones/plantilla', [ImportController::class, 'template']);
            Route::get('importaciones/{import}', [ImportController::class, 'show']);
            Route::get('importaciones/{import}/contrasenas', [ImportController::class, 'downloadPasswords']);
        });
});
