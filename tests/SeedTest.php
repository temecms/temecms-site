<?php

/**
 * The seed's steps on a test site: what each makes, that a second run
 * changes nothing, that it stops before writing when it can't run, and
 * that it leaves what a site already has alone.
 *
 * @package TemecmsSite
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

use App\Domain\Contents\Models\Content;
use App\Domain\Contents\Operations\CreateDraft;
use App\Domain\Contents\Operations\Input\CreateDraftInput;
use App\Domain\Contents\Operations\Input\PublishRevisionInput;
use App\Domain\Contents\Operations\Input\SaveDraftInput;
use App\Domain\Contents\Operations\PublishRevision;
use App\Domain\Contents\Operations\SaveDraft;
use App\Domain\Fields\FieldRegistry;
use App\Domain\Hooks\Payloads;
use App\Domain\Media\Models\Media;
use App\Domain\Menus\Models\Navigation;
use App\Domain\Menus\Models\NavigationItem;
use App\Domain\Types\ContentTypeRegistry;
use App\Domain\Updates\TemeVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Teme\Contracts\Settings\SettingsReader;
use Teme\Testing\Acting;
use Teme\Testing\TemeTestCase;

require_once __DIR__.'/Support/helpers.php';
require_once dirname(__DIR__).'/seed/steps.php';

uses(TemeTestCase::class);

/** Every step, in order. */
const TEMECMS_SITE_ALL = ['media', 'theme', 'content', 'regions', 'menus', 'settings', 'addresses', 'activate', 'words'];

beforeEach(function (): void {
    $this->admin = $this->person('administrator');
    $this->state = sys_get_temp_dir().'/temecms-site-seed-'.bin2hex(random_bytes(6)).'.json';
    $this->seed = fn (array $steps, array $force = []): array => temecms_site_seed($steps, $this->admin->id, $this->state, $force);
});

afterEach(function (): void {
    @unlink($this->state);
});

/** The plain text of a stored Tiptap document. */
function temecmsSiteText(array $node): string
{
    $text = (string) ($node['text'] ?? '');
    foreach ($node['content'] ?? [] as $child) {
        $text .= temecmsSiteText($child).' ';
    }

    return $text;
}

it('runs its steps in one fixed order', function (): void {
    expect(temecms_site_seed_steps())->toBe(TEMECMS_SITE_ALL);
});

it('stops before it starts when no step or an unknown step is named', function (array $steps): void {
    expect(fn () => ($this->seed)($steps))->toThrow(TemecmsSiteSeedStopped::class, 'Name the steps to run');
})->with([[[]], [['types']], [['content', 'fields']]]);

it('stops on a user who is not an administrator', function (): void {
    $editor = $this->person('editor');

    expect(fn () => temecms_site_seed(['media'], $editor->id, $this->state))->toThrow(TemecmsSiteSeedStopped::class, 'isn\'t an administrator');
});

it('stops, writing nothing, when the plugin’s types are missing', function (): void {
    $before = Content::query()->count();

    expect(fn () => ($this->seed)(['content']))->toThrow(TemecmsSiteSeedStopped::class, 'Enable the temecms-site plugin');
    expect(Content::query()->count())->toBe($before);
});

it('uploads its pictures once, with their alt text, and keeps its state in storage', function (): void {
    $made = ($this->seed)(['media']);
    $state = json_decode((string) file_get_contents($this->state), true);

    expect($made)->toBe(['media' => 14])
        ->and($state['media'])->toHaveCount(14)
        ->and(Media::query()->whereKey($state['media']['consent'])->value('alt'))->not->toBe('')
        ->and(glob(temecmsSiteRoot().'/seed/*.json'))->toBe([temecmsSiteRoot().'/seed/content.json', temecmsSiteRoot().'/seed/words.json'])
        ->and(($this->seed)(['media']))->toBe([]);
});

it('loads the whole site, then changes nothing on a second run', function (): void {
    temecmsSiteTypes($this, $this->admin);
    $this->theme(temecmsSiteTheme())->activate();

    $made = ($this->seed)(TEMECMS_SITE_ALL);
    $state = json_decode((string) file_get_contents($this->state), true);
    $faq = Content::query()->findOrFail($state['content']['page:faq']);
    $doc = Content::query()->where('type', 'doc')->whereNotNull('published_revision_id')->firstOrFail();
    $settings = app(SettingsReader::class);

    expect($made['published'])->toBe(59)
        ->and($made['regions'])->toBe(7)
        ->and(temecmsSiteText($faq->publishedRevision->document))->toContain('This site runs Teme '.TemeVersion::current().'.')
        ->not->toContain('{{release}}')
        ->not->toContain('alpha')
        ->and($this->fetch('/docs/'.$doc->slug.'/')->status())->toBe(200)
        ->and($settings->get('addresses', 'front_page_id'))->toBe($state['content']['page:home'])
        ->and(($this->seed)(TEMECMS_SITE_ALL))->toBe([]);
})->skip(fn (): bool => temecmsSiteTheme() === null, 'needs TEMECMS_THEME, a copy of the TemeCMS theme');

it('leaves a published item, a region and a filled menu alone unless their kind is forced', function (): void {
    temecmsSiteTypes($this, $this->admin);
    $this->theme(temecmsSiteTheme())->activate();
    ($this->seed)(TEMECMS_SITE_ALL);
    $state = json_decode((string) file_get_contents($this->state), true);

    $faq = Content::query()->findOrFail($state['content']['page:faq']);
    (new Acting)->as($this->admin, function () use ($faq): void {
        app(SaveDraft::class)->execute(new SaveDraftInput($faq->id, 'Our own questions', ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Ours.']]]]], [], (string) $faq->working_revision_id, (string) $this->admin->id));
        $faq->refresh();
        app(PublishRevision::class)->execute(new PublishRevisionInput($faq->id, (string) $faq->working_revision_id, (string) $this->admin->id));
    }, 'Editing the FAQ');
    $header = Navigation::query()->where('name', 'TemeCMS: Header')->firstOrFail();
    $items = NavigationItem::query()->where('navigation_id', $header->id)->count();

    expect(($this->seed)(['content', 'regions', 'menus']))->toBe([])
        ->and($faq->fresh()->title)->toBe('Our own questions')
        ->and(NavigationItem::query()->where('navigation_id', $header->id)->count())->toBe($items)
        ->and(($this->seed)(['content'], ['page']))->toHaveKey('published')
        ->and($faq->fresh()->title)->toBe('Questions, answered plainly.');
})->skip(fn (): bool => temecmsSiteTheme() === null, 'needs TEMECMS_THEME, a copy of the TemeCMS theme');

it('takes over the types the old theme seed made, renaming them and keeping their items and field values', function (): void {
    $this->artisan('teme:types:import', ['file' => temecmsSiteRoot().'/tests/Support/old-theme-types.json'])->assertExitCode(0);
    // As the old seed stored them, before Teme refused a type named after an admin screen.
    DB::table('content_type_definitions')->where('key', 'plugin')->update(['definition' => DB::raw("jsonb_set(definition, '{plural}', '\"Plugins\"')")]);
    DB::table('content_type_definitions')->where('key', 'theme')->update(['definition' => DB::raw("jsonb_set(definition, '{plural}', '\"Themes\"')")]);
    $guide = (new Acting)->as($this->admin, fn (): Content => app(CreateDraft::class)->execute(new CreateDraftInput('doc', 'A guide the old seed made', ['type' => 'doc', 'content' => [['type' => 'paragraph']]], ['01JQ0TEMECMSSXTE0QDGR0VP00' => 'Install'], (string) $this->admin->id)), 'Writing a guide');
    $guide = $this->publish(Payloads::item($guide), 'doc');

    temecmsSiteRelease();
    $this->plugin(temecmsSiteRoot())->enable($this->admin);
    $types = app(ContentTypeRegistry::class);

    expect($types->get('plugin')->plural)->toBe('Plugin directory')
        ->and($types->get('plugin')->singular)->toBe('Plugin listing')
        ->and($types->get('theme')->plural)->toBe('Theme directory')
        ->and($types->get('doc')->plural)->toBe('Documentation')
        ->and(app(FieldRegistry::class)->find('01JQ0TEMECMSSXTE0QDGR0VP00')?->handle)->toBe('group')
        ->and(Content::query()->findOrFail($guide->id)->workingRevision->fields)->toMatchArray(['01JQ0TEMECMSSXTE0QDGR0VP00' => 'Install'])
        ->and($this->fetch($guide->url)->status())->toBe(200);
})->skip(fn (): bool => ! temecmsSiteSocket(), 'needs a Teme that reads contributes.types and offers the directory’s doors');

it('runs as a script from the Teme install’s folder, and exits non-zero with the reason it stopped', function (): void {
    $script = temecmsSiteRoot().'/seed/seed.php';

    $elsewhere = Process::path(sys_get_temp_dir())->run([PHP_BINARY, $script, 'media']);
    $unknown = Process::path(base_path())->run([PHP_BINARY, $script, 'types', 'media']);

    expect($elsewhere->exitCode())->toBe(1)
        ->and($elsewhere->errorOutput())->toContain("Run the seed from the Teme install's folder")
        ->and($unknown->exitCode())->toBe(1)
        ->and($unknown->errorOutput())->toContain('Name the steps to run, from: media theme content regions menus settings addresses activate words. Unknown: types.');
});


/** The text of an item's published document. */
function temecmsSiteBody(Content $item): string
{
    return temecmsSiteText($item->fresh()->publishedRevision->document);
}

/**
 * Puts every passage the `words` step rewrites back to the older seed's words, as a site an older seed filled holds them.
 */
function temecmsSiteAge(object $test): void
{
    $words = temecmsSiteJson('seed/words.json')['items'];
    (new Acting)->as($test->admin, function () use ($words, $test): void {
        $aid = (string) $test->admin->id;
        foreach ($words as $w) {
            $item = Content::query()->where('type', $w['type'])->where('slug', $w['slug'])->firstOrFail();
            $revision = $item->publishedRevision;
            $document = $revision->document;
            $fields = (array) $revision->fields;
            $keys = [];
            foreach (app(FieldRegistry::class)->forType($w['type']) as $field) {
                $keys[$field->handle] = $field->key;
            }
            foreach ($w['replace'] as $r) {
                $now = str_replace('{{release}}', TemeVersion::current(), temecms_site_plain_text($r['new']));
                foreach ($document['content'] as $i => $block) {
                    if (temecms_site_plain_text($block) === $now) {
                        $document['content'][$i] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $r['old']]]];

                        break;
                    }
                }
            }
            foreach ($w['fields'] ?? [] as $handle => [$was]) {
                $fields[$keys[$handle]] = $was;
            }
            app(SaveDraft::class)->execute(new SaveDraftInput($item->id, (string) $revision->title, $document, $fields, (string) $item->working_revision_id, $aid, (array) $revision->terms));
            $item->refresh();
            app(PublishRevision::class)->execute(new PublishRevisionInput($item->id, (string) $item->working_revision_id, $aid));
        }
    }, 'Putting back the older words');
}

it('has, for every passage it rewrites, the words the seed writes now', function (): void {
    $content = temecmsSiteJson('seed/content.json');
    $now = [];
    foreach (['pages', 'posts', 'docs', 'plugins', 'themes'] as $bucket) {
        foreach ($content[$bucket] as $item) {
            $now[$item['type'].':'.$item['slug']] = array_map('temecms_site_plain_text', $item['body']['content']);
        }
    }
    foreach ($content['regions'] as $name => $body) {
        $now['region:temecms--'.$name] = array_map('temecms_site_plain_text', $body['content']);
    }
    $words = temecmsSiteJson('seed/words.json')['items'];

    expect($words)->not->toBeEmpty();
    foreach ($words as $w) {
        foreach ($w['replace'] as $r) {
            expect($now[$w['type'].':'.$w['slug']] ?? [])->toContain(temecms_site_plain_text($r['new']))
                ->and($now[$w['type'].':'.$w['slug']])->not->toContain($r['old']);
        }
    }
});

it('says nothing is out of date in the seed,', function (): void {
    $content = temecmsSiteJson('seed/content.json');
    unset($content['posts']); // the 0.7.0-alpha.1 notes are history, and say so
    $text = json_encode($content, JSON_UNESCAPED_UNICODE);

    expect($text)->not->toMatch('/alpha|0\.7|not drawn yet|Not written yet|Not yet\. Teme/i')
        ->and($text)->toContain('This guide is being written.');
});

it('brings the older seed’s words up to date, once, and changes nothing the second time', function (): void {
    temecmsSiteTypes($this, $this->admin);
    $this->theme(temecmsSiteTheme())->activate();
    ($this->seed)(array_diff(TEMECMS_SITE_ALL, ['words']));
    $state = json_decode((string) file_get_contents($this->state), true);
    temecmsSiteAge($this);
    $page = static fn (string $key): Content => Content::query()->findOrFail(json_decode((string) file_get_contents(test()->state), true)['content']['page:'.$key]);
    $stub = Content::query()->where('type', 'doc')->where('slug', 'configuring-teme')->firstOrFail();
    $proof = collect(app(FieldRegistry::class)->forType('doc'))->firstWhere('handle', 'proof')->key;

    expect(temecmsSiteBody($page('faq')))->toContain('Teme is in alpha')
        ->and(temecmsSiteBody($stub))->toContain('Its page is not drawn yet.')
        ->and((string) $stub->fresh()->publishedRevision->fields[$proof])->toBe('tests');

    $says = [];
    $made = temecms_site_seed(['words'], $this->admin->id, $this->state, [], function (string $line) use (&$says): void {
        $says[] = $line;
    });

    expect($made)->toHaveKey('words')
        ->and(temecmsSiteBody($page('faq')))->toContain('Yes. Teme 1.x is a stable release line')->toContain('This site runs Teme '.TemeVersion::current().'.')
        ->not->toContain('alpha')->not->toContain('{{release}}')
        ->and(temecmsSiteBody($page('roadmap')))->toContain('Teme 1.2')->toContain('The directory')->not->toContain('alpha')->not->toContain('Installing and listing')
        ->and(temecmsSiteBody($page('migration')))->not->toContain('alpha')
        ->and(temecmsSiteBody($stub))->toContain('This guide is being written.')->not->toContain('not drawn yet')
        ->and((string) $stub->fresh()->publishedRevision->fields[$proof])->toBe('')
        ->and(array_filter($says, static fn (string $line): bool => str_contains($line, 'left as')))->toBe([]);

    expect(temecms_site_seed(['words'], $this->admin->id, $this->state))->toBe([])
        ->and(($this->seed)(TEMECMS_SITE_ALL))->toBe([]);
})->skip(fn (): bool => temecmsSiteTheme() === null, 'needs TEMECMS_THEME, a copy of the TemeCMS theme');

it('leaves a passage a person edited, and an item with a draft waiting alone, and says so', function (): void {
    temecmsSiteTypes($this, $this->admin);
    $this->theme(temecmsSiteTheme())->activate();
    ($this->seed)(array_diff(TEMECMS_SITE_ALL, ['words']));
    $state = json_decode((string) file_get_contents($this->state), true);
    temecmsSiteAge($this);
    $aid = (string) $this->admin->id;

    $faq = Content::query()->findOrFail($state['content']['page:faq']);
    $roadmap = Content::query()->findOrFail($state['content']['page:roadmap']);
    $post = Content::query()->findOrFail($state['content']['post:release-0-7-0-alpha-1']);
    (new Acting)->as($this->admin, function () use ($faq, $roadmap, $aid): void {
        // The first answer rewritten by a person; the rest of the FAQ is as the older seed left it.
        $document = $faq->publishedRevision->document;
        foreach ($document['content'] as $i => $block) {
            if (str_starts_with(temecms_site_plain_text($block), 'Not yet. Teme is in alpha')) {
                $document['content'][$i] = ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Ours.']]];
            }
        }
        app(SaveDraft::class)->execute(new SaveDraftInput($faq->id, (string) $faq->publishedRevision->title, $document, (array) $faq->publishedRevision->fields, (string) $faq->working_revision_id, $aid));
        $faq->refresh();
        app(PublishRevision::class)->execute(new PublishRevisionInput($faq->id, (string) $faq->working_revision_id, $aid));
        // A draft waiting on the roadmap, not published.
        app(SaveDraft::class)->execute(new SaveDraftInput($roadmap->id, (string) $roadmap->publishedRevision->title, $roadmap->publishedRevision->document, (array) $roadmap->publishedRevision->fields, (string) $roadmap->working_revision_id, $aid));
    }, 'Editing the site');

    $says = [];
    temecms_site_seed(['words'], $this->admin->id, $this->state, [], function (string $line) use (&$says): void {
        $says[] = $line;
    });
    $said = implode("\n", $says);

    expect(temecmsSiteBody($faq))->toContain('Ours.')->not->toContain('Yes. Teme 1.x is a stable release line')
        ->toContain('A management API lets outside tools sign in')
        ->and($said)->toContain('words page:faq: the text has changed, so "Not yet. Teme is in alpha')
        ->and(temecmsSiteBody($roadmap))->toContain('Alpha · 0.7.0-alpha.1')
        ->and($said)->toContain('words page:roadmap: a draft is waiting')
        ->and(temecmsSiteBody($post))->toContain('The full notes are under');
})->skip(fn (): bool => temecmsSiteTheme() === null, 'needs TEMECMS_THEME, a copy of the TemeCMS theme');
