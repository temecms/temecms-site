<?php

/**
 * One package as the directory's index describes it, read from its detail
 * file (packages/<vendor>/<name>.json) and checked, with the values a
 * listing's fields take.
 *
 * @package TemecmsSite
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Temecms\Site\Directory;

use InvalidArgumentException;

/**
 * A package from the directory: its name, kind and the fields its listing
 * shows, with lists kept as JSON in long-text fields.
 *
 * @since 1.1.0
 */
final readonly class Listing
{
    /** A Composer package name, vendor/name. */
    public const string NAME = '~^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$~';

    /** The most characters a long-text field holds. */
    public const int MOST_TEXT = 5000;

    /** The most screenshots a listing shows. */
    public const int MOST_SCREENSHOTS = 8;

    /**
     * @param  string  $name  the Composer name, vendor/name: the key the sync finds the listing by
     * @param  string  $kind  plugin or theme, which is also the listing's content type
     * @param  list<string>  $categories  the directory's category keys, the main one first
     * @param  array<string, mixed>  $fields  the listing's fields by handle, images aside
     * @param  string|null  $icon  the icon's address, or null
     * @param  list<array{url: string, caption: string}>  $screenshots
     * @param  array<string, mixed>  $detail  the detail file as read, which the fingerprint is taken from
     */
    private function __construct(
        public string $name,
        public string $kind,
        public string $label,
        public string $readme,
        public array $categories,
        public array $fields,
        public ?string $icon,
        public array $screenshots,
        public array $detail,
    ) {}

    /**
     * Reads a package's detail file.
     *
     * @param  array<string, mixed>  $detail
     *
     * @throws InvalidArgumentException when its name, kind or label is missing or wrong
     *
     * @since 1.1.0
     */
    public static function fromDetail(array $detail): self
    {
        $name = $detail['name'] ?? null;
        $kind = $detail['kind'] ?? null;

        if (! is_string($name) || preg_match(self::NAME, $name) !== 1) {
            throw new InvalidArgumentException('Its name is not a Composer package name.');
        }

        if (! in_array($kind, ['plugin', 'theme'], true)) {
            throw new InvalidArgumentException('Its kind is neither plugin nor theme.');
        }

        $label = self::text($detail['label'] ?? null);

        if ($label === '') {
            throw new InvalidArgumentException('It has no label.');
        }

        $categories = array_values(array_filter(
            is_array($detail['categories'] ?? null) ? $detail['categories'] : [],
            fn (mixed $key): bool => is_string($key) && preg_match('~^[a-z0-9][a-z0-9-]{0,39}$~', $key) === 1,
        ));
        $author = is_array($detail['author'] ?? null) ? $detail['author'] : [];
        $links = is_array($detail['links'] ?? null) ? $detail['links'] : [];

        $fields = [
            'package' => $name,
            'summary' => mb_substr(self::text($detail['summary'] ?? null), 0, 255),
            'categories' => implode(', ', $categories),
            'picked' => ($detail['picked'] ?? false) === true,
            'author_name' => mb_substr(self::text($author['name'] ?? null), 0, 255),
            'author_github' => mb_substr(self::text($author['github'] ?? null), 0, 255),
            'latest_version' => mb_substr(self::text($detail['latest'] ?? null), 0, 255),
            'works_with' => implode(', ', self::strings($detail['works_with'] ?? null)),
            'updated_on' => self::date($detail['updated'] ?? null),
            'listed_on' => self::date($detail['listed'] ?? null),
            'link_source' => self::address($links['source'] ?? null),
            'link_issues' => self::address($links['issues'] ?? null),
            'link_docs' => self::address($links['docs'] ?? null),
            'link_support' => self::address($links['support'] ?? null),
            'stars' => self::count($detail['stars'] ?? null),
            'open_issues' => self::count($detail['issues'] ?? null),
            'releases' => self::releases($detail['releases'] ?? null),
            'capabilities' => self::capabilities($detail['capabilities'] ?? null),
            'changelog' => self::changelog(self::text($detail['changelog'] ?? null, trim: false)),
        ];

        $screenshots = [];

        foreach (is_array($detail['screenshots'] ?? null) ? $detail['screenshots'] : [] as $shot) {
            $url = is_array($shot) ? self::address($shot['url'] ?? null) : null;

            if ($url !== null && count($screenshots) < self::MOST_SCREENSHOTS) {
                $screenshots[] = ['url' => $url, 'caption' => mb_substr(self::text($shot['caption'] ?? null), 0, 255)];
            }
        }

        return new self(
            $name,
            $kind,
            mb_substr($label, 0, 255),
            self::text($detail['readme'] ?? null, trim: false),
            $categories,
            $fields,
            self::address($detail['icon'] ?? null),
            $screenshots,
            $detail,
        );
    }

    /**
     * The last part of the listing's address: the package's own name, without its vendor.
     *
     * @since 1.1.0
     */
    public function slug(): string
    {
        return substr($this->name, (int) strpos($this->name, '/') + 1);
    }

    /**
     * A fingerprint of everything the directory says of the package, which
     * changes when any of it does.
     *
     * @since 1.1.0
     */
    public function fingerprint(): string
    {
        return hash('sha256', json_encode(self::sorted($this->detail), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Releases newest first as JSON, as many as fit in a long-text field.
     */
    private static function releases(mixed $releases): string
    {
        $list = [];

        foreach (is_array($releases) ? $releases : [] as $release) {
            if (! is_array($release) || self::text($release['version'] ?? null) === '') {
                continue;
            }

            $list[] = [
                'version' => self::text($release['version']),
                'date' => self::date($release['date'] ?? null),
                'works_with' => self::strings($release['works_with'] ?? null),
                'held' => ($release['held'] ?? false) === true,
            ];
        }

        usort($list, fn (array $a, array $b): int => version_compare($b['version'], $a['version']));

        while ($list !== [] && mb_strlen(self::json($list)) > self::MOST_TEXT) {
            array_pop($list);
        }

        return self::json($list);
    }

    /**
     * The permissions the package asks for, as JSON.
     */
    private static function capabilities(mixed $capabilities): string
    {
        $list = [];

        foreach (is_array($capabilities) ? $capabilities : [] as $capability) {
            if (is_array($capability) && self::text($capability['key'] ?? null) !== '') {
                $list[] = ['key' => self::text($capability['key']), 'words' => self::text($capability['words'] ?? null)];
            }
        }

        while ($list !== [] && mb_strlen(self::json($list)) > self::MOST_TEXT) {
            array_pop($list);
        }

        return self::json($list);
    }

    /**
     * The changelog, cut before the first version heading that would pass
     * the long-text limit.
     */
    private static function changelog(string $markdown): string
    {
        if (mb_strlen($markdown) <= self::MOST_TEXT) {
            return $markdown;
        }

        $cut = mb_substr($markdown, 0, self::MOST_TEXT);
        $heading = mb_strrpos($cut, "\n## ");

        return rtrim($heading === false || $heading === 0 ? $cut : mb_substr($cut, 0, $heading))."\n";
    }

    /**
     * @param  list<array<string, mixed>>  $list
     */
    private static function json(array $list): string
    {
        return json_encode($list, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function text(mixed $value, bool $trim = true): string
    {
        if (! is_string($value)) {
            return '';
        }

        return $trim ? trim($value) : $value;
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        return array_values(array_filter(is_array($value) ? $value : [], fn (mixed $item): bool => is_string($item) && $item !== ''));
    }

    /** A date as YYYY-MM-DD, from a date or an ISO time; null for anything else. */
    private static function date(mixed $value): ?string
    {
        return is_string($value) && preg_match('~^(\d{4}-\d{2}-\d{2})~', $value, $match) === 1 ? $match[1] : null;
    }

    /** An https or http address, or null. */
    private static function address(mixed $value): ?string
    {
        return is_string($value) && preg_match('~^https?://[^\s]+$~i', $value) === 1 && strlen($value) <= 2000 ? $value : null;
    }

    private static function count(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }

    /**
     * The value with every object's keys in order, so the same data always gives the same JSON.
     */
    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sorted(...), $value);
    }
}
