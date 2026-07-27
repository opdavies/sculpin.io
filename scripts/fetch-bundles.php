#!/usr/bin/env php
<?php

declare(strict_types=1);

/*
 * Discovers Sculpin bundles on GitHub and refreshes their metadata in
 * app/config/bundles.yml.
 *
 * Newly discovered repositories are written to the `discovered` list, which is
 * NOT rendered on the site. A maintainer reviews them and moves the ones that
 * belong on sculpin.io into the `bundles` list.
 *
 * Usage:
 *
 *     php scripts/fetch-bundles.php
 *     php scripts/fetch-bundles.php --dry-run
 *
 * A GitHub token is optional but strongly recommended; without one the Search
 * API is limited to 10 requests per minute.
 *
 *     GITHUB_TOKEN=ghp_... php scripts/fetch-bundles.php
 */

use Symfony\Component\Yaml\Yaml;

require __DIR__.'/../vendor/autoload.php';

const CONFIG_FILE = __DIR__.'/../app/config/bundles.yml';

const USER_AGENT = 'sculpin.io-bundle-fetcher';

/**
 * Repositories are discovered if they carry this GitHub topic, or if their name
 * matches every one of NAME_PATTERNS.
 */
const TOPIC = 'sculpin-bundle';

const SEARCH_QUERIES = [
    'sculpin bundle in:name',
    'topic:sculpin-bundle',
];

const NAME_PATTERNS = ['/sculpin/i', '/bundle/i'];

/**
 * Fields owned by the script. Everything else in an existing entry is left
 * alone, so maintainers can edit `name` and `description` without the next run
 * clobbering them.
 */
const MANAGED_FIELDS = ['description', 'version', 'updated', 'stars', 'archived', 'abandoned'];

const FILE_HEADER = <<<'YAML'
# Sculpin bundles listed on sculpin.io.
#
# `bundles` is rendered on the home page and the community page. `discovered`
# holds repositories found by scripts/fetch-bundles.php that a maintainer has
# not reviewed yet, and is never rendered. `ignored` repositories are skipped
# by discovery entirely.
#
# To add a bundle by hand, add an entry to `bundles` with at least `name`,
# `repository` and `description`, then run:
#
#     composer fetch-bundles
#
# That run fills in the rest. It refreshes description, version, updated,
# stars, archived and abandoned on every run; `name` and any other keys you add
# are left alone.
#
# Both lists are kept in alphabetical order here. The site renders `bundles`
# most recently updated first, and skips anything marked abandoned; see
# source/_views/includes/bundles.html.


YAML;

exit(main($argv));

function main(array $argv): int
{
    $dryRun = in_array('--dry-run', $argv, true);

    $config = loadConfig();

    $ignored = array_map('normaliseRepositoryUrl', $config['ignored'] ?? []);

    $existing = [];
    foreach (['bundles', 'discovered'] as $list) {
        foreach ($config[$list] ?? [] as $entry) {
            if (isset($entry['repository'])) {
                $existing[normaliseRepositoryUrl($entry['repository'])] = $list;
            }
        }
    }

    stderr('Searching GitHub...');

    $repositories = [];
    foreach (SEARCH_QUERIES as $query) {
        foreach (searchRepositories($query) as $repository) {
            $repositories[strtolower($repository['full_name'])] = $repository;
        }
    }

    stderr(sprintf('  %d repositories returned.', count($repositories)));

    $bundles = indexByRepository($config['bundles'] ?? []);
    $discovered = indexByRepository($config['discovered'] ?? []);
    $added = [];

    foreach ($repositories as $repository) {
        $url = normaliseRepositoryUrl($repository['html_url']);

        if (in_array($url, $ignored, true)) {
            continue;
        }

        if ($repository['fork']) {
            continue;
        }

        if (!isDiscoverable($repository)) {
            continue;
        }

        if (!isset($existing[$url])) {
            $discovered[$url] = ['name' => humanise($repository['name']), 'repository' => $repository['html_url']];
            $added[] = $repository['full_name'];
        }
    }

    // Refresh both lists, including entries that were added by hand and so were
    // never returned by a search.
    foreach ([&$bundles, &$discovered] as &$list) {
        foreach ($list as $url => $entry) {
            stderr(sprintf('  %s', $entry['repository']));

            $list[$url] = refresh($entry, $repositories[strtolower(repositorySlug($entry['repository']))] ?? null);
        }
    }
    unset($list);

    $config['bundles'] = sortByName($bundles);
    $config['discovered'] = sortByName($discovered);
    $config['ignored'] = array_values($config['ignored'] ?? []);

    $yaml = FILE_HEADER.Yaml::dump($config, 4, 4, Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE);

    if ($dryRun) {
        echo $yaml;
    } else {
        file_put_contents(CONFIG_FILE, $yaml);
    }

    stderr('');
    stderr(sprintf('%d listed, %d awaiting review.', count($config['bundles']), count($config['discovered'])));

    if ($added) {
        stderr('');
        stderr(sprintf('Newly discovered (review before moving into `bundles`):%s  - %s', PHP_EOL, implode(PHP_EOL.'  - ', $added)));
    }

    return 0;
}

function loadConfig(): array
{
    if (!file_exists(CONFIG_FILE)) {
        return ['bundles' => [], 'discovered' => [], 'ignored' => []];
    }

    return Yaml::parseFile(CONFIG_FILE) ?? [];
}

/**
 * Bring one entry up to date. $repository is the GitHub search result, if the
 * repository was returned by one of the searches.
 */
function refresh(array $entry, ?array $repository): array
{
    if (null === $repository) {
        $repository = github('repos/'.repositorySlug($entry['repository']));
    }

    if (null === $repository) {
        stderr('    ! repository not found on GitHub, leaving as-is');

        return $entry;
    }

    $package = $entry['package'] ?? packageName($repository['full_name']);
    $packagist = null !== $package ? packagist($package) : null;

    $fresh = [
        'description' => trim((string) ($repository['description'] ?? '')) ?: null,
        'version' => null !== $packagist ? latestStableVersion(array_keys($packagist['versions'] ?? [])) : null,
        'updated' => substr((string) $repository['pushed_at'], 0, 10),
        'stars' => (int) $repository['stargazers_count'],
        'archived' => (bool) $repository['archived'],
        'abandoned' => null !== $packagist && ($packagist['abandoned'] ?? null) !== null,
    ];

    foreach (MANAGED_FIELDS as $field) {
        // Description is seeded once, then left to the maintainer.
        if ('description' === $field && isset($entry['description'])) {
            continue;
        }

        $entry[$field] = $fresh[$field];
    }

    $entry['package'] = $package;
    $entry['repository'] = $repository['html_url'];

    return orderKeys($entry);
}

function isDiscoverable(array $repository): bool
{
    if (in_array(TOPIC, $repository['topics'] ?? [], true)) {
        return true;
    }

    foreach (NAME_PATTERNS as $pattern) {
        if (!preg_match($pattern, $repository['name'])) {
            return false;
        }
    }

    return true;
}

/**
 * @return array<int, array>
 */
function searchRepositories(string $query): array
{
    $repositories = [];

    for ($page = 1; $page <= 10; ++$page) {
        $response = github('search/repositories?'.http_build_query([
            'q' => $query,
            'per_page' => 100,
            'page' => $page,
        ]));

        if (null === $response || empty($response['items'])) {
            break;
        }

        $repositories = array_merge($repositories, $response['items']);

        if (count($repositories) >= (int) $response['total_count']) {
            break;
        }
    }

    return $repositories;
}

/**
 * Read the Composer package name out of a repository's composer.json.
 */
function packageName(string $slug): ?string
{
    $response = github('repos/'.$slug.'/contents/composer.json');

    if (null === $response || !isset($response['content'])) {
        return null;
    }

    $composer = json_decode((string) base64_decode($response['content'], true), true);

    return $composer['name'] ?? null;
}

function packagist(string $package): ?array
{
    $response = get('https://packagist.org/packages/'.$package.'.json');

    return $response['package'] ?? null;
}

function latestStableVersion(array $versions): ?string
{
    $stable = array_values(array_filter($versions, static function (string $version): bool {
        return (bool) preg_match('/^v?\d+(\.\d+)*$/', $version);
    }));

    if (!$stable) {
        return null;
    }

    usort($stable, 'version_compare');

    return (string) end($stable);
}

function github(string $path): ?array
{
    $headers = ['Accept: application/vnd.github.mercy-preview+json'];

    if ($token = getenv('GITHUB_TOKEN')) {
        $headers[] = 'Authorization: token '.$token;
    }

    return get('https://api.github.com/'.$path, $headers);
}

function get(string $url, array $headers = []): ?array
{
    static $handle;

    $handle = $handle ?: curl_init();

    curl_setopt_array($handle, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERAGENT => USER_AGENT,
        CURLOPT_HTTPHEADER => $headers,
    ]);

    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);

    if (false === $body) {
        stderr(sprintf('    ! %s: %s', $url, curl_error($handle)));

        return null;
    }

    if (403 === $status || 429 === $status) {
        stderr('    ! rate limited, sleeping for 60s');
        sleep(60);

        return get($url, $headers);
    }

    if (200 !== $status) {
        return null;
    }

    return json_decode((string) $body, true);
}

/**
 * @param array<int, array> $entries
 *
 * @return array<string, array>
 */
function indexByRepository(array $entries): array
{
    $indexed = [];

    foreach ($entries as $entry) {
        if (isset($entry['repository'])) {
            $indexed[normaliseRepositoryUrl($entry['repository'])] = $entry;
        }
    }

    return $indexed;
}

/**
 * The file is kept in alphabetical order so that it stays easy to scan and so
 * that a refresh produces a minimal diff. Display order is a separate concern,
 * handled in source/_views/includes/bundles.html.
 */
function sortByName(array $entries): array
{
    $entries = array_values($entries);

    usort($entries, static function (array $a, array $b): int {
        return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
    });

    return $entries;
}

function orderKeys(array $entry): array
{
    $order = ['name', 'package', 'repository', 'description', 'version', 'updated', 'stars', 'archived', 'abandoned'];

    $ordered = [];
    foreach ($order as $key) {
        if (array_key_exists($key, $entry)) {
            $ordered[$key] = $entry[$key];
        }
    }

    return $ordered + $entry;
}

function normaliseRepositoryUrl(string $url): string
{
    return rtrim(strtolower($url), '/');
}

function repositorySlug(string $url): string
{
    return trim((string) parse_url($url, PHP_URL_PATH), '/');
}

/**
 * "sculpin-twig-markdown-bundle" => "Twig Markdown Bundle"
 * "SculpinRelatedContentBundle" => "Related Content Bundle"
 */
function humanise(string $name): string
{
    if (!preg_match('/[-_]/', $name)) {
        $name = (string) preg_replace('/(?<!^)([A-Z])/', ' $1', $name);
    }

    $words = preg_split('/[-_\s]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];

    $words = array_values(array_filter($words, static function (string $word): bool {
        return 0 !== strcasecmp($word, 'sculpin');
    }));

    return ucwords(implode(' ', $words));
}

function stderr(string $message): void
{
    fwrite(STDERR, $message.PHP_EOL);
}
