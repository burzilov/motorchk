<?php

class MenuRenderer
{
    public static function render(array $items, string $mode = 'desktop'): void
    {
        if ($items === []) {
            return;
        }

        if ($mode === 'mobile') {
            self::renderMobile($items);
            return;
        }

        self::renderDesktop($items);
    }

    private static function renderDesktop(array $items, int $depth = 0): void
    {
        $listClass = $depth === 0
            ? 'flex items-center gap-1'
            : 'absolute z-50 min-w-48 rounded-lg border border-slate-200 bg-white py-1 shadow-lg ' . ($depth === 1 ? 'left-0 top-full' : 'left-full top-0');

        $listAttrs = $depth === 0 ? '' : ' x-show="open" x-cloak @click.outside="open = false"';

        echo '<ul class="' . htmlspecialchars($listClass) . '"' . $listAttrs . '>';
        foreach ($items as $index => $item) {
            self::renderDesktopItem($item, $depth, $index);
        }
        echo '</ul>';
    }

    private static function renderDesktopItem(array $item, int $depth, int $index): void
    {
        $hasChildren = !empty($item['children']);
        $active = !empty($item['active']);
        $linkClass = self::linkClass($active, $depth);

        if ($hasChildren) {
            echo '<li class="relative" x-data="{ open: false }">';
            echo '<div class="flex items-center">';
            echo self::renderLink($item, $linkClass . ' rounded-md px-3 py-2');
            echo '<button type="button" class="rounded p-1 text-slate-500 hover:bg-slate-100 hover:text-slate-800" @click.prevent="open = !open" :aria-expanded="open" aria-label="Раскрыть подменю">';
            echo self::chevronIcon();
            echo '</button>';
            echo '</div>';
            self::renderDesktop($item['children'], $depth + 1);
            echo '</li>';
            return;
        }

        echo '<li>';
        echo self::renderLink($item, $linkClass . ($depth === 0 ? ' block rounded-md px-3 py-2' : ' block px-4 py-2'));
        echo '</li>';
    }

    private static function renderMobile(array $items, int $depth = 0): void
    {
        $listClass = $depth === 0 ? 'space-y-1' : 'mt-1 space-y-1 border-l border-slate-200 pl-3';

        echo '<ul class="' . htmlspecialchars($listClass) . '">';
        foreach ($items as $index => $item) {
            self::renderMobileItem($item, $depth, $index);
        }
        echo '</ul>';
    }

    private static function renderMobileItem(array $item, int $depth, int $index): void
    {
        $hasChildren = !empty($item['children']);
        $active = !empty($item['active']);
        $linkClass = self::linkClass($active, $depth);

        if ($hasChildren) {
            echo '<li x-data="{ open: false }">';
            echo '<div class="flex items-center gap-1">';
            echo self::renderLink($item, $linkClass . ' flex-1 rounded-md px-3 py-2');
            echo '<button type="button" class="rounded px-2 py-2 text-slate-500 hover:bg-slate-100" @click="open = !open" :aria-expanded="open" aria-label="Раскрыть">';
            echo self::chevronIcon();
            echo '</button>';
            echo '</div>';
            echo '<div x-show="open" x-cloak>';
            self::renderMobile($item['children'], $depth + 1);
            echo '</div>';
            echo '</li>';
            return;
        }

        echo '<li>';
        echo self::renderLink($item, $linkClass . ' block rounded-md px-3 py-2');
        echo '</li>';
    }

    private static function renderLink(array $item, string $class): string
    {
        $externalAttrs = !empty($item['external']) ? ' target="_blank" rel="noopener"' : '';

        return '<a href="' . htmlspecialchars($item['url']) . '" class="' . htmlspecialchars($class) . '"' . $externalAttrs . '>'
            . htmlspecialchars($item['label'])
            . '</a>';
    }

    private static function linkClass(bool $active, int $depth): string
    {
        if ($active) {
            return 'font-semibold text-sky-700 bg-sky-50';
        }

        return 'text-slate-700 hover:bg-slate-100 hover:text-sky-700';
    }

    private static function chevronIcon(): string
    {
        return '<svg class="h-4 w-4 transition-transform" :class="open ? \'rotate-180\' : \'\'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 011.06.02L10 10.94l3.71-3.71a.75.75 0 111.06 1.06l-4.24 4.25a.75.75 0 01-1.06 0L5.21 8.29a.75.75 0 01.02-1.08z" clip-rule="evenodd"/></svg>';
    }
}
