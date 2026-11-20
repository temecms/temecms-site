<?php

/**
 * The temecms-site plugin's own class. The site's content types, fields and
 * taxonomies come from types/teme-types.json, and the Submit forms' routes,
 * the sync's job and schedule and the token setting from teme-plugin.json;
 * this class hands Teme the sync job's handler.
 *
 * @package TemecmsSite
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Temecms\Site;

use Teme\Contracts\Content\ContentReader;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Content\HtmlToDocument;
use Teme\Contracts\Directory\VerifiedDirectory;
use Teme\Contracts\Hooks\Hooks;
use Teme\Contracts\Jobs\PluginHasJobs;
use Teme\Contracts\Media\MediaWriter;
use Teme\Contracts\Plugins\Plugin;
use Temecms\Site\Directory\DirectorySync;
use Temecms\Site\Directory\DirectorySyncJob;
use Temecms\Site\Directory\ReadmeToDocument;

/**
 * What temecms.com does on its own, beside its theme. Teme builds it once the
 * plugin is enabled; it listens to no hook.
 *
 * @since 1.0.0
 */
final readonly class TemecmsSite implements Plugin, PluginHasJobs
{
    /** @since 1.1.0 */
    public function __construct(
        private VerifiedDirectory $directory,
        private ContentReader $reader,
        private ContentWriter $writer,
        private MediaWriter $media,
        private HtmlToDocument $documents,
    ) {}

    /**
     * Subscribes to nothing: the plugin's types are declared in its manifest.
     *
     * @since 1.0.0
     */
    public function register(Hooks $hooks): void {}

    /**
     * The directory sync's handler, by its job key.
     *
     * @return array<string, DirectorySyncJob>
     *
     * @since 1.1.0
     */
    public function jobs(): array
    {
        return [
            DirectorySyncJob::KEY => new DirectorySyncJob(
                $this->directory,
                new DirectorySync($this->reader, $this->writer, $this->media, new ReadmeToDocument($this->documents)),
            ),
        ];
    }
}
