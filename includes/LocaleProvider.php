<?php

/**
 * Контракт службы языков сайта. Плагин регистрирует её под именем «i18n»:
 *
 *     $plugin->provide('i18n', $service); // $service implements LocaleProvider
 *
 * Пока такой службы нет, ядро работает на одном языке (SiteLocale::FALLBACK) и не ищет
 * переводы страниц. Адреса на других языках ядро получает через фильтр «url»
 * (его регистрирует тот же плагин), см. docs/11-i18n.md.
 */
interface LocaleProvider
{
    /**
     * Языки сайта (коды вида ru, en, pt-br), включая язык по умолчанию.
     *
     * @return list<string>
     */
    public function locales(): array;

    /** Язык по умолчанию (страницы content/*.md написаны на нём). */
    public function defaultLocale(): string;
}
