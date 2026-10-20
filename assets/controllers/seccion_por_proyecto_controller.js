import { Controller } from '@hotwired/stimulus';

/*
 * Formulario de documento: el selector de sección solo muestra las del proyecto elegido.
 * Las secciones vienen agrupadas por proyecto (<optgroup label="Nombre del proyecto">).
 * El servidor valida lo mismo; esto solo evita que el editor elija una que va a ser rechazada.
 */
export default class extends Controller {
    static targets = ['proyecto', 'seccion'];

    connect() {
        this.filtrar();
    }

    filtrar() {
        const proyecto = this.proyectoTarget.selectedOptions[0];
        const nombre = proyecto && proyecto.value ? proyecto.textContent.trim() : null;

        this.seccionTarget.querySelectorAll('optgroup').forEach((grupo) => {
            const visible = nombre === null || grupo.label === nombre;
            grupo.hidden = !visible;
            grupo.disabled = !visible;
        });

        const elegida = this.seccionTarget.selectedOptions[0];
        if (elegida && elegida.parentElement.tagName === 'OPTGROUP' && elegida.parentElement.disabled) {
            this.seccionTarget.value = '';
        }
    }
}
