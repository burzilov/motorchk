<?php
$locations = $locations ?? [];
$locationId = $locationId ?? MenuWriter::DEFAULT_LOCATION;
$locationLabel = $locationLabel ?? $locationId;
$items = $items ?? [];
$pages = $pages ?? [];
$defaultLocation = $defaultLocation ?? MenuWriter::DEFAULT_LOCATION;
$itemsJson = htmlspecialchars(json_encode($items, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
$pagesJson = htmlspecialchars(json_encode($pages, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
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
    class="mc-page"
    x-data="menuEditor(
        <?= $itemsJson ?>,
        <?= $pagesJson ?>,
        <?= htmlspecialchars(json_encode($locationId, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>,
        <?= htmlspecialchars(json_encode($locationLabel, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>,
        <?= htmlspecialchars(json_encode($defaultLocation, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>
    )"
>
    <div class="mc-head">
        <div>
            <h1 class="mc-head__title">Меню</h1>
            <p class="mc-head__lead">Именованные меню в <code>content/_menus.yaml</code></p>
        </div>
    </div>

    <nav class="mc-tabs" aria-label="Меню сайта">
        <?php foreach ($locations as $loc): ?>
            <a
                href="/admin-panel/menu?location=<?= rawurlencode($loc['id']) ?>"
                class="mc-tabs__tab"<?= $loc['id'] === $locationId ? ' aria-current="page"' : '' ?>
                hx-get="/admin-panel/menu?location=<?= rawurlencode($loc['id']) ?>"
                hx-target="#menu-editor"
                hx-select="#menu-editor"
                hx-swap="outerHTML"
            >
                <?= htmlspecialchars($loc['label']) ?>
                <span class="mc-tabs__id">(<?= htmlspecialchars($loc['id']) ?>)</span>
            </a>
        <?php endforeach; ?>
    </nav>

    <div class="mc-grid-2 mc-grid-2--wide">
        <section class="mc-sheet">
            <h2 class="mc-sheet__title">Текущее меню</h2>
            <form
                method="post"
                action="/admin-panel/menu"
                hx-post="/admin-panel/menu"
                hx-target="#menu-editor"
                hx-swap="outerHTML"
                class="mc-stack"
            >
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="update_location">
                <input type="hidden" name="location" value="<?= htmlspecialchars($locationId) ?>">
                <div>
                    <label class="mc-label" for="menu-edit-label">Название</label>
                    <input id="menu-edit-label" name="label" x-model="editLabel" type="text" required class="mc-input">
                </div>
                <?php if ($locationId !== $defaultLocation): ?>
                    <div>
                        <label class="mc-label" for="menu-edit-id">Id</label>
                        <input id="menu-edit-id" name="new_id" x-model="editId" type="text" pattern="[a-z][a-z0-9_-]*" class="mc-input mc-input--code">
                    </div>
                <?php else: ?>
                    <p class="mc-hint">Id <code>main</code> нельзя изменить или удалить.</p>
                <?php endif; ?>
                <button type="submit" class="mc-btn">Обновить меню</button>
            </form>

            <?php if ($locationId !== $defaultLocation): ?>
                <form
                    method="post"
                    action="/admin-panel/menu"
                    hx-post="/admin-panel/menu"
                    hx-target="#menu-editor"
                    hx-swap="outerHTML"
                    class="mc-sheet__foot"
                    onsubmit="return confirm('Удалить это меню?');"
                >
                    <?= Csrf::field() ?>
                    <input type="hidden" name="action" value="delete_location">
                    <input type="hidden" name="location" value="<?= htmlspecialchars($locationId) ?>">
                    <button type="submit" class="mc-btn mc-btn--quiet mc-btn--danger-text">Удалить меню</button>
                </form>
            <?php endif; ?>
        </section>

        <section class="mc-sheet">
            <h2 class="mc-sheet__title">Новое меню</h2>
            <form
                method="post"
                action="/admin-panel/menu"
                hx-post="/admin-panel/menu"
                hx-target="#menu-editor"
                hx-swap="outerHTML"
                class="mc-stack"
            >
                <?= Csrf::field() ?>
                <input type="hidden" name="action" value="create_location">
                <input type="hidden" name="location" value="<?= htmlspecialchars($locationId) ?>">
                <div>
                    <label class="mc-label" for="menu-new-id">Id</label>
                    <input id="menu-new-id" name="new_id" type="text" required pattern="[a-z][a-z0-9_-]*" placeholder="ceo-lead" class="mc-input mc-input--code">
                </div>
                <div>
                    <label class="mc-label" for="menu-new-label">Название</label>
                    <input id="menu-new-label" name="new_label" type="text" required placeholder="CEO Lead" class="mc-input">
                </div>
                <button type="submit" class="mc-btn mc-btn--primary">Создать</button>
            </form>
        </section>
    </div>

    <section class="mc-sheet">
        <div class="mc-sheet__head">
            <h2 class="mc-sheet__title">Пункты: <?= htmlspecialchars($locationLabel) ?></h2>
            <span class="mc-dirty" x-show="dirty">Есть несохранённые изменения</span>
        </div>

        <div class="mc-adder">
            <div>
                <label class="mc-label" for="menu-add-mode">Тип</label>
                <select id="menu-add-mode" x-model="addMode" class="mc-input">
                    <option value="page">Страница</option>
                    <option value="link">URL / якорь</option>
                </select>
            </div>
            <div x-show="addMode === 'page'" class="mc-adder__grow">
                <label class="mc-label" for="menu-add-page">Страница</label>
                <select id="menu-add-page" x-model="pageSlug" class="mc-input">
                    <option value="">Выберите…</option>
                    <template x-for="page in pages" :key="page.slug">
                        <option :value="page.slug" x-text="page.title + ' (' + page.slug + ')' + (page.published ? '' : ' — черновик')"></option>
                    </template>
                </select>
            </div>
            <template x-if="addMode === 'link'">
                <div class="mc-adder__grow mc-adder__pair">
                    <div class="mc-adder__grow">
                        <label class="mc-label" for="menu-add-link-label">Подпись</label>
                        <input id="menu-add-link-label" x-model="linkLabel" type="text" class="mc-input" placeholder="Квиз">
                    </div>
                    <div class="mc-adder__grow">
                        <label class="mc-label" for="menu-add-link-url">URL</label>
                        <input id="menu-add-link-url" x-model="linkUrl" type="text" class="mc-input mc-input--code" placeholder="#quiz или https://…">
                    </div>
                </div>
            </template>
            <button type="button" class="mc-btn" @click="addItem()">Добавить</button>
        </div>

        <template x-if="items.length === 0">
            <p class="mc-empty">Пока нет пунктов — добавьте страницу или ссылку.</p>
        </template>

        <div class="mc-menu-tree" id="menu-items-tree">
            <template x-for="(item, index) in items" :key="'root-'+index+'-'+(item.slug||item.url||'')">
                <div>
                    <div class="mc-menu-row">
                        <div class="mc-menu-row__main">
                            <input
                                type="text"
                                class="mc-menu-row__label"
                                aria-label="Подпись пункта"
                                x-model="item.label"
                                @input="markDirty()"
                            >
                            <div class="mc-menu-row__meta" x-text="itemMeta(item)"></div>
                        </div>
                        <div class="mc-menu-row__tools">
                            <button aria-label="Вверх" type="button" class="mc-icon-btn" @click="move([index], -1)" title="Вверх">↑</button>
                            <button aria-label="Вниз" type="button" class="mc-icon-btn" @click="move([index], 1)" title="Вниз">↓</button>
                            <button aria-label="Сделать дочерним" type="button" class="mc-icon-btn" @click="nestUnderPrev([index])" title="Сделать дочерним">↳</button>
                            <button aria-label="Удалить" type="button" class="mc-icon-btn mc-icon-btn--danger" @click="removeAt([index])" title="Удалить">×</button>
                        </div>
                    </div>
                    <template x-if="item.children && item.children.length">
                        <ul class="mc-menu-tree__sub">
                            <template x-for="(child, cIndex) in item.children" :key="'c-'+index+'-'+cIndex">
                                <li>
                                    <div class="mc-menu-row">
                                        <div class="mc-menu-row__main">
                                            <input type="text" class="mc-menu-row__label" aria-label="Подпись пункта" x-model="child.label" @input="markDirty()">
                                            <div class="mc-menu-row__meta" x-text="itemMeta(child)"></div>
                                        </div>
                                        <div class="mc-menu-row__tools">
                                            <button aria-label="Вверх" type="button" class="mc-icon-btn" @click="move([index, cIndex], -1)">↑</button>
                                            <button aria-label="Вниз" type="button" class="mc-icon-btn" @click="move([index, cIndex], 1)">↓</button>
                                            <button aria-label="На уровень выше" type="button" class="mc-icon-btn" @click="unnest([index, cIndex])" title="На уровень выше">↰</button>
                                            <button aria-label="Сделать дочерним" type="button" class="mc-icon-btn" @click="nestUnderPrev([index, cIndex])" title="Сделать дочерним">↳</button>
                                            <button aria-label="Удалить" type="button" class="mc-icon-btn mc-icon-btn--danger" @click="removeAt([index, cIndex])">×</button>
                                        </div>
                                    </div>
                                    <template x-if="child.children && child.children.length">
                                        <ul class="mc-menu-tree__sub">
                                            <template x-for="(grand, gIndex) in child.children" :key="'g-'+index+'-'+cIndex+'-'+gIndex">
                                                <li>
                                                    <div class="mc-menu-row">
                                                        <div class="mc-menu-row__main">
                                                            <input type="text" class="mc-menu-row__label" aria-label="Подпись пункта" x-model="grand.label" @input="markDirty()">
                                                            <div class="mc-menu-row__meta" x-text="itemMeta(grand)"></div>
                                                        </div>
                                                        <div class="mc-menu-row__tools">
                                                            <button aria-label="Вверх" type="button" class="mc-icon-btn" @click="move([index, cIndex, gIndex], -1)">↑</button>
                                                            <button aria-label="Вниз" type="button" class="mc-icon-btn" @click="move([index, cIndex, gIndex], 1)">↓</button>
                                                            <button aria-label="На уровень выше" type="button" class="mc-icon-btn" @click="unnest([index, cIndex, gIndex])" title="На уровень выше">↰</button>
                                                            <button aria-label="Удалить" type="button" class="mc-icon-btn mc-icon-btn--danger" @click="removeAt([index, cIndex, gIndex])">×</button>
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
            class="mc-sheet__foot mc-sheet__foot--end"
            @submit="$refs.itemsJson.value = serializeItems()"
        >
            <?= Csrf::field() ?>
            <input type="hidden" name="action" value="save_items">
            <input type="hidden" name="location" value="<?= htmlspecialchars($locationId) ?>">
            <input type="hidden" name="items_json" x-ref="itemsJson" value="">
            <button type="submit" class="mc-btn mc-btn--primary">Сохранить пункты</button>
        </form>
    </section>
</div>
