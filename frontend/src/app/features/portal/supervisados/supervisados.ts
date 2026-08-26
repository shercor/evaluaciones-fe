import { Component, inject, signal } from '@angular/core';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { ResultsService } from '../../../core/api/results.service';
import { PanelResultados, ResultsPanel } from '../../../shared/results-panel/results-panel';
import { mensajeDeError } from '../../../core/http/api-error';
import { Avatar } from '../../../shared/avatar/avatar';

interface Supervisado {
  user_id: number;
  nombre: string;
  iniciales: string;
  foto: string | null;
  cargo: string | null;
}

/**
 * Resultados de mi equipo.
 *
 * Solo aparecen quienes me reportan **directamente** dentro de esa evaluación,
 * y el backend vuelve a comprobarlo al pedir cada resultado: elegir a alguien
 * de la lista no es lo que da permiso.
 */
@Component({
  selector: 'app-supervisados',
  imports: [ResultsPanel, RouterLink, Avatar],
  templateUrl: './supervisados.html',
})
export class Supervisados {
  private readonly api = inject(ResultsService);
  private readonly ruta = inject(ActivatedRoute);

  protected readonly id = Number(this.ruta.snapshot.paramMap.get('id'));

  protected readonly equipo = signal<Supervisado[]>([]);
  protected readonly elegido = signal<Supervisado | null>(null);
  protected readonly datos = signal<PanelResultados | null>(null);
  protected readonly cargando = signal(true);
  protected readonly cargandoPanel = signal(false);
  protected readonly error = signal<string | null>(null);

  constructor() {
    this.api.misSupervisados(this.id).subscribe({
      next: (r) => {
        this.equipo.set(r.data);
        this.cargando.set(false);
        // Con una sola persona a cargo, mostrarla directamente ahorra un clic
        // que no decide nada.
        if (r.data.length === 1) this.elegir(r.data[0]);
      },
      error: (e) => {
        this.error.set(mensajeDeError(e, 'No se pudo cargar tu equipo.'));
        this.cargando.set(false);
      },
    });
  }

  protected elegir(s: Supervisado): void {
    this.elegido.set(s);
    this.cargandoPanel.set(true);
    this.datos.set(null);
    this.error.set(null);

    this.api.resultadosDeSupervisado(this.id, s.user_id).subscribe({
      next: (d) => {
        this.datos.set(d);
        this.cargandoPanel.set(false);
      },
      error: (e) => {
        this.error.set(mensajeDeError(e, 'No se pudieron cargar esos resultados.'));
        this.cargandoPanel.set(false);
      },
    });
  }
}
