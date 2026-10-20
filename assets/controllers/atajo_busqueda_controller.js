import { Controller } from '@hotwired/stimulus';

/*
 * Atajos para ir a la búsqueda: Ctrl+K / Cmd+K, o "/" cuando no se está escribiendo.
 */
export default class extends Controller {
    static targets = ['campo', 'tecla'];

    connect() {
        if (this.hasTeclaTarget && /Mac|iPhone|iPad/.test(navigator.platform)) {
            this.teclaTarget.textContent = '⌘ K';
        }
    }

    tecla(evento) {
        const escribiendo = evento.target.closest('input, textarea, select, [contenteditable]');
        if (((evento.ctrlKey || evento.metaKey) && evento.key.toLowerCase() === 'k') || (evento.key === '/' && !escribiendo)) {
            evento.preventDefault();
            this.campoTarget.focus();
            this.campoTarget.select();
        }
    }
}
