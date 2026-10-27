<?php
$formTitle = $form['title'] ?? '';
$formSlug = $form['slug'] ?? '';
$formParent = $form['parent'] ?? '';
$formDescription = $form['description'] ?? '';
$formTemplate = $form['template'] ?? 'default';
$formPublished = !empty($form['published']);
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
    class="mc-sheet mc-sheet--editor"
    x-data="createPageForm()"
>
    <div class="mc-head mc-head--sheet">
        <h1 class="mc-head__title">Создать страницу</h1>
    </div>

    <div id="form-errors">
        <?php if (!empty($errors)): ?>
            <div class="mc-alert mc-alert--error" role="alert">
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

        <div class="mc-editor">
            <div class="mc-editor__main mc-stack">
                <div>
                    <label class="mc-label" for="title">Заголовок</label>
                    <input
                        id="title"
                        name="title"
                        x-model="pageTitle"
                        @input="if (!slugTouched) pageSlug = slugify(pageTitle)"
                        type="text"
                        required
                        class="mc-input"
                    >
                </div>

                <div class="mc-grid-2">
                    <div>
                        <label class="mc-label" for="slug">Часть адреса</label>
                        <input id="slug" name="slug" x-model="pageSlug" @input="slugTouched = true" type="text" class="mc-input">
                    </div>
                    <div>
                        <label class="mc-label" for="parent">Родитель</label>
                        <select id="parent" name="parent" class="mc-input">
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

                <p class="mc-note">
                    После создания откройте страницу на сайте и отредактируйте текст двойным кликом по блоку.
                </p>
            </div>

            <aside class="mc-editor__aside mc-stack">
                <div>
                    <label class="mc-label" for="description">Описание для сниппета</label>
                    <textarea id="description" name="description" rows="3" class="mc-input"><?= htmlspecialchars($formDescription) ?></textarea>
                </div>

                <div class="mc-disclosure">
                    <button
                        type="button"
                        class="mc-disclosure__toggle"
                        @click="openSeo = !openSeo"
                        :aria-expanded="openSeo.toString()"
                    >
                        <span>SEO и соцсети</span>
                        <span class="mc-disclosure__sign" aria-hidden="true" x-text="openSeo ? '▾' : '▸'"></span>
                    </button>
                    <div x-show="openSeo" x-cloak class="mc-disclosure__body mc-stack">
                        <div>
                            <label class="mc-label" for="og_title">Заголовок для соцсетей</label>
                            <input id="og_title" name="og_title" type="text" value="<?= htmlspecialchars($formOgTitle) ?>" class="mc-input">
                        </div>
                        <div>
                            <label class="mc-label" for="og_description">Описание для соцсетей</label>
                            <textarea id="og_description" name="og_description" rows="2" class="mc-input"><?= htmlspecialchars($formOgDescription) ?></textarea>
                        </div>
                        <div>
                            <label class="mc-label" for="og_image">Картинка для соцсетей</label>
                            <input id="og_image" name="og_image" type="text" value="<?= htmlspecialchars($formOgImage) ?>" class="mc-input" placeholder="/themes/example/assets/images/og.jpg">
                            <p class="mc-hint">Путь на сайте или полный URL</p>
                        </div>
                    </div>
                </div>

                <div class="mc-disclosure">
                    <button
                        type="button"
                        class="mc-disclosure__toggle"
                        @click="openAdvanced = !openAdvanced"
                        :aria-expanded="openAdvanced.toString()"
                    >
                        <span>Дополнительно</span>
                        <span class="mc-disclosure__sign" aria-hidden="true" x-text="openAdvanced ? '▾' : '▸'"></span>
                    </button>
                    <div x-show="openAdvanced" x-cloak class="mc-disclosure__body mc-stack">
                        <div>
                            <label class="mc-label" for="template">Шаблон</label>
                            <select id="template" name="template" class="mc-input">
                                <?php foreach ($templates as $template): ?>
                                    <option value="<?= htmlspecialchars($template) ?>" <?= $formTemplate === $template ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($template) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="mc-label" for="scripts">JS-скрипты</label>
                            <textarea id="scripts" name="scripts" rows="4" class="mc-input mc-input--code" placeholder="/themes/example/assets/js/pages/demo.js"><?= htmlspecialchars(is_array($formScripts) ? PageScripts::toTextarea($formScripts) : $formScripts) ?></textarea>
                            <p class="mc-hint">По одному пути на строку. Только файлы из <code>/themes/{theme}/assets/js/</code></p>
                        </div>
                    </div>
                </div>
            </aside>
        </div>

        <div class="mc-savebar">
            <div class="mc-savebar__inner">
                <button
                    id="create-button"
                    type="submit"
                    class="mc-btn mc-btn--primary"
                >
                    Создать
                </button>
                <span id="create-indicator" class="htmx-indicator mc-muted">Создание…</span>
                <label class="mc-check mc-savebar__publish">
                    <input type="checkbox" name="published" value="1" <?= $formPublished ? 'checked' : '' ?>>
                    Опубликовано
                </label>
            </div>
        </div>
    </form>
</div>
