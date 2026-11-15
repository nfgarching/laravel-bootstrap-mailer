<?php

namespace Nfgarching\Mailer\Support;

use Illuminate\Support\Str;
use TijsVerkoyen\CssToInlineStyles\CssToInlineStyles;

/**
 * Mailtext (Markdown) → HTML für den HTML-Teil der Mail.
 *
 * - Einfache Zeilenumbrüche bleiben Umbrüche (soft_break): Vorbelegungen aus
 *   Textbausteinen sind reiner Text mit Anrede, Adresse, Grußformel.
 * - Eingegebenes HTML wird maskiert, nicht interpretiert (kein Purifier nötig).
 * - Die Styles werden inline gesetzt, weil Outlook & Co. <style> ignorieren.
 * Der Text-Teil der Mail bleibt das Markdown selbst (lesbarer Klartext).
 */
class MailMarkdown
{
    protected const CSS = <<<'CSS'
        p, ul, ol, blockquote, pre, table { margin: 0 0 12px 0; }
        h1, h2, h3, h4 { margin: 18px 0 8px 0; line-height: 1.3; }
        h1 { font-size: 20px; } h2 { font-size: 18px; } h3, h4 { font-size: 15px; }
        ul, ol { padding-left: 24px; }
        li { margin: 2px 0; }
        a { color: #0a58ca; }
        blockquote { padding: 0 0 0 12px; border-left: 3px solid #d0d7de; color: #57606a; }
        code { font-family: Consolas, Menlo, monospace; font-size: 13px; background: #f6f8fa; }
        pre { padding: 8px 12px; background: #f6f8fa; }
        table { border-collapse: collapse; }
        th, td { border: 1px solid #d0d7de; padding: 4px 8px; text-align: left; }
        hr { border: 0; border-top: 1px solid #d0d7de; margin: 16px 0; }
        CSS;

    public static function html(string $markdown): string
    {
        if (trim($markdown) === '') {
            return '';
        }

        $html = Str::markdown($markdown, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'renderer' => ['soft_break' => "<br>\n"],
        ]);

        $document = (new CssToInlineStyles)->convert('<div>'.$html.'</div>', self::CSS);

        return preg_match('#<body[^>]*>(.*)</body>#si', $document, $m) ? trim($m[1]) : $html;
    }
}
