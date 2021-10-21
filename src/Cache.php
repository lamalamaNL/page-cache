<?php

namespace Silber\PageCache;

use Exception;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Contracts\Container\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;

class Cache
{
    /**
     * The filesystem instance.
     *
     * @var \Illuminate\Filesystem\Filesystem
     */
    protected $files;

    /**
     * The container instance.
     *
     * @var \Illuminate\Contracts\Container\Container|null
     */
    protected $container = null;

    /**
     * The directory in which to store the cached pages.
     *
     * @var string|null
     */
    protected $cachePath = null;


    /**
     * The locale of the site cache.
     *
     * @var string|null
     */
    protected $locale = null;


    /**
     * The type of page to cache (used for cache index). Use page | plp | pdp
     *
     * @var string|null
     */
    protected $pageType = null;

    /**
     * Time to cache in minutes
     *
     * @var int|null
     */
    protected $expireAt = null;

    /**
     * Constructor.
     *
     * @var \Illuminate\Filesystem\Filesystem  $files
     */
    public function __construct(Filesystem $files)
    {
        $this->files = $files;
    }

    /**
     * Sets the container instance.
     *
     * @param  \Illuminate\Contracts\Container\Container  $container
     * @return $this
     */
    public function setContainer(Container $container)
    {
        $this->container = $container;

        return $this;
    }

    /**
     * Sets the directory in which to store the cached pages.
     *
     * @param  string  $path
     * @return void
     */
    public function setCachePath($path)
    {
        $this->cachePath = rtrim($path, '\/');
    }

    /**
     * Gets the path to the cache directory.
     *
     * @param  string  ...$paths
     * @return string
     *
     * @throws \Exception
     */
    public function getCachePath()
    {
        $base = $this->cachePath ? $this->cachePath : $this->getDefaultCachePath();

        if (is_null($base)) {
            throw new Exception('Cache path not set.');
        }

        return $this->join(array_merge([$base], func_get_args()));
    }

    /**
     * Join the given paths together by the system's separator.
     *
     * @param  string[] $paths
     * @return string
     */
    protected function join(array $paths)
    {
        $trimmed = array_map(function ($path) {
            return trim($path, '/');
        }, $paths);

        return $this->matchRelativity(
            $paths[0], implode('/', array_filter($trimmed))
        );
    }

    /**
     * Makes the target path absolute if the source path is also absolute.
     *
     * @param  string  $source
     * @param  string  $target
     * @return string
     */
    protected function matchRelativity($source, $target)
    {
        return $source[0] == '/' ? '/'.$target : $target;
    }

    /**
     * Caches the given response if we determine that it should be cache.
     *
     * @param  \Symfony\Component\HttpFoundation\Request  $request
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return $this
     */
    public function cacheIfNeeded(Request $request, Response $response)
    {
        if ($this->shouldCache($request, $response)) {
            $this->cache($request, $response);
        }

        return $this;
    }

    /**
     * Determines whether the given request/response pair should be cached.
     *
     * @param  \Symfony\Component\HttpFoundation\Request  $request
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return bool
     */
    public function shouldCache(Request $request, Response $response)
    {
        return $request->isMethod('GET') && $response->getStatusCode() == 200;
    }

    /**
     * Cache the response to a file.
     *
     * @param  \Symfony\Component\HttpFoundation\Request  $request
     * @param  \Symfony\Component\HttpFoundation\Response  $response
     * @return void
     */
    public function cache(Request $request, Response $response)
    {
        list($path, $file) = $this->getDirectoryAndFileNames($request, $response);

        $fileJoined = $this->join([$path, $file]);

        @mkdir(dirname($path.'/'.$file), 0777, true);

        // Create the file handle. We use the "c" mode which will avoid writing an
        // empty file if we abort when checking the lock status in the next step.
        $handle = fopen($path.'/'.$file, 'c');


        fwrite($handle, $response->getContent());
        chmod($path.'/'.$file, 0777);

        // Hold the file lock for a moment to prevent other processes from trying to write the same file.
        sleep(0);

        fclose($handle);

        \App\Models\CacheIndex::create([
            'path' => $fileJoined,
            'page_type' => $this->pageType,
            'expire_at' => \Carbon\Carbon::now()->addMinutes($this->expireAt),
        ]);
    }

    /**
     * Remove the cached file for the given slug.
     *
     * @param  string  $slug
     * @return bool
     */
    public function forget($slug)
    {
        $deletedHtml = $this->files->delete($this->getCachePath($slug.'.html'));
        $deletedJson = $this->files->delete($this->getCachePath($slug.'.json'));

        return $deletedHtml || $deletedJson;
    }

    /**
     * Clear the full cache directory, or a subdirectory.
     *
     * @param  string|null
     * @return bool
     */
    public function clear($path = null)
    {
        return $this->files->deleteDirectory($this->getCachePath($path), true);
    }

    /**
     * Get the names of the directory and file.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Illuminate\Http\Response $response
     * @return array
     */
    protected function getDirectoryAndFileNames($request, $response)
    {
        $segments = explode('/', ltrim($request->getPathInfo(), '/'));
        $fullUrl = config('app/url').$_SERVER['REQUEST_URI'];
        $filename = $this->aliasFilename(array_pop($segments));
        $extension = $this->guessFileExtension($response);
        $urlParts = parse_url($fullUrl);
        $query = $this->arrGet($urlParts, 'query', '');

        if (isset($query[0]['query']) && $this->filterQuery($query[0]['query']) !== null) {
            $basename = '_' . $this->filterQuery($query[0]['query']) . '.html';
            return [$this->getCachePath(implode('/',$segments )), $basename];
        }

        $basename = "{$filename}.{$extension}";
        return [$this->getCachePath(implode('/',$segments )), $basename];
    }

    /**
     * Check if the filter is allowed and accept only the first 2 query's
     * @param $query
     * @return string|null
     */
    private function filterQuery($query)
    {
        if (config('cache-whitelist.tags') === null) {
            return null;
        }

        if (strpos($query, '&') === false) {
            $query = explode('=', $query);
            if (!in_array($query[0], config('cache-whitelist.tags'))) {
                return null;
            }
            return $query[0].'='.$query[1];
        }

        $query = explode('&', $query);
        $queryPartOne = explode('=', $query[0]);
        $queryPartTow = explode('=', $query[1]);
        $combineQuery = '';

        if (!in_array($queryPartOne[0], config('cache-whitelist.tags')) &&
            !in_array($queryPartTow[0], config('cache-whitelist.tags'))) {
            return null;
        }

        if (in_array($queryPartOne[0], config('cache-whitelist.tags'))) {
            $combineQuery .= $queryPartOne[0].'='.$queryPartOne[1];
        }
        if (in_array($queryPartTow[0], config('cache-whitelist.tags'))) {
            if ($combineQuery === '') {
                $combineQuery .= $queryPartTow[0] . '=' . $queryPartTow[1];
            }
            else {
                $combineQuery .= '&' . $queryPartTow[0] . '=' . $queryPartTow[1];
            }
        }

        return $combineQuery;
    }

    /**
     * Alias the filename if necessary.
     *
     * @param  string  $filename
     * @return string
     */
    protected function aliasFilename($filename)
    {
        return $filename ?: 'pc__index__pc';
    }

    /**
     * Get the default path to the cache directory.
     *
     * @return string|null
     */
    protected function getDefaultCachePath()
    {
        if ($this->container && $this->container->bound('path.public')) {
            $cachePath = $this->container->make('path.public').'/static/';
            if ($this->locale) {
                $sites = config('statamic.sites.sites');
                $subFolder = '';
                foreach ($sites as $site) {
                    if ($site['locale'] === $this->locale) {
                        $subFolder = parse_url($site['url'])['host'] . '/';
                    }
                }
                $cachePath = $cachePath . $subFolder . '/';
            }

            return $cachePath;
        }
    }

    /**
     * Guess the correct file extension for the given response.
     *
     * Currently, only JSON and HTML are supported.
     *
     * @return string
     */
    protected function guessFileExtension($response)
    {
        $contentType = $response->headers->get('Content-Type');

        if ($response instanceof JsonResponse ||
            $contentType == 'application/json'
        ) {
            return 'json';
        }

        if (in_array($contentType, ['text/xml', 'application/xml'])) {
            return 'xml';
        }

        return 'html';
    }

    /**
     * @param string|null $locale
     * @return Cache
     */
    public function setLocale($locale)
    {
        $this->locale = $locale;
        return $this;
    }

    /**
     * @param string|null $pageType
     * @return Cache
     */
    public function setPageType($pageType)
    {
        $this->pageType = $pageType;
        return $this;
    }

    /**
     * @param int|null $expireAt
     * @return Cache
     */
    public function setExpireAt($expireAt)
    {
        $this->expireAt = $expireAt;
        return $this;
    }

    /**
     * @return string|null
     */
    public function getLocale()
    {
        return $this->locale;
    }

    /**
     * @return int|null
     */
    public function getExpireAt()
    {
        return $this->expireAt;
    }

    /**
     * @return string|null
     */
    public function getPageType()
    {
        return $this->pageType;
    }

    /**
     * @param  mixed  $content
     * @return string
     */
    protected function normalizeContent($content)
    {
        if ($content instanceof Response) {
            $content = $content->content();
        }

        return $content;
    }

    public function cacheUrl($key, $url)
    {
        $this->cacheDomain();

        $urls = $this->getUrls();

        $url = Str::removeLeft($url, $this->getBaseUrl());

        $urls->put($key, $url);

        $this->cache->forever($this->getUrlsCacheKey(), $urls->all());
    }

    /**
     * Get a hashed string representation of a URL.
     *
     * @param  string  $url
     * @return string
     */
    protected function makeHash($url)
    {
        return md5($url);
    }

    private function isBasenameTooLong($basename)
    {
        return strlen($basename) > 255;
    }

    private function arrGet($array, $key, $default = null)
    {
        if ($key) {
            $key = str_replace(':', '.', $key);
        }

        return [$array, $key, $default];
    }

}
