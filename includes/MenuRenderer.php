<?php

/**
 * Меню списком без классов CSS-фреймворков и без JS — оформление задаёт тема.
 *
 *   <ul class="menu">
 *     <li class="menu__item is-active has-children">
 *       <a class="menu__link" href="/catalog" aria-current="page">Каталог</a>
 *       <ul class="menu__sub">…</ul>
 *     </li>
 *   </ul>
 *
 * Классы строятся от options['class'] (по умолчанию «menu»): {class}__item, {class}__link, {class}__sub.
 * is-active — страница пункта или раздел, в котором открыта страница; aria-current="page" —
 * только сама страница пункта. Нужна другая разметка — тема обходит данные $menu / $menus сама
 * (формат пункта — в docs/12-themes.md).
 */
class MenuRenderer
{
    /**
     * @param array<int, array<string, mixed>> $items пункты из MenuBuilder::build()
     * @param array{class?: string, depth?: int} $options class — корневой класс; depth — сколько уровней выводить (1–3, по умолчанию все)
     */
    public static function render(array $items, array $options = []): void
    {
        echo self::html($items, $options);
    }

    /** То же, что render(), но строкой. */
    public static function html(array $items, array $options = []): string
    {
        $class = trim((string) ($options['class'] ?? 'menu'));
        if ($class === '' || !preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $class)) {
            $class = 'menu';
        }
        $depth = max(1, min(3, (int) ($options['depth'] ?? 3)));

        return self::branch($items, $class, $depth, 0);
    }

    private static function branch(array $items, string $class, int $maxDepth, int $depth): string
    {
        if ($items === [] || $depth >= $maxDepth) {
            return '';
        }

        $html = '<ul class="' . $class . ($depth === 0 ? '' : '__sub') . '">';
        foreach ($items as $item) {
            $children = $depth + 1 < $maxDepth ? (array) ($item['children'] ?? []) : [];
            $classes = [$class . '__item'];
            if (!empty($item['active'])) {
                $classes[] = 'is-active';
            }
            if ($children !== []) {
                $classes[] = 'has-children';
            }

            $attrs = ' class="' . $class . '__link" href="' . self::e((string) ($item['url'] ?? '')) . '"';
            if (!empty($item['current'])) {
                $attrs .= ' aria-current="page"';
            }
            if (!empty($item['external'])) {
                $attrs .= ' target="_blank" rel="noopener"';
            }

            $html .= '<li class="' . implode(' ', $classes) . '"><a' . $attrs . '>' . self::e((string) ($item['label'] ?? '')) . '</a>'
                . self::branch($children, $class, $maxDepth, $depth + 1)
                . '</li>';
        }

        return $html . '</ul>';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
