<?php

/**
 * Where a Submit post sends the visitor next: back to the page it came from,
 * with what happened in the query, so the page can say it.
 *
 * @package TemecmsSite
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Temecms\Site\Submit;

use Teme\Contracts\Routes\PublicRequest;
use Teme\Contracts\Routes\PublicResponse;

/**
 * Builds the 303 answers the Submit forms give.
 *
 * @since 1.1.0
 */
final class Answer
{
    /** Where a post with no usable Referer goes back to. */
    public const string FALLBACK = '/submit/';

    /**
     * A 303 to the page the post came from, on this site only, with these query values.
     *
     * @param  array<string, string|int>  $query
     *
     * @since 1.1.0
     */
    public static function back(PublicRequest $request, array $query): PublicResponse
    {
        return new PublicResponse(303, 'text/plain; charset=utf-8', '', [
            'location' => self::page($request).'?'.http_build_query($query),
        ]);
    }

    /**
     * The path of the page the post came from when the Referer names this
     * site, so the answer never sends anyone elsewhere.
     */
    private static function page(PublicRequest $request): string
    {
        $referer = $request->headers['referer'] ?? '';
        $host = strtolower((string) preg_replace('~:\d+$~', '', $request->headers['host'] ?? ''));
        $parts = parse_url($referer);

        if (! is_array($parts) || strtolower($parts['host'] ?? '') !== $host || $host === '') {
            return self::FALLBACK;
        }

        $path = $parts['path'] ?? '/';

        return preg_match('#^/(?!/)[A-Za-z0-9/_.~-]*$#', $path) === 1 ? $path : self::FALLBACK;
    }
}
