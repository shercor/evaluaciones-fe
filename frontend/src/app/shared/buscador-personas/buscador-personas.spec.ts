import { ComponentRef } from '@angular/core';
import { ComponentFixture, TestBed } from '@angular/core/testing';
import { Observable, Subject, of } from 'rxjs';
import { Mock, beforeEach, describe, expect, it, vi } from 'vitest';
import { BuscadorPersonas, PersonaSugerida } from './buscador-personas';

/**
 * Lo que se prueba acá es el **contrato de tecleo**: cuándo se consulta y
 * cuándo no. Es la parte que no se ve mirando la pantalla y la que más caro
 * sale equivocar, porque el error se manifiesta como «la lista a veces muestra
 * cualquier cosa» con un padrón de 7.000 personas.
 */
describe('BuscadorPersonas', () => {
  let fixture: ComponentFixture<BuscadorPersonas>;
  let ref: ComponentRef<BuscadorPersonas>;
  let consultar: Mock<(termino: string) => Observable<PersonaSugerida[]>>;

  const persona = (id: number, nombre: string): PersonaSugerida => ({ id, nombre, codigo: null });

  /** Escribe en la caja como lo haría una persona. */
  const escribir = (texto: string) => {
    const campo: HTMLInputElement = fixture.nativeElement.querySelector('input');
    campo.value = texto;
    campo.dispatchEvent(new Event('input'));
    fixture.detectChanges();
  };

  const sugerencias = (): string[] =>
    [...fixture.nativeElement.querySelectorAll('.sugerencia-nombre')].map((n: Element) =>
      (n.textContent ?? '').trim(),
    );

  beforeEach(async () => {
    vi.useFakeTimers();
    consultar = vi.fn((_termino: string) => of<PersonaSugerida[]>([]));

    await TestBed.configureTestingModule({ imports: [BuscadorPersonas] }).compileComponents();

    fixture = TestBed.createComponent(BuscadorPersonas);
    ref = fixture.componentRef;
    ref.setInput('buscar', (t: string) => consultar(t));
    ref.setInput('minimo', 3);
    ref.setInput('espera', 300);
    fixture.detectChanges();
  });

  it('no consulta por debajo del mínimo', () => {
    escribir('ro');
    vi.advanceTimersByTime(1000);

    expect(consultar).not.toHaveBeenCalled();
  });

  it('avisa cuántos caracteres faltan', () => {
    escribir('r');
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain('Escribí 2 caracteres más');
  });

  it('espera a que el teclado se quede quieto: una consulta, no una por tecla', () => {
    consultar.mockReturnValue(of([persona(1, 'Rodrigo Fuentes')]));

    for (const t of ['rod', 'rodr', 'rodri', 'rodrig', 'rodrigo']) {
      escribir(t);
      vi.advanceTimersByTime(80); // más rápido que la espera
    }

    expect(consultar).not.toHaveBeenCalled();

    vi.advanceTimersByTime(300);

    expect(consultar).toHaveBeenCalledTimes(1);
    expect(consultar).toHaveBeenCalledWith('rodrigo');
  });

  it('respeta la espera que le pasen, no la de fábrica', () => {
    ref.setInput('espera', 1000);
    fixture.detectChanges();
    consultar.mockReturnValue(of([]));

    escribir('rodrigo');
    vi.advanceTimersByTime(400);
    expect(consultar).not.toHaveBeenCalled();

    vi.advanceTimersByTime(700);
    expect(consultar).toHaveBeenCalledTimes(1);
  });

  it('descarta la respuesta vieja si llega después de una búsqueda nueva', () => {
    const lenta = new Subject<PersonaSugerida[]>();
    const rapida = new Subject<PersonaSugerida[]>();

    consultar.mockReturnValueOnce(lenta).mockReturnValueOnce(rapida);

    escribir('rod');
    vi.advanceTimersByTime(300);

    escribir('rodrigo');
    vi.advanceTimersByTime(300);

    // Contesta primero la nueva y después la vieja, que es el orden que rompe
    // una implementación con `mergeMap`.
    rapida.next([persona(2, 'Rodrigo Fuentes')]);
    fixture.detectChanges();
    lenta.next([persona(9, 'Rosa Olmos')]);
    fixture.detectChanges();

    expect(sugerencias()).toEqual(['Rodrigo Fuentes']);
  });

  it('entrega la persona elegida y la retira al limpiar', () => {
    const elegidas: (PersonaSugerida | null)[] = [];
    fixture.componentInstance.elegida.subscribe((p) => elegidas.push(p));

    consultar.mockReturnValue(of([persona(7, 'Rodrigo Fuentes')]));
    escribir('rodrigo');
    vi.advanceTimersByTime(300);
    fixture.detectChanges();

    fixture.nativeElement.querySelector('.sugerencia').dispatchEvent(new MouseEvent('click'));
    fixture.detectChanges();

    expect(elegidas).toEqual([persona(7, 'Rodrigo Fuentes')]);
    expect(fixture.nativeElement.querySelector('input').value).toBe('Rodrigo Fuentes');

    fixture.nativeElement.querySelector('.buscador-limpiar').dispatchEvent(new MouseEvent('click'));
    fixture.detectChanges();

    expect(elegidas).toEqual([persona(7, 'Rodrigo Fuentes'), null]);
  });

  it('abre mostrando la persona que ya estaba elegida', () => {
    ref.setInput('inicial', persona(4, 'Marcela Rivas'));
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('input').value).toBe('Marcela Rivas');
    expect(consultar).not.toHaveBeenCalled();
  });

  it('al reabrir sobre otra persona no arrastra la anterior', () => {
    ref.setInput('inicial', persona(4, 'Marcela Rivas'));
    fixture.detectChanges();

    ref.setInput('inicial', persona(9, 'Felipe Cortés'));
    fixture.detectChanges();

    expect(fixture.nativeElement.querySelector('input').value).toBe('Felipe Cortés');
  });

  it('editar el texto de la persona elegida la deselecciona', () => {
    const elegidas: (PersonaSugerida | null)[] = [];
    ref.setInput('inicial', persona(4, 'Marcela Rivas'));
    fixture.detectChanges();
    fixture.componentInstance.elegida.subscribe((p) => elegidas.push(p));

    escribir('Marcela Riva');

    expect(elegidas).toEqual([null]);
  });

  it('el contenedor puede vaciarlo, y sin robarle el foco a nadie', () => {
    const elegidas: (PersonaSugerida | null)[] = [];
    consultar.mockReturnValue(of([persona(7, 'Rodrigo Fuentes')]));
    escribir('rodrigo');
    vi.advanceTimersByTime(300);
    fixture.detectChanges();
    fixture.nativeElement.querySelector('.sugerencia').dispatchEvent(new MouseEvent('click'));
    fixture.detectChanges();
    fixture.componentInstance.elegida.subscribe((p) => elegidas.push(p));

    const campo: HTMLInputElement = fixture.nativeElement.querySelector('input');
    campo.blur();

    fixture.componentInstance.limpiar();
    fixture.detectChanges();

    expect(campo.value).toBe('');
    expect(elegidas).toEqual([null]);
    expect(document.activeElement).not.toBe(campo);
  });

  it('un fallo de red no rompe el control', () => {
    consultar.mockReturnValue(
      new Observable<PersonaSugerida[]>((s) => s.error(new Error('sin red'))),
    );

    escribir('rodrigo');
    vi.advanceTimersByTime(300);
    fixture.detectChanges();

    expect(fixture.nativeElement.textContent).toContain('No se pudo buscar');

    // Y sigue sirviendo después del fallo.
    consultar.mockReturnValue(of([persona(1, 'Rodrigo Fuentes')]));
    escribir('rodrigo f');
    vi.advanceTimersByTime(300);
    fixture.detectChanges();

    expect(sugerencias()).toEqual(['Rodrigo Fuentes']);
  });
});
