# Security hardening and deployment notes

Saisho is a small file-based publishing engine. Keep the content directory writable only by trusted editors and the cache directory writable only by the PHP process.

## Configuration

Set the `SAISHO_SITE_URL` environment variable to the canonical public origin, including the scheme and without a trailing slash. For example, `https://example.com`. This value is used for absolute RSS links. The engine does not use the incoming `Host` request header to build canonical links.

The router accepts simple ASCII path segments containing letters, digits, underscores and hyphens. Query strings are ignored. Dot segments, encoded traversal, backslashes, control characters and other unsupported path characters return 404.

Markdown raw HTML is disabled when the installed Parsedown version supports safe mode. Keep Parsedown and its extensions updated. Review custom parser extensions before enabling them.

## Web-server access controls

The repository includes Apache access-control files for `cache/`, `inc/` and Markdown files in `data/`. If the site runs on Nginx, apply equivalent rules in the server configuration:

```nginx
location ^~ /cache/ { deny all; }
location ^~ /inc/ { deny all; }
location ~* ^/data/.*\.md$ { deny all; }
```

Do not expose PHP source, configuration files, backups, logs or temporary files through the web server. The `data/` directory may contain public assets; the included rule blocks Markdown files only.

## Headers

The engine sends baseline security headers, including a restrictive Content Security Policy. The policy currently permits inline styles and scripts because the default templates contain them. Remove those allowances after moving inline code into external files or adopting nonces.

HSTS is sent only on HTTPS requests. Enable it only when the entire site and all relevant subdomains are permanently HTTPS; add `includeSubDomains` or `preload` only after checking the deployment.

## Regression checks

Run:

```sh
php tests/security-smoke.php
php -l index.php
php -l template/page.php
php -l template/list.php
php -l template/head.php
```

These smoke tests cover route normalization and output escaping. They are not a substitute for testing the site behind its production web server.
