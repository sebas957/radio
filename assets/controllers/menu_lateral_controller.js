import { Controller } from '@hotwired/stimulus';

/*
 * Menú lateral del visor:
 * - al cargar, deja visible el documento activo (Turbo reinicia el scroll en cada página);
 * - filtra los documentos por título, sin distinguir tildes, abriendo las ramas que coinciden.
 */
export default class extends Controller {
    static targets = ['filtro', 'vacio'];

    connect() {
        this.element.querySelector('a.activo')?.scrollIntoView({ block: 'center' });
    }

    filtrar() {
        const texto = this.normalizar(this.filtroTarget.value);
        const ramas = [...this.element.querySelectorAll('details')];

        if (!texto) {
            this.element.querySelectorAll('[hidden]').forEach((elemento) => { elemento.hidden = false; });
            ramas.forEach((rama) => {
                if (rama.dataset.abiertaOriginal !== undefined) {
                    rama.open = rama.dataset.abiertaOriginal === '1';
                    delete rama.dataset.abiertaOriginal;
                }
            });
            this.vacioTarget.hidden = true;
            return;
        }

        ramas.forEach((rama) => {
            if (rama.dataset.abiertaOriginal === undefined) {
                rama.dataset.abiertaOriginal = rama.open ? '1' : '0';
            }
        });

        let coincidencias = 0;
        this.element.querySelectorAll('a.documento').forEach((enlace) => {
            const coincide = this.normalizar(enlace.textContent).includes(texto);
            enlace.closest('li').hidden = !coincide;
            coincidencias += coincide ? 1 : 0;
        });

        // De adentro hacia afuera: una rama se ve si tiene algún documento visible.
        ramas.reverse().forEach((rama) => {
            const visible = [...rama.querySelectorAll('a.documento')].some((enlace) => !enlace.closest('li').hidden);
            rama.closest('li').hidden = !visible;
            rama.open = visible;
        });

        this.vacioTarget.hidden = coincidencias > 0;
    }

    limpiar(evento) {
        if (evento.key === 'Escape') {
            this.filtroTarget.value = '';
            this.filtrar();
        }
    }

    normalizar(texto) {
        return texto.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
    }
}
