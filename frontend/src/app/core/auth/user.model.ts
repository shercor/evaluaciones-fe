export type Role = 'super_admin' | 'admin' | 'collaborator';

export interface Named {
  id: number;
  name: string;
}

/**
 * La persona autenticada, tal como la devuelve el BFF.
 *
 * Es un reflejo de `UserResource` en Laravel. Si allá cambia la forma, acá
 * tiene que cambiar también.
 */
export interface User {
  id: number;
  name: string;
  lastname: string | null;
  full_name: string;
  initials: string;
  email: string;
  role: Role;
  role_label: string;
  is_administrative: boolean;
  active: boolean;
  /** Por qué está inactiva, ya redactado. `null` si está activa. */
  deactivation_reason?: string | null;
  deactivated_at?: string | null;
  must_set_password: boolean;
  avatar_url: string | null;
  branch_office?: Named | null;
  job_position?: Named | null;
  supervisor?: { id: number; full_name: string } | null;
}

export interface LoginResponse {
  user: User;
  /** A dónde mandar a la persona según su rol. Lo decide el backend. */
  redirect_to: string;
}
