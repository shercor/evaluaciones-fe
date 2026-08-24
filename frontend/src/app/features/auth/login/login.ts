import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { AuthService } from '../../../core/auth/auth.service';
import { erroresPorCampo, mensajeDeError } from '../../../core/http/api-error';

@Component({
  selector: 'app-login',
  imports: [ReactiveFormsModule, RouterLink],
  templateUrl: './login.html',
})
export class Login {
  private readonly auth = inject(AuthService);
  private readonly router = inject(Router);
  private readonly route = inject(ActivatedRoute);
  private readonly fb = inject(FormBuilder);

  protected readonly form = this.fb.nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
    password: ['', [Validators.required]],
    remember: [false],
  });

  protected readonly enviando = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly erroresCampo = signal<Record<string, string>>({});

  protected async enviar(): Promise<void> {
    if (this.form.invalid || this.enviando()) {
      this.form.markAllAsTouched();
      return;
    }

    this.enviando.set(true);
    this.error.set(null);
    this.erroresCampo.set({});

    const { email, password, remember } = this.form.getRawValue();

    try {
      const respuesta = await this.auth.login(email, password, remember);

      // Si venía de una URL protegida, se la devuelve; si no, al portal que
      // le corresponde según el rol, que decide el backend.
      const solicitada = this.route.snapshot.queryParamMap.get('redirect');
      await this.router.navigateByUrl(solicitada ?? respuesta.redirect_to);
    } catch (e) {
      this.error.set(mensajeDeError(e, 'No se pudo iniciar sesión.'));
      this.erroresCampo.set(erroresPorCampo(e));
    } finally {
      this.enviando.set(false);
    }
  }
}
