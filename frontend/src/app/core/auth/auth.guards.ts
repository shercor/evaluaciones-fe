import { inject } from '@angular/core';
import { CanActivateFn, Router } from '@angular/router';
import { AuthService } from './auth.service';
import { Role } from './user.model';

/**
 * Guards de navegación.
 *
 * Son de comodidad, no de seguridad: evitan mostrar pantallas que no
 * corresponden. La autorización real la aplica el BFF en cada petición, y
 * sigue aplicándola aunque alguien escriba la URL a mano o modifique este
 * código desde el navegador.
 */

/** Exige sesión iniciada. */
export const authGuard: CanActivateFn = (_route, state) => {
  const auth = inject(AuthService);
  const router = inject(Router);

  if (auth.isAuthenticated()) {
    // Con contraseña pendiente de definir, no se navega a ningún otro lado.
    if (auth.user()?.must_set_password && state.url !== '/definir-contrasena') {
      return router.parseUrl('/definir-contrasena');
    }
    return true;
  }

  // Se recuerda a dónde quería ir, para volver ahí después de iniciar sesión.
  return router.createUrlTree(['/login'], { queryParams: { redirect: state.url } });
};

/** Solo para quien no tiene sesión: evita ver el login ya estando dentro. */
export const guestGuard: CanActivateFn = () => {
  const auth = inject(AuthService);
  const router = inject(Router);

  return auth.isAuthenticated() ? router.parseUrl(auth.homeRoute()) : true;
};

/**
 * Exige uno de los roles indicados.
 *
 * Se usa como `canActivate: [authGuard, roleGuard('super_admin', 'admin')]`.
 */
export const roleGuard =
  (...roles: Role[]): CanActivateFn =>
  () => {
    const auth = inject(AuthService);
    const router = inject(Router);

    if (auth.hasRole(...roles)) {
      return true;
    }

    // A su propio portal, no a una pantalla de error: la persona no hizo nada
    // malo, simplemente ese sector no es para ella.
    return router.parseUrl(auth.homeRoute());
  };

/**
 * Entrada de la aplicación.
 *
 * Nadie se queda en `/`: con sesión va a su portal, sin sesión al login. Así
 * la raíz no necesita una pantalla propia.
 */
export const rootRedirect: CanActivateFn = () => {
  const auth = inject(AuthService);
  const router = inject(Router);

  return router.parseUrl(auth.isAuthenticated() ? auth.homeRoute() : '/login');
};
