<?php

namespace App\Markdown;

use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\HtmlSanitizer\Visitor\AttributeSanitizer\AttributeSanitizerInterface;

/**
 * Solo deja los id que genera el renderizador en los títulos ("titulo-..."), para que el
 * contenido no pueda reutilizar ids de la página (ej. "menu-movil") y alterar su comportamiento.
 */
class IdTituloSanitizer implements AttributeSanitizerInterface
{
    public function getSupportedElements(): ?array
    {
        return ['h1', 'h2', 'h3', 'h4', 'h5', 'h6'];
    }

    public function getSupportedAttributes(): ?array
    {
        return ['id'];
    }

    public function sanitizeAttribute(string $element, string $attribute, string $value, HtmlSanitizerConfig $config): ?string
    {
        return preg_match('/^titulo-[\p{L}\p{N}_-]+$/u', $value) ? $value : null;
    }
}
