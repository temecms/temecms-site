<?php

/**
 * The Submit form for a plugin or theme: with the owner's GitHub token it
 * opens a pull request on the directory's repository adding the listing
 * file; without one it takes nothing; a filled honeypot and a sixth post in
 * a minute go no further.
 *
 * @package TemecmsSite
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Teme\Testing\TemeTestCase;

require_once __DIR__.'/Support/helpers.php';

uses(TemeTestCase::class);

beforeEach(function (): void {
    if (! temecmsSiteSocket()) {
        $this->markTestSkipped('needs the plugin types socket (Teme 1.2), which reads contributes.types');
    }

    temecmsSiteRelease();
    $this->admin = $this->person('administrator');
    $this->site = $this->plugin(temecmsSiteRoot())->enable($this->admin);

    $this->sent = [];
    Http::fake(function (Request $request) {
        $this->sent[] = $request;
        $path = (string) parse_url($request->url(), PHP_URL_PATH);

        return match (true) {
            $path === '/repos/acme/forms/contents/composer.json' => Http::response(['content' => base64_encode(json_encode(['name' => 'acme/forms', 'type' => 'teme-plugin']))]),
            $path === '/repos/temecms/directory/contents/listings/acme/forms.json' => Http::response(['message' => 'Not Found'], 404),
            $path === '/repos/temecms/directory/contents/listings/acme/listed.json' => Http::response(['sha' => 'f00']),
            $path === '/repos/acme/listed/contents/composer.json' => Http::response(['content' => base64_encode(json_encode(['name' => 'acme/listed']))]),
            $path === '/repos/temecms/directory' => Http::response(['default_branch' => 'main']),
            $path === '/repos/temecms/directory/git/ref/heads/main' => Http::response(['object' => ['sha' => 'base000']]),
            $path === '/repos/temecms/directory/git/commits/base000' => Http::response(['tree' => ['sha' => 'tree000']]),
            $path === '/repos/temecms/directory/git/trees' => Http::response(['sha' => 'tree111'], 201),
            $path === '/repos/temecms/directory/git/commits' => Http::response(['sha' => 'abcdef1234'], 201),
            $path === '/repos/temecms/directory/git/refs' => Http::response(['ref' => 'refs/heads/submit/acme-forms-abcdef1'], 201),
            $path === '/repos/temecms/directory/pulls' => Http::response(['number' => 128, 'html_url' => 'https://github.com/temecms/directory/pull/128'], 201),
            default => Http::response(['message' => 'Not Found'], 404),
        };
    });

    $this->submit = fn (array $form, string $from = '/plugins/submit/') => $this->fetch('/temecms-site/submit-package', 'POST', [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Host' => 'localhost',
        'Origin' => 'http://localhost',
        'Referer' => 'http://localhost'.$from,
    ], http_build_query($form));

    $this->form = ['repository' => 'https://github.com/acme/forms', 'kind' => 'plugin', 'category' => 'forms', 'contact' => 'author@example.com', 'agree' => '1', 'website' => ''];
});

it('opens a pull request adding the listing file, with the owner’s token', function (): void {
    $this->site->changeSettings(['github_token' => 'placeholder-token']);

    $answer = ($this->submit)($this->form);

    $answer->assertStatus(303)->assertHeader('Location', '/plugins/submit/?submitted=pull-request&number=128');

    $tree = collect($this->sent)->first(fn (Request $request): bool => str_ends_with($request->url(), '/git/trees'));
    $pull = collect($this->sent)->first(fn (Request $request): bool => str_ends_with($request->url(), '/pulls'));

    expect($tree['base_tree'])->toBe('tree000')
        ->and($tree['tree'][0]['path'])->toBe('listings/acme/forms.json')
        ->and(json_decode($tree['tree'][0]['content'], true))->toBe(['repository' => 'https://github.com/acme/forms', 'kind' => 'plugin', 'categories' => ['forms'], 'picked' => false])
        ->and($pull['base'])->toBe('main')
        ->and($pull['head'])->toBe('submit/acme-forms-abcdef1')
        ->and($pull['title'])->toBe('Add acme/forms')
        ->and($this->sent[0]->header('Authorization'))->toBe(['Bearer placeholder-token']);

    foreach ($this->sent as $request) {
        expect($request->body())->not->toContain('author@example.com');
    }
});

it('takes no package without a token, and says it can’t', function (): void {
    ($this->submit)($this->form)->assertStatus(303)->assertHeader('Location', '/plugins/submit/?error=unavailable');

    expect($this->sent)->toBe([]);
});

it('says so when the package is already listed', function (): void {
    $this->site->changeSettings(['github_token' => 'placeholder-token']);

    ($this->submit)([...$this->form, 'repository' => 'https://github.com/acme/listed'])->assertStatus(303)->assertHeader('Location', '/plugins/submit/?error=listed');

    expect(collect($this->sent)->contains(fn (Request $request): bool => $request->method() === 'POST'))->toBeFalse();
});

it('names the first wrong field and sends nothing', function (array $change, string $field): void {
    $this->site->changeSettings(['github_token' => 'placeholder-token']);

    ($this->submit)([...$this->form, ...$change])->assertStatus(303)->assertHeader('Location', '/plugins/submit/?error='.$field);

    expect($this->sent)->toBe([]);
})->with([
    'not GitHub' => [['repository' => 'https://gitlab.com/acme/forms'], 'repository'],
    'not https' => [['repository' => 'http://github.com/acme/forms'], 'repository'],
    'a kind it doesn’t know' => [['kind' => 'widget'], 'kind'],
    'a category that isn’t a key' => [['category' => 'Forms & Fields'], 'category'],
    'no contact' => [['contact' => 'nobody'], 'contact'],
    'the rules not agreed' => [['agree' => ''], 'agree'],
]);

it('goes no further when the honeypot is filled', function (): void {
    $this->site->changeSettings(['github_token' => 'placeholder-token']);

    ($this->submit)([...$this->form, 'website' => 'https://spam.example'])->assertStatus(303);

    expect($this->sent)->toBe([]);
});

it('sends the visitor back only to a page of this site', function (): void {
    $answer = $this->fetch('/temecms-site/submit-package', 'POST', [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Host' => 'localhost',
        'Referer' => 'https://elsewhere.example/phish/',
    ], http_build_query($this->form));

    $answer->assertStatus(303)->assertHeader('Location', '/plugins/submit/?error=unavailable');
});

it('refuses a post from another site’s page', function (): void {
    $this->fetch('/temecms-site/submit-package', 'POST', [
        'Content-Type' => 'application/x-www-form-urlencoded',
        'Host' => 'localhost',
        'Origin' => 'https://elsewhere.example',
    ], http_build_query($this->form))->assertStatus(401);
});

it('answers at most five posts a minute from one address', function (): void {
    foreach (range(1, 5) as $post) {
        ($this->submit)($this->form)->assertStatus(303);
    }

    ($this->submit)($this->form)->assertStatus(429);
});
