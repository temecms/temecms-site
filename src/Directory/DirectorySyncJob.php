<?php

/**
 * The scheduled directory sync: reads the directory's index as Teme last
 * verified it, each package's detail file through the same door, and hands
 * them to DirectorySync. An index Teme can't vouch for changes nothing.
 *
 * @package TemecmsSite
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Temecms\Site\Directory;

use RuntimeException;
use Teme\Contracts\Directory\VerifiedDirectory;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Jobs\JobHandler;

/**
 * The handler of the plugin's `directory-sync` job.
 *
 * @since 1.1.0
 */
final readonly class DirectorySyncJob implements JobHandler
{
    /** The job's key, as teme-plugin.json declares it. */
    public const string KEY = 'directory-sync';

    public function __construct(
        private VerifiedDirectory $directory,
        private DirectorySync $sync,
    ) {}

    /**
     * Syncs every package the verified index lists, reporting what it did as the run's message.
     *
     * @throws RuntimeException when there is no verified index, so the run fails and nothing changes
     *
     * @since 1.1.0
     */
    public function handle(JobContext $context): void
    {
        $index = $this->directory->index();

        if ($index->data === null) {
            throw new RuntimeException('The directory index can’t be used: '.($index->reason ?? 'no reason given').'.');
        }

        $names = [];

        foreach (is_array($index->data['packages'] ?? null) ? $index->data['packages'] : [] as $entry) {
            if (is_array($entry) && is_string($entry['name'] ?? null)) {
                $names[$entry['name']] = true;
            }
        }

        $details = [];
        $unread = [];
        $done = 0;

        foreach (array_keys($names) as $name) {
            $answer = $this->directory->package($name);

            if ($answer->data === null) {
                $unread[] = $name;
            } else {
                $details[] = $answer->data;
            }

            $context->progress(++$done, count($names) + 1, 'Reading the directory');
        }

        $report = $this->sync->run($details, $context->personId(), $unread);

        $context->progress(count($names) + 1, count($names) + 1, $report->summary().($unread === [] ? '' : ' '.count($unread).' not read this time.'));
    }
}
