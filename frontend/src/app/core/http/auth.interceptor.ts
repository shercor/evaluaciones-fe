import { HttpErrorResponse, HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { Router } from '@angular/router';
import { catchError, throwError } from 'rxjs';
import { AuthService } from '../auth/auth.service';

/**
 * Manda las credenciales en toda petición al propio backend y reacciona a los
 * dos errores de sesión que importan.
 *
 * 401 — la sesión venció o nunca existió. Se limpia el estado local y se manda
 *       al login recordando dónde estaba.
 * 419 — Laravel rechazó el token XSRF, casi siempre porque la cookie caducó.
 *       No es un error para mostrarle a nadie; se deja pasar para que quien
 *       llamó reintente.
 */
export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const router = inject(Router);
  const auth = inject(AuthService);

  const esPropio = req.url.startsWith('/api') || req.url.startsWith('/sanctum');
  const request = esPropio ? req.clone({ withCredentials: true }) : req;

  return next(request).pipe(
    catchError((error: HttpErrorResponse) => {
      if (!esPropio) {
        return throwError(() => error);
      }

      // El propio /api/auth/me devuelve 401 cuando no hay sesión: es su forma
      // de contestar «no hay nadie», no un vencimiento.
      const esSondeoDeSesion = req.url.endsWith('/api/auth/me');

      if (error.status === 401 && !esSondeoDeSesion) {
        auth.clear();

        const actual = router.url;
        router.navigate(['/login'], {
          queryParams: actual && actual !== '/login' ? { redirect: actual } : {},
        });
      }

      return throwError(() => error);
    }),
  );
};
