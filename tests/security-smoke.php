<?php
/*
 * Lightweight regression checks. Run with: php tests/security-smoke.php
 */
require dirname(__DIR__) . '/index.php';

function check($condition, $message) {
    if (!$condition) {
        fwrite(STDERR, "FAIL: " . $message . PHP_EOL);
        exit(1);
    }
}

$engine = (new ReflectionClass('Saisho'))->newInstanceWithoutConstructor();

$_SERVER['REQUEST_URI'] = '/articles/hello-world?preview=1';
check($engine->getRequestedPage() === 'articles/hello-world', 'query string is not part of the page path');

$_SERVER['REQUEST_URI'] = '/%2e%2e/secret';
check($engine->getRequestedPage() === "\\0", 'encoded traversal is rejected');

$_SERVER['REQUEST_URI'] = '/%252e%252e/secret';
check($engine->getRequestedPage() === "\\0", 'double-encoded traversal is rejected');

$_SERVER['REQUEST_URI'] = '/folder%5csecret';
check($engine->getRequestedPage() === "\\0", 'encoded backslash is rejected');

$_SERVER['REQUEST_URI'] = '/bad%00name';
check($engine->getRequestedPage() === "\\0", 'null byte is rejected');

$_SERVER['REQUEST_URI'] = '/valid-page';
check($engine->getRequestedPage() === 'valid-page', 'valid page slug is accepted');

check(saisho_escape('" onmouseover="alert(1)') === '&quot; onmouseover=&quot;alert(1)', 'HTML attributes are escaped');
check(saisho_xml_escape('<title>&') === '&lt;title&gt;&amp;', 'XML text is escaped');

echo "All Saisho security smoke tests passed." . PHP_EOL;
