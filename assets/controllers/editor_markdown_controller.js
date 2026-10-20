/* stimulusFetch: 'lazy' */
import { Controller } from '@hotwired/stimulus';
import EasyMDE from 'easymde';
import 'easymde/dist/easymde.min.css';

/*
 * Editor Markdown para el contenido de los documentos.
 * La vista previa la genera el servidor (mismo renderizador que el visor), así lo que
 * ve el editor es exactamente lo que verá el lector.
 */
export default class extends Controller {
    static targets = ['texto'];
    static values = { vistaPreviaUrl: String };

    connect() {
        this.editor = new EasyMDE({
            element: this.textoTarget,
            forceSync: true,
            spellChecker: false,
            autoDownloadFontAwesome: true,
            minHeight: '480px',
            placeholder: 'Escriba el contenido en Markdown...',
            renderingConfig: { codeSyntaxHighlighting: false },
            status: ['lines', 'words', 'cursor'],
            previewRender: (markdown, preview) => this.vistaPrevia(markdown, preview),
            toolbar: [
                { name: 'bold', action: EasyMDE.toggleBold, className: 'fa fa-bold', title: 'Negrita' },
                { name: 'italic', action: EasyMDE.toggleItalic, className: 'fa fa-italic', title: 'Cursiva' },
                { name: 'heading', action: EasyMDE.toggleHeadingSmaller, className: 'fa fa-header fa-heading', title: 'Título' },
                '|',
                { name: 'quote', action: EasyMDE.toggleBlockquote, className: 'fa fa-quote-left', title: 'Cita' },
                { name: 'unordered-list', action: EasyMDE.toggleUnorderedList, className: 'fa fa-list-ul', title: 'Lista' },
                { name: 'ordered-list', action: EasyMDE.toggleOrderedList, className: 'fa fa-list-ol', title: 'Lista numerada' },
                { name: 'code', action: EasyMDE.toggleCodeBlock, className: 'fa fa-code', title: 'Código' },
                '|',
                { name: 'link', action: EasyMDE.drawLink, className: 'fa fa-link', title: 'Enlace' },
                { name: 'image', action: EasyMDE.drawImage, className: 'fa fa-image', title: 'Imagen' },
                { name: 'table', action: EasyMDE.drawTable, className: 'fa fa-table', title: 'Tabla' },
                { name: 'horizontal-rule', action: EasyMDE.drawHorizontalRule, className: 'fa fa-minus', title: 'Línea' },
                '|',
                { name: 'preview', action: EasyMDE.togglePreview, className: 'fa fa-eye no-disable', title: 'Vista previa', noDisable: true },
                { name: 'side-by-side', action: EasyMDE.toggleSideBySide, className: 'fa fa-columns no-disable no-mobile', title: 'Lado a lado', noDisable: true, noMobile: true },
                { name: 'fullscreen', action: EasyMDE.toggleFullScreen, className: 'fa fa-arrows-alt no-disable no-mobile', title: 'Pantalla completa', noDisable: true, noMobile: true },
                '|',
                { name: 'guide', action: 'https://www.markdownguide.org/basic-syntax/', className: 'fa fa-question-circle', title: 'Guía de Markdown' },
            ],
        });
    }

    disconnect() {
        clearTimeout(this.temporizador);
        this.solicitud?.abort();
        this.editor?.toTextArea();
        this.editor = null;
    }

    vistaPrevia(markdown, preview) {
        clearTimeout(this.temporizador);
        this.temporizador = setTimeout(async () => {
            this.solicitud?.abort();
            this.solicitud = new AbortController();
            try {
                const respuesta = await fetch(this.vistaPreviaUrlValue, {
                    method: 'POST',
                    headers: { 'Content-Type': 'text/plain; charset=utf-8', 'X-Requested-With': 'XMLHttpRequest' },
                    body: markdown,
                    signal: this.solicitud.signal,
                });
                if (!respuesta.ok) {
                    throw new Error(`HTTP ${respuesta.status}`);
                }
                preview.innerHTML = await respuesta.text();
            } catch (error) {
                if (error.name !== 'AbortError') {
                    preview.innerHTML = '<p class="vista-previa-error">No se pudo generar la vista previa.</p>';
                }
            }
        }, 300);

        // Mientras llega la respuesta se mantiene lo que ya se mostraba.
        return preview.innerHTML || '<p class="suave">Generando vista previa...</p>';
    }
}
