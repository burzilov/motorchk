<?php

function renderMenuBranch(array $nodes, string $parentKey, int $depth = 0): void
{
    if ($nodes === []) {
        return;
    }

    $pad = 12 + ($depth * 20);
    ?>
    <ul class="menu-sortable m-0 list-none p-0" data-parent="<?= htmlspecialchars($parentKey) ?>">
        <?php foreach ($nodes as $node): ?>
            <li
                class="menu-page-item bg-white"
                data-slug="<?= htmlspecialchars($node['slug']) ?>"
                draggable="false"
            >
                <div class="flex items-center gap-3 rounded-lg transition-colors hover:bg-slate-50">
                    <div class="flex min-w-0 flex-1 items-center gap-2 py-2.5" style="padding-left: <?= (int) $pad ?>px">
                        <span class="drag-handle inline-flex h-7 w-7 shrink-0 cursor-grab items-center justify-center rounded-md text-slate-400 hover:bg-slate-200/70 hover:text-slate-600" title="Перетащить" aria-label="Перетащить">
                            <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                <path d="M7 4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM7 10a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM7 16a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM13 4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM13 10a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM13 16a1 1 0 1 1-2 0 1 1 0 0 1 2 0Z"/>
                            </svg>
                        </span>
                        <div class="min-w-0">
                            <div class="truncate text-sm font-medium text-slate-800"><?= htmlspecialchars($node['title']) ?></div>
                            <div class="mt-0.5 flex flex-wrap items-center gap-2">
                                <span class="truncate font-mono text-[11px] text-slate-400"><?= htmlspecialchars($node['slug']) ?></span>
                                <?php if (empty($node['published'])): ?>
                                    <span class="rounded-md bg-amber-50 px-1.5 py-0.5 text-[11px] font-medium leading-none text-amber-800 ring-1 ring-inset ring-amber-200/80">Черновик</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="flex shrink-0 items-center gap-3 py-2.5 pr-3">
                        <label class="flex items-center gap-2 text-sm whitespace-nowrap text-slate-600">
                            <input type="hidden" name="menu_slugs[]" value="<?= htmlspecialchars($node['slug']) ?>">
                            <input
                                type="checkbox"
                                name="menu[<?= htmlspecialchars($node['slug']) ?>]"
                                value="1"
                                <?= !empty($node['menu']) ? 'checked' : '' ?>
                            >
                            В меню
                        </label>
                        <a href="<?= htmlspecialchars(AdminUrl::page($node['slug'])) ?>" class="text-sm text-sky-700 hover:underline">Редактировать</a>
                    </div>
                </div>
                <?php if (!empty($node['children'])): ?>
                    <?php renderMenuBranch($node['children'], $node['slug'], $depth + 1); ?>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
}

$externalJson = json_encode($external ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$parentKey = ($parent ?? '') === '' ? '__root__' : $parent;
$parentValueJson = json_encode((string) ($parent ?? ''), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
$menuAction = '/admin-panel/menu' . (($parent ?? '') !== '' ? '?parent=' . rawurlencode($parent) : '');
?>
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('menuEditor', (initialExternal, initialParent) => ({
            external: initialExternal,
            parentValue: initialParent,
            dirty: false,
            _ready: false,

            markDirty() {
                this.dirty = true;
            },

            addExternal() {
                this.external.push({ label: '', url: '' });
                this.markDirty();
            },

            removeExternal(index) {
                this.external.splice(index, 1);
                this.markDirty();
            },

            changeParent(event) {
                const value = event.target.value;
                const url = '/admin-panel/menu' + (value ? '?parent=' + encodeURIComponent(value) : '');
                if (this.dirty && !confirm('Есть несохранённые изменения. Уйти без сохранения?')) {
                    event.target.value = this.parentValue;
                    return;
                }
                window.location.href = url;
            },

            init() {
                this._onBeforeUnload = (event) => {
                    if (!this.dirty) {
                        return;
                    }
                    event.preventDefault();
                    event.returnValue = '';
                };
                window.addEventListener('beforeunload', this._onBeforeUnload);

                this._onMenuDirty = () => this.markDirty();
                this.$el.addEventListener('menu:dirty', this._onMenuDirty);

                this._onFormChange = (event) => {
                    if (!this._ready) {
                        return;
                    }
                    const target = event.target;
                    if (!(target instanceof Element)) {
                        return;
                    }
                    if (target.id === 'menu-parent' || target.closest('[data-ignore-dirty]')) {
                        return;
                    }
                    if (target.matches('input, textarea, select')) {
                        this.markDirty();
                    }
                };
                this.$el.addEventListener('change', this._onFormChange);
                this.$el.addEventListener('input', this._onFormChange);

                this.$watch('external', () => {
                    if (this._ready) {
                        this.markDirty();
                    }
                }, { deep: true });

                this.$nextTick(() => {
                    this._ready = true;
                });
            },

            destroy() {
                window.removeEventListener('beforeunload', this._onBeforeUnload);
                this.$el.removeEventListener('menu:dirty', this._onMenuDirty);
                this.$el.removeEventListener('change', this._onFormChange);
                this.$el.removeEventListener('input', this._onFormChange);
            },
        }));
    });
</script>

<div id="menu-editor" class="rounded-xl bg-white p-6 pb-0 shadow-sm">
    <h1 class="mb-2 text-2xl font-bold">Редактор меню</h1>
    <p class="mb-6 text-sm text-slate-600">
        Порядок пунктов задаётся перетаскиванием среди страниц одного уровня. Slug и URL берутся из страниц автоматически.
    </p>

    <?php if (!empty($saved)): ?>
        <div class="mb-4 rounded border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">Меню сохранено</div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form
        id="menu-editor-form"
        method="post"
        action="<?= htmlspecialchars($menuAction) ?>"
        hx-post="<?= htmlspecialchars($menuAction) ?>"
        hx-target="#menu-editor"
        hx-swap="outerHTML"
        class="space-y-8"
        x-data="menuEditor(<?= htmlspecialchars($externalJson, ENT_QUOTES) ?>, <?= htmlspecialchars($parentValueJson, ENT_QUOTES) ?>)"
    >
        <?= Csrf::field() ?>
        <input type="hidden" name="parent" value="<?= htmlspecialchars($parent ?? '') ?>">

        <div class="flex flex-wrap items-end gap-4">
            <div>
                <label class="mb-1 block text-sm font-medium" for="menu-parent">Раздел дерева</label>
                <select
                    id="menu-parent"
                    data-ignore-dirty
                    class="rounded border border-slate-300 px-3 py-2 text-sm"
                    @change="changeParent($event)"
                >
                    <?php foreach ($parentOptions as $option): ?>
                        <option value="<?= htmlspecialchars($option['slug']) ?>" <?= ($parent ?? '') === $option['slug'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($option['title']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <p class="text-xs text-slate-500">
                Показаны страницы, начиная с выбранного раздела. Чтобы изменить порядок корневых пунктов, выберите «Корень сайта».
            </p>
        </div>

        <div>
            <h2 class="mb-3 text-sm font-semibold text-slate-700">Страницы</h2>
            <?php if (($branch ?? []) === []): ?>
                <p class="rounded-xl border border-dashed border-slate-300 px-4 py-8 text-center text-sm text-slate-500">
                    В этом разделе нет дочерних страниц.
                </p>
            <?php else: ?>
                <div class="menu-pages-tree -mx-1">
                    <?php renderMenuBranch($branch, $parentKey); ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="border-t border-slate-200 pt-6">
            <div class="mb-3 flex items-center justify-between gap-2">
                <h2 class="text-sm font-semibold text-slate-700">Внешние ссылки</h2>
                <button type="button" @click="addExternal()" class="text-sm text-sky-700 hover:underline">+ Добавить ссылку</button>
            </div>
            <p class="mb-4 text-xs text-slate-500">Внешние ссылки добавляются в конец меню верхнего уровня.</p>

            <div class="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <div class="divide-y divide-slate-100">
                    <template x-for="(link, index) in external" :key="'ext-' + index">
                        <div class="grid gap-3 px-3 py-3 md:grid-cols-[1fr_1fr_auto] md:items-center">
                            <input
                                type="text"
                                :name="'external[' + index + '][label]'"
                                x-model="link.label"
                                placeholder="Название"
                                class="rounded border border-slate-300 px-3 py-2 text-sm"
                            >
                            <input
                                type="url"
                                :name="'external[' + index + '][url]'"
                                x-model="link.url"
                                placeholder="https://..."
                                class="rounded border border-slate-300 px-3 py-2 text-sm"
                            >
                            <button type="button" @click="removeExternal(index)" class="justify-self-start text-sm text-red-600 hover:underline md:justify-self-end">Удалить</button>
                        </div>
                    </template>
                </div>
                <p x-show="external.length === 0" class="px-4 py-8 text-center text-sm text-slate-500">Нет внешних ссылок.</p>
            </div>
        </div>

        <div
            class="sticky bottom-0 z-10 -mx-6 mt-8 rounded-b-xl border-t px-6 py-3 backdrop-blur"
            :class="dirty ? 'border-amber-200 bg-amber-50/95' : 'border-slate-200 bg-white/95'"
        >
            <div class="flex flex-wrap items-center gap-3">
                <p x-show="dirty" x-cloak class="text-sm font-medium text-amber-900">
                    Есть несохранённые изменения
                </p>
                <button
                    type="submit"
                    class="rounded bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700"
                    :class="dirty ? 'ring-2 ring-amber-400 ring-offset-2' : ''"
                >
                    Сохранить меню
                </button>
            </div>
        </div>
    </form>
</div>

<script>
    function menuListOrder(list) {
        return [...list.querySelectorAll(':scope > .menu-page-item')]
            .map((item) => item.dataset.slug)
            .join('\0');
    }

    function dispatchMenuDirty(fromEl) {
        const form = fromEl.closest('#menu-editor-form');
        if (form) {
            form.dispatchEvent(new CustomEvent('menu:dirty', { bubbles: true }));
        }
    }

    function initMenuDragDrop() {
        const lists = document.querySelectorAll('#menu-editor .menu-sortable');
        lists.forEach((list) => {
            if (list._dragBound) {
                return;
            }
            list._dragBound = true;

            list.addEventListener('dragover', (event) => {
                const dragging = document.querySelector('#menu-editor .menu-page-item.dragging');
                // Only reorder within the same parent list (same depth).
                if (!dragging || dragging.parentElement !== list) {
                    return;
                }
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';

                const afterElement = getDragAfterElement(list, event.clientY);
                if (afterElement == null) {
                    list.appendChild(dragging);
                } else {
                    list.insertBefore(dragging, afterElement);
                }
            });

            list.querySelectorAll(':scope > .menu-page-item').forEach((item) => {
                if (item._dragItemBound) {
                    return;
                }
                item._dragItemBound = true;

                const handle = item.querySelector(':scope > div .drag-handle');
                if (handle) {
                    handle.addEventListener('mousedown', () => {
                        // Enable draggable only on this item so nested parents don't start a drag.
                        item.draggable = true;
                        item._dragFromHandle = true;
                    });
                    handle.addEventListener('touchstart', () => {
                        item.draggable = true;
                        item._dragFromHandle = true;
                    }, { passive: true });
                }

                item.addEventListener('dragstart', (event) => {
                    // Nested items: dragstart bubbles to parent <li> — ignore unless this item is the source.
                    if (event.target !== item) {
                        return;
                    }
                    if (!item._dragFromHandle) {
                        event.preventDefault();
                        item.draggable = false;
                        return;
                    }
                    event.stopPropagation();
                    if (event.dataTransfer) {
                        event.dataTransfer.effectAllowed = 'move';
                        event.dataTransfer.setData('text/plain', item.dataset.slug || '');
                    }
                    item._orderBefore = menuListOrder(list);
                    item.classList.add('dragging', 'opacity-50');
                });

                item.addEventListener('dragend', () => {
                    const orderBefore = item._orderBefore;
                    item.classList.remove('dragging', 'opacity-50');
                    item._dragFromHandle = false;
                    item.draggable = false;
                    const parentList = item.parentElement;
                    if (parentList instanceof HTMLElement && parentList.classList.contains('menu-sortable')) {
                        const orderAfter = menuListOrder(parentList);
                        if (orderBefore != null && orderBefore !== orderAfter) {
                            dispatchMenuDirty(item);
                        }
                    }
                    item._orderBefore = null;
                });

                item.addEventListener('mouseup', () => {
                    window.setTimeout(() => {
                        if (!item.classList.contains('dragging')) {
                            item._dragFromHandle = false;
                            item.draggable = false;
                        }
                    }, 0);
                });
            });
        });
    }

    function getDragAfterElement(container, y) {
        const draggableElements = [...container.querySelectorAll(':scope > .menu-page-item:not(.dragging)')];

        return draggableElements.reduce((closest, child) => {
            // Use the row (first child), not the whole li with nested children.
            const row = child.firstElementChild;
            const box = (row || child).getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) {
                return { offset, element: child };
            }
            return closest;
        }, { offset: Number.NEGATIVE_INFINITY, element: null }).element;
    }

    function collectMenuOrders(form) {
        form.querySelectorAll('input[name^="orders["]').forEach((input) => input.remove());

        document.querySelectorAll('#menu-editor .menu-sortable').forEach((list) => {
            const parent = list.dataset.parent;
            [...list.querySelectorAll(':scope > .menu-page-item')].forEach((item) => {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = `orders[${parent}][]`;
                input.value = item.dataset.slug;
                form.appendChild(input);
            });
        });
    }

    function bindMenuFormSubmit() {
        const form = document.getElementById('menu-editor-form');
        if (!form || form._menuSubmitBound) {
            return;
        }

        form._menuSubmitBound = true;
        form.addEventListener('submit', () => collectMenuOrders(form));
        form.addEventListener('htmx:configRequest', () => collectMenuOrders(form));
    }

    function initMenuEditorUi() {
        initMenuDragDrop();
        bindMenuFormSubmit();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initMenuEditorUi);
    } else {
        initMenuEditorUi();
    }
    document.body.addEventListener('htmx:afterSwap', (event) => {
        if (event.detail.target?.id === 'menu-editor') {
            if (window.Alpine) {
                Alpine.initTree(event.detail.target);
            }
            initMenuEditorUi();
        }
    });
</script>
