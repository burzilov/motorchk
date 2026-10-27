<?php
/**
 * @return list<array{slug:string,title:string,parent:?string,depth:int,hasChildren:bool,published:bool}>
 */
function adminTreeFlatNodes(array $nodes, int $depth = 0, ?string $parent = null): array
{
    $flat = [];
    foreach ($nodes as $node) {
        $hasChildren = !empty($node['children']);
        $flat[] = [
            'slug' => $node['slug'],
            'title' => $node['title'],
            'parent' => $parent,
            'depth' => $depth,
            'hasChildren' => $hasChildren,
            'published' => !empty($node['published']),
        ];
        if ($hasChildren) {
            $flat = array_merge($flat, adminTreeFlatNodes($node['children'], $depth + 1, $node['slug']));
        }
    }

    return $flat;
}

/**
 * Flat tree rows (one index, indent by depth, no nested bordered lists).
 */
function renderAdminTreeRows(array $nodes, int $depth = 0): void
{
    foreach ($nodes as $node) {
        $slug = $node['slug'];
        $slugJs = htmlspecialchars(json_encode($slug, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
        $hasChildren = !empty($node['children']);
        $pathLabel = $slug === 'index' ? '/' : '/' . $slug;
        $createChildUrl = $slug === 'index'
            ? '/admin-panel/pages/create'
            : '/admin-panel/pages/create?parent=' . rawurlencode($slug);
        $publicUrl = AdminUrl::viewPage($slug, !empty($node['published']));
        $editUrl = AdminUrl::page($slug);
        echo '<li';
        echo ' class="mc-tree__item"';
        echo ' x-show="isVisible(' . $slugJs . ')"';
        echo ' x-cloak';
        echo '>';

        echo '<div class="mc-tree__row" style="--depth:' . (int) $depth . '">';

        if ($hasChildren) {
            echo '<button type="button"';
            echo ' class="mc-tree__toggle"';
            echo ' @click="toggle(' . $slugJs . ')"';
            echo ' :aria-expanded="isOpen(' . $slugJs . ').toString()"';
            echo ' :aria-label="isOpen(' . $slugJs . ') ? \'Свернуть\' : \'Развернуть\'">';
            echo '<svg class="mc-tree__chevron" :class="isOpen(' . $slugJs . ') && \'is-open\'" viewBox="0 0 20 20" fill="currentColor" width="14" height="14" aria-hidden="true">';
            echo '<path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/>';
            echo '</svg>';
            echo '</button>';
        } else {
            echo '<span class="mc-tree__leaf" aria-hidden="true"></span>';
        }

        echo '<div class="mc-tree__main">';
        echo '<div class="mc-tree__titleline">';
        echo '<a class="mc-tree__title" href="' . htmlspecialchars($editUrl) . '">';
        echo htmlspecialchars($node['title']);
        echo '</a>';
        if (empty($node['published'])) {
            echo '<span class="mc-tag mc-tag--draft">Черновик</span>';
        }
        echo '</div>';
        echo '<div class="mc-tree__path">' . htmlspecialchars($pathLabel) . '</div>';
        echo '</div>';

        echo '<div class="mc-tree__actions">';
        echo '<a class="mc-tree__action" href="' . htmlspecialchars($createChildUrl) . '">Дочерняя</a>';
        echo '<a class="mc-tree__action" href="' . htmlspecialchars($publicUrl) . '" target="_blank" rel="noopener noreferrer">На сайте</a>';
        echo '</div>';

        echo '</div>';
        echo '</li>';

        if ($hasChildren) {
            renderAdminTreeRows($node['children'], $depth + 1);
        }
    }
}
?>
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('pagesTree', (nodes, defaultOpen) => ({
            nodes,
            open: { ...defaultOpen },
            query: '',
            defaultOpen,
            get q() {
                return this.query.trim().toLowerCase();
            },
            nodeBySlug(slug) {
                return this.nodes.find((n) => n.slug === slug);
            },
            childrenOf(slug) {
                return this.nodes.filter((n) => n.parent === slug);
            },
            ancestorsOf(slug) {
                const chain = [];
                let current = this.nodeBySlug(slug);
                while (current && current.parent) {
                    chain.push(current.parent);
                    current = this.nodeBySlug(current.parent);
                }
                return chain;
            },
            matches(slug) {
                const node = this.nodeBySlug(slug);
                if (!node) return false;
                const q = this.q;
                if (!q) return true;
                return node.title.toLowerCase().includes(q) || node.slug.toLowerCase().includes(q);
            },
            hasMatchInSubtree(slug) {
                if (this.matches(slug)) return true;
                return this.childrenOf(slug).some((child) => this.hasMatchInSubtree(child.slug));
            },
            ancestorsOpen(slug) {
                return this.ancestorsOf(slug).every((ancestorSlug) => !!this.open[ancestorSlug]);
            },
            isVisible(slug) {
                const node = this.nodeBySlug(slug);
                if (!node) return false;

                if (this.q) {
                    return this.hasMatchInSubtree(slug);
                }

                if (node.depth === 0) {
                    return true;
                }

                return this.ancestorsOpen(slug);
            },
            isOpen(slug) {
                if (this.q) {
                    return this.childrenOf(slug).some((child) => this.hasMatchInSubtree(child.slug));
                }
                return !!this.open[slug];
            },
            toggle(slug) {
                if (this.q) return;
                this.open[slug] = !this.open[slug];
            },
            clearSearch() {
                this.query = '';
            },
            init() {
                this.$watch('query', (value) => {
                    if (!String(value).trim()) {
                        this.open = { ...this.defaultOpen };
                    }
                });
            },
        }));
    });
</script>

<div class="mc-head">
    <h1 class="mc-head__title">Страницы</h1>
    <a href="/admin-panel/pages/create" class="mc-btn mc-btn--primary">
        Создать страницу
    </a>
</div>

<?php
$flatNodes = adminTreeFlatNodes($tree);
$defaultOpen = [];
foreach ($flatNodes as $node) {
    if ($node['hasChildren'] && $node['depth'] === 0) {
        $defaultOpen[$node['slug']] = true;
    }
}
?>

<div
    class="mc-stack"
    x-data="pagesTree(<?= htmlspecialchars(json_encode($flatNodes, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode((object) $defaultOpen, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)"
>
    <div class="mc-search">
        <label class="visually-hidden" for="pages-search">Поиск страниц</label>
        <input
            id="pages-search"
            type="search"
            x-model="query"
            placeholder="Поиск по названию или адресу…"
            class="mc-input mc-search__input"
        >
        <button
            type="button"
            class="mc-btn mc-btn--quiet"
            x-show="query.trim()"
            x-cloak
            @click="clearSearch()"
        >
            Сбросить
        </button>
    </div>

    <?php if ($tree === []): ?>
        <p class="mc-empty">
            Страниц пока нет.
        </p>
    <?php else: ?>
        <div class="admin-page-tree mc-tree">
            <ul>
                <?php renderAdminTreeRows($tree); ?>
            </ul>
        </div>
        <p class="mc-muted" x-show="q && !nodes.some((n) => matches(n.slug))" x-cloak>
            Ничего не найдено.
        </p>
    <?php endif; ?>
</div>
