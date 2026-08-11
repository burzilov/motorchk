<?php
$fm = $page['front_matter'];
$publicUrl = AdminUrl::viewPage($fm['slug'] ?? 'index', !empty($fm['published']));
$pageHeading = trim((string) ($fm['title'] ?? '')) !== '' ? $fm['title'] : 'Редактирование';
?>
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('editPageForm', () => ({
            showDelete: false,
            openSeo: false,
            openAdvanced: false,
        }));
    });
</script>

<div
    class="rounded-xl bg-white p-6 pb-0 shadow-sm"
    x-data="editPageForm()"
>
    <div class="mb-6 flex flex-wrap items-center justify-between gap-4">
        <h1 class="truncate text-2xl font-bold"><?= htmlspecialchars($pageHeading) ?></h1>
        <div class="flex shrink-0 items-center gap-3">
            <a
                href="<?= htmlspecialchars($publicUrl) ?>"
                target="_blank"
                rel="noopener noreferrer"
                class="text-sm text-sky-700 hover:underline"
            >
                Редактировать на сайте
            </a>
            <?php if (!$is_index): ?>
                <button type="button" class="text-sm text-red-600 hover:underline" @click="showDelete = true">
                    Удалить
                </button>
            <?php endif; ?>
        </div>
    </div>

    <form
        x-ref="form"
        method="post"
        action="<?= htmlspecialchars(AdminUrl::page($fm['slug'])) ?>"
        hx-post="<?= htmlspecialchars(AdminUrl::page($fm['slug'])) ?>"
        hx-target="#save-status"
        hx-disabled-elt="#save-button"
        hx-indicator="#save-indicator"
    >
        <?= Csrf::field() ?>

        <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_18rem]">
            <div class="min-w-0 space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium" for="title">Заголовок</label>
                    <input id="title" name="title" type="text" value="<?= htmlspecialchars($fm['title'] ?? '') ?>" required class="w-full rounded border border-slate-300 px-3 py-2">
                </div>

                <?php if (!$is_index): ?>
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="slug">Часть адреса</label>
                            <input id="slug" name="slug" type="text" value="<?= htmlspecialchars($leaf_slug) ?>" class="w-full rounded border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="parent">Родитель</label>
                            <select id="parent" name="parent" class="w-full rounded border border-slate-300 px-3 py-2">
                                <option value="">— корень —</option>
                                <?php foreach ($parents as $parentNode): ?>
                                    <?php if ($parentNode['slug'] === $fm['slug'] || $parentNode['slug'] === 'index') continue; ?>
                                    <option value="<?= htmlspecialchars($parentNode['slug']) ?>" <?= ($parent === $parentNode['slug']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($parentNode['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                <?php endif; ?>

                <p class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    Текст страницы правится на сайте: откройте
                    <a href="<?= htmlspecialchars($publicUrl) ?>" target="_blank" rel="noopener noreferrer" class="text-sky-700 hover:underline">страницу</a>
                    и дважды кликните по блоку.
                </p>
            </div>

            <aside class="space-y-4 lg:sticky lg:top-4 lg:self-start">
                <div>
                    <label class="mb-1 block text-sm font-medium" for="description">Описание для сниппета</label>
                    <textarea id="description" name="description" rows="3" class="w-full rounded border border-slate-300 px-3 py-2"><?= htmlspecialchars($fm['description'] ?? '') ?></textarea>
                </div>

                <div class="rounded-lg border border-slate-200">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between px-3 py-2.5 text-left text-sm font-medium"
                        @click="openSeo = !openSeo"
                        :aria-expanded="openSeo.toString()"
                    >
                        <span>SEO и соцсети</span>
                        <span class="text-slate-400" x-text="openSeo ? '▾' : '▸'"></span>
                    </button>
                    <div x-show="openSeo" x-cloak class="space-y-3 border-t border-slate-200 px-3 py-3">
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="og_title">Заголовок для соцсетей</label>
                            <input id="og_title" name="og_title" type="text" value="<?= htmlspecialchars($fm['og_title'] ?? '') ?>" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="og_description">Описание для соцсетей</label>
                            <textarea id="og_description" name="og_description" rows="2" class="w-full rounded border border-slate-300 px-3 py-2 text-sm"><?= htmlspecialchars($fm['og_description'] ?? '') ?></textarea>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="og_image">Картинка для соцсетей</label>
                            <input id="og_image" name="og_image" type="text" value="<?= htmlspecialchars($fm['og_image'] ?? '') ?>" class="w-full rounded border border-slate-300 px-3 py-2 text-sm" placeholder="/themes/example/assets/images/og.jpg">
                            <p class="mt-1 text-xs text-slate-500">Путь на сайте или полный URL</p>
                        </div>
                    </div>
                </div>

                <div class="rounded-lg border border-slate-200">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between px-3 py-2.5 text-left text-sm font-medium"
                        @click="openAdvanced = !openAdvanced"
                        :aria-expanded="openAdvanced.toString()"
                    >
                        <span>Дополнительно</span>
                        <span class="text-slate-400" x-text="openAdvanced ? '▾' : '▸'"></span>
                    </button>
                    <div x-show="openAdvanced" x-cloak class="space-y-3 border-t border-slate-200 px-3 py-3">
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="template">Шаблон</label>
                            <select id="template" name="template" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                                <?php foreach ($templates as $template): ?>
                                    <option value="<?= htmlspecialchars($template) ?>" <?= (($fm['template'] ?? 'default') === $template) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($template) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="scripts">JS-скрипты</label>
                            <textarea id="scripts" name="scripts" rows="4" class="w-full rounded border border-slate-300 px-3 py-2 font-mono text-sm" placeholder="/themes/example/assets/js/pages/demo.js"><?= htmlspecialchars(PageScripts::toTextarea($fm['scripts'] ?? [])) ?></textarea>
                            <p class="mt-1 text-xs text-slate-500">По одному пути на строку. Только файлы из <code>/themes/{theme}/assets/js/</code></p>
                        </div>
                    </div>
                </div>
            </aside>
        </div>

        <div class="sticky bottom-0 z-10 -mx-6 mt-6 rounded-b-xl border-t border-slate-200 bg-white/95 px-6 py-3 backdrop-blur">
            <div class="flex flex-wrap items-center gap-3">
                <button
                    id="save-button"
                    type="submit"
                    class="rounded bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Сохранить
                </button>
                <span id="save-indicator" class="htmx-indicator text-sm text-slate-500">Сохранение…</span>
                <div id="save-status" class="min-h-8 text-sm"></div>
                <label class="ml-auto flex items-center gap-2 text-sm">
                    <input type="checkbox" name="published" value="1" <?= !empty($fm['published']) ? 'checked' : '' ?>>
                    Опубликовано
                </label>
                <a
                    href="<?= htmlspecialchars($publicUrl) ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="text-sm text-sky-700 hover:underline"
                >
                    Открыть на сайте
                </a>
            </div>
        </div>
    </form>

    <?php if (!$is_index): ?>
        <div x-show="showDelete" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-lg">
                <h2 class="mb-2 text-lg font-semibold">Удалить страницу?</h2>
                <p class="mb-4 text-sm text-slate-600">
                    Будут удалены также все дочерние страницы (<?= (int) $descendants ?> шт.).
                </p>
                <div class="flex justify-end gap-3">
                    <button type="button" class="rounded border px-4 py-2 text-sm" @click="showDelete = false">Отмена</button>
                    <button
                        type="button"
                        class="rounded bg-red-600 px-4 py-2 text-sm text-white"
                        hx-delete="<?= htmlspecialchars(AdminUrl::page($fm['slug'])) ?>"
                        hx-headers='{"X-CSRF-Token": "<?= Csrf::token() ?>"}'
                        hx-vals='{"_csrf": "<?= Csrf::token() ?>"}'
                    >
                        Удалить
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>
