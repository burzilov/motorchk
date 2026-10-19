(() => {
  document.addEventListener('DOMContentLoaded', () => {
    const root = document.getElementById('hello-public');
    if (!root) return;
    // Пример публичного POST с CSRF-токеном страницы.
    fetch(root.dataset.api, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': root.dataset.csrf },
      body: JSON.stringify({ text: 'ping' }),
    })
      .then((r) => r.json())
      .then((data) => root.setAttribute('data-echo', data.echo || ''))
      .catch(() => {});
  });
})();
