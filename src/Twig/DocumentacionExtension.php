<?php

namespace App\Twig;

use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class DocumentacionExtension extends AbstractExtension
{
    private AsciiSlugger $slugger;

    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
        $this->slugger = new AsciiSlugger('es');
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('url_documento', $this->urlDocumento(...)),
            new TwigFunction('video_embebido', $this->videoEmbebido(...)),
        ];
    }

    public function getFilters(): array
    {
        return [
            new TwigFilter('fragmento_busqueda', $this->fragmentoBusqueda(...), ['is_safe' => ['html']]),
        ];
    }

    /**
     * URL legible del documento: /docs/{proyecto}/{id}/{titulo-en-minusculas}.
     */
    public function urlDocumento(string $proyectoSlug, int $id, string $titulo): string
    {
        return $this->urlGenerator->generate('visor_documento', [
            'proyecto' => $proyectoSlug,
            'id' => $id,
            'slug' => $this->slug($titulo),
        ]);
    }

    public function slug(string $titulo): string
    {
        return $this->slugger->slug($titulo)->lower()->toString();
    }

    /**
     * URL para incrustar un video de YouTube, o null si el enlace es de otro sitio.
     */
    public function videoEmbebido(?string $url): ?string
    {
        if ($url && preg_match('#(?:youtu\.be/|youtube\.com/(?:watch\?(?:.*&)?v=|embed/|shorts/))([\w-]{11})#', $url, $coincidencia)) {
            return 'https://www.youtube-nocookie.com/embed/'.$coincidencia[1];
        }

        return null;
    }

    /**
     * Escapa el fragmento de la búsqueda y solo deja pasar el resaltado <mark>.
     */
    public function fragmentoBusqueda(?string $fragmento): string
    {
        return str_replace(['&lt;mark&gt;', '&lt;/mark&gt;'], ['<mark>', '</mark>'], htmlspecialchars((string) $fragmento, ENT_QUOTES));
    }
}
