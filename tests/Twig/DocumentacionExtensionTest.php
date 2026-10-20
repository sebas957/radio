<?php

namespace App\Tests\Twig;

use App\Twig\DocumentacionExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class DocumentacionExtensionTest extends TestCase
{
    private DocumentacionExtension $extension;

    protected function setUp(): void
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            fn (string $ruta, array $p) => sprintf('/docs/%s/%d/%s', $p['proyecto'], $p['id'], $p['slug'])
        );
        $this->extension = new DocumentacionExtension($urlGenerator);
    }

    public function testElFragmentoDeBusquedaSoloDejaPasarElResaltado(): void
    {
        $fragmento = 'Crear <mark>guías</mark> <img src=x onerror=alert(1)> "comillas" & <script>alert(2)</script>';

        self::assertSame(
            'Crear <mark>guías</mark> &lt;img src=x onerror=alert(1)&gt; &quot;comillas&quot; &amp; &lt;script&gt;alert(2)&lt;/script&gt;',
            $this->extension->fragmentoBusqueda($fragmento),
        );
    }

    /**
     * @return iterable<string, array{?string, ?string}>
     */
    public static function videos(): iterable
    {
        yield 'youtu.be' => ['https://youtu.be/GZTGQT2XC90', 'https://www.youtube-nocookie.com/embed/GZTGQT2XC90'];
        yield 'watch' => ['https://www.youtube.com/watch?v=GZTGQT2XC90&t=10', 'https://www.youtube-nocookie.com/embed/GZTGQT2XC90'];
        yield 'shorts' => ['https://youtube.com/shorts/GZTGQT2XC90', 'https://www.youtube-nocookie.com/embed/GZTGQT2XC90'];
        yield 'otro sitio' => ['https://vimeo.com/123', null];
        yield 'intento de inyección' => ['https://youtu.be/"><script>', null];
        yield 'vacío' => [null, null];
    }

    #[DataProvider('videos')]
    public function testSoloIncrustaVideosDeYoutube(?string $url, ?string $esperado): void
    {
        self::assertSame($esperado, $this->extension->videoEmbebido($url));
    }

    public function testLaUrlDelDocumentoUsaElTituloEnMinusculasSinTildes(): void
    {
        self::assertSame('/docs/semantica/191/guia-de-recogida', $this->extension->urlDocumento('semantica', 191, 'Guía de Recogida'));
    }
}
