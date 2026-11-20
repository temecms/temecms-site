<?php

/**
 * The Showcase form. It checks what was sent and stores the site as an
 * unapproved Showcase site waiting for review; the screenshot's address,
 * the theme and the plugins named go in its body for the person who
 * approves it, and the contact address is kept for them alone.
 *
 * @package TemecmsSite
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Temecms\Site\Submit;

use Teme\Contracts\Content\HtmlToDocument;
use Teme\Contracts\Content\Submission;
use Teme\Contracts\Content\Submissions;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Routes\PublicRequest;
use Teme\Contracts\Routes\PublicResponse;
use Teme\Contracts\Routes\PublicRoute;

/**
 * Answers a post of the Showcase form.
 *
 * @since 1.1.0
 */
final readonly class SubmitSite implements PublicRoute
{
    /** The kinds of site the Showcase files sites under. */
    public const array KINDS = ['news', 'blog', 'documentation', 'magazine', 'other'];

    public function __construct(
        private Submissions $submissions,
        private HtmlToDocument $documents,
    ) {}

    /**
     * Stores the site and sends the visitor back to the form's page, saying what happened.
     *
     * @since 1.1.0
     */
    public function handle(PublicRequest $request): PublicResponse
    {
        parse_str($request->rawBody, $form);
        $field = fn (string $name, int $longest = 255): string => is_string($form[$name] ?? null) ? mb_substr(trim($form[$name]), 0, $longest) : '';

        if ($field(SubmitPackage::HONEYPOT) !== '') {
            return Answer::back($request, ['submitted' => 'showcase']);
        }

        $address = $field('address', 2000);
        $kind = $field('kind');
        $screenshot = $field('screenshot', 2000);
        $contact = $field('contact', 254);

        $wrong = match (true) {
            ! self::isAddress($address) => 'address',
            ! in_array($kind, self::KINDS, true) => 'kind',
            $screenshot !== '' && ! self::isAddress($screenshot) => 'screenshot',
            filter_var($contact, FILTER_VALIDATE_EMAIL) === false => 'contact',
            default => null,
        };

        if ($wrong !== null) {
            return Answer::back($request, ['error' => $wrong]);
        }

        $name = $field('name') !== '' ? $field('name') : (string) parse_url($address, PHP_URL_HOST);

        try {
            $this->submissions->submit(new Submission(
                type: 'showcase',
                title: $name,
                body: $this->documents->convert($this->notes($screenshot, $field('theme'), $field('plugins', 1000))),
                fields: [
                    'site_address' => $address,
                    'site_kind' => $kind,
                    'owner_line' => $field('line'),
                    'approved' => false,
                ],
                from: $contact,
            ));
        } catch (WriteRefused) {
            return Answer::back($request, ['error' => 'unavailable']);
        }

        return Answer::back($request, ['submitted' => 'showcase']);
    }

    /**
     * What the person approving the site needs and the fields can't hold: the
     * screenshot's address, and the theme and plugins as the visitor named them.
     */
    private function notes(string $screenshot, string $theme, string $plugins): string
    {
        $lines = [];

        if ($screenshot !== '') {
            $url = htmlspecialchars($screenshot, ENT_QUOTES);
            $lines[] = "<p>Screenshot: <a href=\"{$url}\">{$url}</a></p>";
        }

        if ($theme !== '') {
            $lines[] = '<p>Theme: '.htmlspecialchars($theme, ENT_QUOTES).'</p>';
        }

        if ($plugins !== '') {
            $lines[] = '<p>Plugins: '.htmlspecialchars($plugins, ENT_QUOTES).'</p>';
        }

        return $lines === [] ? '<p></p>' : implode('', $lines);
    }

    /** Whether the value is an https or http address. */
    private static function isAddress(string $value): bool
    {
        return preg_match('~^https?://[^\s/$.?#][^\s]*$~i', $value) === 1 && filter_var($value, FILTER_VALIDATE_URL) !== false;
    }
}
