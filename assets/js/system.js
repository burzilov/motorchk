(() => {
  const dialog = document.getElementById('update-confirm');
  let pending = null;

  const secretBox = document.getElementById('maintenance-secret-box');
  if (secretBox) {
    const link = document.getElementById('maintenance-secret-link');
    const secret = secretBox.dataset.maintenanceSecret || '';
    const url = `${window.location.origin}/?maintenance_key=${encodeURIComponent(secret)}`;
    if (link) link.textContent = url;
    const copyButton = secretBox.querySelector('[data-maintenance-copy]');
    if (copyButton) {
      copyButton.addEventListener('click', async () => {
        try {
          await navigator.clipboard.writeText(url);
          copyButton.textContent = 'Скопировано';
        } catch {
          copyButton.textContent = 'Не удалось скопировать — выделите из строки ниже';
        }
      });
    }
    const hideButton = secretBox.querySelector('[data-maintenance-hide]');
    if (hideButton) {
      hideButton.addEventListener('click', () => secretBox.remove());
    }
  }

  document.addEventListener('submit', async (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement)) return;

    if (form.hasAttribute('data-confirm-update')) {
      event.preventDefault();
      if (!dialog || typeof dialog.showModal !== 'function') {
        document.dispatchEvent(new CustomEvent('mc:toast', { detail: { message: 'Подтверждение обновления недоступно в этом браузере.', type: 'error' } }));
        return;
      }
      pending = form;
      dialog.querySelector('#update-confirm-text').textContent = form.dataset.confirmUpdate;
      dialog.showModal();
      dialog.querySelector('[data-confirm-cancel]').focus();
      return;
    }

    if (!form.hasAttribute('data-update-check') || !window.fetch) return;
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    const section = document.getElementById(form.dataset.updateCheck);
    if (!button || !section) return;
    const original = button.textContent;
    button.disabled = true;
    button.textContent = 'Проверка…';
    try {
      const response = await fetch(form.action, { method: 'POST', body: new FormData(form), credentials: 'same-origin' });
      if (!response.ok || new URL(response.url).pathname !== '/admin-panel/system') throw new Error('Не удалось проверить обновления. Проверьте подключение или войдите снова.');
      const page = new DOMParser().parseFromString(await response.text(), 'text/html');
      const replacement = page.getElementById(section.id);
      if (!replacement) throw new Error('Не удалось получить результат проверки.');
      const boot = page.getElementById('mc-toasts-boot');
      if (boot) {
        const payload = JSON.parse(boot.textContent);
        (payload.items || []).forEach((item) => document.dispatchEvent(new CustomEvent('mc:toast', { detail: item })));
      }
      section.replaceWith(replacement);
      const version = document.querySelector('.mc-bar__version');
      const newVersion = page.querySelector('.mc-bar__version');
      if (version && newVersion) version.replaceWith(newVersion);
    } catch (error) {
      document.dispatchEvent(new CustomEvent('mc:toast', { detail: { message: error.message || 'Не удалось проверить обновления.', type: 'error' } }));
    } finally {
      button.disabled = false;
      button.textContent = original;
    }
  });

  if (dialog) {
    dialog.addEventListener('close', () => { pending = null; });
    dialog.querySelector('[data-confirm-cancel]').addEventListener('click', () => dialog.close());
    dialog.querySelector('[data-confirm-accept]').addEventListener('click', () => {
      const form = pending;
      dialog.close();
      if (form) form.submit();
    });
  }
})();
