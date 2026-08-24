import { Routes } from '@angular/router';
import { authGuard, guestGuard, rootRedirect, roleGuard } from './core/auth/auth.guards';

/**
 * Mapa de la aplicación.
 *
 * Tres zonas: acceso (sin sesión), administración y portal del colaborador.
 * Todo entra por `loadComponent`, así cada sector viaja en su propio paquete.
 *
 * Los guards deciden qué se muestra. Quién puede hacer qué lo decide el BFF.
 */
export const routes: Routes = [
  // -- Entrada: manda a cada quien donde corresponda ------------------
  {
    path: '',
    pathMatch: 'full',
    canActivate: [rootRedirect],
    children: [],
  },

  // -- Acceso ---------------------------------------------------------
  {
    path: '',
    loadComponent: () => import('./layouts/auth-layout/auth-layout').then((m) => m.AuthLayout),
    children: [
      {
        path: 'login',
        canActivate: [guestGuard],
        loadComponent: () => import('./features/auth/login/login').then((m) => m.Login),
      },
      {
        path: 'recuperar-contrasena',
        canActivate: [guestGuard],
        loadComponent: () =>
          import('./features/auth/forgot-password/forgot-password').then((m) => m.ForgotPassword),
      },
      {
        path: 'restablecer-contrasena',
        loadComponent: () =>
          import('./features/auth/reset-password/reset-password').then((m) => m.ResetPassword),
      },
      {
        // Primera contraseña. Sin `guestGuard`: se llega por invitación (sin
        // sesión) o tras entrar con una temporal (con sesión).
        path: 'definir-contrasena',
        loadComponent: () =>
          import('./features/auth/reset-password/reset-password').then((m) => m.ResetPassword),
      },
    ],
  },

  // -- Administración -------------------------------------------------
  {
    path: 'admin',
    canActivate: [authGuard, roleGuard('super_admin', 'admin')],
    loadComponent: () => import('./layouts/admin-layout/admin-layout').then((m) => m.AdminLayout),
    children: [
      {
        path: '',
        loadComponent: () => import('./features/admin/home/admin-home').then((m) => m.AdminHome),
      },
      {
        path: 'evaluaciones',
        loadComponent: () =>
          import('./features/admin/evaluations/list/evaluations-list').then(
            (m) => m.EvaluationsList,
          ),
      },
      {
        // El asistente: un armazón con la barra de pasos y cuatro pantallas.
        // Antes de crear la evaluación no hay id; después, viaja en la ruta.
        path: 'evaluaciones/asistente',
        loadComponent: () =>
          import('./features/admin/evaluations/wizard/shell/wizard-shell').then(
            (m) => m.WizardShell,
          ),
        children: [
          { path: '', pathMatch: 'full', redirectTo: 'definir' },
          {
            path: 'definir',
            loadComponent: () =>
              import('./features/admin/evaluations/wizard/step-definir/step-definir').then(
                (m) => m.StepDefinir,
              ),
          },
        ],
      },
      {
        path: 'evaluaciones/asistente/:id',
        loadComponent: () =>
          import('./features/admin/evaluations/wizard/shell/wizard-shell').then(
            (m) => m.WizardShell,
          ),
        children: [
          { path: '', pathMatch: 'full', redirectTo: 'sucursales' },
          {
            path: 'sucursales',
            loadComponent: () =>
              import('./features/admin/evaluations/wizard/step-sucursales/step-sucursales').then(
                (m) => m.StepSucursales,
              ),
          },
          {
            path: 'participantes',
            loadComponent: () =>
              import(
                './features/admin/evaluations/wizard/step-participantes/step-participantes'
              ).then((m) => m.StepParticipantes),
          },
          {
            path: 'previsualizacion',
            loadComponent: () =>
              import(
                './features/admin/evaluations/wizard/step-previsualizacion/step-previsualizacion'
              ).then((m) => m.StepPrevisualizacion),
          },
        ],
      },
      {
        path: 'directorio',
        loadComponent: () =>
          import('./features/admin/directory/people/people').then((m) => m.People),
      },
      {
        path: 'directorio/importar',
        loadComponent: () =>
          import('./features/admin/directory/import/import').then((m) => m.Import),
      },
      {
        // Sucursales y cargos comparten componente: el tipo va en `data`.
        path: 'directorio/sucursales',
        data: { tipo: 'sucursales' },
        loadComponent: () =>
          import('./features/admin/directory/catalog/catalog').then((m) => m.Catalog),
      },
      {
        path: 'directorio/cargos',
        data: { tipo: 'cargos' },
        loadComponent: () =>
          import('./features/admin/directory/catalog/catalog').then((m) => m.Catalog),
      },
    ],
  },

  // -- Portal del colaborador -----------------------------------------
  {
    path: 'portal',
    canActivate: [authGuard],
    loadComponent: () =>
      import('./layouts/portal-layout/portal-layout').then((m) => m.PortalLayout),
    children: [
      {
        path: '',
        loadComponent: () =>
          import('./features/portal/home/portal-home').then((m) => m.PortalHome),
      },
      {
        path: 'evaluacion/:id',
        loadComponent: () =>
          import('./features/portal/tareas/tareas').then((m) => m.PortalTareas),
      },
      {
        path: 'tarea/:id',
        loadComponent: () =>
          import('./features/portal/responder/responder').then((m) => m.PortalResponder),
      },
    ],
  },

  // -- Diagnóstico del andamiaje (hito 1) -----------------------------
  {
    path: 'estado',
    loadComponent: () =>
      import('./features/system-status/system-status').then((m) => m.SystemStatus),
  },

  { path: '**', redirectTo: '' },
];
