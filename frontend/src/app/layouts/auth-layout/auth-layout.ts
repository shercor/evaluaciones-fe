import { Component } from '@angular/core';
import { RouterOutlet } from '@angular/router';

/**
 * Marco de las pantallas de acceso: login, recuperación y definición de
 * contraseña. Sin navegación, porque todavía no hay sesión.
 */
@Component({
  selector: 'app-auth-layout',
  imports: [RouterOutlet],
  templateUrl: './auth-layout.html',
})
export class AuthLayout {}
