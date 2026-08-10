<?php
$formTitle = $form['title'] ?? '';
$formSlug = $form['slug'] ?? '';
$formParent = $form['parent'] ?? '';
$formDescription = $form['description'] ?? '';
$formTemplate = $form['template'] ?? 'default';
$formPublished = !empty($form['published']);
$formMenu = !array_key_exists('menu', $form) || !empty($form['menu']);
$formOgTitle = $form['og_title'] ?? '';
$formOgDescription = $form['og_description'] ?? '';
$formOgImage = $form['og_image'] ?? '';
$formScripts = $form['scripts'] ?? '';
?>
<script>
    document.addEventListener('alpine:init', () => {
        Alpine.data('createPageForm', () => ({
            pageTitle: <?= json_encode($formTitle, JSON_UNESCAPED_UNICODE) ?>,
            pageSlug: <?= json_encode($formSlug, JSON_UNESCAPED_UNICODE) ?>,
            slugTouched: <?= $formSlug !== '' ? 'true' : 'false' ?>,
            openSeo: false,
            openAdvanced: false,
            slugify(text) {
                const map = <?= json_encode(Slugifier::MAP, JSON_UNESCAPED_UNICODE) ?>;
                let result = String(text).toLowerCase();
                for (const [from, to] of Object.entries(map)) {
                    result = result.split(from).join(to);
                }
                return result.replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'page';
            },
        }));
    });
</script>

<div
    class="rounded-xl bg-white p-6 pb-0 shadow-sm"
    x-data="createPageForm()"
>
    <div class="mb-6 flex items-center justify-between gap-4">
        <h1 class="text-2xl font-bold">Создать страницу</h1>
    </div>

    <div id="form-errors">
        <?php if (!empty($errors)): ?>
            <div class="mb-4 rounded border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                <?php foreach ($errors as $error): ?>
                    <div><?= htmlspecialchars($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <form
        x-ref="form"
        method="post"
        action="/admin-panel/pages"
        hx-post="/admin-panel/pages"
        hx-target="#form-errors"
        hx-disabled-elt="#create-button"
        hx-indicator="#create-indicator"
    >
        <?= Csrf::field() ?>

        <div class="grid gap-8 lg:grid-cols-[minmax(0,1fr)_18rem]">
            <div class="min-w-0 space-y-4">
                <div>
                    <label class="mb-1 block text-sm font-medium" for="title">Заголовок</label>
                    <input
                        id="title"
                        name="title"
                        x-model="pageTitle"
                        @input="if (!slugTouched) pageSlug = slugify(pageTitle)"
                        type="text"
                        required
                        class="w-full rounded border border-slate-300 px-3 py-2"
                    >
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="slug">Часть адреса</label>
                        <input id="slug" name="slug" x-model="pageSlug" @input="slugTouched = true" type="text" class="w-full rounded border border-slate-300 px-3 py-2">
                    </div>
                    <div>
                        <label class="mb-1 block text-sm font-medium" for="parent">Родитель</label>
                        <select id="parent" name="parent" class="w-full rounded border border-slate-300 px-3 py-2">
                            <option value="">— корень —</option>
                            <?php foreach ($parents as $parentNode): ?>
                                <?php if ($parentNode['slug'] === 'index') continue; ?>
                                <option value="<?= htmlspecialchars($parentNode['slug']) ?>" <?= $formParent === $parentNode['slug'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($parentNode['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <p class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">
                    После создания откройте страницу на сайте и отредактируйте текст двойным кликом по блоку.
                </p>
            </div>

            <aside class="space-y-4 lg:sticky lg:top-4 lg:self-start">
                <div>
                    <label class="mb-1 block text-sm font-medium" for="description">Описание для сниппета</label>
                    <textarea id="description" name="description" rows="3" class="w-full rounded border border-slate-300 px-3 py-2"><?= htmlspecialchars($formDescription) ?></textarea>
                </div>

                <label class="flex items-center gap-2 text-sm">
                    <input type="checkbox" name="menu" value="1" <?= $formMenu ? 'checked' : '' ?>>
                    Показывать в меню
                </label>

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
                            <input id="og_title" name="og_title" type="text" value="<?= htmlspecialchars($formOgTitle) ?>" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="og_description">Описание для соцсетей</label>
                            <textarea id="og_description" name="og_description" rows="2" class="w-full rounded border border-slate-300 px-3 py-2 text-sm"><?= htmlspecialchars($formOgDescription) ?></textarea>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="og_image">Картинка для соцсетей</label>
                            <input id="og_image" name="og_image" type="text" value="<?= htmlspecialchars($formOgImage) ?>" class="w-full rounded border border-slate-300 px-3 py-2 text-sm" placeholder="/themes/example/assets/images/og.jpg">
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
                                    <option value="<?= htmlspecialchars($template) ?>" <?= $formTemplate === $template ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($template) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium" for="scripts">JS-скрипты</label>
                            <textarea id="scripts" name="scripts" rows="4" class="w-full rounded border border-slate-300 px-3 py-2 font-mono text-sm" placeholder="/themes/example/assets/js/pages/demo.js"><?= htmlspecialchars(is_array($formScripts) ? PageScripts::toTextarea($formScripts) : $formScripts) ?></textarea>
                            <p class="mt-1 text-xs text-slate-500">По одному пути на строку. Только файлы из <code>/themes/{theme}/assets/js/</code></p>
                        </div>
                    </div>
                </div>
            </aside>
        </div>

        <div class="sticky bottom-0 z-10 -mx-6 mt-6 rounded-b-xl border-t border-slate-200 bg-white/95 px-6 py-3 backdrop-blur">
            <div class="flex flex-wrap items-center gap-3">
                <button
                    id="create-button"
                    type="submit"
                    class="rounded bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700 disabled:cursor-not-allowed disabled:opacity-50"
                >
                    Создать
                </button>
                <span id="create-indicator" class="htmx-indicator text-sm text-slate-500">Создание…</span>
                <label class="ml-auto flex items-center gap-2 text-sm">
                    <input type="checkbox" name="published" value="1" <?= $formPublished ? 'checked' : '' ?>>
                    Опубликовано
                </label>
            </div>
        </div>
    </form>
</div>
