<?php

/**
 * What one directory sync did: the listings it made and changed, those it
 * left as they were, the packages it skipped, and the listings whose
 * package has left the index.
 *
 * @package TemecmsSite
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Temecms\Site\Directory;

/**
 * The outcome of a DirectorySync run, by package name.
 *
 * @since 1.1.0
 */
final class SyncReport
{
    /** @var list<string> */
    public array $created = [];

    /** @var list<string> */
    public array $updated = [];

    /** @var list<string> */
    public array $unchanged = [];

    /** @var array<string, string> why each was skipped, by package name or by its place in the index */
    public array $skipped = [];

    /** @var list<string> published listings whose package is no longer in the index */
    public array $left = [];

    /**
     * One line saying what the run did.
     *
     * @since 1.1.0
     */
    public function summary(): string
    {
        return sprintf(
            '%d added, %d updated, %d unchanged, %d skipped, %d no longer in the directory.',
            count($this->created),
            count($this->updated),
            count($this->unchanged),
            count($this->skipped),
            count($this->left),
        );
    }
}
