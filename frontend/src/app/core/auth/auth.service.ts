import { HttpClient } from '@angular/common/http';
import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable, firstValueFrom, tap } from 'rxjs';
import { LoginResponse, Role, User } from './user.model';

/**
 * Estado de sesión de la aplicación.
 *
 * La sesión vive en una cookie `HttpOnly` que pone Laravel (Sanctum en modo
 * SPA). Acá no se guarda ningún token: el navegador manda la cookie sola y el
 * JavaScript no puede leerla, así que un XSS no se la lleva.
 *
 * Lo que sí se guarda es *quién* es la persona, para decidir qué mostrar. Esa
 * decisión es cosmética — quien mande, manda el backend.
 */
@Injectable({ providedIn: 'root' })
export class AuthService {
  private readonly http = inject(HttpClient);

  private readonly currentUser = signal<User | null>(null);
  /** Aún no se sabe si hay sesión: todavía no se preguntó al backend. */
  private readonly resolved = signal(false);

  readonly user = this.currentUser.asReadonly();
  readonly isResolved = this.resolved.asReadonly();
  readonly isAuthenticated = computed(() => this.currentUser() !== null);
  readonly isAdministrative = computed(() => this.currentUser()?.is_administrative ?? false);

  /**
   * Laravel exige el token XSRF en cada petición que modifica algo. Se pide
   * una vez antes de iniciar sesión; después Angular lo reenvía solo, porque
   * `withXsrfConfiguration` lo lee de la cookie.
   */
  csrf(): Promise<unknown> {
    return firstValueFrom(this.http.get('/sanctum/csrf-cookie', { withCredentials: true }));
  }

  async login(email: string, password: string, remember = false): Promise<LoginResponse> {
    await this.csrf();

    const response = await firstValueFrom(
      this.http.post<LoginResponse>(
        '/api/auth/login',
        { email, password, remember },
        { withCredentials: true },
      ),
    );

    this.currentUser.set(response.user);
    this.resolved.set(true);

    return response;
  }

  logout(): Observable<unknown> {
    return this.http
      .post('/api/auth/logout', {}, { withCredentials: true })
      .pipe(tap(() => this.clear()));
  }

  /**
   * Recupera la sesión al arrancar la aplicación.
   *
   * Se ejecuta antes de resolver las rutas, para que un F5 dentro del portal
   * no rebote al login teniendo la sesión viva.
   */
  async restore(): Promise<void> {
    try {
      const response = await firstValueFrom(
        this.http.get<{ user: User }>('/api/auth/me', { withCredentials: true }),
      );
      this.currentUser.set(response.user);
    } catch {
      // Sin sesión: es el caso normal de quien entra por primera vez.
      this.currentUser.set(null);
    } finally {
      this.resolved.set(true);
    }
  }

  /** Limpia el estado local. La invalida el backend, no esto. */
  clear(): void {
    this.currentUser.set(null);
    this.resolved.set(true);
  }

  hasRole(...roles: Role[]): boolean {
    const role = this.currentUser()?.role;
    return role !== undefined && roles.includes(role);
  }

  /** Ruta inicial según el rol. El backend manda la suya al iniciar sesión. */
  homeRoute(): string {
    const user = this.currentUser();
    if (!user) return '/login';
    if (user.must_set_password) return '/definir-contrasena';
    return user.is_administrative ? '/admin' : '/portal';
  }
}
