(() => {
  function ready(fn) {
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', fn);
    else fn();
  }

  ready(() => {
    const root = document.getElementById('hello-admin');
    const form = document.getElementById('hello-form');
    const status = document.getElementById('hello-status');
    if (!root || !form) return;

    const toast = (message, type) => {
      status.textContent = message;
      if (window.Motorchk && window.Motorchk.toast) window.Motorchk.toast.show(message, type);
    };

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const greeting = form.elements.greeting.value;
      try {
        const response = await fetch(root.dataset.api, {
          method: 'PUT',
          headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-CSRF-Token': root.dataset.csrf,
          },
          body: JSON.stringify({ greeting }),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.ok) throw new Error(data.error || 'HTTP ' + response.status);
        toast('Сохранено: ' + data.greeting, 'success');
      } catch (error) {
        toast('Ошибка: ' + error.message, 'error');
      }
    });
  });
})();
