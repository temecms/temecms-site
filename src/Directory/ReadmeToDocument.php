<?php

/**
 * Turns a listing's README, Markdown as the directory cleaned it, into the
 * document stored as the listing's body: safe HTML first, then Teme's own
 * converter, so content is stored as an editor document, never as HTML.
 *
 * @package TemecmsSite
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Temecms\Site\Directory;

use League\CommonMark\GithubFlavoredMarkdownConverter;
use Teme\Contracts\Content\HtmlToDocument;

/**
 * Converts a README from Markdown to a Tiptap document.
 *
 * @since 1.1.0
 */
final readonly class ReadmeToDocument
{
    /** The most Markdown read from one README, in bytes; the directory keeps no more. */
    public const int MOST_BYTES = 102_400;

    public function __construct(private HtmlToDocument $documents) {}

    /**
     * The README as a Tiptap document (`type: doc`).
     *
     * @return array<string, mixed>
     *
     * @since 1.1.0
     */
    public function convert(string $markdown): array
    {
        return $this->documents->convert(self::html($markdown));
    }

    /**
     * The README as HTML, in safe mode: raw HTML in the Markdown is dropped,
     * and links and images with a scheme other than https, http or mailto
     * lose their address, and an image whose address isn't https or http
     * is dropped. GitHub's tables, task lists and strikethrough are kept.
     *
     * @since 1.1.0
     */
    public static function html(string $markdown): string
    {
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);

        $html = (string) $converter->convert(mb_strcut($markdown, 0, self::MOST_BYTES, 'UTF-8'));

        return (string) preg_replace('~<img\b(?![^>]*\bsrc="https?://)[^>]*>~i', '', $html);
    }
}
