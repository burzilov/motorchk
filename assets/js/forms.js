(() => {
  function fieldName(el) {
    if (!el.name) return null;
    return el.name.endsWith('[]') ? el.name.slice(0, -2) : el.name;
  }

  function collectPayload(form) {
    const data = {};
    const fd = new FormData(form);
    for (const [key, value] of fd.entries()) {
      const name = key.endsWith('[]') ? key.slice(0, -2) : key;
      if (Object.prototype.hasOwnProperty.call(data, name)) {
        if (!Array.isArray(data[name])) {
          data[name] = [data[name]];
        }
        data[name].push(value);
      } else {
        data[name] = value;
      }
    }

    form.querySelectorAll('input[type="checkbox"]').forEach((el) => {
      const name = fieldName(el);
      if (!name || name.startsWith('_')) return;
      const group = form.querySelectorAll(`input[type="checkbox"][name="${CSS.escape(el.name)}"]`);
      if (group.length > 1) {
        if (!Object.prototype.hasOwnProperty.call(data, name)) {
          data[name] = [];
        }
      } else if (!el.checked && !Object.prototype.hasOwnProperty.call(data, name)) {
        data[name] = '';
      }
    });

    return data;
  }

  function clearErrors(form) {
    form.querySelectorAll('[data-form-error]').forEach((el) => el.remove());
    const box = form.querySelector('[data-form-errors]');
    if (box) {
      box.hidden = true;
      box.textContent = '';
    }
  }

  function showErrors(form, errors) {
    const box = form.querySelector('[data-form-errors]');
    const messages = Object.values(errors || {});
    if (box) {
      box.hidden = false;
      box.textContent = messages.join(' ');
    }

    Object.entries(errors || {}).forEach(([name, message]) => {
      if (name === '_form') return;
      const field =
        form.querySelector(`[name="${CSS.escape(name)}"]`) ||
        form.querySelector(`[name="${CSS.escape(name)}[]"]`);
      if (!field || !field.parentElement) return;
      const err = document.createElement('p');
      err.dataset.formError = name;
      err.className = 'mt-1 text-sm text-red-600';
      err.textContent = message;
      field.parentElement.appendChild(err);
    });
  }

  function showSuccess(form, message) {
    if (window.Motorchk && Motorchk.toast) {
      Motorchk.toast.success(message || 'Отправлено');
    }
    form.reset();
  }

  async function onSubmit(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-motorchk-form]')) {
      return;
    }

    event.preventDefault();
    clearErrors(form);

    const submitBtn = form.querySelector('[type="submit"]');
    if (submitBtn) submitBtn.disabled = true;

    try {
      const payload = collectPayload(form);
      const response = await fetch(form.action || '/api/forms/submit', {
        method: 'POST',
        headers: {
          Accept: 'application/json',
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(payload),
        credentials: 'same-origin',
      });

      const data = await response.json().catch(() => ({}));
      if (response.ok && data.ok) {
        showSuccess(form, data.message);
      } else {
        showErrors(form, data.errors || { _form: 'Не удалось отправить форму' });
      }
    } catch (e) {
      showErrors(form, { _form: 'Ошибка сети, попробуйте позже' });
    } finally {
      if (submitBtn) submitBtn.disabled = false;
    }
  }

  document.addEventListener('submit', onSubmit, true);
})();
