import { HttpClient } from '@angular/common/http';
import { Component, computed, inject, signal } from '@angular/core';
import { AbstractControl, FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { AuthService } from '../../../core/auth/auth.service';
import { mensajeDeError } from '../../../core/http/api-error';

/**
 * Define una contraseña nueva. Cubre los dos caminos que llevan acá:
 *
 * **Con enlace** — llega por correo con `token` y `email` en la URL. Sirve
 * tanto para recuperar como para la invitación inicial: es el mismo mecanismo.
 *
 * **Con sesión abierta** — quien entró con una contraseña temporal y tiene
 * `must_set_password`. Se le pide la actual, así la temporal funciona como
 * credencial de un solo uso.
 */
@Component({
  selector: 'app-reset-password',
  imports: [ReactiveFormsModule, RouterLink],
  templateUrl: './reset-password.html',
})
export class ResetPassword {
  private readonly http = inject(HttpClient);
  private readonly fb = inject(FormBuilder);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly auth = inject(AuthService);

  private readonly token = this.route.snapshot.queryParamMap.get('token');
  private readonly email = this.route.snapshot.queryParamMap.get('email');

  /** Con token se usa el enlace; sin token, la sesión abierta. */
  protected readonly conEnlace = this.token !== null && this.email !== null;

  /** Primera vez (invitación o contraseña temporal) o recuperación. */
  protected readonly esPrimeraVez = computed(
    () => this.router.url.startsWith('/definir-contrasena'),
  );

  protected readonly form = this.fb.nonNullable.group(
    {
      current_password: [''],
      password: ['', [Validators.required, Validators.minLength(8)]],
      password_confirmation: ['', [Validators.required]],
    },
    { validators: coincidenLasContrasenas },
  );

  protected readonly enviando = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly listo = signal(false);

  constructor() {
    if (!this.conEnlace) {
      this.form.controls.current_password.addValidators(Validators.required);
      this.form.controls.current_password.updateValueAndValidity();
    }
  }

  protected async enviar(): Promise<void> {
    if (this.form.invalid || this.enviando()) {
      this.form.markAllAsTouched();
      return;
    }

    this.enviando.set(true);
    this.error.set(null);

    const { current_password, password, password_confirmation } = this.form.getRawValue();

    try {
      if (this.conEnlace) {
        await firstValueFrom(
          this.http.post(
            '/api/auth/reset-password',
            { token: this.token, email: this.email, password, password_confirmation },
            { withCredentials: true },
          ),
        );

        this.listo.set(true);
      } else {
        const respuesta = await firstValueFrom(
          this.http.post<{ redirect_to: string }>(
            '/api/auth/change-password',
            { current_password, password, password_confirmation },
            { withCredentials: true },
          ),
        );

        // La sesión sigue viva y ya no debe nada: se refresca el estado local
        // para que `must_set_password` deje de retenerla en esta pantalla.
        await this.auth.restore();
        await this.router.navigateByUrl(respuesta.redirect_to);
      }
    } catch (e) {
      this.error.set(mensajeDeError(e, 'No se pudo actualizar la contraseña.'));
    } finally {
      this.enviando.set(false);
    }
  }
}

/** Las dos contraseñas tienen que ser iguales. */
function coincidenLasContrasenas(control: AbstractControl) {
  const password = control.get('password')?.value;
  const confirmacion = control.get('password_confirmation')?.value;

  return password && confirmacion && password !== confirmacion
    ? { noCoinciden: true }
    : null;
}
