<?php
$locations = $locations ?? [];
$locationId = $locationId ?? MenuWriter::DEFAULT_LOCATION;
$locationLabel = $locationLabel ?? $locationId;
$items = $items ?? [];
$pages = $pages ?? [];
$defaultLocation = $defaultLocation ?? MenuWriter::DEFAULT_LOCATION;
$itemsJson = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$pagesJson = json_encode($pages, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
?>
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('menuEditor', (initialItems, initialPages, locationId, locationLabel, defaultLocation) => ({
            items: initialItems,
            pages: initialPages,
            locationId,
            locationLabel,
            defaultLocation,
            dirty: false,
            addMode: 'page',
            pageSlug: '',
            linkLabel: '',
            linkUrl: '',
            newId: '',
            newLabel: '',
            editLabel: locationLabel,
            editId: locationId,
            dragFrom: null,

            markDirty() {
                this.dirty = true;
            },

            serializeItems() {
                return JSON.stringify(this.items);
            },

            findPage(slug) {
                return this.pages.find((p) => p.slug === slug) || null;
            },

            addItem() {
                if (this.addMode === 'page') {
                    if (!this.pageSlug) return;
                    const page = this.findPage(this.pageSlug);
                    this.items.push({
                        label: page ? page.title : this.pageSlug,
                        slug: this.pageSlug,
                        children: [],
                    });
                    this.pageSlug = '';
                } else {
                    const url = this.linkUrl.trim();
                    const label = this.linkLabel.trim() || url;
                    if (!url) return;
                    const node = { label, url, children: [] };
                    if (/^(https?:)?\/\//i.test(url)) {
                        node.external = true;
                    }
                    this.items.push(node);
                    this.linkLabel = '';
                    this.linkUrl = '';
                }
                this.markDirty();
            },

            removeAt(path) {
                const { parent, index } = this.resolvePath(path);
                parent.splice(index, 1);
                this.markDirty();
            },

            move(path, dir) {
                const { parent, index } = this.resolvePath(path);
                const target = index + dir;
                if (target < 0 || target >= parent.length) return;
                const tmp = parent[index];
                parent[index] = parent[target];
                parent[target] = tmp;
                this.markDirty();
            },

            nestUnderPrev(path) {
                const { parent, index } = this.resolvePath(path);
                if (index === 0) return;
                const item = parent.splice(index, 1)[0];
                const prev = parent[index - 1];
                if (!Array.isArray(prev.children)) prev.children = [];
                if (this.depthOf(path) >= 3) {
                    parent.splice(index, 0, item);
                    return;
                }
                prev.children.push(item);
                this.markDirty();
            },

            unnest(path) {
                if (path.length < 2) return;
                const parentPath = path.slice(0, -1);
                const { parent: grandParent, index: parentIndex } = this.resolvePath(parentPath);
                const parentNode = grandParent[parentIndex];
                const childIndex = path[path.length - 1];
                const item = parentNode.children.splice(childIndex, 1)[0];
                grandParent.splice(parentIndex + 1, 0, item);
                this.markDirty();
            },

            depthOf(path) {
                return path.length;
            },

            resolvePath(path) {
                let parent = this.items;
                for (let i = 0; i < path.length - 1; i++) {
                    parent = parent[path[i]].children;
                }
                return { parent, index: path[path.length - 1] };
            },

            itemMeta(item) {
                if (item.slug) return item.slug;
                return item.url || '';
            },
        }));
    });
</script>

<div
    id="menu-editor"
    class="space-y-6"
    x-data="menuEditor(
        <?= $itemsJson ?>,
        <?= $pagesJson ?>,
        <?= htmlspecialchars(json_encode($locationId, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>,
        <?= htmlspecialchars(json_encode($locationLabel, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>,
        <?= htmlspecialchars(json_encode($defaultLocation, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>
    )"
>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">Меню</h1>
            <p class="mt-1 text-sm text-slate-500">Именованные меню в <code class="text-xs">content/_menus.yaml</code></p>
        </div>
    </div>

    <?php if (!empty($saved)): ?>
        <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800">Сохранено</div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="flex flex-wrap gap-2 border-b border-slate-200 pb-3">
        <?php foreach ($locations as $loc): ?>
            <a
                href="/admin-panel/menu?location=<?= rawurlencode($loc['id']) ?>"
                class="rounded-md px-3 py-1.5 text-sm <?= $loc['id'] === $locationId ? 'bg-slate-900 text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>"
                hx-get="/admin-panel/menu?location=<?= rawurlencode($loc['id']) ?>"
                hx-target="#menu-editor"
                hx-select="#menu-editor"
                hx-swap="outerHTML"
            >
                <?= htmlspecialchars($loc['label']) ?>
                <span class="opacity-60">(<?= htmlspecialchars($loc['id']) ?>)</span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <h2 class="mb-3 text-sm font-semibold text-slate-800">Текущее меню</h2>
            <form
                method="post"
                action="/admin-panel/menu"
                hx-post="/admin-panel/menu"
                hx-target="#menu-editor"
                hx-swap="outerHTML"
                class="space-y-3"
            >
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="update_location">
                <input type="hidden" name="location" value="<?= htmlspecialchars($locationId) ?>">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Название</label>
                    <input name="label" x-model="editLabel" type="text" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </div>
                <?php if ($locationId !== $defaultLocation): ?>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-slate-600">Id</label>
                        <input name="new_id" x-model="editId" type="text" pattern="[a-z][a-z0-9_-]*" class="w-full rounded border border-slate-300 px-3 py-2 font-mono text-sm">
                    </div>
                <?php else: ?>
                    <p class="text-xs text-slate-500">Id <code>main</code> нельзя изменить или удалить.</p>
                <?php endif; ?>
                <button type="submit" class="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Обновить меню</button>
            </form>

            <?php if ($locationId !== $defaultLocation): ?>
                <form
                    method="post"
                    action="/admin-panel/menu"
                    hx-post="/admin-panel/menu"
                    hx-target="#menu-editor"
                    hx-swap="outerHTML"
                    class="mt-4 border-t border-slate-100 pt-4"
                    onsubmit="return confirm('Удалить это меню?');"
                >
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="delete_location">
                    <input type="hidden" name="location" value="<?= htmlspecialchars($locationId) ?>">
                    <button type="submit" class="text-sm text-red-700 hover:underline">Удалить меню</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
            <h2 class="mb-3 text-sm font-semibold text-slate-800">Новое меню</h2>
            <form
                method="post"
                action="/admin-panel/menu"
                hx-post="/admin-panel/menu"
                hx-target="#menu-editor"
                hx-swap="outerHTML"
                class="space-y-3"
            >
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="create_location">
                <input type="hidden" name="location" value="<?= htmlspecialchars($locationId) ?>">
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Id</label>
                    <input name="new_id" type="text" required pattern="[a-z][a-z0-9_-]*" placeholder="ceo-lead" class="w-full rounded border border-slate-300 px-3 py-2 font-mono text-sm">
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-slate-600">Название</label>
                    <input name="new_label" type="text" required placeholder="CEO Lead" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </div>
                <button type="submit" class="rounded bg-sky-700 px-3 py-1.5 text-sm text-white hover:bg-sky-600">Создать</button>
            </form>
        </section>
    </div>

    <section class="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-sm font-semibold text-slate-800">Пункты: <?= htmlspecialchars($locationLabel) ?></h2>
            <span class="text-xs text-slate-400" x-show="dirty">Есть несохранённые изменения</span>
        </div>

        <div class="mb-4 flex flex-wrap items-end gap-3 rounded-lg bg-slate-50 p-3">
            <div>
                <label class="mb-1 block text-xs font-medium text-slate-600">Тип</label>
                <select x-model="addMode" class="rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="page">Страница</option>
                    <option value="link">URL / якорь</option>
                </select>
            </div>
            <div x-show="addMode === 'page'" class="min-w-[12rem] flex-1">
                <label class="mb-1 block text-xs font-medium text-slate-600">Страница</label>
                <select x-model="pageSlug" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="">Выберите…</option>
                    <template x-for="page in pages" :key="page.slug">
                        <option :value="page.slug" x-text="page.title + ' (' + page.slug + ')' + (page.published ? '' : ' — черновик')"></option>
                    </template>
                </select>
            </div>
            <template x-if="addMode === 'link'">
                <div class="flex min-w-[12rem] flex-1 flex-wrap gap-3">
                    <div class="min-w-[8rem] flex-1">
                        <label class="mb-1 block text-xs font-medium text-slate-600">Подпись</label>
                        <input x-model="linkLabel" type="text" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm" placeholder="Квиз">
                    </div>
                    <div class="min-w-[8rem] flex-1">
                        <label class="mb-1 block text-xs font-medium text-slate-600">URL</label>
                        <input x-model="linkUrl" type="text" class="w-full rounded border border-slate-300 px-2 py-1.5 font-mono text-sm" placeholder="#quiz или https://…">
                    </div>
                </div>
            </template>
            <button type="button" class="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700" @click="addItem()">Добавить</button>
        </div>

        <template x-if="items.length === 0">
            <p class="py-6 text-center text-sm text-slate-500">Пока нет пунктов — добавьте страницу или ссылку.</p>
        </template>

        <div class="space-y-1" id="menu-items-tree">
            <template x-for="(item, index) in items" :key="'root-'+index+'-'+(item.slug||item.url||'')">
                <div>
                    <div class="flex items-center gap-2 rounded-lg px-2 py-2 hover:bg-slate-50" :style="'padding-left: 0.5rem'">
                        <div class="min-w-0 flex-1">
                            <input
                                type="text"
                                class="w-full rounded border border-transparent bg-transparent px-1 py-0.5 text-sm font-medium text-slate-800 hover:border-slate-200 focus:border-slate-300 focus:bg-white"
                                x-model="item.label"
                                @input="markDirty()"
                            >
                            <div class="truncate px-1 font-mono text-[11px] text-slate-400" x-text="itemMeta(item)"></div>
                        </div>
                        <div class="flex shrink-0 gap-1">
                            <button type="button" class="rounded px-1.5 py-1 text-xs text-slate-500 hover:bg-slate-200" @click="move([index], -1)" title="Вверх">↑</button>
                            <button type="button" class="rounded px-1.5 py-1 text-xs text-slate-500 hover:bg-slate-200" @click="move([index], 1)" title="Вниз">↓</button>
                            <button type="button" class="rounded px-1.5 py-1 text-xs text-slate-500 hover:bg-slate-200" @click="nestUnderPrev([index])" title="Сделать дочерним">↳</button>
                            <button type="button" class="rounded px-1.5 py-1 text-xs text-red-600 hover:bg-red-50" @click="removeAt([index])" title="Удалить">×</button>
                        </div>
                    </div>
                    <template x-if="item.children && item.children.length">
                        <ul class="ml-4 space-y-1 border-l border-slate-200 pl-2">
                            <template x-for="(child, cIndex) in item.children" :key="'c-'+index+'-'+cIndex">
                                <li>
                                    <div class="flex items-center gap-2 rounded-lg px-2 py-2 hover:bg-slate-50">
                                        <div class="min-w-0 flex-1">
                                            <input type="text" class="w-full rounded border border-transparent bg-transparent px-1 py-0.5 text-sm font-medium text-slate-800 hover:border-slate-200 focus:border-slate-300 focus:bg-white" x-model="child.label" @input="markDirty()">
                                            <div class="truncate px-1 font-mono text-[11px] text-slate-400" x-text="itemMeta(child)"></div>
                                        </div>
                                        <div class="flex shrink-0 gap-1">
                                            <button type="button" class="rounded px-1.5 py-1 text-xs text-slate-500 hover:bg-slate-200" @click="move([index, cIndex], -1)">↑</button>
                                            <button type="button" class="rounded px-1.5 py-1 text-xs text-slate-500 hover:bg-slate-200" @click="move([index, cIndex], 1)">↓</button>
                                            <button type="button" class="rounded px-1.5 py-1 text-xs text-slate-500 hover:bg-slate-200" @click="unnest([index, cIndex])" title="На уровень выше">↰</button>
                                            <button type="button" class="rounded px-1.5 py-1 text-xs text-slate-500 hover:bg-slate-200" @click="nestUnderPrev([index, cIndex])" title="Сделать дочерним">↳</button>
                                            <button type="button" class="rounded px-1.5 py-1 text-xs text-red-600 hover:bg-red-50" @click="removeAt([index, cIndex])">×</button>
                                        </div>
                                    </div>
                                    <template x-if="child.children && child.children.length">
                                        <ul class="ml-4 space-y-1 border-l border-slate-200 pl-2">
                                            <template x-for="(grand, gIndex) in child.children" :key="'g-'+index+'-'+cIndex+'-'+gIndex">
                                                <li>
                                                    <div class="flex items-center gap-2 rounded-lg px-2 py-2 hover:bg-slate-50">
                                                        <div class="min-w-0 flex-1">
                                                            <input type="text" class="w-full rounded border border-transparent bg-transparent px-1 py-0.5 text-sm font-medium text-slate-800 hover:border-slate-200 focus:border-slate-300 focus:bg-white" x-model="grand.label" @input="markDirty()">
                                                            <div class="truncate px-1 font-mono text-[11px] text-slate-400" x-text="itemMeta(grand)"></div>
                                                        </div>
                                                        <div class="flex shrink-0 gap-1">
                                                            <button type="button" class="rounded px-1.5 py-1 text-xs text-slate-500 hover:bg-slate-200" @click="move([index, cIndex, gIndex], -1)">↑</button>
                                                            <button type="button" class="rounded px-1.5 py-1 text-xs text-slate-500 hover:bg-slate-200" @click="move([index, cIndex, gIndex], 1)">↓</button>
                                                            <button type="button" class="rounded px-1.5 py-1 text-xs text-slate-500 hover:bg-slate-200" @click="unnest([index, cIndex, gIndex])" title="На уровень выше">↰</button>
                                                            <button type="button" class="rounded px-1.5 py-1 text-xs text-red-600 hover:bg-red-50" @click="removeAt([index, cIndex, gIndex])">×</button>
                                                        </div>
                                                    </div>
                                                </li>
                                            </template>
                                        </ul>
                                    </template>
                                </li>
                            </template>
                        </ul>
                    </template>
                </div>
            </template>
        </div>

        <form
            method="post"
            action="/admin-panel/menu"
            hx-post="/admin-panel/menu"
            hx-target="#menu-editor"
            hx-swap="outerHTML"
            class="mt-6 flex justify-end border-t border-slate-100 pt-4"
            @submit="$refs.itemsJson.value = serializeItems()"
        >
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_items">
            <input type="hidden" name="location" value="<?= htmlspecialchars($locationId) ?>">
            <input type="hidden" name="items_json" x-ref="itemsJson" value="">
            <button type="submit" class="rounded bg-sky-700 px-4 py-2 text-sm font-medium text-white hover:bg-sky-600">Сохранить пункты</button>
        </form>
    </section>
</div>
