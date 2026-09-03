(() => {
  const DURATION_MS = 4000;
  let nextId = 1;
  const pending = [];

  function push(message, type) {
    const text = String(message || '').trim();
    if (!text) {
      return;
    }
    const kind = type === 'error' || type === 'info' ? type : 'success';
    if (window.Alpine && typeof Alpine.store === 'function') {
      try {
        const store = Alpine.store('toast');
        if (store && typeof store.show === 'function') {
          store.show(text, kind);
          return;
        }
      } catch (_) {
        /* store not ready yet */
      }
    }
    pending.push({ message: text, type: kind });
  }

  function flushPending() {
    while (pending.length) {
      const item = pending.shift();
      push(item.message, item.type);
    }
  }

  function applyBoot() {
    const el = document.getElementById('mc-toasts-boot');
    if (!el) {
      return;
    }
    let payload = null;
    try {
      payload = JSON.parse(el.textContent || 'null');
    } catch (_) {
      payload = null;
    }
    el.remove();
    if (!payload) {
      return;
    }

    const items = Array.isArray(payload) ? payload : payload.items;
    if (Array.isArray(items)) {
      items.forEach((item) => {
        if (!item || typeof item !== 'object') {
          return;
        }
        push(item.message, item.type || 'success');
      });
    }

    const cleanParams = Array.isArray(payload.cleanParams) ? payload.cleanParams : [];
    if (cleanParams.length && window.history && window.location.search) {
      const url = new URL(window.location.href);
      let changed = false;
      cleanParams.forEach((name) => {
        if (url.searchParams.has(name)) {
          url.searchParams.delete(name);
          changed = true;
        }
      });
      if (changed) {
        const next = url.pathname + (url.searchParams.toString() ? '?' + url.searchParams.toString() : '') + url.hash;
        window.history.replaceState({}, '', next);
      }
    }
  }

  function onToastEvent(event) {
    const detail = event.detail;
    if (detail == null) {
      return;
    }
    if (typeof detail === 'string') {
      push(detail, 'success');
      return;
    }
    if (Array.isArray(detail)) {
      detail.forEach((item) => {
        if (typeof item === 'string') {
          push(item, 'success');
        } else if (item && typeof item === 'object') {
          push(item.message, item.type || 'success');
        }
      });
      return;
    }
    if (typeof detail === 'object') {
      push(detail.message, detail.type || 'success');
    }
  }

  document.addEventListener('alpine:init', () => {
    Alpine.store('toast', {
      items: [],
      show(message, type = 'success') {
        const id = nextId++;
        const kind = type === 'error' || type === 'info' ? type : 'success';
        this.items.push({ id, message: String(message || ''), type: kind });
        window.setTimeout(() => this.dismiss(id), DURATION_MS);
      },
      dismiss(id) {
        this.items = this.items.filter((item) => item.id !== id);
      },
      queue(list) {
        (list || []).forEach((item) => {
          if (!item) {
            return;
          }
          if (typeof item === 'string') {
            this.show(item, 'success');
            return;
          }
          this.show(item.message, item.type || 'success');
        });
      },
    });
    flushPending();
  });

  window.Motorchk = window.Motorchk || {};
  window.Motorchk.toast = {
    show: push,
    success(message) {
      push(message, 'success');
    },
    error(message) {
      push(message, 'error');
    },
    info(message) {
      push(message, 'info');
    },
  };

  document.addEventListener('mc:toast', onToastEvent);

  document.addEventListener('DOMContentLoaded', () => {
    applyBoot();
  });
  if (document.readyState !== 'loading') {
    applyBoot();
  }
})();
