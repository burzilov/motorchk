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
    class="mc-sheet mc-sheet--editor"
    x-data="editPageForm()"
>
    <div class="mc-head mc-head--sheet">
        <h1 class="mc-head__title"><?= htmlspecialchars($pageHeading) ?></h1>
        <div class="mc-head__actions">
            <a
                href="<?= htmlspecialchars($publicUrl) ?>"
                target="_blank"
                rel="noopener noreferrer"
                class="mc-link"
            >
                Редактировать на сайте
            </a>
            <?php if (!$is_index): ?>
                <button type="button" class="mc-btn mc-btn--quiet mc-btn--danger-text" @click="showDelete = true">
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
        hx-swap="none"
        hx-disabled-elt="#save-button"
        hx-indicator="#save-indicator"
    >
        <?= Csrf::field() ?>

        <div class="mc-editor">
            <div class="mc-editor__main mc-stack">
                <div>
                    <label class="mc-label" for="title">Заголовок</label>
                    <input id="title" name="title" type="text" value="<?= htmlspecialchars($fm['title'] ?? '') ?>" required class="mc-input">
                </div>

                <?php if (!$is_index): ?>
                    <div class="mc-grid-2">
                        <div>
                            <label class="mc-label" for="slug">Часть адреса</label>
                            <input id="slug" name="slug" type="text" value="<?= htmlspecialchars($leaf_slug) ?>" class="mc-input">
                        </div>
                        <div>
                            <label class="mc-label" for="parent">Родитель</label>
                            <select id="parent" name="parent" class="mc-input">
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

                <p class="mc-note">
                    Текст страницы правится на сайте: откройте
                    <a href="<?= htmlspecialchars($publicUrl) ?>" target="_blank" rel="noopener noreferrer" class="mc-link">страницу</a>
                    и дважды кликните по блоку.
                </p>
            </div>

            <aside class="mc-editor__aside mc-stack">
                <div>
                    <label class="mc-label" for="description">Описание для сниппета</label>
                    <textarea id="description" name="description" rows="3" class="mc-input"><?= htmlspecialchars($fm['description'] ?? '') ?></textarea>
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
                            <input id="og_title" name="og_title" type="text" value="<?= htmlspecialchars($fm['og_title'] ?? '') ?>" class="mc-input">
                        </div>
                        <div>
                            <label class="mc-label" for="og_description">Описание для соцсетей</label>
                            <textarea id="og_description" name="og_description" rows="2" class="mc-input"><?= htmlspecialchars($fm['og_description'] ?? '') ?></textarea>
                        </div>
                        <div>
                            <label class="mc-label" for="og_image">Картинка для соцсетей</label>
                            <input id="og_image" name="og_image" type="text" value="<?= htmlspecialchars($fm['og_image'] ?? '') ?>" class="mc-input" placeholder="/themes/example/assets/images/og.jpg">
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
                                    <option value="<?= htmlspecialchars($template) ?>" <?= (($fm['template'] ?? 'default') === $template) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($template) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label class="mc-label" for="scripts">JS-скрипты</label>
                            <textarea id="scripts" name="scripts" rows="4" class="mc-input mc-input--code" placeholder="/themes/example/assets/js/pages/demo.js"><?= htmlspecialchars(PageScripts::toTextarea($fm['scripts'] ?? [])) ?></textarea>
                            <p class="mc-hint">По одному пути на строку. Только файлы из <code>/themes/{theme}/assets/js/</code></p>
                        </div>
                    </div>
                </div>
            </aside>
        </div>

        <div class="mc-savebar">
            <div class="mc-savebar__inner">
                <button
                    id="save-button"
                    type="submit"
                    class="mc-btn mc-btn--primary"
                >
                    Сохранить
                </button>
                <span id="save-indicator" class="htmx-indicator mc-muted">Сохранение…</span>
                <label class="mc-check mc-savebar__publish">
                    <input type="checkbox" name="published" value="1" <?= !empty($fm['published']) ? 'checked' : '' ?>>
                    Опубликовано
                </label>
                <a
                    href="<?= htmlspecialchars($publicUrl) ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="mc-link"
                >
                    Открыть на сайте
                </a>
            </div>
        </div>
    </form>

    <?php if (!$is_index): ?>
        <div x-show="showDelete" x-cloak class="mc-modal">
            <div class="mc-modal__panel" role="dialog" aria-modal="true" aria-labelledby="delete-title">
                <h2 class="mc-modal__title" id="delete-title">Удалить страницу?</h2>
                <p class="mc-modal__text">
                    Будут удалены также все дочерние страницы (<?= (int) $descendants ?> шт.).
                </p>
                <div class="mc-modal__actions">
                    <button type="button" class="mc-btn" @click="showDelete = false">Отмена</button>
                    <button
                        type="button"
                        class="mc-btn mc-btn--danger"
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
