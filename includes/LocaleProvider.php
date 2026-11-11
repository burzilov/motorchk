<?php

/**
 * Контракт службы языков сайта. Плагин регистрирует её под именем «locale»:
 *
 *     $plugin->provide('locale', $service); // $service implements LocaleProvider
 *
 * Пока такой службы нет, ядро работает на одном языке (SiteLocale::FALLBACK) и не ищет
 * переводы страниц. Адреса на других языках ядро получает через фильтр «url»
 * (его регистрирует плагин со службой), см. docs/11-locale.md.
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
