<?php

namespace App\Tests\Markdown;

use App\Markdown\Renderizador;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RenderizadorTest extends TestCase
{
    private Renderizador $renderizador;

    protected function setUp(): void
    {
        $this->renderizador = new Renderizador();
    }

    /**
     * Vectores de XSS y phishing que un editor (o un .md importado) podría meter en el contenido.
     *
     * @return iterable<string, array{string}>
     */
    public static function vectoresMaliciosos(): iterable
    {
        yield 'img onerror' => ['<img src=x onerror="alert(1)">'];
        yield 'svg onload' => ['<svg onload="alert(1)"><circle r="5"/></svg>'];
        yield 'details ontoggle' => ['<details open ontoggle="alert(1)">x</details>'];
        yield 'a href javascript en HTML' => ['<a href="javascript:alert(1)">clic</a>'];
        yield 'a href javascript en Markdown' => ['[clic](javascript:alert(1))'];
        yield 'a href javascript con mayúsculas y espacios' => ['<a href=" JaVaScRiPt:alert(1)">clic</a>'];
        yield 'imagen data: en Markdown' => ['![x](data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==)'];
        yield 'script en línea' => ['texto <script>alert(1)</script>'];
        yield 'script en bloque' => ["<script>\nalert(1)\n</script>"];
        yield 'iframe' => ['<iframe src="https://evil.example"></iframe>'];
        yield 'style' => ['<style>body{display:none}</style>'];
        yield 'formulario de phishing' => ['<form action="https://evil.example"><input name="clave" type="password"><button>Entrar</button></form>'];
        yield 'meta refresh' => ['<meta http-equiv="refresh" content="0;url=https://evil.example">'];
        yield 'base href' => ['<base href="https://evil.example/">'];
        yield 'object y embed' => ['<object data="https://evil.example/x.swf"></object><embed src="https://evil.example/x">'];
        yield 'capa que tapa la página' => ['<div style="position:fixed;inset:0;background:#fff">Sitio en mantenimiento</div>'];
        yield 'evento en etiqueta desconocida' => ['<font face="Arial" onmouseover="alert(1)">texto</font>'];
        yield 'evento en enlace permitido' => ['<a href="https://semantica.com.co" onclick="alert(1)">ir</a>'];
        yield 'id que choca con la página' => ['<h2 id="menu-movil">Título</h2>'];
    }

    #[DataProvider('vectoresMaliciosos')]
    public function testEliminaContenidoPeligroso(string $markdown): void
    {
        $html = $this->renderizador->html($markdown);

        self::assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $html, 'Quedó un atributo de evento');
        self::assertDoesNotMatchRegularExpression('/<(script|style|iframe|form|input[^>]*password|button|meta|base|object|embed|svg)\b/i', $html, 'Quedó una etiqueta peligrosa');
        self::assertDoesNotMatchRegularExpression('/(href|src)\s*=\s*"\s*(javascript|data|vbscript):/i', $html, 'Quedó una URL peligrosa');
        self::assertDoesNotMatchRegularExpression('/\sstyle\s*=/i', $html, 'Quedó un atributo style');
        self::assertDoesNotMatchRegularExpression('/\sid="(?!titulo-)/', $html, 'Quedó un id ajeno a los títulos');
    }

    public function testConservaElMarkdownLegitimo(): void
    {
        $markdown = <<<'MD'
            ## Crear la guía

            Texto con **negrita**, *cursiva*, ~~tachado~~ y `código`.

            - Uno
            - Dos

            1. Primero

            - [x] Hecho
            - [ ] Pendiente

            > Nota importante

            | Campo | Descripción |
            |---|---|
            | Tercero | Cliente |

            ```
            Transporte > Movimiento
            ```

            [Semantica](https://semantica.com.co) · [relativo](/docs/semantica) · [correo](mailto:soporte@semantica.com.co)

            ![Pantalla](https://semantica.com.co/captura.png)
            MD;

        $html = $this->renderizador->html($markdown);

        self::assertStringContainsString('<h2 id="titulo-crear-la-guía">Crear la guía<a href="#titulo-crear-la-guía" class="heading-permalink"', $html);
        self::assertStringContainsString('<strong>negrita</strong>', $html);
        self::assertStringContainsString('<em>cursiva</em>', $html);
        self::assertStringContainsString('<del>tachado</del>', $html);
        self::assertStringContainsString('<code>código</code>', $html);
        self::assertStringContainsString('<li>Uno</li>', $html);
        self::assertStringContainsString('<ol>', $html);
        self::assertMatchesRegularExpression('/<input checked disabled="disabled" type="checkbox" \/> Hecho/', $html);
        self::assertStringContainsString('<blockquote>', $html);
        self::assertStringContainsString('<td>Tercero</td>', $html);
        self::assertStringContainsString('<pre><code>Transporte &gt; Movimiento', $html);
        self::assertStringContainsString('<a href="https://semantica.com.co">Semantica</a>', $html);
        self::assertStringContainsString('<a href="/docs/semantica">relativo</a>', $html);
        // El sanitizador codifica la @ en los atributos; el navegador la lee igual.
        self::assertStringContainsString('<a href="mailto:soporte&#64;semantica.com.co">correo</a>', $html);
        self::assertStringContainsString('<img src="https://semantica.com.co/captura.png" alt="Pantalla" />', $html);
    }

    public function testConservaElTextoDelHtmlDeTeneso(): void
    {
        // Así guardaba teneso los .md: HTML de su editor visual con <font> y estilos en línea.
        $html = $this->renderizador->html(
            '<p class="MsoNormal"><font face="Arial, sans-serif"><b>Tercero:</b>&nbsp;Seleccionar el tercero.</font><br>'
            .'<span style="font-family: Arial, sans-serif;"><b>Ruta:</b> Seleccionar la ruta.</span></p>'
        );

        self::assertStringContainsString('<b>Tercero:</b>', $html);
        self::assertStringContainsString('Seleccionar el tercero.', $html);
        self::assertStringContainsString('<b>Ruta:</b>', $html);
        self::assertStringContainsString('Seleccionar la ruta.', $html);
        self::assertStringNotContainsString('<font', $html);
        self::assertStringNotContainsString('style=', $html);
        self::assertStringNotContainsString('class="MsoNormal"', $html);
    }

    public function testLasCasillasDeTareasNoSePuedenUsarComoCampos(): void
    {
        $html = $this->renderizador->html('<input type="text" name="clave" value="x">');

        self::assertStringContainsString('type="checkbox"', $html);
        self::assertStringContainsString('disabled="disabled"', $html);
        self::assertStringNotContainsString('type="text"', $html);
        self::assertStringNotContainsString('name=', $html);
    }

    public function testNoCortaDocumentosLargos(): void
    {
        // El sanitizador corta en 20.000 caracteres si no se configura otro límite.
        $markdown = str_repeat("Párrafo de un manual extenso con bastante texto.\n\n", 1_000).'FIN-DEL-DOCUMENTO';

        self::assertGreaterThan(20_000, mb_strlen($markdown));
        self::assertStringContainsString('FIN-DEL-DOCUMENTO', $this->renderizador->html($markdown));
    }

    public function testElIndiceSigueFuncionandoDespuesDeSanitizar(): void
    {
        $documento = $this->renderizador->documento("# Título\n\n## Crear guía\n\ntexto\n\n### Paso *uno*\n\n## Anular");

        self::assertSame([
            ['nivel' => 2, 'id' => 'titulo-crear-guía', 'texto' => 'Crear guía'],
            ['nivel' => 3, 'id' => 'titulo-paso-uno', 'texto' => 'Paso uno'],
            ['nivel' => 2, 'id' => 'titulo-anular', 'texto' => 'Anular'],
        ], $documento['indice']);
    }

    public function testCadaSaltoDeLineaEsUnSaltoReal(): void
    {
        self::assertStringContainsString("Línea uno<br />\nLínea dos", $this->renderizador->html("Línea uno\nLínea dos"));
    }
}
