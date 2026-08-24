import { HttpClient } from '@angular/common/http';
import { Component, inject, signal } from '@angular/core';
import { FormBuilder, ReactiveFormsModule, Validators } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { firstValueFrom } from 'rxjs';
import { mensajeDeError } from '../../../core/http/api-error';

@Component({
  selector: 'app-forgot-password',
  imports: [ReactiveFormsModule, RouterLink],
  templateUrl: './forgot-password.html',
})
export class ForgotPassword {
  private readonly http = inject(HttpClient);
  private readonly fb = inject(FormBuilder);

  protected readonly form = this.fb.nonNullable.group({
    email: ['', [Validators.required, Validators.email]],
  });

  protected readonly enviando = signal(false);
  protected readonly error = signal<string | null>(null);
  protected readonly enviado = signal(false);

  protected async enviar(): Promise<void> {
    if (this.form.invalid || this.enviando()) {
      this.form.markAllAsTouched();
      return;
    }

    this.enviando.set(true);
    this.error.set(null);

    try {
      await firstValueFrom(
        this.http.post('/api/auth/forgot-password', this.form.getRawValue(), {
          withCredentials: true,
        }),
      );

      // El backend responde lo mismo exista o no la cuenta, así que acá
      // tampoco se distingue: decir «ese correo no existe» permitiría
      // averiguar qué direcciones están registradas.
      this.enviado.set(true);
    } catch (e) {
      this.error.set(mensajeDeError(e, 'No se pudo enviar el correo.'));
    } finally {
      this.enviando.set(false);
    }
  }
}
