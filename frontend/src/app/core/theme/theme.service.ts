import { Injectable, signal } from '@angular/core';

export type Modo = 'sistema' | 'claro' | 'oscuro';

export interface Tema {
  id: string;
  nombre: string;
  /** Muestra de color para la ficha del selector: acento y segunda voz. */
  acento: string;
  realce: string;
}

/**
 * Los ocho temas, en el orden en que se ofrecen.
 *
 * Las muestras están escritas acá y no leídas del CSS a propósito: el panel
 * tiene que poder dibujar la ficha de un tema **sin aplicarlo**, y las
 * variables solo existen para el que está puesto.
 */
export const TEMAS: Tema[] = [
  { id: 'violeta', nombre: 'Violeta', acento: '#61468a', realce: '#8a4a76' },
  { id: 'azul', nombre: 'Azul', acento: '#1c5991', realce: '#007284' },
  { id: 'teal', nombre: 'Verde azulado', acento: '#006658', realce: '#4b6294' },
  { id: 'bosque', nombre: 'Bosque', acento: '#2c643b', realce: '#6f6423' },
  { id: 'borgona', nombre: 'Borgoña', acento: '#893943', realce: '#844c7f' },
  { id: 'cobre', nombre: 'Cobre', acento: '#804515', realce: '#924c47' },
  { id: 'indigo', nombre: 'Índigo', acento: '#434d9e', realce: '#006e9b' },
  { id: 'grafito', nombre: 'Grafito', acento: '#505668', realce: '#5d6374' },
];

const CLAVE_TEMA = 'ep-tema';
const CLAVE_MODO = 'ep-modo';

/**
 * Apariencia de la aplicación: qué paleta y si va en claro u oscuro.
 *
 * Son dos ejes independientes. La paleta se marca en `data-tema` y el modo
 * en `data-theme`; el CSS combina ambos. «Sistema» quita el atributo del
 * modo para que mande `prefers-color-scheme`, en vez de congelar el valor
 * que hubiera al cargar: así, si la persona cambia el ajuste del sistema
 * con la app abierta, la app la sigue.
 */
@Injectable({ providedIn: 'root' })
export class ThemeService {
  readonly tema = signal<string>('violeta');
  readonly modo = signal<Modo>('sistema');

  /**
   * Sube cada vez que cambia algo de la apariencia.
   *
   * Los gráficos leen sus colores en el momento de dibujarse, así que
   * necesitan una señal para volver a hacerlo: sin esto se quedarían con la
   * paleta anterior hasta recargar.
   */
  readonly version = signal(0);

  constructor() {
    this.aplicar(this.leer(CLAVE_TEMA, 'violeta'), this.leer(CLAVE_MODO, 'sistema') as Modo);

    // Si el modo es «sistema», seguir al sistema mientras la app está abierta.
    globalThis.matchMedia?.('(prefers-color-scheme: dark)').addEventListener('change', () => {
      if (this.modo() === 'sistema') {
        this.version.update((v) => v + 1);
      }
    });
  }

  elegirTema(id: string): void {
    this.aplicar(id, this.modo());
  }

  elegirModo(modo: Modo): void {
    this.aplicar(this.tema(), modo);
  }

  /** ¿Se está viendo en oscuro ahora mismo? Cuenta la preferencia del sistema. */
  esOscuro(): boolean {
    const m = this.modo();
    if (m === 'oscuro') return true;
    if (m === 'claro') return false;
    return globalThis.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false;
  }

  private aplicar(tema: string, modo: Modo): void {
    const raiz = document.documentElement;

    raiz.setAttribute('data-tema', tema);

    if (modo === 'sistema') {
      raiz.removeAttribute('data-theme');
    } else {
      raiz.setAttribute('data-theme', modo === 'oscuro' ? 'dark' : 'light');
    }

    this.tema.set(tema);
    this.modo.set(modo);
    this.version.update((v) => v + 1);

    this.guardar(CLAVE_TEMA, tema);
    this.guardar(CLAVE_MODO, modo);
  }

  // El almacenamiento puede fallar —ventana privada, cookies bloqueadas— y
  // eso no es motivo para dejar la aplicación sin tema.
  private leer(clave: string, porDefecto: string): string {
    try {
      return localStorage.getItem(clave) ?? porDefecto;
    } catch {
      return porDefecto;
    }
  }

  private guardar(clave: string, valor: string): void {
    try {
      localStorage.setItem(clave, valor);
    } catch {
      /* sin persistencia, pero la sesión actual funciona igual */
    }
  }
}
