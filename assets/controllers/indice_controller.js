import { Controller } from '@hotwired/stimulus';

/*
 * Índice "En esta página": resalta el título que se está leyendo.
 */
export default class extends Controller {
    static targets = ['enlace'];

    connect() {
        this.titulos = this.enlaceTargets
            .map((enlace) => document.getElementById(decodeURIComponent(enlace.hash.slice(1))))
            .filter(Boolean);
        if (!this.titulos.length) {
            return;
        }
        this.alDesplazar = () => {
            cancelAnimationFrame(this.cuadro);
            this.cuadro = requestAnimationFrame(() => this.actualizar());
        };
        window.addEventListener('scroll', this.alDesplazar, { passive: true });
        this.actualizar();
    }

    disconnect() {
        window.removeEventListener('scroll', this.alDesplazar);
        cancelAnimationFrame(this.cuadro);
    }

    actualizar() {
        const margen = 120;
        let activo = this.titulos[0];
        for (const titulo of this.titulos) {
            if (titulo.getBoundingClientRect().top <= margen) {
                activo = titulo;
            }
        }
        // Al llegar al final de la página se marca el último título.
        if (window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 4) {
            activo = this.titulos[this.titulos.length - 1];
        }
        // Los ids con tildes llegan codificados en enlace.hash ("gu%C3%ADa").
        this.enlaceTargets.forEach((enlace) => {
            enlace.classList.toggle('activo', decodeURIComponent(enlace.hash.slice(1)) === activo?.id);
        });
    }
}
