(() => {
  const body = document.body;
  if (!body || body.dataset.inlineEdit !== '1') {
    return;
  }

  const saveUrl = body.dataset.saveUrl || '';
  const csrf = body.dataset.csrf || '';
  if (!saveUrl || !csrf) {
    return;
  }

  let active = null;

  function turndownHtml(html) {
    if (typeof TurndownService === 'undefined') {
      throw new Error('Turndown не загружен');
    }
    const service = new TurndownService({
      headingStyle: 'atx',
      bulletListMarker: '-',
      codeBlockStyle: 'fenced',
    });
    return service.turndown(html).trim();
  }

  function createToolbar(blockEl) {
    const toolbar = document.createElement('div');
    toolbar.className = 'inline-edit-toolbar';
    toolbar.setAttribute('role', 'toolbar');
    toolbar.contentEditable = 'false';
    toolbar.innerHTML = `
      <button type="button" data-cmd="bold" title="Жирный"><b>B</b></button>
      <button type="button" data-cmd="italic" title="Курсив"><i>I</i></button>
      <button type="button" data-cmd="h2" title="Заголовок">H2</button>
      <button type="button" data-cmd="ul" title="Список">• List</button>
      <button type="button" data-cmd="ol" title="Нумерованный">1. List</button>
      <button type="button" data-cmd="link" title="Ссылка">Link</button>
      <span class="inline-edit-toolbar__sep"></span>
      <button type="button" data-cmd="save" class="inline-edit-toolbar__save">Сохранить</button>
      <button type="button" data-cmd="cancel" class="inline-edit-toolbar__cancel">Отмена</button>
      <span class="inline-edit-toolbar__status" aria-live="polite"></span>
    `;

    toolbar.addEventListener('mousedown', (event) => {
      event.preventDefault();
    });

    toolbar.addEventListener('click', async (event) => {
      const btn = event.target.closest('button[data-cmd]');
      if (!btn || !active || active.el !== blockEl) {
        return;
      }

      const cmd = btn.dataset.cmd;
      if (cmd === 'bold' || cmd === 'italic') {
        document.execCommand(cmd);
        blockEl.focus();
        return;
      }
      if (cmd === 'h2') {
        document.execCommand('formatBlock', false, 'h2');
        blockEl.focus();
        return;
      }
      if (cmd === 'ul') {
        document.execCommand('insertUnorderedList');
        blockEl.focus();
        return;
      }
      if (cmd === 'ol') {
        document.execCommand('insertOrderedList');
        blockEl.focus();
        return;
      }
      if (cmd === 'link') {
        const url = window.prompt('URL ссылки', 'https://');
        if (url) {
          document.execCommand('createLink', false, url);
        }
        blockEl.focus();
        return;
      }
      if (cmd === 'cancel') {
        cancelEdit();
        return;
      }
      if (cmd === 'save') {
        await saveEdit(btn);
      }
    });

    return toolbar;
  }

  function setStatus(message, isError) {
    if (!active) {
      return;
    }
    const status = active.toolbar.querySelector('.inline-edit-toolbar__status');
    if (!status) {
      return;
    }
    status.textContent = message || '';
    status.classList.toggle('is-error', Boolean(isError));
  }

  function cancelEdit() {
    if (!active) {
      return;
    }
    active.el.innerHTML = active.snapshot;
    teardownEdit();
  }

  function teardownEdit() {
    if (!active) {
      return;
    }
    active.el.contentEditable = 'false';
    active.el.classList.remove('is-editing');
    if (active.toolbar.isConnected) {
      active.toolbar.remove();
    }
    document.removeEventListener('keydown', onKeyDown);
    active = null;
  }

  async function saveEdit(saveBtn) {
    if (!active) {
      return;
    }

    const blockName = active.el.dataset.block;
    active.toolbar.remove();

    let markdown;
    try {
      markdown = turndownHtml(active.el.innerHTML);
    } catch (err) {
      active.el.appendChild(active.toolbar);
      setStatus(err.message || 'Ошибка конвертации', true);
      return;
    }

    saveBtn.disabled = true;
    active.el.appendChild(active.toolbar);
    setStatus('Сохранение…', false);

    const form = new FormData();
    form.append('_csrf', csrf);
    form.append('block', blockName);
    form.append('markdown', markdown);

    try {
      const response = await fetch(saveUrl, {
        method: 'POST',
        body: form,
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          'X-CSRF-Token': csrf,
        },
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || data.error) {
        throw new Error(data.error || `Ошибка ${response.status}`);
      }
      active.toolbar.remove();
      active.snapshot = active.el.innerHTML;
      teardownEdit();
    } catch (err) {
      setStatus(err.message || 'Не удалось сохранить', true);
      saveBtn.disabled = false;
    }
  }

  function onKeyDown(event) {
    if (event.key === 'Escape') {
      event.preventDefault();
      cancelEdit();
    }
  }

  function clearSelection() {
    const selection = window.getSelection();
    if (selection && selection.rangeCount > 0) {
      selection.removeAllRanges();
    }
  }

  function startEdit(blockEl) {
    if (active && active.el === blockEl) {
      return;
    }
    if (active) {
      cancelEdit();
    }

    const snapshot = blockEl.innerHTML;
    const toolbar = createToolbar(blockEl);
    blockEl.classList.add('is-editing');
    blockEl.contentEditable = 'true';
    blockEl.appendChild(toolbar);
    blockEl.focus();
    clearSelection();

    active = { el: blockEl, snapshot, toolbar };
    document.addEventListener('keydown', onKeyDown);
  }

  document.addEventListener('dblclick', (event) => {
    const blockEl = event.target.closest('.editable[data-block]');
    if (!blockEl || !body.contains(blockEl)) {
      return;
    }
    if (event.target.closest('.inline-edit-toolbar')) {
      return;
    }
    event.preventDefault();
    clearSelection();
    startEdit(blockEl);
  });
})();
