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
$onDark = 'assets/brand/motorchk-cms-horizontal-on-dark.png';
$approved = [
    $horizontal => [
        'sha256' => '84d66c0df2c28643a905d3a20e363da07864e02632d4397dbf141a57e44949cc',
        'source' => 'branding/approved-raster/transparent-2026-11-12/motorchk-cms-horizontal.png',
        'width' => 1656,
        'height' => 378,
    ],
    $stacked => [
        'sha256' => 'd5f7174494685e3abebc9c3e9cb0311ce56909f61ad55f7a62a3e127e17ed868',
        'source' => 'branding/approved-raster/transparent-2026-11-12/motorchk-cms-stacked.png',
        'width' => 1330,
        'height' => 1377,
    ],
    $onDark => [
        'sha256' => '5d478432ae9569496124da5d889a378218e99fadff610d7541dd4c80a67a8b09',
        'source' => 'branding/approved-raster/transparent-2026-11-12/motorchk-cms-horizontal-on-dark-outline.png',
        'width' => 1656,
        'height' => 378,
    ],
];

foreach ($approved as $rel => $expect) {
    $runtime = $corePath . '/' . $rel;
    $source = $corePath . '/' . $expect['source'];
    $hash = is_file($runtime) ? hash_file('sha256', $runtime) : '';
    check($hash === $expect['sha256'], "{$rel}: sha256 утверждённого PNG", $hash);
    check(is_file($source) && hash_file('sha256', $source) === $hash && $hash !== '', "{$rel}: побайтно совпадает с утверждённым исходником");
    [$w, $h, $color] = pngIhdr($runtime);
    check(
        $w === $expect['width'] && $h === $expect['height'] && $color === 6,
        "{$rel}: {$expect['width']}×{$expect['height']}, RGBA",
        "{$w}×{$h} color {$color}"
    );
}

$rejected = 'branding/approved-raster/transparent-2026-11-12/motorchk-cms-horizontal-on-dark.png';
$rejectedHash = is_file($corePath . '/' . $rejected) ? hash_file('sha256', $corePath . '/' . $rejected) : '';
check(
    $rejectedHash === '78ad2bf416c6cb8b10e2dbd265b84bdda807615c375c4805cc1ef44c5a89fd0c',
    'отклонённый вариант без контура сохранён в исходниках',
    $rejectedHash
);
check(
    $rejectedHash !== '' && $rejectedHash !== $approved[$onDark]['sha256'],
    'runtime on-dark — не отклонённый вариант без контура'
);
check(!is_file($corePath . '/assets/brand/motorchk-cms-horizontal-on-dark-outline.png'), 'в runtime нет файла с именем черновика outline');

$sumFile = $corePath . '/branding/approved-raster/SHA256SUMS';
$sumOk = is_file($sumFile);
$sumLines = $sumOk ? file($sumFile, FILE_IGNORE_NEW_LINES) : [];
check($sumOk && is_array($sumLines) && count($sumLines) >= 8, 'SHA256SUMS на месте', (string) count((array) $sumLines));
$listed = [];
foreach ($sumLines ?: [] as $line) {
    if (!preg_match('/^([a-f0-9]{64})  (.+)$/', $line, $m)) {
        $sumOk = false;
        check(false, 'строка SHA256SUMS', $line);
        continue;
    }
    $listed[$m[2]] = $m[1];
    $path = $corePath . '/branding/approved-raster/' . $m[2];
    $actual = is_file($path) ? hash_file('sha256', $path) : '';
    check($actual === $m[1], "исходник {$m[2]}", $actual);
}
$disk = [];
$rasterRoot = $corePath . '/branding/approved-raster';
$sumScan = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rasterRoot, FilesystemIterator::SKIP_DOTS));
foreach ($sumScan as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $rel = substr($file->getPathname(), strlen($rasterRoot) + 1);
    if ($rel === 'SHA256SUMS') {
        continue;
    }
    $disk[] = $rel;
}
$missingFromSums = array_values(array_diff($disk, array_keys($listed)));
check($missingFromSums === [], 'SHA256SUMS покрывает все исходники', implode(', ', $missingFromSums));

$origin = (string) @file_get_contents($corePath . '/branding/ORIGIN.txt');
$originHasRuntime = true;
foreach ($approved as $expect) {
    if (!str_contains($origin, $expect['sha256'])) {
        $originHasRuntime = false;
    }
}
check(
    str_contains($origin, 'cd7a128d5a98017dbe17a7af8da2a82e4a0107d0ac594bf5f10df0a8b8a0b076')
        && $originHasRuntime,
    'ORIGIN.txt хранит сумму прежнего архива и sha256 runtime PNG'
);

fwrite(STDOUT, "\nbrand: каталог исходников закрыт\n");

$brandHt = (string) @file_get_contents($corePath . '/branding/.htaccess');
check(str_contains($brandHt, 'Require all denied'), 'branding/.htaccess запрещает доступ');
$coreHt = (string) @file_get_contents($corePath . '/.htaccess');
check(preg_match('~RedirectMatch 404 \^/core/\(\?!assets/\)~', $coreHt) === 1, 'core/.htaccess закрывает всё кроме assets/');
$coreRe = '~^/core/(?!assets/)~';
check(preg_match($coreRe, '/core/branding/approved-raster/originals/enamel-hen-original.jpg') === 1, '/core/branding/… попадает под 404');
check(preg_match($coreRe, '/core/branding/approved-raster/delivery/motorchk-cms-horizontal.png') === 1, 'delivery PNG по HTTP закрыт');
check(
    preg_match($coreRe, '/core/' . $horizontal) !== 1
        && preg_match($coreRe, '/core/' . $stacked) !== 1
        && preg_match($coreRe, '/core/' . $onDark) !== 1,
    'runtime PNG открыты как /core/assets/'
);
check(
    preg_match($coreRe, '/core/branding/approved-raster/transparent-2026-11-12/motorchk-cms-horizontal-on-dark.png') === 1,
    'отклонённый PNG по HTTP закрыт'
);
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
    $logoRules = [];
    if (preg_match_all('/\.mc-brand\.mc-brand--logo\s*\{([^}]*)\}/', $css, $logoMatch)) {
        $logoRules = $logoMatch[1];
    }
    $logoBody = implode("\n", $logoRules);
    check($logoRules !== [], "{$label}: правило шапки на месте");
    check(!preg_match('/\bbackground\s*:/', $logoBody), "{$label}: белой плашки под логотипом шапки нет");
    check(!preg_match('/\bpadding\s*:/', $logoBody), "{$label}: у логотипа шапки нет отступов плашки");
    $authLogo = '';
    if (preg_match('/\.mc-auth__logo\s*\{([^}]*)\}/', $css, $authMatch)) {
        $authLogo = $authMatch[1];
    }
    check($authLogo !== '' && !preg_match('/\bbackground\s*:/', $authLogo), "{$label}: под знаком входа нет плашки");
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
        && str_contains($layout, 'src="/core/assets/brand/motorchk-cms-horizontal-on-dark.png"')
        && !str_contains($layout, 'src="/core/assets/brand/motorchk-cms-horizontal.png"')
        && str_contains($layout, 'width="1656"')
        && str_contains($layout, 'height="378"')
        && str_contains($layout, 'alt="Motorchk CMS"'),
    'шапка: on-dark знак, ссылка на страницы'
);
check(!str_contains($layout, 'mc-brand__square'), 'шапка больше не рисует красный квадрат вместо знака');
foreach (['Страницы', 'Меню', 'Заявки', 'Система', 'На сайт', 'Выйти', 'id="brand-fixture"'] as $piece) {
    check(str_contains($layout, $piece), "шапка сохраняет «{$piece}»");
}
check(str_contains($layout, 'v' . Version::current()), 'в шапке остаётся номер версии');

fwrite(STDOUT, "\n{$passed} passed, {$failures} failed\n");
exit($failures === 0 ? 0 : 1);
