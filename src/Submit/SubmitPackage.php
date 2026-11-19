<?php

/**
 * The Submit form for a plugin or theme. It checks what was sent and opens
 * a pull request on the directory's repository that adds the package's
 * listing file, with the GitHub token the site's owner saved in the
 * plugin's settings; with no token it stores the package as a submission
 * for a person to add by hand. The contact address goes nowhere public.
 *
 * @package TemecmsSite
 * @since   1.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Temecms\Site\Submit;

use JsonException;
use Teme\Contracts\Content\HtmlToDocument;
use Teme\Contracts\Content\Submission;
use Teme\Contracts\Content\Submissions;
use Teme\Contracts\Content\WriteRefused;
use Teme\Contracts\Http\HttpAnswer;
use Teme\Contracts\Http\OutboundHttp;
use Teme\Contracts\Routes\PublicRequest;
use Teme\Contracts\Routes\PublicResponse;
use Teme\Contracts\Routes\PublicRoute;
use Teme\Contracts\Settings\SecretReader;
use Throwable;

/**
 * Answers a post of the package Submit form.
 *
 * @since 1.1.0
 */
final readonly class SubmitPackage implements PublicRoute
{
    /** The repository the listing files live in. */
    public const string DIRECTORY = 'temecms/directory';

    /** GitHub's API. */
    public const string API = 'https://api.github.com';

    /** The settings group the token is read from. */
    public const string SETTINGS = 'plugin.temecms-site';

    /** The secret setting holding the GitHub token. */
    public const string TOKEN = 'github_token';

    /** The field a person never sees and a robot fills in. */
    public const string HONEYPOT = 'website';

    public function __construct(
        private SecretReader $secrets,
        private OutboundHttp $http,
        private Submissions $submissions,
        private HtmlToDocument $documents,
    ) {}

    /**
     * Opens the pull request and sends the visitor back to the form's page,
     * saying what happened.
     *
     * @since 1.1.0
     */
    public function handle(PublicRequest $request): PublicResponse
    {
        parse_str($request->rawBody, $form);

        if (self::field($form, self::HONEYPOT) !== '') {
            return Answer::back($request, ['submitted' => 'pull-request']);
        }

        $repository = self::repository(self::field($form, 'repository'));
        $kind = self::field($form, 'kind');
        $category = self::field($form, 'category');
        $contact = self::field($form, 'contact');

        $wrong = match (true) {
            $repository === null => 'repository',
            ! in_array($kind, ['plugin', 'theme'], true) => 'kind',
            preg_match('~^[a-z0-9][a-z0-9-]{0,39}$~', $category) !== 1 => 'category',
            filter_var($contact, FILTER_VALIDATE_EMAIL) === false || strlen($contact) > 254 => 'contact',
            ! in_array(self::field($form, 'agree'), ['1', 'on', 'yes'], true) => 'agree',
            default => null,
        };

        if ($wrong !== null) {
            return Answer::back($request, ['error' => $wrong]);
        }

        $token = trim((string) $this->secrets->secret(self::SETTINGS, self::TOKEN));

        if ($token === '') {
            return $this->store($request, $repository, $kind, $category, $contact);
        }

        try {
            return Answer::back($request, $this->open($token, $repository, $kind, $category));
        } catch (Throwable) {
            return Answer::back($request, ['error' => 'github']);
        }
    }

    /**
     * Stores the package as a submission waiting for review, the contact
     * address kept for the reviewer alone.
     *
     * @param  array{owner: string, repo: string, url: string}  $repository
     */
    private function store(PublicRequest $request, array $repository, string $kind, string $category, string $contact): PublicResponse
    {
        $url = htmlspecialchars($repository['url'], ENT_QUOTES);
        $html = "<p>Repository: <a href=\"{$url}\">{$url}</a></p><p>Kind: {$kind}. Main category: {$category}.</p>";

        try {
            $this->submissions->submit(new Submission(
                type: $kind,
                title: "{$repository['owner']}/{$repository['repo']}",
                body: $this->documents->convert($html),
                fields: ['link_source' => $repository['url'], 'categories' => $category],
                from: $contact,
            ));
        } catch (WriteRefused) {
            return Answer::back($request, ['error' => 'unavailable']);
        }

        return Answer::back($request, ['submitted' => 'by-hand']);
    }

    /**
     * Opens the pull request, returning the query that says what happened.
     *
     * @param  array{owner: string, repo: string, url: string}  $repository
     *
     * @return array<string, string|int>
     */
    private function open(string $token, array $repository, string $kind, string $category): array
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'Authorization' => 'Bearer '.$token,
            'X-GitHub-Api-Version' => '2022-11-28',
            'User-Agent' => 'temecms-site',
        ];

        $composer = $this->get("/repos/{$repository['owner']}/{$repository['repo']}/contents/composer.json", $headers);

        if ($composer === null) {
            return ['error' => 'repository'];
        }

        $package = json_decode((string) base64_decode((string) ($composer['content'] ?? ''), true), true);
        $name = is_array($package) && is_string($package['name'] ?? null) ? strtolower($package['name']) : '';

        if (preg_match('~^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*$~', $name) !== 1) {
            return ['error' => 'repository'];
        }

        $path = "listings/{$name}.json";

        if ($this->get('/repos/'.self::DIRECTORY.'/contents/'.$path, $headers) !== null) {
            return ['error' => 'listed'];
        }

        $directory = $this->get('/repos/'.self::DIRECTORY, $headers) ?? throw new SubmitFailed('The directory repository is out of reach.');
        $base = (string) ($directory['default_branch'] ?? 'main');
        $head = $this->get('/repos/'.self::DIRECTORY.'/git/ref/heads/'.rawurlencode($base), $headers);
        $parent = (string) ($head['object']['sha'] ?? throw new SubmitFailed('The default branch has no commit.'));
        $commit = $this->get('/repos/'.self::DIRECTORY.'/git/commits/'.$parent, $headers);
        $baseTree = (string) ($commit['tree']['sha'] ?? throw new SubmitFailed('The commit has no tree.'));

        $listing = json_encode(['repository' => $repository['url'], 'kind' => $kind, 'categories' => [$category], 'picked' => false], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)."\n";

        $tree = $this->post('/repos/'.self::DIRECTORY.'/git/trees', [
            'base_tree' => $baseTree,
            'tree' => [['path' => $path, 'mode' => '100644', 'type' => 'blob', 'content' => $listing]],
        ], $headers);
        $made = $this->post('/repos/'.self::DIRECTORY.'/git/commits', [
            'message' => "Add {$name}",
            'tree' => (string) ($tree['sha'] ?? ''),
            'parents' => [$parent],
        ], $headers);
        $branch = 'submit/'.str_replace('/', '-', $name).'-'.substr((string) ($made['sha'] ?? ''), 0, 7);
        $this->post('/repos/'.self::DIRECTORY.'/git/refs', ['ref' => 'refs/heads/'.$branch, 'sha' => (string) ($made['sha'] ?? '')], $headers);
        $pull = $this->post('/repos/'.self::DIRECTORY.'/pulls', [
            'title' => "Add {$name}",
            'head' => $branch,
            'base' => $base,
            'body' => "Submitted with the form on temecms.com.\n\n- Repository: {$repository['url']}\n- Kind: {$kind}\n- Main category: {$category}\n",
        ], $headers);

        $number = $pull['number'] ?? null;

        return is_int($number) ? ['submitted' => 'pull-request', 'number' => $number] : ['submitted' => 'pull-request'];
    }

    /**
     * A GitHub address as owner, repository and its canonical URL, or null.
     *
     * @return array{owner: string, repo: string, url: string}|null
     */
    private static function repository(string $address): ?array
    {
        if (preg_match('~^https://github\.com/([A-Za-z0-9](?:[A-Za-z0-9-]{0,38}))/([A-Za-z0-9._-]{1,100}?)(?:\.git)?/?$~', $address, $match) !== 1) {
            return null;
        }

        return ['owner' => $match[1], 'repo' => $match[2], 'url' => "https://github.com/{$match[1]}/{$match[2]}"];
    }

    /**
     * @param  array<array-key, mixed>  $form
     */
    private static function field(array $form, string $name): string
    {
        return is_string($form[$name] ?? null) ? trim($form[$name]) : '';
    }

    /**
     * The decoded answer to a GET, or null for a 404.
     *
     * @param  array<string, string>  $headers
     *
     * @return array<string, mixed>|null
     */
    private function get(string $path, array $headers): ?array
    {
        $answer = $this->http->get(self::API.$path, $headers, 8);

        return $answer->status === 404 ? null : self::decoded($answer);
    }

    /**
     * The decoded answer to a POST.
     *
     * @param  array<string, mixed>  $json
     * @param  array<string, string>  $headers
     *
     * @return array<string, mixed>
     */
    private function post(string $path, array $json, array $headers): array
    {
        return self::decoded($this->http->postJson(self::API.$path, $json, $headers, 8));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws SubmitFailed for an answer that isn't a success with a JSON object
     */
    private static function decoded(HttpAnswer $answer): array
    {
        if ($answer->status < 200 || $answer->status > 299) {
            throw new SubmitFailed("GitHub answered {$answer->status}.");
        }

        try {
            $json = $answer->json();
        } catch (JsonException) {
            throw new SubmitFailed('GitHub answered with something other than JSON.');
        }

        return is_array($json) ? $json : throw new SubmitFailed('GitHub answered with something other than an object.');
    }
}
