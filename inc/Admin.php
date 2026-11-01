<?php
/**
 * Minimal file-based administration for Saisho.
 * Credentials are created by bin/create-admin.php and never stored in source.
 */
class SaishoAdmin
{
    private $configFile;
    private $username = '';
    private $passwordHash = '';

    public function __construct()
    {
        $this->configFile = ROOT_DIR . DS . 'config' . DS . 'admin.json';
        $this->loadCredentials();
        $this->startSession();
    }

    private function loadCredentials()
    {
        if (!is_file($this->configFile)) {
            return;
        }
        $data = json_decode((string)file_get_contents($this->configFile), true);
        if (is_array($data)) {
            $this->username = isset($data['username']) ? (string)$data['username'] : '';
            $this->passwordHash = isset($data['password_hash']) ? (string)$data['password_hash'] : '';
        }
    }

    private function startSession()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = !empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off';
        session_name('saisho_admin');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Strict'
        ]);
        session_start();
    }

    public function handle($route)
    {
        if ($route === 'admin/logout') {
            $this->logout();
            return;
        }

        if ($route === 'admin/login') {
            $this->login();
            return;
        }

        if (!$this->isAuthenticated()) {
            header('Location: /admin/login', true, 303);
            exit;
        }

        if ($route === 'admin/new') {
            $this->newPost();
            return;
        }

        $this->dashboard();
    }

    private function isAuthenticated()
    {
        return !empty($_SESSION['saisho_admin_authenticated']) &&
            $_SESSION['saisho_admin_authenticated'] === true &&
            !empty($_SESSION['saisho_admin_username']) &&
            hash_equals($this->username, (string)$_SESSION['saisho_admin_username']);
    }

    private function csrfToken()
    {
        if (empty($_SESSION['saisho_admin_csrf'])) {
            $_SESSION['saisho_admin_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['saisho_admin_csrf'];
    }

    private function verifyCsrf()
    {
        $token = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
        return $token !== '' && !empty($_SESSION['saisho_admin_csrf']) &&
            hash_equals($_SESSION['saisho_admin_csrf'], $token);
    }

    private function pageStart($title)
    {
        header('Cache-Control: no-store, private');
        header('Content-Type: text/html; charset=UTF-8');
        echo '<!doctype html><html lang="ru"><head><meta charset="utf-8">';
        echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . saisho_escape($title) . ' — Saisho</title>';
        echo '<style>
            :root{color-scheme:light dark}*{box-sizing:border-box}
            body{font:16px/1.55 system-ui,sans-serif;max-width:900px;margin:40px auto;padding:0 20px}
            a{color:inherit}header{display:flex;justify-content:space-between;gap:20px;align-items:center;border-bottom:1px solid #8885;padding-bottom:16px;margin-bottom:28px}
            nav{display:flex;gap:16px;flex-wrap:wrap}label{display:block;font-weight:600;margin:16px 0 6px}
            input,textarea{font:inherit;width:100%;padding:10px;border:1px solid #8888;border-radius:5px;background:transparent}
            textarea{min-height:320px;font-family:ui-monospace,monospace}button{font:inherit;padding:10px 18px;margin-top:18px;cursor:pointer}
            .muted{opacity:.7}.error{color:#c44}.post{padding:12px 0;border-bottom:1px solid #8885}
            </style></head><body>';
        echo '<header><strong><a href="/admin">Saisho Admin</a></strong><nav><a href="/admin/new">Новая публикация</a><a href="/">Открыть сайт</a>';
        if ($this->isAuthenticated()) {
            echo '<form method="post" action="/admin/logout" style="display:inline"><input type="hidden" name="csrf_token" value="' .
                saisho_escape($this->csrfToken()) . '"><button type="submit" style="margin:0">Выйти</button></form>';
        }
        echo '</nav></header><main>';
    }

    private function pageEnd()
    {
        echo '</main></body></html>';
    }

    private function login()
    {
        if ($this->isAuthenticated()) {
            header('Location: /admin', true, 303);
            exit;
        }

        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $lockedUntil = isset($_SESSION['saisho_admin_locked_until']) ? (int)$_SESSION['saisho_admin_locked_until'] : 0;
            if ($lockedUntil > time()) {
                $error = 'Слишком много попыток. Попробуйте позже.';
            } elseif (!$this->verifyCsrf()) {
                $error = 'Сессия формы истекла. Обновите страницу.';
            } else {
                $username = isset($_POST['username']) ? (string)$_POST['username'] : '';
                $password = isset($_POST['password']) ? (string)$_POST['password'] : '';
                if ($this->username !== '' && $this->passwordHash !== '' &&
                    hash_equals($this->username, $username) && password_verify($password, $this->passwordHash)) {
                    session_regenerate_id(true);
                    $_SESSION['saisho_admin_authenticated'] = true;
                    $_SESSION['saisho_admin_username'] = $this->username;
                    unset($_SESSION['saisho_admin_failed'], $_SESSION['saisho_admin_locked_until']);
                    header('Location: /admin', true, 303);
                    exit;
                }
                $failed = isset($_SESSION['saisho_admin_failed']) ? (int)$_SESSION['saisho_admin_failed'] + 1 : 1;
                $_SESSION['saisho_admin_failed'] = $failed;
                if ($failed >= 5) {
                    $_SESSION['saisho_admin_locked_until'] = time() + 300;
                    $_SESSION['saisho_admin_failed'] = 0;
                }
                $error = 'Неверный логин или пароль.';
            }
        }

        $this->pageStart('Вход');
        echo '<h1>Вход в панель</h1>';
        if (!is_file($this->configFile)) {
            echo '<p class="error">Администратор ещё не создан. Выполните команду из SECURITY.md.</p>';
        }
        if ($error !== '') {
            echo '<p class="error">' . saisho_escape($error) . '</p>';
        }
        echo '<form method="post" action="/admin/login" autocomplete="on">';
        echo '<input type="hidden" name="csrf_token" value="' . saisho_escape($this->csrfToken()) . '">';
        echo '<label for="username">Логин</label><input id="username" name="username" autocomplete="username" required maxlength="80">';
        echo '<label for="password">Пароль</label><input id="password" type="password" name="password" autocomplete="current-password" required>';
        echo '<button type="submit">Войти</button></form>';
        $this->pageEnd();
    }

    private function logout()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$this->verifyCsrf()) {
            http_response_code(400);
            echo 'Bad request';
            return;
        }
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, [
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => 'Strict'
            ]);
        }
        session_destroy();
        header('Location: /admin/login', true, 303);
        exit;
    }

    private function dashboard()
    {
        $files = glob(DATA_DIR . DS . '*.md');
        $this->pageStart('Публикации');
        echo '<h1>Публикации</h1><p><a href="/admin/new">Создать новую публикацию</a></p>';
        if (!$files) {
            echo '<p class="muted">Публикаций пока нет.</p>';
        } else {
            foreach ($files as $file) {
                $slug = basename($file, '.md');
                $title = $slug;
                $raw = (string)file_get_contents($file);
                if (preg_match('/^title:\s*(.+)$/m', $raw, $match)) {
                    $title = trim($match[1]);
                }
                echo '<div class="post"><strong>' . saisho_escape($title) . '</strong> <span class="muted">/' .
                    saisho_escape($slug) . '</span></div>';
            }
        }
        $this->pageEnd();
    }

    private function newPost()
    {
        $error = '';
        $values = ['title' => '', 'slug' => '', 'date' => date('Y-m-d'), 'tags' => '', 'description' => '', 'body' => ''];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            foreach ($values as $key => $default) {
                $values[$key] = isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
            }

            if (!$this->verifyCsrf()) {
                $error = 'Сессия формы истекла. Обновите страницу.';
            } elseif ($values['title'] === '' || mb_strlen($values['title'], 'UTF-8') > 180) {
                $error = 'Укажите заголовок длиной не более 180 символов.';
            } elseif (!preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/', $values['slug'])) {
                $error = 'Slug должен содержать только латинские строчные буквы, цифры и дефисы.';
            } elseif (in_array($values['slug'], ['admin', 'projects', 'feed'], true)) {
                $error = 'Этот slug зарезервирован.';
            } elseif (!preg_match('/\A\d{4}-\d{2}-\d{2}\z/', $values['date']) ||
                DateTime::createFromFormat('!Y-m-d', $values['date']) === false ||
                DateTime::createFromFormat('!Y-m-d', $values['date'])->format('Y-m-d') !== $values['date']) {
                $error = 'Укажите корректную дату в формате ГГГГ-ММ-ДД.';
            } elseif ($values['body'] === '' || strlen($values['body']) > 1000000) {
                $error = 'Текст публикации не должен быть пустым и не должен превышать 1 МБ.';
            } elseif (is_file(DATA_DIR . DS . $values['slug'] . '.md')) {
                $error = 'Публикация с таким slug уже существует.';
            } else {
                $title = str_replace(["\r", "\n"], ' ', $values['title']);
                $description = str_replace(["\r", "\n"], ' ', $values['description']);
                $tags = preg_replace('/[^\p{L}\p{N}_ -]/u', '', $values['tags']);
                $markdown = "---\n";
                $markdown .= 'title: ' . $title . "\n";
                $markdown .= 'date: ' . $values['date'] . "\n";
                $markdown .= 'tags: ' . $tags . "\n";
                if ($description !== '') {
                    $markdown .= 'description: ' . $description . "\n";
                }
                $markdown .= "---\n\n" . $values['body'] . "\n";

                $target = DATA_DIR . DS . $values['slug'] . '.md';
                $handle = @fopen($target, 'x');
                if ($handle === false) {
                    $error = 'Не удалось создать файл. Возможно, такой slug уже занят.';
                } else {
                    $written = fwrite($handle, $markdown);
                    fflush($handle);
                    fclose($handle);
                    if ($written !== strlen($markdown)) {
                        @unlink($target);
                        $error = 'Не удалось полностью записать публикацию.';
                    } else {
                        @unlink(ROOT_DIR . DS . 'feed.xml');
                        header('Location: /' . rawurlencode($values['slug']), true, 303);
                        exit;
                    }
                }
            }
        }

        $this->pageStart('Новая публикация');
        echo '<h1>Новая публикация</h1>';
        if ($error !== '') {
            echo '<p class="error">' . saisho_escape($error) . '</p>';
        }
        echo '<form method="post" action="/admin/new">';
        echo '<input type="hidden" name="csrf_token" value="' . saisho_escape($this->csrfToken()) . '">';
        $this->field('title', 'Заголовок', $values['title'], 'text', true);
        $this->field('slug', 'Адрес (slug)', $values['slug'], 'text', true);
        $this->field('date', 'Дата', $values['date'], 'date', true);
        $this->field('tags', 'Теги через запятую', $values['tags']);
        $this->field('description', 'Описание для RSS', $values['description']);
        echo '<label for="body">Текст в Markdown</label><textarea id="body" name="body" required>' .
            saisho_escape($values['body']) . '</textarea>';
        echo '<button type="submit">Опубликовать</button></form>';
        $this->pageEnd();
    }

    private function field($name, $label, $value, $type = 'text', $required = false)
    {
        echo '<label for="' . saisho_escape($name) . '">' . saisho_escape($label) . '</label>';
        echo '<input id="' . saisho_escape($name) . '" name="' . saisho_escape($name) . '" type="' .
            saisho_escape($type) . '" value="' . saisho_escape($value) . '"' .
            ($required ? ' required' : '') . '>';
    }
}
