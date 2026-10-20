<?php
/**
 * Меню темы-примера (Tailwind + Alpine.js) из данных ядра: $menu — пункты MenuBuilder,
 * $menuMode — 'desktop' (выпадающие подменю) или 'mobile' (раскрывающийся список).
 * Ядро даёт только данные и нейтральный MenuRenderer; классы и поведение — забота темы.
 */
$exampleMenuItems = $menu ?? [];
if ($exampleMenuItems === []) {
    return;
}

$menuEsc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
$menuChevron = '<svg class="h-4 w-4 transition-transform" :class="open ? \'rotate-180\' : \'\'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd"/></svg>';
$menuLink = static function (array $item, string $class) use ($menuEsc): string {
    $class = (!empty($item['active']) ? 'font-semibold text-sky-700 bg-sky-50' : 'text-slate-700 hover:bg-slate-100 hover:text-sky-700') . ' ' . $class;

    return '<a href="' . $menuEsc((string) $item['url']) . '" class="' . $menuEsc($class) . '"'
        . (!empty($item['current']) ? ' aria-current="page"' : '')
        . (!empty($item['external']) ? ' target="_blank" rel="noopener"' : '')
        . '>' . $menuEsc((string) $item['label']) . '</a>';
};

$menuDesktop = static function (array $items, int $depth = 0) use (&$menuDesktop, $menuLink, $menuChevron): string {
    $listClass = $depth === 0
        ? 'flex items-center gap-1'
        : 'absolute z-50 min-w-48 rounded-lg border border-slate-200 bg-white py-1 shadow-lg ' . ($depth === 1 ? 'left-0 top-full' : 'left-full top-0');
    $html = '<ul class="' . $listClass . '"' . ($depth === 0 ? '' : ' x-show="open" x-cloak @click.outside="open = false"') . '>';
    foreach ($items as $item) {
        if (!empty($item['children'])) {
            $html .= '<li class="relative" x-data="{ open: false }"><div class="flex items-center">'
                . $menuLink($item, 'rounded-md px-3 py-2')
                . '<button type="button" class="rounded p-1 text-slate-500 hover:bg-slate-100 hover:text-slate-800" @click.prevent="open = !open" :aria-expanded="open" aria-label="Раскрыть подменю">' . $menuChevron . '</button>'
                . '</div>' . $menuDesktop($item['children'], $depth + 1) . '</li>';
            continue;
        }
        $html .= '<li>' . $menuLink($item, $depth === 0 ? 'block rounded-md px-3 py-2' : 'block px-4 py-2') . '</li>';
    }

    return $html . '</ul>';
};

$menuMobile = static function (array $items, int $depth = 0) use (&$menuMobile, $menuLink, $menuChevron): string {
    $html = '<ul class="' . ($depth === 0 ? 'space-y-1' : 'mt-1 space-y-1 border-l border-slate-200 pl-3') . '">';
    foreach ($items as $item) {
        if (!empty($item['children'])) {
            $html .= '<li x-data="{ open: false }"><div class="flex items-center gap-1">'
                . $menuLink($item, 'flex-1 rounded-md px-3 py-2')
                . '<button type="button" class="rounded px-2 py-2 text-slate-500 hover:bg-slate-100" @click="open = !open" :aria-expanded="open" aria-label="Раскрыть">' . $menuChevron . '</button>'
                . '</div><div x-show="open" x-cloak>' . $menuMobile($item['children'], $depth + 1) . '</div></li>';
            continue;
        }
        $html .= '<li>' . $menuLink($item, 'block rounded-md px-3 py-2') . '</li>';
    }

    return $html . '</ul>';
};

echo ($menuMode ?? 'desktop') === 'mobile' ? $menuMobile($exampleMenuItems) : $menuDesktop($exampleMenuItems);
