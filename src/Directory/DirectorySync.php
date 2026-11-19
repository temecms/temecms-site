<?php

/**
 * Fills the Plugin directory and Theme directory from the directory's
 * index: one listing per package, found again by its package name, made or
 * changed only through Teme's content and media doors, and left alone when
 * nothing about its package has changed.
 *
 * @package TemecmsSite
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Temecms\Site\Directory;

use InvalidArgumentException;
use JsonException;
use Teme\Contracts\Content\ContentReader;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Content\ItemChanges;
use Teme\Contracts\Content\ItemQuery;
use Teme\Contracts\Content\ItemRef;
use Teme\Contracts\Content\NewItem;
use Teme\Contracts\Content\QueueQuery;
use Teme\Contracts\Content\ReadRefused;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Media\MediaWriter;

/**
 * Makes the site's listings match the packages it is given. Running it
 * twice with the same packages changes nothing the second time.
 *
 * @since 1.1.0
 */
final readonly class DirectorySync
{
    /** The taxonomy the directory's categories are filed under. */
    public const string CATEGORIES = 'directory_category';

    public function __construct(
        private ContentReader $reader,
        private ContentWriter $writer,
        private MediaWriter $media,
        private ReadmeToDocument $readme,
    ) {}

    /**
     * Makes or changes a published listing for each package, keyed by its
     * name, and reports the published listings whose package is missing.
     *
     * @param  iterable<array<string, mixed>>  $packages  the packages' detail files, as the index lists them
     *
     * @since 1.1.0
     */
    public function run(iterable $packages, string $personId): SyncReport
    {
        $report = new SyncReport;
        $existing = $this->existing($personId);
        $seen = [];
        $place = 0;

        foreach ($packages as $detail) {
            $place++;

            try {
                $listing = Listing::fromDetail($detail);
            } catch (InvalidArgumentException $wrong) {
                $report->skipped['#'.$place] = $wrong->getMessage();

                continue;
            }

            if (isset($seen[$listing->name])) {
                $report->skipped[$listing->name] = 'The index lists it twice.';

                continue;
            }

            $seen[$listing->name] = true;

            try {
                $this->one($listing, $existing[$listing->name] ?? null, $personId, $report);
            } catch (WriteRefused|ReadRefused $refused) {
                $report->skipped[$listing->name] = $refused->getMessage();
            }
        }

        foreach ($existing as $name => $found) {
            if (! isset($seen[$name]) && $found['ref']->status === 'published') {
                $report->left[] = $name;
            }
        }

        return $report;
    }

    /**
     * @param  array{ref: ItemRef, record: array{fingerprint: string, images: array<string, string>}}|null  $found
     */
    private function one(Listing $listing, ?array $found, string $personId, SyncReport $report): void
    {
        $fingerprint = $listing->fingerprint();

        if ($found !== null && $found['ref']->type !== $listing->kind) {
            $report->skipped[$listing->name] = "It is listed as a {$found['ref']->type}, and the index now calls it a {$listing->kind}.";

            return;
        }

        if ($found !== null && $found['record']['fingerprint'] === $fingerprint && $found['ref']->status === 'published') {
            $report->unchanged[] = $listing->name;

            return;
        }

        $images = $found['record']['images'] ?? [];
        $kept = [];
        $fields = $listing->fields;

        $icon = $listing->icon === null ? null : $this->image($listing->icon, $images, $kept, $personId, null);
        $fields['icon_image'] = $icon === null ? null : ['id' => $icon, 'alt' => null, 'decorative' => true];

        $shots = [];

        foreach ($listing->screenshots as $shot) {
            $id = $this->image($shot['url'], $images, $kept, $personId, $shot['caption'] === '' ? null : $shot['caption']);

            if ($id !== null) {
                $shots[] = ['media' => $id, 'caption' => $shot['caption']];
            }
        }

        $fields['screenshots'] = json_encode($shots, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $fields['directory_sync'] = json_encode(['fingerprint' => $fingerprint, 'images' => $kept], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $body = $this->readme->convert($listing->readme);
        $terms = $listing->categories === [] ? [] : [self::CATEGORIES => $listing->categories];

        if ($found === null) {
            $item = $this->writer->create(new NewItem(
                type: $listing->kind,
                title: $listing->label,
                body: $body,
                fields: $fields,
                terms: $terms,
                slug: $listing->slug(),
            ), $personId);
            $this->writer->publish($item, $personId);
            $report->created[] = $listing->name;

            return;
        }

        $this->writer->change($found['ref'], new ItemChanges(
            title: $listing->label,
            body: $body,
            fields: $fields,
            terms: $listing->categories === [] ? [self::CATEGORIES => []] : $terms,
        ), $personId);
        $this->writer->publish($found['ref'], $personId);
        $report->updated[] = $listing->name;
    }

    /**
     * The media ID for an image's address: the copy made before when the
     * address is the same, else a new copy. Null when it can't be copied,
     * so a picture that can't be fetched is dropped, never left broken.
     *
     * @param  array<string, string>  $before  media IDs by address, from the last run
     * @param  array<string, string>  $kept  media IDs by address, for this run's record
     */
    private function image(string $url, array $before, array &$kept, string $personId, ?string $alt): ?string
    {
        if (isset($before[$url])) {
            return $kept[$url] = $before[$url];
        }

        try {
            return $kept[$url] = $this->media->fromAddress($url, $personId, $alt)->id;
        } catch (WriteRefused) {
            return null;
        }
    }

    /**
     * The listings the sync made before, by package name: every plugin and
     * theme listing, published or not, that carries a sync record.
     *
     * @return array<string, array{ref: ItemRef, record: array{fingerprint: string, images: array<string, string>}}>
     */
    private function existing(string $personId): array
    {
        $refs = [];
        $after = null;

        do {
            $page = $this->reader->published(new ItemQuery(types: ['plugin', 'theme'], after: $after, limit: 200));

            foreach ($page->items as $ref) {
                $refs[$ref->id] = $ref;
            }

            $after = $page->next;
        } while ($after !== null);

        $after = null;

        do {
            $queue = $this->reader->queue(new QueueQuery(types: ['plugin', 'theme'], after: $after, limit: 200), $personId);

            foreach ($queue->items as $queued) {
                $refs[$queued->item->id] ??= $queued->item;
            }

            $after = $queue->next;
        } while ($after !== null);

        $found = [];

        foreach ($refs as $ref) {
            $fields = $this->reader->draft($ref->id, $personId)->fields;
            $package = $fields['package'] ?? null;
            $record = self::record($fields['directory_sync'] ?? null);

            if (is_string($package) && $package !== '' && $record !== null) {
                $found[$package] = ['ref' => $ref, 'record' => $record];
            }
        }

        return $found;
    }

    /**
     * The sync's record on a listing, or null when it has none.
     *
     * @return array{fingerprint: string, images: array<string, string>}|null
     */
    private static function record(mixed $value): ?array
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $record = json_decode($value, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (! is_array($record) || ! is_string($record['fingerprint'] ?? null)) {
            return null;
        }

        $images = array_filter(is_array($record['images'] ?? null) ? $record['images'] : [], fn (mixed $id, mixed $url): bool => is_string($id) && is_string($url), ARRAY_FILTER_USE_BOTH);

        return ['fingerprint' => $record['fingerprint'], 'images' => $images];
    }
}
