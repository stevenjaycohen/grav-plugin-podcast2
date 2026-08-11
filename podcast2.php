<?php
namespace Grav\Plugin;

use Grav\Common\Grav;
use Grav\Common\Page\Header;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Common\Plugin;
use RocketTheme\Toolbox\Event\Event;
use RocketTheme\Toolbox\File\File;
use Symfony\Component\Yaml\Yaml;
use Grav\Plugin\GetID3Plugin;

/**
 * Adds podcast Page types, RSS rendering, and audio metadata handling to Grav.
 *
 * @package Grav\Plugin
 */
class Podcast2Plugin extends Plugin
{
    /** @var int Remote connection timeout in seconds. */
    private const REMOTE_CONNECT_TIMEOUT = 10;

    /** @var int Overall remote transfer timeout in seconds. */
    private const REMOTE_TRANSFER_TIMEOUT = 60;

    /** @var int Maximum number of remote HTTP redirects. */
    private const REMOTE_REDIRECT_LIMIT = 5;

    /** @var array<string, int> Grav plugin feature priorities. */
    public $features = [
        'blueprints' => 0, // Use priority 0
    ];

    /**
     * Return the Grav events handled in every request context.
     *
     * @return array<string, array{0: string, 1: int}> Event names mapped to handlers and priorities.
     */
    public static function getSubscribedEvents(): array
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
            // These events must be subscribed in every request context. Admin2
            // performs Page operations through the API, where isAdmin() is false.
            'onGetPageBlueprints' => ['onGetPageBlueprints', 0],
            'onGetPageTemplates' => ['onGetPageTemplates', 0],
            'onAdminSave' => ['onAdminSave', 0],
        ];
    }

    /**
     * Enable frontend-only event handlers.
     *
     * @return void
     */
    public function onPluginsInitialized(): void
    {
        if (!$this->isAdmin()) {
            $this->enable([
                'onPageInitialized' => ['onPageInitialized', 1],
                'onTwigTemplatePaths' => ['onTwigTemplatePaths', 1],
                'onTwigSiteVariables' => ['onTwigSiteVariables', 0],
            ]);
        }
    }

    /**
     * Select the podcast RSS template for pages that predate its blueprint default.
     *
     * @return void
     */
    public function onPageInitialized(): void
    {
        if ($this->grav['uri']->extension() !== 'rss') {
            return;
        }

        $page = $this->grav['page'];
        if (!$page instanceof PageInterface || !in_array($page->template(), ['podcast-channel', 'podcast-series'], true)) {
            return;
        }

        $header = $page->header();
        $feed = isset($header->feed) ? (array) $header->feed : [];
        $templates = isset($feed['template']) ? (array) $feed['template'] : [];
        if (!isset($templates['rss'])) {
            $templates['rss'] = 'podcast-feed';
            $feed['template'] = $templates;
            $header->feed = $feed;
        }
    }

    /**
     * Add the plugin's Page blueprints to Grav's type registry.
     *
     * @param Event $event Page-blueprint discovery event.
     * @return void
     */
    public function onGetPageBlueprints(Event $event): void
    {
        $types = $event->types;
        $locator = Grav::instance()['locator'];
        $types->scanBlueprints($locator->findResource('plugin://' . $this->name . '/blueprints'));
    }

    /**
     * Add the plugin's Page templates to Grav's type registry.
     *
     * @param Event $event Page-template discovery event.
     * @return void
     */
    public function onGetPageTemplates(Event $event): void
    {
        $types = $event->types;
        $locator = Grav::instance()['locator'];
        $types->scanTemplates($locator->findResource('plugin://' . $this->name . '/templates'));
    }

    /**
     * Add the plugin template directory to Twig's lookup paths.
     *
     * @return void
     */
    public function onTwigTemplatePaths(): void
    {
        $this->grav['twig']->twig_paths[] = $this->grav['locator']->findResource('plugin://' . $this->name . '/templates');
    }

    /**
     * Add the podcast stylesheet to frontend pages.
     *
     * @return void
     */
    public function onTwigSiteVariables(): void
    {
        $this->grav['assets']->addCss('plugin://' . $this->name . '/assets/css/podcast.css');
    }

    /**
     * Add collection migrations and getID3 audio metadata before a Page is saved.
     *
     * @param Event $event Admin save event containing the saved object.
     * @return void
     */
    public function onAdminSave(Event $event): void
    {

        /** @var PageInterface $page */
        $page = $event['object'];

        // Process only onAdminSave events on pages.
        if (!$page instanceof PageInterface) {
            return;
        }
        /** @var Header $header */
        $header = $page->header();
        if (!$header instanceof Header) {
            $header = new Header((array) $header);
        }

        if (str_starts_with($page->template(), 'podcast-')) {
            // Set autodate field on all podcast-* page types.
            if (!isset($header->date)) {
                $date = date($this->grav['config']->get('system.pages.dateformat.default', 'H:i d-m-Y'));
                $header['date'] = $date;
            }

            // Migrate only the collection fields used by Podcast before Feed 1.8.2.
            if (isset($header['feed'])) {
                $feed = (array) $header['feed'];
                $content = isset($header['content']) ? (array) $header['content'] : [];

                foreach (['rss', 'items'] as $field) {
                    if (array_key_exists($field, $feed)) {
                        if (!array_key_exists($field, $content)) {
                            $content[$field] = $feed[$field];
                        }
                        unset($feed[$field]);
                    }
                }

                if ($content !== []) {
                    $header['content'] = $content;
                }
                if ($feed === []) {
                    $header->undef('feed');
                } else {
                    $header['feed'] = $feed;
                }
            }
        } else {
            // Refrain from editing pages not of template "podcast-*".
            return;
        }

        // Return with just updated header content if not podcast-episode.
        if ($page->template() != 'podcast-episode') {
            $page->header($header->toArray());
            return;
        }

        // Use local file for meta calculations, if present.
        // Else, use remote file for meta, if present.
        // Else, cleanup media entry in markdown header.

        $local_selection = $header->podcast['audio']['local']['select'] ?? null;
        $remote_url = $header->podcast['audio']['remote'] ?? null;
        $has_local_audio = is_string($local_selection) && $local_selection !== '';
        $has_remote_audio = is_string($remote_url) && trim($remote_url) !== '';

        if ($has_local_audio) {
            $local['select'] = $header->podcast['audio']['local']['select'];
            $media = $page->media()->audios()[$local['select']] ?? null;

            if ($media === null) {
                $this->grav['messages']?->add(
                    "Podcast audio file '{$local['select']}' was not found in the Page media.",
                    'error'
                );
                $header->undef('podcast.audio.meta');
                $page->header($header->toArray());
                return;
            }

            // Create array for backwards compatibility with existing Podcast content.
            $file_path = $media->path();
            $relative_file_path = $media->relativePath();
            $route = str_replace('\\', '/', (string) $page->route());
            $filename = str_replace('\\', '/', $local['select']);
            $file_url = rtrim('/' . trim($route, '/'), '/') . '/' . ltrim($filename, '/');

            try {
                $audio_meta = $this->buildAudioMetadata($file_path);
            } catch (\Throwable $e) {
                $audio_meta = null;
            }

            if ($audio_meta === null) {
                $this->addAudioMetadataError();
                $header->undef('podcast.audio.meta');
                $page->header($header->toArray());
                return;
            }

            $audio_meta['guid'] = $file_url;

            $local_file = [
                'name' => $local['select'],
                'type' => $audio_meta['type'],
                'size' => $audio_meta['enclosure_length'],
                'path' => $file_url,
            ];
            $local[$relative_file_path] = $local_file;
            $header->offsetSet('podcast.audio.local', $local);
        }
        if ($has_remote_audio && !isset($audio_meta)) {
            // Download file from external URL to a temporary location.
            $path = $this->getRemoteAudio($remote_url);

            if ($path) {
                try {
                    $audio_meta = $this->buildAudioMetadata($path);
                } catch (\Throwable $e) {
                    $audio_meta = null;
                } finally {
                    if (is_file($path)) {
                        unlink($path);
                    }
                }

                if ($audio_meta === null) {
                    $this->addAudioMetadataError();
                    $header->undef('podcast.audio.meta');
                    $page->header($header->toArray());
                    return;
                }

                $audio_meta['guid'] = $remote_url;
            } else {
                // Remove previously calculated meta if remote file is not found.
                $header->undef('podcast.audio.meta');
                $page->header($header->toArray());
                return;
            }
        }

        // Reset the guid if using an external file source.
        if ($has_remote_audio && isset($audio_meta)) {
            $audio_meta['guid'] = $remote_url;
        }

        // Prepare $page to return new header data.
        if (isset($audio_meta)) {
            $header->set('podcast.audio.meta', $audio_meta);
        } elseif (!$has_local_audio && !$has_remote_audio) {
            // Cleanup any leftover data if neither local or remote file are set.
            $header->undef('podcast.audio');
        } else {
            $header->undef('podcast.audio.meta');
        }

        $page->header($header->toArray());
        return;
    }

    /**
     * Retrieve an audio file's size from getID3 metadata.
     *
     * @param string $file Filesystem path to the audio file.
     * @return int|null Audio size in bytes, or null when unavailable.
     */
    public static function retreiveAudioLength($file): ?int
    {
        $id3 = GetID3Plugin::analyzeFile($file);
        $filesize = $id3['filesize'] ?? null;

        return is_numeric($filesize) && (int) $filesize > 0 ? (int) $filesize : null;
    }

    /**
     * Retrieve an audio file's MIME type from getID3 metadata.
     *
     * @param string $file Filesystem path to the audio file.
     * @return string|null MIME type, or null when unavailable.
     */
    public static function retreiveAudioType($file): ?string
    {
        $id3 = GetID3Plugin::analyzeFile($file);
        $mime_type = $id3['mime_type'] ?? null;

        return is_string($mime_type) && $mime_type !== '' ? $mime_type : null;
    }

    /**
     * Retrieve an audio file's display duration from getID3 metadata.
     *
     * @param string $file Filesystem path to the audio file.
     * @return string|null Human-readable duration, or null when unavailable.
     */
    public static function retreiveAudioDuration($file): ?string
    {
        $id3 = GetID3Plugin::analyzeFile($file);
        $duration = $id3['playtime_string'] ?? null;

        return is_string($duration) && $duration !== '' ? $duration : null;
    }

    /**
     * Build the required enclosure metadata for an audio file.
     *
     * @param string $file Filesystem path to the audio file.
     * @return array{type: string, enclosure_length: int, duration?: string}|null Enclosure metadata,
     *     or null when required metadata is unavailable.
     */
    private function buildAudioMetadata(string $file): ?array
    {
        $type = $this->retreiveAudioType($file);
        $duration = $this->retreiveAudioDuration($file);
        $length = $this->retreiveAudioLength($file);

        if ($type === null || $length === null) {
            return null;
        }

        $metadata = [
            'type' => $type,
            'enclosure_length' => $length,
        ];
        if ($duration !== null) {
            $metadata['duration'] = $duration;
        }

        return $metadata;
    }

    /**
     * Report an audio-analysis failure without exposing a source URL.
     *
     * @return void
     */
    private function addAudioMetadataError(): void
    {
        $this->grav['messages']?->add('Audio file metadata calculation failed!', 'error');
    }

    /**
     * Retrieve audio from a validated remote HTTP or HTTPS source.
     *
     * @param string $url Remote audio URL.
     * @return string|null Filesystem path to the temporary file, or null when retrieval fails.
     */
    public function getRemoteAudio(string $url): ?string
    {
        $tmp_dir = $this->grav['locator']->findResource('tmp://', true, true);
        if (!is_string($tmp_dir) || !is_dir($tmp_dir)) {
            $this->addRemoteAudioError('The temporary directory is unavailable.');
            return null;
        }

        $local_file = tempnam($tmp_dir, 'podcast');
        if ($local_file === false) {
            $this->addRemoteAudioError('A temporary file could not be created.');
            return null;
        }

        $handle = fopen($local_file, 'w+b');
        if ($handle === false) {
            unlink($local_file);
            $this->addRemoteAudioError('The temporary file could not be opened.');
            return null;
        }

        $success = false;

        try {
            $current_url = $url;

            for ($redirects = 0; $redirects <= self::REMOTE_REDIRECT_LIMIT; $redirects++) {
                $target = $this->validateRemoteUrl($current_url);
                if ($target === null) {
                    throw new \RuntimeException('The URL is invalid or does not resolve exclusively to public addresses.');
                }

                if (!ftruncate($handle, 0) || !rewind($handle)) {
                    throw new \RuntimeException('The temporary file could not be prepared.');
                }

                $transfer = $this->performRemoteTransfer($current_url, $handle, $target);
                $status = $transfer['status'];
                $location = $transfer['location'];
                if (!$transfer['result']) {
                    throw new \RuntimeException("The network transfer failed (cURL error {$transfer['error']}).");
                }

                if ($status >= 300 && $status < 400) {
                    if ($location === null || $location === '') {
                        throw new \RuntimeException('The remote server returned a redirect without a destination.');
                    }
                    if ($redirects === self::REMOTE_REDIRECT_LIMIT) {
                        throw new \RuntimeException('The remote server exceeded the redirect limit.');
                    }

                    $current_url = $this->resolveRedirectUrl($current_url, $location);
                    if ($current_url === null) {
                        throw new \RuntimeException('The remote server returned an invalid redirect destination.');
                    }
                    continue;
                }

                if ($status < 200 || $status >= 300) {
                    throw new \RuntimeException("The remote server returned HTTP status {$status}.");
                }

                if (!fflush($handle)) {
                    throw new \RuntimeException('The downloaded audio could not be flushed to disk.');
                }
                $closed = fclose($handle);
                $handle = null;
                if (!$closed) {
                    throw new \RuntimeException('The downloaded audio file could not be closed.');
                }

                clearstatcache(true, $local_file);
                $size = filesize($local_file);
                if ($size === false || $size <= 0) {
                    throw new \RuntimeException('The remote server returned an empty audio file.');
                }

                $success = true;
                return $local_file;
            }
        } catch (\Throwable $e) {
            $this->addRemoteAudioError($e->getMessage());
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (!$success && is_file($local_file)) {
                unlink($local_file);
            }
        }

        return null;
    }

    /**
     * Perform one cURL request and stream its response into an open file handle.
     *
     * This boundary is protected so tests can supply a deterministic streaming
     * transport without making network requests.
     *
     * @param string $url Validated request URL.
     * @param resource $handle Writable temporary-file handle.
     * @param array{resolve: string|null} $target Validated cURL hostname pinning data.
     * @return array{result: bool, status: int, error: int, location: string|null} Transfer result.
     */
    protected function performRemoteTransfer(string $url, $handle, array $target): array
    {
        $location = null;
        $ch = curl_init();
        if ($ch === false) {
            throw new \RuntimeException('The network transfer could not be initialized.');
        }

        try {
            $options = $this->buildCurlOptions($url, $handle, $target, $location);
            if (!curl_setopt_array($ch, $options)) {
                throw new \RuntimeException('The network transfer could not be configured.');
            }

            $result = curl_exec($ch);

            return [
                'result' => $result !== false,
                'status' => (int) curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'error' => curl_errno($ch),
                'location' => $location,
            ];
        } finally {
            curl_close($ch);
        }
    }

    /**
     * Build the bounded and protocol-restricted cURL options for one request.
     *
     * @param string $url Validated request URL.
     * @param resource $handle Writable temporary-file handle.
     * @param array{resolve: string|null} $target Validated cURL hostname pinning data.
     * @param string|null $location Receives the response Location header, when present.
     * @return array<int, mixed> cURL options keyed by CURLOPT constants.
     */
    protected function buildCurlOptions(string $url, $handle, array $target, ?string &$location): array
    {
        $options = [
            CURLOPT_URL => $url,
            CURLOPT_FILE => $handle,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => self::REMOTE_CONNECT_TIMEOUT,
            CURLOPT_TIMEOUT => self::REMOTE_TRANSFER_TIMEOUT,
            CURLOPT_MAXREDIRS => self::REMOTE_REDIRECT_LIMIT,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_NOSIGNAL => true,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use (&$location): int {
                if (strncasecmp($header, 'Location:', 9) === 0) {
                    $location = trim(substr($header, 9));
                }

                return strlen($header);
            },
        ];
        if ($target['resolve'] !== null) {
            $options[CURLOPT_RESOLVE] = [$target['resolve']];
        }

        return $options;
    }

    /**
     * Validate a remote URL and pin its hostname to the checked public addresses.
     *
     * @param string $url Remote URL to validate.
     * @return array{resolve: string|null}|null cURL resolution override, or null for an invalid target.
     */
    protected function validateRemoteUrl(string $url): ?array
    {
        if ($url === '' || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return null;
        }

        $parts = parse_url($url);
        if ($parts === false || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }
        if (isset($parts['user']) || isset($parts['pass']) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!is_int($port) || $port < 1 || $port > 65535) {
            return null;
        }

        $host = $parts['host'];
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $host = substr($host, 1, -1);
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (!$this->isPublicIpAddress($host)) {
                return null;
            }

            return ['resolve' => null];
        }

        if (preg_match('/^(?:0x[0-9a-f]+|[0-9.]+)$/i', $host)) {
            return null;
        }
        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            return null;
        }

        $addresses = $this->resolvePublicHostAddresses($host);
        if ($addresses === null) {
            return null;
        }

        $pinned_addresses = array_map(
            static fn(string $address): string => str_contains($address, ':') ? "[{$address}]" : $address,
            $addresses
        );

        return [
            'resolve' => strtolower($host) . ':' . $port . ':' . implode(',', $pinned_addresses),
        ];
    }

    /**
     * Resolve a hostname and any CNAME chain exclusively to public addresses.
     *
     * @param string $host Hostname to resolve.
     * @param array<string, bool> $visited Hostnames already visited in the current CNAME chain.
     * @return list<string>|null Public IP addresses, or null when resolution is unsafe or fails.
     */
    private function resolvePublicHostAddresses(string $host, array $visited = []): ?array
    {
        $host = strtolower(rtrim($host, '.'));
        if ($host === '' || isset($visited[$host]) || count($visited) >= 8) {
            return null;
        }
        $visited[$host] = true;

        $records = dns_get_record($host, DNS_A | DNS_AAAA);
        if ($records === false) {
            return null;
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if ($address !== null) {
                if (!$this->isPublicIpAddress($address)) {
                    return null;
                }
                $addresses[] = $address;
                continue;
            }

            if (($record['type'] ?? null) === 'CNAME' && isset($record['target'])) {
                $target = rtrim($record['target'], '.');
                if (filter_var($target, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
                    return null;
                }
                $resolved = $this->resolvePublicHostAddresses($target, $visited);
                if ($resolved === null) {
                    return null;
                }
                array_push($addresses, ...$resolved);
            }
        }

        $addresses = array_values(array_unique($addresses));
        return $addresses !== [] ? $addresses : null;
    }

    /**
     * Return true only for globally routable IPv4 and IPv6 addresses.
     *
     * @param string $address IPv4 or IPv6 address to inspect.
     * @return bool True when the address is safe for a remote transfer.
     */
    private function isPublicIpAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE) === false) {
            return false;
        }

        $packed = inet_pton($address);
        if ($packed === false) {
            return false;
        }

        if (strlen($packed) === 4) {
            $first_byte = ord($packed[0]);
            return $first_byte < 224 || $first_byte > 239;
        }

        // FILTER_FLAG_GLOBAL_RANGE allows multicast and deprecated site-local IPv6.
        if (ord($packed[0]) === 0xff) {
            return false;
        }

        return ord($packed[0]) !== 0xfe || (ord($packed[1]) & 0xc0) !== 0xc0;
    }

    /**
     * Resolve an HTTP Location value against the current URL.
     *
     * @param string $base_url Current absolute URL.
     * @param string $location Absolute or relative Location header value.
     * @return string|null Resolved URL, or null when the inputs are invalid.
     */
    private function resolveRedirectUrl(string $base_url, string $location): ?string
    {
        if ($location === '' || preg_match('/[\x00-\x20\x7f]/', $location)) {
            return null;
        }
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $base = parse_url($base_url);
        $relative = parse_url($location);
        if ($base === false || $relative === false || empty($base['scheme']) || empty($base['host'])) {
            return null;
        }

        if (str_starts_with($location, '//')) {
            return $base['scheme'] . ':' . $location;
        }

        $authority = $base['host'];
        if (isset($base['port'])) {
            $authority .= ':' . $base['port'];
        }

        $relative_path = $relative['path'] ?? '';
        if ($relative_path === '') {
            $path = $base['path'] ?? '/';
        } elseif (str_starts_with($relative_path, '/')) {
            $path = $relative_path;
        } else {
            $base_path = $base['path'] ?? '/';
            $path = substr($base_path, 0, (int) strrpos($base_path, '/') + 1) . $relative_path;
        }
        $path = $this->removeDotSegments($path);

        $url = $base['scheme'] . '://' . $authority . $path;
        if (array_key_exists('query', $relative)) {
            $url .= '?' . $relative['query'];
        } elseif ($relative_path === '' && isset($base['query'])) {
            $url .= '?' . $base['query'];
        }
        if (isset($relative['fragment'])) {
            $url .= '#' . $relative['fragment'];
        }

        return $url;
    }

    /**
     * Normalize dot segments in a URL path.
     *
     * @param string $path URL path to normalize.
     * @return string Normalized absolute path.
     */
    private function removeDotSegments(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        $normalized = '/' . implode('/', $segments);
        if (str_ends_with($path, '/') && $normalized !== '/') {
            $normalized .= '/';
        }

        return $normalized;
    }

    /**
     * Report a remote retrieval failure without exposing the submitted URL.
     *
     * @param string $detail Non-sensitive failure detail.
     * @return void
     */
    private function addRemoteAudioError(string $detail): void
    {
        $this->grav['messages']?->add("Remote audio could not be retrieved: {$detail}", 'error');
    }

    /**
     * Return the available iTunes categories for an Admin select field.
     *
     * @return array<string, string> Category labels keyed by category name.
     */
    public static function getCategoryOptions(): array
    {
        $options = [];
        $data_file_path = __DIR__ . DS . 'data' . DS . 'iTunesCategories.yaml';
        $file = File::instance($data_file_path);
        $data = Yaml::parse($file->content());
        $keys = array_keys($data);

        foreach ($keys as $option) {
            $options[$option] = $option;
        }

        return $options;
    }

    /**
     * Return the available iTunes subcategories for an Admin select field.
     *
     * @return array<string, string> Subcategory labels keyed by subcategory name.
     */
    public static function getSubCategoryOptions(): array
    {
        $options = [];
        $data_file_path = __DIR__ . DS . 'data' . DS . 'iTunesCategories.yaml';
        $file = File::instance($data_file_path);
        $data = Yaml::parse($file->content());
        foreach ($data as $key => $category) {
            if ($category != null) {
                foreach ($category as $sub) {
                    $options[$sub] = $sub;
                }
            } else {
                $options[$key] = $key;
            }
        }

        return $options;
    }

    /**
     * Return the available podcast languages for an Admin select field.
     *
     * @return array<string, string> Language labels keyed by ISO 639 code.
     */
    public static function getLanguageOptions(): array
    {
        $options = [];
        $data_file_path = __DIR__ . DS . 'data' . DS . 'languages.yaml';
        $file = File::instance($data_file_path);
        $languages = Yaml::parse($file->content());
        foreach ($languages as $language) {
            $options[$language['ISO 639 Code']] = $language['English Name'] . " (" . $language['ISO 639 Code'] . ")";
        }
        return $options;
    }

    /**
     * Return a property of the current user for an Admin blueprint default.
     *
     * @param string $property User property name.
     * @return mixed Property value, or an empty string when no property was requested.
     */
    public static function getCurrentUserInfo($property)
    {
        if (!$property) {
            return "";
        }
        $grav = Grav::instance();
        return $grav['user']->get($property);
    }
}
