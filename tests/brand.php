<?php

/**
 * Логотип админки: байты утверждённых PNG, закрытые исходники, шапка и форма входа.
 * php tests/brand.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$corePath = dirname(__DIR__);
require_once $corePath . '/includes/Csrf.php';
require_once $corePath . '/includes/Version.php';

$failures = 0;
$passed = 0;

function check(bool $ok, string $name, string $details = ''): void
{
    global $failures, $passed;
    if ($ok) {
        $passed++;
        fwrite(STDOUT, "  ok   {$name}\n");
        return;
    }
    $failures++;
    fwrite(STDOUT, "  FAIL {$name}" . ($details !== '' ? " — {$details}" : '') . "\n");
}

/** @return array{0:int,1:int,2:int} width, height, color type */
function pngIhdr(string $path): array
{
    $fh = fopen($path, 'rb');
    if ($fh === false) {
        return [0, 0, -1];
    }
    $sig = fread($fh, 8);
    $lenRaw = fread($fh, 4);
    $type = fread($fh, 4);
    $data = fread($fh, 13);
    fclose($fh);
    if ($sig !== "\x89PNG\r\n\x1a\n" || $type !== 'IHDR' || !is_string($data) || strlen($data) < 10) {
        return [0, 0, -1];
    }
    $ihdr = unpack('Nwidth/Nheight/Cbit/Ccolor', $data);

    return [(int) $ihdr['width'], (int) $ihdr['height'], (int) $ihdr['color']];
}

fwrite(STDOUT, "brand: утверждённые PNG\n");

$horizontal = 'assets/brand/motorchk-cms-horizontal.png';
$stacked = 'assets/brand/motorchk-cms-stacked.png';
$approved = [
    $horizontal => [
        'sha256' => 'abf8f98d85f99de2c431b6e03548961f6e3417cd8dcaf9ce98b4bb3aac9136e2',
        'source' => 'branding/approved-raster/delivery/motorchk-cms-horizontal.png',
        'width' => 1656,
        'height' => 378,
    ],
    $stacked => [
        'sha256' => '30d839d77a6c47caf837619e4682eeedbd380ff8583741a9eca9724115e2e235',
        'source' => 'branding/approved-raster/delivery/motorchk-cms-stacked.png',
        'width' => 1330,
        'height' => 1377,
    ],
];

foreach ($approved as $rel => $expect) {
    $runtime = $corePath . '/' . $rel;
    $source = $corePath . '/' . $expect['source'];
    $hash = is_file($runtime) ? hash_file('sha256', $runtime) : '';
    check($hash === $expect['sha256'], "{$rel}: sha256 утверждённого PNG", $hash);
    check(is_file($source) && hash_file('sha256', $source) === $hash && $hash !== '', "{$rel}: побайтно совпадает с delivery/");
    [$w, $h, $color] = pngIhdr($runtime);
    check(
        $w === $expect['width'] && $h === $expect['height'] && $color === 2,
        "{$rel}: {$expect['width']}×{$expect['height']}, RGB без альфы",
        "{$w}×{$h} color {$color}"
    );
}

$sumFile = $corePath . '/branding/approved-raster/SHA256SUMS';
$sumOk = is_file($sumFile);
$sumLines = $sumOk ? file($sumFile, FILE_IGNORE_NEW_LINES) : [];
check($sumOk && is_array($sumLines) && count($sumLines) >= 8, 'SHA256SUMS на месте', (string) count((array) $sumLines));
foreach ($sumLines ?: [] as $line) {
    if (!preg_match('/^([a-f0-9]{64})  (.+)$/', $line, $m)) {
        $sumOk = false;
        check(false, 'строка SHA256SUMS', $line);
        continue;
    }
    $path = $corePath . '/branding/approved-raster/' . $m[2];
    $actual = is_file($path) ? hash_file('sha256', $path) : '';
    check($actual === $m[1], "исходник {$m[2]}", $actual);
}

$origin = (string) @file_get_contents($corePath . '/branding/ORIGIN.txt');
check(
    str_contains($origin, 'cd7a128d5a98017dbe17a7af8da2a82e4a0107d0ac594bf5f10df0a8b8a0b076')
        && str_contains($origin, $approved[$horizontal]['sha256'])
        && str_contains($origin, $approved[$stacked]['sha256']),
    'ORIGIN.txt хранит суммы архива и runtime PNG'
);

fwrite(STDOUT, "\nbrand: каталог исходников закрыт\n");

$brandHt = (string) @file_get_contents($corePath . '/branding/.htaccess');
check(str_contains($brandHt, 'Require all denied'), 'branding/.htaccess запрещает доступ');
$coreHt = (string) @file_get_contents($corePath . '/.htaccess');
check(preg_match('~RedirectMatch 404 \^/core/\(\?!assets/\)~', $coreHt) === 1, 'core/.htaccess закрывает всё кроме assets/');
$coreRe = '~^/core/(?!assets/)~';
check(preg_match($coreRe, '/core/branding/approved-raster/originals/enamel-hen-original.jpg') === 1, '/core/branding/… попадает под 404');
check(preg_match($coreRe, '/core/branding/approved-raster/delivery/motorchk-cms-horizontal.png') === 1, 'delivery PNG по HTTP закрыт');
check(preg_match($coreRe, '/core/' . $horizontal) !== 1 && preg_match($coreRe, '/core/' . $stacked) !== 1, 'runtime PNG открыты как /core/assets/');
check(!str_starts_with($horizontal, 'branding/') && !str_contains($horizontal, '..'), 'runtime отделён от исходников');

$build = (string) @file_get_contents($corePath . '/.github/build-release.sh');
check(str_contains($build, "--exclude '/branding'"), 'release zip не забирает branding/');
check(!str_contains($build, "--exclude '/assets'"), 'release zip не исключает runtime assets/');

$leaks = [];
$scan = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($corePath, FilesystemIterator::SKIP_DOTS));
foreach ($scan as $file) {
    $rel = substr($file->getPathname(), strlen($corePath) + 1);
    if (!preg_match('~^(admin-panel|includes|assets|scripts|skeleton)/.*\.(php|css|js|html)$~', $rel) || str_ends_with($rel, '.min.js')) {
        continue;
    }
    $text = (string) file_get_contents($file->getPathname());
    if (str_contains($text, '/core/branding/') || str_contains($text, 'branding/approved-raster')) {
        $leaks[] = $rel;
    }
}
check($leaks === [], 'шаблоны и статика не ссылаются на исходники', implode(', ', $leaks));

fwrite(STDOUT, "\nbrand: шапка и форма входа\n");

$cssSrc = (string) file_get_contents($corePath . '/scripts/css/admin.css');
$cssBuilt = (string) file_get_contents($corePath . '/assets/css/admin.css');
foreach (['scripts/css/admin.css' => $cssSrc, 'assets/css/admin.css' => $cssBuilt] as $label => $css) {
    check(str_contains($css, '.mc-brand.mc-brand--logo') && str_contains($css, 'background: #fff'), "{$label}: белая плашка под горизонтальный PNG");
    check(str_contains($css, 'aspect-ratio: 1656 / 378') && str_contains($css, 'aspect-ratio: 1330 / 1377'), "{$label}: пропорции обоих знаков");
    check(
        preg_match('/\.mc-brand__logo\s*\{[^}]*object-fit/', $css) !== 1
            && preg_match('/\.mc-auth__logo\s*\{[^}]*object-fit/', $css) !== 1,
        "{$label}: логотипы без object-fit"
    );
}
check(str_contains($cssSrc, 'width: 9.875rem') && str_contains($cssSrc, 'width: 7.25rem'), 'исходник CSS: размер шапки на узком экране меньше');
check(str_contains($cssSrc, 'width: min(11.5rem, 100%)'), 'исходник CSS: компактный знак не шире колонки входа');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$error = null;
ob_start();
require $corePath . '/admin-panel/templates/login.php';
$login = (string) ob_get_clean();
check(substr_count($login, 'mc-auth__logo') === 1, 'экран входа: один компактный знак');
check(
    str_contains($login, 'src="/core/assets/brand/motorchk-cms-stacked.png"')
        && str_contains($login, 'width="1330"')
        && str_contains($login, 'height="1377"')
        && str_contains($login, 'alt="Motorchk CMS"'),
    'экран входа: src, intrinsic size и alt'
);
check(!str_contains($login, 'mc-brand__square'), 'экран входа больше не рисует текстовый знак с квадратом');
foreach ([
    'id="login-form"' => 'форма входа: контейнер #login-form',
    'hx-post="/admin-panel/login"' => 'форма входа: hx-post',
    'hx-target="#login-form"' => 'форма входа: hx-target',
    'hx-swap="outerHTML"' => 'форма входа: hx-swap',
    'id="username"' => 'форма входа: поле логина',
    'name="username"' => 'форма входа: name username',
    'id="password"' => 'форма входа: поле пароля',
    'name="password"' => 'форма входа: name password',
    'name="_csrf"' => 'форма входа: CSRF',
    'Вход в админку' => 'форма входа: заголовок',
] as $needle => $label) {
    check(str_contains($login, $needle), $label);
}
check(preg_match('/<button\b[^>]*>\s*Войти\s*<\/button>/', $login) === 1, 'форма входа: кнопка «Войти»');

$error = 'Неверный пароль';
ob_start();
require $corePath . '/admin-panel/templates/login-form.php';
$failed = (string) ob_get_clean();
check(str_contains($failed, 'role="alert"') && str_contains($failed, 'Неверный пароль') && str_contains($failed, 'name="password"'), 'ошибка входа остаётся в форме');

$_SERVER['REQUEST_URI'] = '/admin-panel/pages';
$pageTitle = 'Страницы';
$layoutFullscreen = false;
$layoutScripts = [];
$layoutStyles = [];
$layoutScriptsAfterAlpine = [];
$pluginMenu = [];
$coreUpdate = null;
$content = '<p id="brand-fixture">Страницы</p>';
ob_start();
require $corePath . '/admin-panel/templates/layout.php';
$layout = (string) ob_get_clean();
check(
    str_contains($layout, 'class="mc-brand mc-brand--logo"')
        && str_contains($layout, 'src="/core/assets/brand/motorchk-cms-horizontal.png"')
        && str_contains($layout, 'width="1656"')
        && str_contains($layout, 'height="378"')
        && str_contains($layout, 'alt="Motorchk CMS"'),
    'шапка: горизонтальный знак, ссылка на страницы'
);
check(!str_contains($layout, 'mc-brand__square'), 'шапка больше не рисует красный квадрат вместо знака');
foreach (['Страницы', 'Меню', 'Заявки', 'Система', 'На сайт', 'Выйти', 'id="brand-fixture"'] as $piece) {
    check(str_contains($layout, $piece), "шапка сохраняет «{$piece}»");
}
check(str_contains($layout, 'v' . Version::current()), 'в шапке остаётся номер версии');

fwrite(STDOUT, "\n{$passed} passed, {$failures} failed\n");
exit($failures === 0 ? 0 : 1);
