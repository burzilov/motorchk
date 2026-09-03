<?php
Form::begin('contact');
?>
    <div data-form-errors class="mb-4 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700" hidden></div>

    <div class="grid gap-4 sm:grid-cols-2">
        <label class="block text-sm text-slate-700">
            Имя
            <input id="contact-name" name="name" type="text" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
        </label>
        <label class="block text-sm text-slate-700">
            Email
            <input id="contact-email" name="email" type="email" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
        </label>
    </div>

    <label class="mt-4 block text-sm text-slate-700">
        Телефон
        <input id="contact-phone" name="phone" type="tel" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
    </label>

    <label class="mt-4 block text-sm text-slate-700">
        Тема
        <select id="contact-topic" name="topic" class="mt-1 w-full rounded border border-slate-300 px-3 py-2">
            <option value="general">Общий вопрос</option>
            <option value="support">Поддержка</option>
            <option value="sales">Сотрудничество</option>
        </select>
    </label>

    <fieldset class="mt-4">
        <legend class="text-sm font-medium text-slate-700">Как связаться</legend>
        <div class="mt-2 flex flex-wrap gap-4 text-sm text-slate-700">
            <label class="inline-flex items-center gap-2">
                <input type="radio" name="channel" value="email" checked>
                Email
            </label>
            <label class="inline-flex items-center gap-2">
                <input type="radio" name="channel" value="phone">
                Телефон
            </label>
        </div>
    </fieldset>

    <label class="mt-4 block text-sm text-slate-700">
        Сообщение
        <textarea id="contact-message" name="message" rows="4" required class="mt-1 w-full rounded border border-slate-300 px-3 py-2"></textarea>
    </label>

    <label class="mt-4 inline-flex items-start gap-2 text-sm text-slate-700">
        <input type="checkbox" name="agree" value="1" required class="mt-1">
        <span>Согласен на обработку персональных данных</span>
    </label>

    <div class="mt-6">
        <button type="submit" class="rounded bg-sky-600 px-4 py-2 text-sm font-medium text-white hover:bg-sky-700">
            Отправить
        </button>
    </div>
<?php
Form::end();
