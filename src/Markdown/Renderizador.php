<?php

namespace App\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;

/**
 * Convierte Markdown (GFM: tablas, listas de tareas, autolinks) a HTML seguro.
 * Se usa en el visor y en la vista previa del editor para que ambos muestren lo mismo.
 *
 * El Markdown puede traer HTML (los .md de teneso son HTML de su editor visual), así que el
 * resultado siempre pasa por un sanitizador con lista blanca: se conservan las etiquetas de
 * formato y se quitan scripts, eventos (on*), estilos, formularios, iframes y enlaces javascript:.
 *
 * Cada salto de línea se muestra como salto real (como en los comentarios de GitHub), así el
 * texto se ve igual que en el editor y se conservan los <br> del contenido importado.
 */
class Renderizador
{
    private MarkdownConverter $convertidor;
    private HtmlSanitizer $sanitizador;

    public function __construct()
    {
        $entorno = new Environment([
            'html_input' => 'allow',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
            'renderer' => [
                'soft_break' => "<br>\n",
            ],
            'heading_permalink' => [
                'id_prefix' => 'titulo',
                'fragment_prefix' => 'titulo',
                'apply_id_to_heading' => true,
                'insert' => 'after',
                'symbol' => '#',
                'title' => 'Enlace a esta sección',
                'aria_hidden' => true,
            ],
        ]);
        $entorno->addExtension(new CommonMarkCoreExtension());
        $entorno->addExtension(new GithubFlavoredMarkdownExtension());
        $entorno->addExtension(new HeadingPermalinkExtension());

        $this->convertidor = new MarkdownConverter($entorno);
        $this->sanitizador = new HtmlSanitizer(self::configuracionSanitizador());
    }

    public function html(string $markdown): string
    {
        return $this->sanitizador->sanitize($this->convertidor->convert($markdown)->getContent());
    }

    /**
     * HTML más el índice de títulos de nivel 2 y 3, para el panel "En esta página".
     *
     * @return array{html: string, indice: list<array{nivel: int, id: string, texto: string}>}
     */
    public function documento(string $markdown): array
    {
        $html = $this->html($markdown);

        preg_match_all('#<h([23]) id="([^"]+)">(.*?)</h\1>#s', $html, $titulos, PREG_SET_ORDER);
        $indice = [];
        foreach ($titulos as [, $nivel, $id, $contenido]) {
            // Se quita el enlace "#" que agrega HeadingPermalink al final del título.
            $texto = trim(html_entity_decode(strip_tags(preg_replace('#<a [^>]*class="heading-permalink"[^>]*>.*?</a>#s', '', $contenido))));
            if ('' !== $texto) {
                $indice[] = ['nivel' => (int) $nivel, 'id' => html_entity_decode($id), 'texto' => $texto];
            }
        }

        return ['html' => $html, 'indice' => $indice];
    }

    /**
     * Lista blanca de lo que puede quedar en el HTML del documento.
     */
    private static function configuracionSanitizador(): HtmlSanitizerConfig
    {
        $config = (new HtmlSanitizerConfig())
            // Una etiqueta desconocida se quita pero conserva su texto (ej. <font> de teneso)...
            ->defaultAction(HtmlSanitizerAction::Block)
            ->allowLinkSchemes(['http', 'https', 'mailto'])
            ->allowRelativeLinks()
            ->allowMediaSchemes(['http', 'https'])
            ->allowRelativeMedias()
            // Por defecto el sanitizador corta en 20.000 caracteres; los manuales pueden ser más largos.
            ->withMaxInputLength(2_000_000)
            ->withAttributeSanitizer(new IdTituloSanitizer());

        // ...pero estas se eliminan con todo su contenido: ejecutan código, cargan otros sitios,
        // cambian la página entera o sirven para phishing.
        foreach (['script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet', 'noscript', 'template',
            'form', 'select', 'textarea', 'button', 'option', 'meta', 'base', 'link', 'title', 'head',
            'svg', 'math', 'audio', 'video', 'source', 'track', 'canvas', 'dialog'] as $elemento) {
            $config = $config->dropElement($elemento);
        }

        $elementos = [
            'p' => [], 'br' => [], 'hr' => [], 'div' => [], 'span' => [],
            'h1' => ['id'], 'h2' => ['id'], 'h3' => ['id'], 'h4' => ['id'], 'h5' => ['id'], 'h6' => ['id'],
            'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [], 'strike' => [], 'del' => [], 'ins' => [],
            'mark' => [], 'sub' => [], 'sup' => [], 'small' => [], 'kbd' => [], 'abbr' => ['title'],
            'code' => ['class'], 'pre' => [], 'blockquote' => [],
            'ul' => [], 'ol' => ['start'], 'li' => [], 'dl' => [], 'dt' => [], 'dd' => [],
            'table' => [], 'caption' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [],
            'th' => ['align', 'colspan', 'rowspan', 'scope'], 'td' => ['align', 'colspan', 'rowspan'],
            'details' => [], 'summary' => [],
            'img' => ['src', 'alt', 'title', 'width', 'height'],
            // El enlace "#" de los títulos usa class, aria-hidden y tabindex.
            'a' => ['href', 'title', 'class', 'aria-hidden', 'tabindex'],
            // Casillas de las listas de tareas de GFM; siempre deshabilitadas.
            'input' => ['type', 'checked', 'disabled'],
        ];
        foreach ($elementos as $elemento => $atributos) {
            $config = $config->allowElement($elemento, $atributos);
        }

        return $config
            ->forceAttribute('input', 'disabled', 'disabled')
            ->forceAttribute('input', 'type', 'checkbox');
    }
}
