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
 * Flat tree rows (one card, indent by depth — no nested bordered lists).
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
        $pad = 12 + ($depth * 20);

        echo '<li';
        echo ' class="group border-b border-slate-100 last:border-b-0"';
        echo ' x-show="isVisible(' . $slugJs . ')"';
        echo ' x-cloak';
        echo '>';

        echo '<div class="flex items-center gap-2 py-2.5 pr-3 transition-colors hover:bg-slate-50" style="padding-left:' . (int) $pad . 'px">';

        if ($hasChildren) {
            echo '<button type="button"';
            echo ' class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-md text-slate-400 hover:bg-slate-200/70 hover:text-slate-700"';
            echo ' @click="toggle(' . $slugJs . ')"';
            echo ' :aria-expanded="isOpen(' . $slugJs . ').toString()"';
            echo ' :aria-label="isOpen(' . $slugJs . ') ? \'Свернуть\' : \'Развернуть\'">';
            echo '<svg class="h-3.5 w-3.5 transition-transform" :class="isOpen(' . $slugJs . ') && \'rotate-90\'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">';
            echo '<path fill-rule="evenodd" d="M7.21 14.77a.75.75 0 01.02-1.06L11.168 10 7.23 6.29a.75.75 0 111.04-1.08l4.5 4.25a.75.75 0 010 1.08l-4.5 4.25a.75.75 0 01-1.06-.02z" clip-rule="evenodd"/>';
            echo '</svg>';
            echo '</button>';
        } else {
            echo '<span class="inline-flex h-7 w-7 shrink-0 items-center justify-center" aria-hidden="true">';
            echo '<span class="h-1 w-1 rounded-full bg-slate-300"></span>';
            echo '</span>';
        }

        echo '<div class="min-w-0 flex-1">';
        echo '<div class="flex flex-wrap items-center gap-2">';
        echo '<a class="truncate text-sm font-medium text-slate-800 hover:text-sky-700" href="' . htmlspecialchars($editUrl) . '">';
        echo htmlspecialchars($node['title']);
        echo '</a>';
        if (empty($node['published'])) {
            echo '<span class="rounded-md bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium leading-none text-amber-800 ring-1 ring-inset ring-amber-200/80">Черновик</span>';
        }
        echo '</div>';
        echo '<div class="mt-0.5 truncate font-mono text-[11px] text-slate-400">' . htmlspecialchars($pathLabel) . '</div>';
        echo '</div>';

        echo '<div class="flex shrink-0 items-center gap-1 opacity-100 sm:opacity-0 sm:transition-opacity sm:group-hover:opacity-100 sm:focus-within:opacity-100">';
        echo '<a class="rounded-md px-2 py-1 text-xs text-slate-500 hover:bg-white hover:text-sky-700 hover:shadow-sm" href="' . htmlspecialchars($createChildUrl) . '">Дочерняя</a>';
        echo '<a class="rounded-md px-2 py-1 text-xs text-slate-500 hover:bg-white hover:text-sky-700 hover:shadow-sm" href="' . htmlspecialchars($publicUrl) . '" target="_blank" rel="noopener noreferrer">На сайте</a>';
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

<div class="mb-6 flex flex-wrap items-center justify-between gap-3">
    <h1 class="text-2xl font-bold">Страницы</h1>
    <a href="/admin-panel/pages/create" class="rounded bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700">
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
    class="space-y-4"
    x-data="pagesTree(<?= htmlspecialchars(json_encode($flatNodes, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode((object) $defaultOpen, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)"
>
    <div class="flex flex-wrap items-center gap-2">
        <label class="sr-only" for="pages-search">Поиск страниц</label>
        <input
            id="pages-search"
            type="search"
            x-model="query"
            placeholder="Поиск по названию или адресу…"
            class="w-full max-w-md rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm"
        >
        <button
            type="button"
            class="text-sm text-slate-600 hover:text-sky-700"
            x-show="query.trim()"
            x-cloak
            @click="clearSearch()"
        >
            Сбросить
        </button>
    </div>

    <?php if ($tree === []): ?>
        <p class="rounded-xl border border-dashed border-slate-300 bg-white px-4 py-8 text-center text-sm text-slate-500">
            Страниц пока нет.
        </p>
    <?php else: ?>
        <div class="admin-page-tree overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <ul class="m-0 list-none p-0">
                <?php renderAdminTreeRows($tree); ?>
            </ul>
        </div>
        <p class="text-sm text-slate-500" x-show="q && !nodes.some((n) => matches(n.slug))" x-cloak>
            Ничего не найдено.
        </p>
    <?php endif; ?>
</div>
