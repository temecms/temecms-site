<?php

/**
 * The scheduled directory sync: it reads only the index Teme verified,
 * fails and changes nothing without one, and keeps the listing of a package
 * whose detail file couldn't be read this time.
 *
 * @package TemecmsSite
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Plugins\Seams\PluginServices;
use Illuminate\Support\Facades\Http;
use Teme\Contracts\Content\ContentReader;
use Teme\Contracts\Content\ContentWriter;
use Teme\Contracts\Content\ItemQuery;
use Teme\Contracts\Directory\DirectoryAnswer;
use Teme\Contracts\Directory\VerifiedDirectory;
use Teme\Contracts\Jobs\JobContext;
use Teme\Contracts\Media\MediaWriter;
use Teme\Testing\Acting;
use Teme\Testing\JobRun;
use Teme\Testing\TemeTestCase;
use Temecms\Site\Directory\DirectorySync;
use Temecms\Site\Directory\DirectorySyncJob;
use Temecms\Site\Directory\ReadmeToDocument;

require_once __DIR__.'/Support/helpers.php';

uses(TemeTestCase::class);

beforeEach(function (): void {
    if (! interface_exists(VerifiedDirectory::class)) {
        $this->markTestSkipped('needs Teme’s VerifiedDirectory door (the directory source)');
    }

    $this->admin = $this->person('administrator');
    temecmsSiteTypes($this, $this->admin);
    Http::fake(fn () => Http::response(temecmsSitePng(), 200, ['Content-Type' => 'image/png']));

    $doors = app(PluginServices::class)->for('temecms/temecms-site', 'temecms-site', ['content.read', 'content.write', 'media.write'], ['temecms.github.io']);
    $this->reader = $doors[ContentReader::class];
    $this->sync = new DirectorySync($doors[ContentReader::class], $doors[ContentWriter::class], $doors[MediaWriter::class], new ReadmeToDocument(temecmsSiteHtmlToDocument()));

    $this->context = new class((string) $this->admin->id) implements JobContext
    {
        public string $message = '';

        public function __construct(private string $person) {}

        public function payload(): array
        {
            return [];
        }

        public function attempt(): int
        {
            return 1;
        }

        public function progress(int $done, int $total, string $message = ''): void
        {
            $this->message = $message;
        }

        public function personId(): string
        {
            return $this->person;
        }
    };

    $this->directory = fn (?array $index, array $details): VerifiedDirectory => new class($index, $details) implements VerifiedDirectory
    {
        /** @param array<string, array<string, mixed>> $details */
        public function __construct(private ?array $index, private array $details) {}

        public function index(): DirectoryAnswer
        {
            return new DirectoryAnswer($this->index, $this->index === null ? 'Nothing is verified yet' : null);
        }

        public function package(string $name): DirectoryAnswer
        {
            return new DirectoryAnswer($this->details[$name] ?? null, isset($this->details[$name]) ? null : 'It was tampered with');
        }
    };

    $this->runJob = fn (VerifiedDirectory $directory) => (new Acting)->as($this->admin, fn () => (new DirectorySyncJob($directory, $this->sync))->handle($this->context), 'Running the sync job');
    $this->published = fn (): int => (new Acting)->as($this->admin, fn (): int => count($this->reader->published(new ItemQuery(types: ['plugin', 'theme']))->items), 'Counting the listings');
    $this->index = fn (string ...$names): array => ['format' => 1, 'sequence' => 1, 'packages' => array_map(fn (string $name): array => ['name' => $name], $names)];
});

it('syncs every package the verified index lists', function (): void {
    ($this->runJob)(($this->directory)(($this->index)('temecms/teme-seo', 'acme/forms'), [
        'temecms/teme-seo' => temecmsSitePackage('temecms/teme-seo'),
        'acme/forms' => temecmsSitePackage('acme/forms'),
    ]));

    expect(($this->published)())->toBe(2)
        ->and($this->context->message)->toStartWith('2 added');
});

it('fails and changes nothing when there is no verified index', function (): void {
    ($this->runJob)(($this->directory)(($this->index)('acme/forms'), ['acme/forms' => temecmsSitePackage('acme/forms')]));

    expect(fn () => ($this->runJob)(($this->directory)(null, [])))->toThrow(RuntimeException::class, 'Nothing is verified yet')
        ->and(($this->published)())->toBe(1);
});

it('keeps a listing whose detail file can’t be read this time', function (): void {
    ($this->runJob)(($this->directory)(($this->index)('acme/forms'), ['acme/forms' => temecmsSitePackage('acme/forms')]));

    ($this->runJob)(($this->directory)(($this->index)('acme/forms'), []));

    expect(($this->published)())->toBe(1)
        ->and($this->context->message)->toContain('1 not read this time');
});

it('is the plugin’s hourly job, and fails without changing anything while Teme has verified no index', function (): void {
    $manifest = temecmsSiteJson('teme-plugin.json');
    $run = $this->plugin(temecmsSiteRoot())->runJob('directory-sync');

    expect($manifest['schedules'])->toBe([['job' => 'directory-sync', 'every' => 'hour']])
        ->and($run->state)->toBe(JobRun::FAILED)
        ->and((string) $run->message)->toContain('The directory index can’t be used')
        ->and(($this->published)())->toBe(0);
});
