<?php

/**
 * Accepts a post from one of the site's own forms: a form-encoded body, and
 * when the browser names the page's origin, this same site. The forms
 * carry no secret, since anyone may submit; the honeypot and the route's
 * rate limit keep them usable.
 *
 * @package TemecmsSite
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Temecms\Site\Submit;

use Teme\Contracts\Routes\InboundVerifier;
use Teme\Contracts\Routes\PublicRequest;

/**
 * The check every Submit post passes before its handler runs.
 *
 * @since 1.1.0
 */
final class FormPost implements InboundVerifier
{
    /**
     * Whether the post is a form sent from a page of this site.
     *
     * @since 1.1.0
     */
    public function verify(PublicRequest $request, string $secret): bool
    {
        $type = strtolower(trim(explode(';', $request->headers['content-type'] ?? '')[0]));

        if ($type !== 'application/x-www-form-urlencoded') {
            return false;
        }

        $origin = $request->headers['origin'] ?? '';

        if ($origin === '') {
            return true;
        }

        $host = strtolower((string) preg_replace('~:\d+$~', '', $request->headers['host'] ?? ''));

        return $host !== '' && strtolower((string) parse_url($origin, PHP_URL_HOST)) === $host;
    }
}
