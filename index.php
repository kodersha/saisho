<?php
/*
 * Saisho MK. 2
 * Security hardening pass.
 */
define('SAISHO_VERSION', '20261101');
define('META_TITLE', 'Saisho');
define('META_DESCRIPTION', 'Very (VERY) simple and quick semi-static site engine');
define('DS', DIRECTORY_SEPARATOR);
define('ROOT_DIR', __DIR__);
define('DATA_DIR', ROOT_DIR . DS . 'data');
define('TEMPLATE_DIR', ROOT_DIR . DS . 'template');
define('CACHE_DIR', ROOT_DIR . DS . 'cache');
define('INC_DIR', ROOT_DIR . DS . 'inc');
define('CACHE_TIME', 1000);
define('TAGS_TO_HIDE', ['hide']);

function saisho_escape($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function saisho_xml_escape($value) {
    return htmlspecialchars((string)$value, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/* Use a configured canonical origin for feeds. Never trust HTTP_HOST. */
function saisho_site_url() {
    $configured = getenv('SAISHO_SITE_URL');
    if ($configured && filter_var($configured, FILTER_VALIDATE_URL)) {
        $parts = parse_url($configured);
        if (isset($parts['scheme'], $parts['host']) &&
            in_array(strtolower($parts['scheme']), ['http', 'https'], true) &&
            !isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])) {
            return rtrim($configured, '/');
        }
    }

    $serverName = isset($_SERVER['SERVER_NAME']) ? $_SERVER['SERVER_NAME'] : 'localhost';
    if (!preg_match('/\\A[a-zA-Z0-9.-]+\\z/', $serverName)) {
        $serverName = 'localhost';
    }
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    return $scheme . '://' . $serverName;
}

function saisho_security_headers() {
    if (headers_sent()) {
        return;
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
    if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
        header('Strict-Transport-Security: max-age=31536000');
    }
}
saisho_security_headers();

class Saisho {
    public $requestedPage, $pagePath, $cachedPagePath, $time;

    public function __construct() {
        $this->time = microtime(true);
        $this->setPagePaths($this->getRequestedPage());
        $this->handleRequest($this->requestedPage);
    }

    public function getRequestedPage() {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path)) {
            return "\\0";
        }
        $path = rawurldecode($path);
        $path = trim($path, '/');
        if ($path === '') {
            return '';
        }
        if (strpos($path, "\\0") !== false || strpos($path, chr(92)) !== false ||
            preg_match('/[\\x00-\\x1F\\x7F]/', $path) ||
            !preg_match('/\\A[A-Za-z0-9_-]+(?:\\/[A-Za-z0-9_-]+)*\\z/', $path)) {
            return "\\0";
        }
        foreach (explode('/', $path) as $segment) {
            if ($segment === '.' || $segment === '..') {
                return "\\0";
            }
        }
        return $path;
    }

    public function setPagePaths(string $page) {
        $this->requestedPage = $page;
        if ($page === "\\0") {
            $this->pagePath = DATA_DIR . DS . '.invalid-request';
            $this->cachedPagePath = CACHE_DIR . DS . '.invalid-request';
            return;
        }
        $relative = str_replace('/', DS, $page);
        $this->pagePath = DATA_DIR . DS . $relative . '.md';
        $this->cachedPagePath = CACHE_DIR . DS . $relative . '.html';
    }

    private function isInsideDirectory($path, $directory) {
        $root = realpath($directory);
        $target = realpath($path);
        if ($root === false || $target === false) {
            return false;
        }
        $root = rtrim($root, DS) . DS;
        return strpos($target, $root) === 0;
    }

    private function validPageFile($path) {
        return is_file($path) && $this->isInsideDirectory($path, DATA_DIR);
    }

    public function handleRequest(string $requestedPage) {
        if ($requestedPage === "\\0") {
            http_response_code(404);
            $this->setPagePaths('notfound');
            $page = (object)['type' => 'notfound', 'content' => ''];
        } else {
            switch ($requestedPage) {
                case '':
                    $list = $this->listPages();
                    $page = (object)['type' => 'list', 'content' => $this->renderPageList($list, true)];
                    break;
                case 'projects':
                    $list = $this->listPages();
                    $page = (object)['type' => 'list', 'content' => $this->renderPageList($list, 'project')];
                    break;
                default:
                    if ($this->validPageFile($this->pagePath)) {
                        if (!$this->tryCache($this->cachedPagePath)) {
                            $page = (object)['type' => 'page', 'content' => $this->renderPage($this->pagePath)];
                        }
                    } else {
                        http_response_code(404);
                        $this->setPagePaths('notfound');
                        $page = (object)['type' => 'notfound', 'content' => ''];
                    }
                    break;
            }
        }
        $this->servePage($page->type, ['content' => $page->content]);
    }

    public function listPages() {
        $pages = glob(DATA_DIR . DS . '*.md');
        $list = [];
        foreach ($pages as $page) {
            if (is_file($page) && $this->isInsideDirectory($page, DATA_DIR)) {
                $pageName = substr(basename($page), 0, -3);
                $list[$pageName] = $page;
            }
        }
        return $list;
    }

    public function sortPageList(array &$list) {
        uasort($list, function ($a, $b) {
            return strtotime($b->date) - strtotime($a->date);
        });
        return $list;
    }

    public function parsePageList(array $list, $filter) {
        if (empty($list)) {
            return [];
        }
        $parsedPages = [];
        foreach ($list as $name => $path) {
            $page = $this->renderPage($path, true);
            $parsedPages[$name] = $page;
        }
        return array_filter($parsedPages, function ($item) use ($filter) {
            if ($filter === true) {
                return !array_intersect(TAGS_TO_HIDE, $item->tags);
            }
            if (is_string($filter)) {
                return in_array($filter, $item->tags, true);
            }
            return true;
        });
    }

    public function renderPageList(array $list, $filter) {
        if (empty($list)) {
            return '<h1>ʅ(°⊱,°)ʃ Nothing found</h1>';
        }
        $list = $this->parsePageList($list, $filter);
        $this->sortPageList($list);
        $html = '<ol reversed class="pl">' . PHP_EOL;
        foreach ($list as $handle => $page) {
            $safeHandle = rawurlencode($handle);
            $html .= '<li><span class="g">' . saisho_escape($page->date) . '</span> <a href="/' .
                saisho_escape($safeHandle) . '">' . saisho_escape($page->title) . '</a></li>' . PHP_EOL;
        }
        return $html . '</ol>' . PHP_EOL;
    }

    private function getRawContent(string $pageName) {
        return (string)file_get_contents($pageName);
    }

    private function parseYaml(string $str) {
        $parsed = [];
        preg_match("'^---(.+?)---'si", $str, $yaml);
        if (!isset($yaml[0])) {
            return (object)['body' => $str];
        }
        $str = str_replace($yaml[0], '', $str);
        $parsed['body'] = $str;
        $yaml = trim($yaml[0]);
        preg_match_all("'(\\w+):\\s?(.+)'m", $yaml, $yamlAttribs, PREG_SET_ORDER);
        foreach ($yamlAttribs as $attribute) {
            $parsed[$attribute[1]] = trim($attribute[2]);
        }
        return $parsed;
    }

    public function renderPage(string $pageName, bool $onlyYaml = false) {
        if (!$this->validPageFile($pageName)) {
            throw new RuntimeException('Page path is outside the content directory.');
        }
        include_once INC_DIR . DS . 'Parsedown.php';
        include_once INC_DIR . DS . 'ParsedownExtra.php';
        include_once INC_DIR . DS . 'ParsedownExtended.php';
        $contentRaw = $this->getRawContent($pageName);
        $page = $this->parseYaml($contentRaw);
        $page = (array)$page;
        $page['title'] = $page['title'] ?? 'untitled';
        $page['date'] = $page['date'] ?? '1969-01-01';
        $page['modified'] = filemtime($pageName);
        $page['tags'] = isset($page['tags']) ? array_map('trim', explode(',', $page['tags'])) : [];
        $parsedown = new ParsedownExtended(['toc' => ['enable' => true, 'inline' => true], 'mark' => true, 'insert' => true, 'task' => true, 'kbd' => true]);
        if (method_exists($parsedown, 'setSafeMode')) {
            $parsedown->setSafeMode(true);
        }
        if (!$onlyYaml) {
            $page['body'] = $parsedown->text($page['body'] ?? '');
        } else {
            unset($page['body']);
        }
        return (object)$page;
    }

    private function buildFeed() {
        $pages = $this->parsePageList($this->listPages(), true);
        $base = saisho_site_url();
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        $xml .= '<rss xmlns:atom="http://www.w3.org/2005/Atom" version="2.0"><channel>';
        $xml .= '<title>' . saisho_xml_escape(META_TITLE) . '</title>';
        $xml .= '<link>' . saisho_xml_escape($base . '/') . '</link>';
        $xml .= '<description>' . saisho_xml_escape(META_DESCRIPTION) . '</description>';
        $xml .= '<generator>Saisho</generator><language>en-us</language>';
        $xml .= '<lastBuildDate>' . saisho_xml_escape(date(DATE_RSS)) . '</lastBuildDate>';
        $xml .= '<atom:link href="' . saisho_xml_escape($base . '/feed.xml') . '" rel="self" type="application/rss+xml"/>';
        foreach ($pages as $handle => $page) {
            $link = $base . '/' . rawurlencode($handle);
            $xml .= '<item><title>' . saisho_xml_escape($page->title) . '</title>';
            $xml .= '<link>' . saisho_xml_escape($link) . '</link>';
            $xml .= '<pubDate>' . saisho_xml_escape(date(DATE_RSS, strtotime($page->date))) . '</pubDate>';
            $xml .= '<description>' . saisho_xml_escape($page->description ?? '') . '</description></item>';
        }
        return $xml . '</channel></rss>';
    }

    public function saveFeed($filename = 'feed.xml') {
        $path = ROOT_DIR . DS . 'feed.xml';
        $mtime = is_file($path) ? filemtime($path) : 0;
        if (!$mtime || (time() - $mtime >= CACHE_TIME)) {
            $temporary = $path . '.' . uniqid('', true) . '.tmp';
            if (file_put_contents($temporary, $this->buildFeed(), LOCK_EX) === false) {
                return false;
            }
            if (!rename($temporary, $path)) {
                @unlink($temporary);
                return false;
            }
        }
        return saisho_site_url() . '/feed.xml';
    }

    public function servePage(string $template, array $args) {
        $render = $this->renderTemplate($template, $args);
        if ($this->requestedPage !== '' && $this->requestedPage !== "\\0") {
            $directory = dirname($this->cachedPagePath);
            if (!is_dir($directory)) {
                @mkdir($directory, 0755, true);
            }
            if (is_dir($directory)) {
                $temporary = $this->cachedPagePath . '.' . uniqid('', true) . '.tmp';
                if (file_put_contents($temporary, $render, LOCK_EX) !== false) {
                    @rename($temporary, $this->cachedPagePath);
                }
            }
            $this->saveFeed();
        }
        echo $render;
    }

    private function tryCache(string $filePath) {
        if (!is_file($filePath) || !$this->isInsideDirectory($filePath, CACHE_DIR)) {
            return false;
        }
        $filemtime = @filemtime($filePath);
        if ($filemtime && (time() - $filemtime <= CACHE_TIME)) {
            readfile($filePath);
            exit;
        }
        return false;
    }

    private function renderTemplate(string $template, array $args) {
        $templatePath = TEMPLATE_DIR . DS . $template . '.php';
        $templateRoot = realpath(TEMPLATE_DIR);
        $resolved = realpath($templatePath);
        if ($resolved === false || $templateRoot === false ||
            strpos($resolved, rtrim($templateRoot, DS) . DS) !== 0) {
            http_response_code(500);
            return 'Template not found.';
        }
        ob_start();
        $page = (object)[];
        foreach ($args as $key => $value) {
            $page->{$key} = $value;
        }
        $content = is_object($page->content) ? (array)$page->content : [];
        if ($this->requestedPage !== '') {
            header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $page->content->modified ?? time()) . ' GMT');
        }
        include $resolved;
        return (string)ob_get_clean();
    }
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath($_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $saisho = new Saisho();
}
