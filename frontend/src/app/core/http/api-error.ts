import { HttpErrorResponse } from '@angular/common/http';

/**
 * Traduce un error del BFF a algo que se le pueda mostrar a una persona.
 *
 * Laravel devuelve 422 con `{message, errors: {campo: [texto, …]}}`. Se prefiere
 * el primer mensaje de validación porque es el que dice qué hacer; el `message`
 * genérico solo sirve de respaldo.
 */
export function mensajeDeError(error: unknown, respaldo = 'Ocurrió un error inesperado.'): string {
  if (!(error instanceof HttpErrorResponse)) {
    return respaldo;
  }

  if (error.status === 0) {
    return 'No se pudo contactar al servidor. Revisá tu conexión.';
  }

  const errores = error.error?.errors as Record<string, string[]> | undefined;
  if (errores) {
    const primero = Object.values(errores)[0]?.[0];
    if (primero) return primero;
  }

  if (typeof error.error?.message === 'string' && error.error.message !== '') {
    return error.error.message;
  }

  if (error.status === 403) {
    return 'No tenés permisos para realizar esta acción.';
  }

  return respaldo;
}

/**
 * Errores por campo, para pintarlos junto a cada input.
 */
export function erroresPorCampo(error: unknown): Record<string, string> {
  if (!(error instanceof HttpErrorResponse)) return {};

  const errores = error.error?.errors as Record<string, string[]> | undefined;
  if (!errores) return {};

  return Object.fromEntries(
    Object.entries(errores).map(([campo, mensajes]) => [campo, mensajes[0]]),
  );
}
