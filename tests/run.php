<?php

declare(strict_types=1);

use Grav\Common\Grav;
use Grav\Common\Page\Page;
use Grav\Plugin\Podcast2Plugin;
use RocketTheme\Toolbox\Event\Event;
use Symfony\Component\Yaml\Yaml;

require __DIR__ . '/bootstrap.php';

/**
 * Podcast2 test double with a deterministic streamed transport.
 */
final class Podcast2FakeTransportPlugin extends Podcast2Plugin
{
    public bool $failTransfer = false;
    public bool $oversizedTransfer = false;
    public int $transferBytes = 8 * 1024 * 1024;

    /** @inheritDoc */
    protected function validateRemoteUrl(string $url): ?array
    {
        return str_starts_with($url, 'https://audio.example.test/') ? ['resolve' => null] : null;
    }

    /** @inheritDoc */
    protected function performRemoteTransfer(string $url, $handle, array $target): array
    {
        if ($this->oversizedTransfer) {
            return ['result' => false, 'status' => 200, 'error' => CURLE_FILESIZE_EXCEEDED, 'location' => null];
        }

        $chunk = str_repeat('P', 65536);
        $remaining = $this->failTransfer ? 262144 : $this->transferBytes;
        while ($remaining > 0) {
            $length = min($remaining, strlen($chunk));
            if (fwrite($handle, $chunk, $length) !== $length) {
                throw new RuntimeException('Fake transport could not write its payload.');
            }
            $remaining -= $length;
        }

        return $this->failTransfer
            ? ['result' => false, 'status' => 0, 'error' => 7, 'location' => null]
            : ['result' => true, 'status' => 200, 'error' => 0, 'location' => null];
    }

    /**
     * Expose production cURL options for assertions.
     *
     * @param resource $handle Writable stream.
     * @param string|null $location Captured Location header.
     * @return array<int, mixed>
     */
    public function curlOptionsForTest($handle, ?string &$location): array
    {
        return $this->buildCurlOptions(
            'https://audio.example.test/file.mp3',
            $handle,
            ['resolve' => 'audio.example.test:443:203.0.113.10'],
            $location
        );
    }
}

/**
 * Podcast2 test double that counts getID3 analysis calls.
 */
final class Podcast2CountingAnalysisPlugin extends Podcast2Plugin
{
    public int $analysisCalls = 0;

    /** @inheritDoc */
    protected function analyzeAudioFile(string $file): array
    {
        $this->analysisCalls++;
        return parent::analyzeAudioFile($file);
    }
}

/**
 * Podcast2 test double with deterministic system-resolver answers.
 */
final class Podcast2ResolverPlugin extends Podcast2Plugin
{
    /** @var array<string, list<string>|null> */
    public array $systemAnswers = [];

    /** @inheritDoc */
    protected function resolveSystemHostAddresses(string $host): ?array
    {
        if (array_key_exists($host, $this->systemAnswers)) {
            return $this->systemAnswers[$host];
        }

        return parent::resolveSystemHostAddresses($host);
    }
}

/**
 * Return every relative file and directory path below a directory.
 *
 * @return array<string, bool>
 */
function podcast2TreeSnapshot(string $root): array
{
    if (!is_dir($root)) {
        return [];
    }
    $paths = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $entry) {
        $relative = substr($entry->getPathname(), strlen($root) + 1);
        $paths[$relative] = $entry->isDir();
    }

    return $paths;
}

/**
 * Remove image-cache paths created during the test run, preserving prior files.
 */
function podcast2RemoveNewTreePaths(string $root, array $before): void
{
    $after = podcast2TreeSnapshot($root);
    $newFiles = [];
    $newDirectories = [];
    foreach (array_diff_key($after, $before) as $relative => $directory) {
        if ($directory) {
            $newDirectories[] = $relative;
        } else {
            $newFiles[] = $relative;
        }
    }
    foreach ($newFiles as $relative) {
        $path = $root . '/' . $relative;
        if (is_file($path) && !unlink($path)) {
            throw new RuntimeException("Cannot remove generated image {$path}");
        }
    }
    usort($newDirectories, static fn(string $left, string $right): int => substr_count($right, '/') <=> substr_count($left, '/'));
    foreach ($newDirectories as $relative) {
        $path = $root . '/' . $relative;
        if (is_dir($path) && !rmdir($path)) {
            throw new RuntimeException("Cannot remove generated image directory {$path}");
        }
    }
}

/**
 * Create the temporary Page fixtures used by unit and HTTP cases.
 *
 * @return array<string, string>
 */
function podcast2CreateFixtures(string $token): array
{
    $pages = PODCAST2_TEST_GRAV_ROOT . '/user/pages';
    $channel = $pages . '/97.podcast2-regression-' . $token;
    $ordinary = $pages . '/96.podcast2-ordinary-' . $token;
    foreach ([$channel, $ordinary] as $path) {
        if (file_exists($path)) {
            throw new RuntimeException("Fixture path already exists: {$path}");
        }
    }

    $direct = $channel . '/01.direct-episode';
    $series = $channel . '/02.test-series';
    $nested = $series . '/01.nested-episode';
    $ordinaryItem = $ordinary . '/01.item';
    foreach ([$channel, $direct, $series, $nested, $ordinary, $ordinaryItem] as $path) {
        if (!mkdir($path, 0700) && !is_dir($path)) {
            throw new RuntimeException("Cannot create fixture directory {$path}");
        }
    }

    $channelRoute = '/podcast2-regression-' . $token;
    $ordinaryRoute = '/podcast2-ordinary-' . $token;
    file_put_contents($channel . '/podcast-channel.md', <<<YAML
---
title: Podcast2 Regression Channel
published: true
routes:
  default: {$channelRoute}
content:
  rss: true
  items: '@self.descendants'
podcast:
  title: Podcast2 Regression Channel
  link: http://127.0.0.1/
  description: Dependency-free Podcast2 integration fixture.
  channelLanguage: en-US
  copyright: Test
  itunes:
    subtitle: Test channel
    author: Test Author
    summary: Test channel summary
    owner:
      name: Test Owner
      email: test@example.test
    category: Arts
    explicit: 'no'
---

Regression channel.
YAML);

    file_put_contents($series . '/podcast-series.md', <<<YAML
---
title: Test Series
published: true
content:
  rss: true
  items: '@self.children'
series: {  }
---

Regression series.
YAML);

    $episodes = [
        [$direct, 'Direct Episode', $channelRoute . '/direct-episode/audio.wav?download=1&name=episode', 'direct-guid', '2026-01-02T12:00:00+00:00'],
        [$nested, 'Nested Episode', $channelRoute . '/test-series/nested-episode/audio.wav', 'nested-guid', '2026-01-01T12:00:00+00:00'],
    ];
    foreach ($episodes as [$directory, $title, $audioRoute, $guid, $date]) {
        podcast2WriteWave($directory . '/audio.wav');
        $size = filesize($directory . '/audio.wav');
        file_put_contents($directory . '/podcast-episode.md', <<<YAML
---
title: {$title}
published: true
date: '{$date}'
podcast:
  guid: {$guid}
  audio:
    local:
      select: audio.wav
    meta:
      guid: {$audioRoute}
      type: audio/wav
      enclosure_length: {$size}
      duration: '0:01'
  itunes:
    author: Test Author
    subtitle: {$title}
    explicit: 'no'
---

{$title} notes.
YAML);
    }

    file_put_contents($ordinary . '/blog.md', <<<YAML
---
title: Ordinary Feed
published: true
routes:
  default: {$ordinaryRoute}
content:
  rss: true
  items: '@self.children'
feed:
  description: Ordinary regression feed
---

Ordinary feed page.
YAML);
    file_put_contents($ordinaryItem . '/item.md', <<<YAML
---
title: Ordinary Item
published: true
date: '2026-01-01T12:00:00+00:00'
---

Ordinary feed item.
YAML);

    return [
        'channel' => $channel,
        'ordinary' => $ordinary,
        'direct_page' => $direct . '/podcast-episode.md',
        'channel_page' => $channel . '/podcast-channel.md',
        'channel_route' => $channelRoute,
        'ordinary_route' => $ordinaryRoute,
    ];
}

/**
 * Perform a bounded HTTP request against the fixture server.
 *
 * @return array{status: int, type: string, body: string}
 */
function podcast2HttpGet(string $url): array
{
    $curl = curl_init($url);
    if ($curl === false) {
        throw new RuntimeException('Cannot initialize fixture request.');
    }
    try {
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_PROXY => '',
        ]);
        $body = curl_exec($curl);
        if ($body === false) {
            throw new RuntimeException('Fixture request failed: ' . curl_error($curl));
        }

        return [
            'status' => (int) curl_getinfo($curl, CURLINFO_HTTP_CODE),
            'type' => (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE),
            'body' => $body,
        ];
    } finally {
        curl_close($curl);
    }
}

/**
 * Stop a PHP fixture server within a bounded interval.
 */
function podcast2StopServer(mixed &$server): void
{
    if (!is_resource($server)) {
        return;
    }
    $status = proc_get_status($server);
    if ($status['running']) {
        proc_terminate($server);
        for ($attempt = 0; $attempt < 20; $attempt++) {
            usleep(50000);
            $status = proc_get_status($server);
            if (!$status['running']) {
                break;
            }
        }
        if ($status['running']) {
            proc_terminate($server, 9);
        }
    }
    proc_close($server);
    $server = null;
}

$harness = new Podcast2TestHarness();
$rootRepository = dirname(PODCAST2_TEST_GRAV_ROOT);
$sourceState = podcast2GitState(PODCAST2_TEST_SOURCE);
$rootState = podcast2GitState($rootRepository);
$imageState = podcast2TreeSnapshot(PODCAST2_TEST_GRAV_ROOT . '/images');
$token = bin2hex(random_bytes(5));
$fixtures = podcast2CreateFixtures($token);
$server = null;
$cleanupError = null;
$grav = Grav::instance();
$plugin = new Podcast2Plugin('podcast2', $grav, $grav['config']);
$fakePlugin = new Podcast2FakeTransportPlugin('podcast2', $grav, $grav['config']);
$countingPlugin = new Podcast2CountingAnalysisPlugin('podcast2', $grav, $grav['config']);
$resolverPlugin = new Podcast2ResolverPlugin('podcast2', $grav, $grav['config']);
register_shutdown_function(static function () use (&$server, $fixtures, $imageState): void {
    try {
        podcast2StopServer($server);
        podcast2RemoveTree($fixtures['channel']);
        podcast2RemoveTree($fixtures['ordinary']);
        podcast2RemoveNewTreePaths(PODCAST2_TEST_GRAV_ROOT . '/images', $imageState);
        podcast2RemoveTree(PODCAST2_TEST_RUNTIME);
    } catch (Throwable) {
        // The normal runner reports cleanup failures; shutdown cleanup is best effort.
    }
});

try {
    $harness->test('complete PHPDoc coverage', static function (): void {
        Podcast2TestHarness::same(
            file_get_contents(PODCAST2_TEST_SOURCE . '/podcast2.php'),
            file_get_contents(PODCAST2_TEST_GRAV_ROOT . '/user/plugins/podcast2/podcast2.php'),
            'Installed Podcast2 runtime does not match the authoritative source'
        );
        $class = new ReflectionClass(Podcast2Plugin::class);
        Podcast2TestHarness::assert($class->getDocComment() !== false, 'Podcast2Plugin class PHPDoc is missing');
        foreach ($class->getReflectionConstants() as $constant) {
            if ($constant->getDeclaringClass()->getName() === $class->getName()) {
                Podcast2TestHarness::assert($constant->getDocComment() !== false, "PHPDoc missing for {$constant->getName()}");
            }
        }
        foreach ($class->getProperties() as $property) {
            if ($property->getDeclaringClass()->getName() === $class->getName()) {
                $doc = $property->getDocComment();
                Podcast2TestHarness::assert($doc !== false && str_contains($doc, '@var'), "PHPDoc missing for \${$property->getName()}");
            }
        }
        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() !== $class->getName()) {
                continue;
            }
            $doc = $method->getDocComment();
            Podcast2TestHarness::assert($doc !== false, "PHPDoc missing for {$method->getName()}()");
            Podcast2TestHarness::assert(str_contains($doc, '@return'), "@return missing for {$method->getName()}()");
            foreach ($method->getParameters() as $parameter) {
                Podcast2TestHarness::assert(
                    preg_match('/@param\s+[^\r\n]*\$' . preg_quote($parameter->getName(), '/') . '\b/', $doc) === 1,
                    "@param missing for {$method->getName()}(\${$parameter->getName()})"
                );
            }
        }
    });

    $harness->test('manifest and feed blueprint defaults', static function (): void {
        $manifest = Yaml::parseFile(PODCAST2_TEST_SOURCE . '/blueprints.yaml');
        Podcast2TestHarness::same('4.0.2', $manifest['version'] ?? null, 'Plugin version mismatch');
        Podcast2TestHarness::same(['2.0'], $manifest['compatibility']['grav'] ?? null, 'Grav compatibility mismatch');
        Podcast2TestHarness::assert(!array_key_exists('gpm', $manifest), 'Unsupported gpm key remains');
        Podcast2TestHarness::same('>=2.0.0', $manifest['dependencies'][0]['version'] ?? null, 'Grav dependency mismatch');
        foreach (['podcast-channel', 'podcast-series'] as $type) {
            $blueprint = Yaml::parseFile(PODCAST2_TEST_SOURCE . "/blueprints/{$type}.yaml");
            $default = $blueprint['form']['fields']['tabs']['fields']['content']['fields']['header.feed.template.rss']['default'] ?? null;
            Podcast2TestHarness::same('podcast-feed', $default, "{$type} feed template default mismatch");
        }
        Podcast2TestHarness::assert(!file_exists(PODCAST2_TEST_SOURCE . '/templates/feed.rss.twig'), 'Generic RSS template remains');
        $template = file_get_contents(PODCAST2_TEST_SOURCE . '/templates/podcast-feed.rss.twig');
        Podcast2TestHarness::assert(str_contains($template, ".ofType('podcast-episode')"), 'Podcast feed episode filter is incorrect');
        Podcast2TestHarness::assert(str_contains($template, "'@self.children' : '@self.descendants'"), 'Series/direct scoping is missing');
        Podcast2TestHarness::assert(!str_contains($template, 'xmlns:atom='), 'Generic Feed fallback remains in podcast template');

        $episodeTemplate = file_get_contents(PODCAST2_TEST_SOURCE . '/templates/podcast-episode.html.twig');
        Podcast2TestHarness::assert(
            str_contains($episodeTemplate, "header.podcast.audio.meta.guid|e('html_attr')"),
            'Episode download URL is not escaped as an HTML attribute'
        );

        $readme = file_get_contents(PODCAST2_TEST_SOURCE . '/README.md');
        Podcast2TestHarness::assert(!str_contains($readme, '[Admin]('), 'Classic Admin is still listed as a requirement');
        Podcast2TestHarness::assert(str_contains($readme, "system resolver"), 'System resolver behavior is not documented');
    });

    $harness->test('regular Page local-audio save', static function () use ($plugin, $fixtures): void {
        $page = new Page();
        $page->init(new SplFileInfo($fixtures['direct_page']));
        $page->route($fixtures['channel_route'] . '/direct-episode');
        $plugin->onAdminSave(new Event(['object' => $page]));
        $header = (array) $page->header();
        $meta = $header['podcast']['audio']['meta'] ?? [];
        Podcast2TestHarness::same($fixtures['channel_route'] . '/direct-episode/audio.wav', $meta['guid'] ?? null, 'Local enclosure route mismatch');
        Podcast2TestHarness::assert(!str_contains((string) ($meta['guid'] ?? ''), '\\'), 'Local enclosure contains backslashes');
        Podcast2TestHarness::assert(($meta['enclosure_length'] ?? 0) > 0, 'Local enclosure size missing');
        $local = $header['podcast']['audio']['local'] ?? [];
        Podcast2TestHarness::same('audio.wav', $local['select'] ?? null, 'Local selection was not retained');
        $paths = array_column(array_filter($local, 'is_array'), 'path');
        Podcast2TestHarness::assert(in_array($meta['guid'], $paths, true), 'Backward-compatible local media path missing');
    });

    $harness->test('audio metadata uses one getID3 analysis per save', static function () use ($countingPlugin, $fixtures): void {
        $page = new Page();
        $page->init(new SplFileInfo($fixtures['direct_page']));
        $page->route($fixtures['channel_route'] . '/direct-episode');
        $countingPlugin->onAdminSave(new Event(['object' => $page]));
        Podcast2TestHarness::same(1, $countingPlugin->analysisCalls, 'Audio file was analyzed more than once');
    });

    $harness->test('legacy Feed fields preserve template configuration', static function () use ($plugin, $fixtures): void {
        $page = new Page();
        $page->init(new SplFileInfo($fixtures['channel_page']));
        $page->header([
            'title' => 'Legacy Channel',
            'feed' => ['rss' => true, 'items' => '@self.children', 'template' => ['rss' => 'podcast-feed'], 'limit' => 20],
            'content' => ['order' => ['by' => 'date']],
        ]);
        $plugin->onAdminSave(new Event(['object' => $page]));
        $header = (array) $page->header();
        Podcast2TestHarness::same(true, $header['content']['rss'] ?? null, 'Legacy rss field not migrated');
        Podcast2TestHarness::same('@self.children', $header['content']['items'] ?? null, 'Legacy items field not migrated');
        Podcast2TestHarness::same('podcast-feed', $header['feed']['template']['rss'] ?? null, 'Feed template was lost');
        Podcast2TestHarness::same(20, $header['feed']['limit'] ?? null, 'Unrelated Feed setting was lost');
    });

    $harness->test('configured source survives local and remote failures', static function () use ($plugin, $fakePlugin, $fixtures): void {
        $page = new Page();
        $page->init(new SplFileInfo($fixtures['direct_page']));
        $page->header(['title' => 'Missing Local', 'podcast' => ['audio' => ['local' => ['select' => 'missing.wav'], 'meta' => ['guid' => '/stale']]]]);
        $plugin->onAdminSave(new Event(['object' => $page]));
        $header = (array) $page->header();
        Podcast2TestHarness::same('missing.wav', $header['podcast']['audio']['local']['select'] ?? null, 'Missing local selection was erased');
        Podcast2TestHarness::assert(!isset($header['podcast']['audio']['meta']), 'Stale local metadata remains');

        $fakePlugin->failTransfer = true;
        $page->header(['title' => 'Failed Remote', 'podcast' => ['audio' => ['remote' => 'https://audio.example.test/fail.mp3', 'meta' => ['guid' => '/stale']]]]);
        $before = glob(PODCAST2_TEST_RUNTIME . '/tmp/podcast*') ?: [];
        $fakePlugin->onAdminSave(new Event(['object' => $page]));
        $after = glob(PODCAST2_TEST_RUNTIME . '/tmp/podcast*') ?: [];
        $header = (array) $page->header();
        Podcast2TestHarness::same('https://audio.example.test/fail.mp3', $header['podcast']['audio']['remote'] ?? null, 'Remote URL was erased');
        Podcast2TestHarness::assert(!isset($header['podcast']['audio']['meta']), 'Stale remote metadata remains');
        Podcast2TestHarness::same($before, $after, 'Failed remote transfer leaked a temporary file');

        $fakePlugin->failTransfer = false;
        $fakePlugin->oversizedTransfer = true;
        $page->header(['title' => 'Oversized Remote', 'podcast' => ['audio' => ['remote' => 'https://audio.example.test/oversized.mp3', 'meta' => ['guid' => '/stale']]]]);
        $fakePlugin->onAdminSave(new Event(['object' => $page]));
        $header = (array) $page->header();
        Podcast2TestHarness::same('https://audio.example.test/oversized.mp3', $header['podcast']['audio']['remote'] ?? null, 'Oversized remote URL was erased');
        Podcast2TestHarness::assert(!isset($header['podcast']['audio']['meta']), 'Oversized response left stale metadata');
        $fakePlugin->oversizedTransfer = false;
    });

    $harness->test('incomplete getID3 results are nullable', static function () use ($plugin): void {
        $empty = PODCAST2_TEST_RUNTIME . '/empty-audio';
        file_put_contents($empty, '');
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });
        try {
            Podcast2TestHarness::same(null, Podcast2Plugin::retreiveAudioLength($empty), 'Empty audio returned a length');
            Podcast2TestHarness::same(null, Podcast2Plugin::retreiveAudioType($empty), 'Empty audio returned a MIME type');
            Podcast2TestHarness::same(null, Podcast2Plugin::retreiveAudioDuration($empty), 'Empty audio returned a duration');
            $method = new ReflectionMethod($plugin, 'buildAudioMetadata');
            $method->setAccessible(true);
            Podcast2TestHarness::same(null, $method->invoke($plugin, $empty), 'Incomplete metadata was persisted');
        } finally {
            restore_error_handler();
        }
    });

    $harness->test('remote URL and redirect policy', static function () use ($plugin): void {
        $validate = new ReflectionMethod($plugin, 'validateRemoteUrl');
        $validate->setAccessible(true);
        foreach ([
            'file:///etc/passwd', 'ftp://8.8.8.8/file', 'https://user:pass@8.8.8.8/file',
            'http://127.0.0.1/file', 'http://10.0.0.1/file', 'http://169.254.1.1/file',
            'http://0.0.0.0/file', 'http://224.0.0.1/file', 'http://192.0.2.1/file',
            'http://[::1]/file', 'http://[fc00::1]/file', 'http://[fe80::1]/file',
            'http://[::]/file', 'http://[ff02::1]/file', 'http://[fec0::1]/file',
            'http://0x7f000001/file', 'http://2130706433/file',
            'http://metadata.google.internal/file', "http://example.test\r\n.invalid/file",
        ] as $url) {
            Podcast2TestHarness::same(null, $validate->invoke($plugin, $url), "Unsafe URL accepted: {$url}");
        }
        Podcast2TestHarness::same(['resolve' => null], $validate->invoke($plugin, 'https://8.8.8.8/audio.mp3'), 'Public HTTPS literal rejected');

        $redirect = new ReflectionMethod($plugin, 'resolveRedirectUrl');
        $redirect->setAccessible(true);
        Podcast2TestHarness::same(
            'https://8.8.8.8/next/audio.mp3',
            $redirect->invoke($plugin, 'https://8.8.8.8/base/file.mp3', '../next/audio.mp3'),
            'Relative redirect resolution mismatch'
        );
        $unsafe = $redirect->invoke($plugin, 'https://8.8.8.8/base/file.mp3', 'http://127.0.0.1/private');
        Podcast2TestHarness::same(null, $validate->invoke($plugin, $unsafe), 'Unsafe redirect target accepted');
    });

    $harness->test('system resolver addresses are validated and pinned', static function () use ($resolverPlugin): void {
        $resolverPlugin->systemAnswers = [
            'split.example.test' => ['8.8.8.8', '2001:4860:4860::8888'],
            'private.example.test' => ['127.0.0.1'],
            'mixed.example.test' => ['8.8.8.8', '10.0.0.1'],
        ];
        $validate = new ReflectionMethod($resolverPlugin, 'validateRemoteUrl');
        $validate->setAccessible(true);
        Podcast2TestHarness::same(
            ['resolve' => 'split.example.test:443:8.8.8.8,[2001:4860:4860::8888]'],
            $validate->invoke($resolverPlugin, 'https://split.example.test/audio.mp3'),
            'System-resolved public addresses were not pinned'
        );
        Podcast2TestHarness::same(
            null,
            $validate->invoke($resolverPlugin, 'https://private.example.test/audio.mp3'),
            'System-resolved private address was accepted'
        );
        Podcast2TestHarness::same(
            null,
            $validate->invoke($resolverPlugin, 'https://mixed.example.test/audio.mp3'),
            'Mixed public and private system-resolver answers were accepted'
        );
        Podcast2TestHarness::same(null, $validate->invoke($resolverPlugin, 'http://localhost/audio.mp3'), 'Localhost was accepted');
    });

    $harness->test('bounded cURL configuration', static function () use ($fakePlugin): void {
        $handle = fopen('php://temp', 'w+b');
        Podcast2TestHarness::assert(is_resource($handle), 'Cannot open cURL option test stream');
        $location = null;
        try {
            $options = $fakePlugin->curlOptionsForTest($handle, $location);
            Podcast2TestHarness::same(CURLPROTO_HTTP | CURLPROTO_HTTPS, $options[CURLOPT_PROTOCOLS] ?? null, 'Initial protocol mask mismatch');
            Podcast2TestHarness::same(CURLPROTO_HTTP | CURLPROTO_HTTPS, $options[CURLOPT_REDIR_PROTOCOLS] ?? null, 'Redirect protocol mask mismatch');
            Podcast2TestHarness::same(10, $options[CURLOPT_CONNECTTIMEOUT] ?? null, 'Connection timeout mismatch');
            Podcast2TestHarness::same(60, $options[CURLOPT_TIMEOUT] ?? null, 'Transfer timeout mismatch');
            Podcast2TestHarness::same(5, $options[CURLOPT_MAXREDIRS] ?? null, 'Redirect limit mismatch');
            Podcast2TestHarness::same(512 * 1024 * 1024, $options[CURLOPT_MAXFILESIZE_LARGE] ?? null, 'File-size limit mismatch');
            Podcast2TestHarness::same(false, $options[CURLOPT_NOPROGRESS] ?? null, 'Transfer progress callback is disabled');
            Podcast2TestHarness::same(false, $options[CURLOPT_FOLLOWLOCATION] ?? null, 'Automatic redirects must be disabled');
            Podcast2TestHarness::same('', $options[CURLOPT_PROXY] ?? null, 'Proxy must be disabled');
            Podcast2TestHarness::assert(isset($options[CURLOPT_RESOLVE]), 'Validated DNS pin is missing');
            $callback = $options[CURLOPT_HEADERFUNCTION];
            $callback(null, "Location: /next.mp3\r\n");
            Podcast2TestHarness::same('/next.mp3', $location, 'Location header was not captured');
            $progress = $options[CURLOPT_XFERINFOFUNCTION];
            Podcast2TestHarness::same(0, $progress(null, 0, 512 * 1024 * 1024, 0, 0), 'Transfer stopped at the byte limit');
            Podcast2TestHarness::same(1, $progress(null, 0, 512 * 1024 * 1024 + 1, 0, 0), 'Oversized transfer was not stopped');
        } finally {
            fclose($handle);
        }
    });

    $harness->test('streamed remote transfer has bounded memory and cleanup', static function () use ($fakePlugin): void {
        $fakePlugin->failTransfer = false;
        $fakePlugin->transferBytes = 8 * 1024 * 1024;
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }
        $baseline = memory_get_usage(true);
        $path = $fakePlugin->getRemoteAudio('https://audio.example.test/large.mp3');
        $peakIncrease = memory_get_peak_usage(true) - $baseline;
        Podcast2TestHarness::assert(is_string($path) && is_file($path), 'Streamed transfer did not return a file');
        try {
            Podcast2TestHarness::same($fakePlugin->transferBytes, filesize($path), 'Streamed file size mismatch');
            Podcast2TestHarness::assert($peakIncrease < 3 * 1024 * 1024, "Streaming used too much memory: {$peakIncrease} bytes");
        } finally {
            unlink($path);
        }
    });

    $harness->test('Grav HTTP integration and feed isolation', static function () use (&$server, $fixtures): void {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorNumber, $errorMessage);
        if ($socket === false) {
            throw new RuntimeException("Cannot reserve localhost port: {$errorMessage}");
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        $port = (int) substr(strrchr($address, ':'), 1);
        $serverLog = PODCAST2_TEST_RUNTIME . '/server.log';
        $environment = getenv();
        $environment['GRAV_CACHE_PATH'] = PODCAST2_TEST_RUNTIME . '/cache';
        $environment['GRAV_LOG_PATH'] = PODCAST2_TEST_RUNTIME . '/logs';
        $environment['GRAV_TMP_PATH'] = PODCAST2_TEST_RUNTIME . '/tmp';
        $environment['GRAV_DOTENV_DISABLE'] = '1';
        $server = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$port}", 'system/router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'ab'], 2 => ['file', $serverLog, 'ab']],
            $pipes,
            PODCAST2_TEST_GRAV_ROOT,
            $environment
        );
        if (!is_resource($server)) {
            throw new RuntimeException('Cannot start the Grav fixture server.');
        }
        fclose($pipes[0]);

        $base = "http://127.0.0.1:{$port}";
        $ready = false;
        for ($attempt = 0; $attempt < 40; $attempt++) {
            usleep(100000);
            try {
                $response = podcast2HttpGet($base . $fixtures['channel_route']);
                if ($response['status'] === 200) {
                    $ready = true;
                    break;
                }
            } catch (Throwable) {
                // Server startup is bounded by the loop.
            }
        }
        Podcast2TestHarness::assert($ready, 'Grav fixture server did not become ready');

        $routes = [
            $fixtures['channel_route'] => 'text/html',
            $fixtures['channel_route'] . '/test-series' => 'text/html',
            $fixtures['channel_route'] . '/direct-episode' => 'text/html',
            $fixtures['channel_route'] . '/test-series/nested-episode' => 'text/html',
            $fixtures['channel_route'] . '.rss' => 'application/rss+xml',
            $fixtures['channel_route'] . '/test-series.rss' => 'application/rss+xml',
            $fixtures['ordinary_route'] . '.rss' => 'application/rss+xml',
            '/user/plugins/podcast2/assets/css/podcast.css' => 'text/css',
        ];
        $responses = [];
        foreach ($routes as $route => $type) {
            $responses[$route] = podcast2HttpGet($base . $route);
            Podcast2TestHarness::same(200, $responses[$route]['status'], "HTTP status mismatch for {$route}");
            Podcast2TestHarness::assert(str_starts_with($responses[$route]['type'], $type), "Content type mismatch for {$route}");
        }

        foreach ([$fixtures['channel_route'] . '.rss' => 2, $fixtures['channel_route'] . '/test-series.rss' => 1] as $route => $count) {
            $document = new DOMDocument();
            Podcast2TestHarness::assert($document->loadXML($responses[$route]['body']), "Invalid XML for {$route}");
            Podcast2TestHarness::same($count, $document->getElementsByTagName('item')->length, "Episode scope mismatch for {$route}");
            foreach ($document->getElementsByTagName('enclosure') as $enclosure) {
                $url = $enclosure->getAttribute('url');
                Podcast2TestHarness::assert(str_starts_with($url, $base . $fixtures['channel_route']), "Enclosure URL mismatch for {$route}");
                Podcast2TestHarness::assert(!str_contains($url, '\\'), "Backslash in enclosure URL for {$route}");
            }
        }
        $ordinary = $responses[$fixtures['ordinary_route'] . '.rss']['body'];
        Podcast2TestHarness::assert(str_contains($ordinary, 'xmlns:atom='), 'Ordinary feed did not use Feed template');
        Podcast2TestHarness::assert(!str_contains($ordinary, 'xmlns:itunes='), 'Podcast2 shadowed ordinary feed');

        $episode = $responses[$fixtures['channel_route'] . '/direct-episode']['body'];
        $episodeDocument = new DOMDocument();
        Podcast2TestHarness::assert($episodeDocument->loadHTML($episode), 'Rendered episode HTML could not be parsed');
        $audio = $episodeDocument->getElementsByTagName('audio')->item(0);
        Podcast2TestHarness::assert($audio instanceof DOMElement, 'Rendered episode audio element is missing');
        Podcast2TestHarness::assert(str_contains($audio->getAttribute('src'), 'audio.wav'), 'Rendered episode audio source is incorrect');
        $download = $audio->getElementsByTagName('a')->item(0);
        Podcast2TestHarness::assert($download instanceof DOMElement, 'Rendered episode download link is missing');
        Podcast2TestHarness::same($audio->getAttribute('src'), $download->getAttribute('href'), 'Rendered audio and download URLs differ');

        podcast2StopServer($server);
        $log = is_file($serverLog) ? file_get_contents($serverLog) : '';
        Podcast2TestHarness::assert(
            preg_match('/fatal|warning|deprecated|undefined|twig.*error|exception/i', $log) !== 1,
            'Server log contains a PHP or Twig problem'
        );
    });
} finally {
    try {
        podcast2StopServer($server);
        podcast2RemoveTree($fixtures['channel']);
        podcast2RemoveTree($fixtures['ordinary']);
        podcast2RemoveNewTreePaths(PODCAST2_TEST_GRAV_ROOT . '/images', $imageState);
    } catch (Throwable $error) {
        $cleanupError = $error;
    }
}

$harness->test('fixture cleanup and repository state preservation', static function () use ($cleanupError, $sourceState, $rootState, $rootRepository): void {
    if ($cleanupError !== null) {
        throw $cleanupError;
    }
    Podcast2TestHarness::same($sourceState, podcast2GitState(PODCAST2_TEST_SOURCE), 'Source repository state changed during tests');
    Podcast2TestHarness::same($rootState, podcast2GitState($rootRepository), 'Site repository state changed during tests');
});

$exitCode = $harness->finish();
try {
    podcast2RemoveTree(PODCAST2_TEST_RUNTIME);
} catch (Throwable $error) {
    fwrite(STDERR, "FAIL runtime cleanup: {$error->getMessage()}\n");
    $exitCode = 1;
}
exit($exitCode);
