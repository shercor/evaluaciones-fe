import {
  ApplicationConfig,
  inject,
  provideAppInitializer,
  provideBrowserGlobalErrorListeners,
} from '@angular/core';
import {
  provideHttpClient,
  withFetch,
  withInterceptors,
  withXsrfConfiguration,
} from '@angular/common/http';
import { provideRouter } from '@angular/router';
import { provideEchartsCore } from 'ngx-echarts';
import { routes } from './app.routes';
import { AuthService } from './core/auth/auth.service';
import { authInterceptor } from './core/http/auth.interceptor';

export const appConfig: ApplicationConfig = {
  providers: [
    provideBrowserGlobalErrorListeners(),
    provideRouter(routes),

    // El BFF autentica por cookie de sesión (Sanctum), así que las peticiones
    // van con credenciales y Angular tiene que reenviar el token XSRF que
    // Laravel deja en la cookie.
    provideHttpClient(
      withFetch(),
      withInterceptors([authInterceptor]),
      withXsrfConfiguration({
        cookieName: 'XSRF-TOKEN',
        headerName: 'X-XSRF-TOKEN',
      }),
    ),

    // ECharts se carga bajo demanda: solo las pantallas con gráficos pagan
    // su peso, no toda la aplicación.
    provideEchartsCore({ echarts: () => import('./shared/charts/echarts') }),

    // Se pregunta al backend si hay sesión ANTES de resolver la primera ruta.
    // Sin esto, recargar dentro del portal rebotaría al login: los guards
    // correrían antes de saber quién está conectado.
    provideAppInitializer(() => inject(AuthService).restore()),
  ],
};
