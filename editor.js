/* Progressive enhancement only: native POST forms and textareas remain fully functional. */
(() => {
    'use strict';
    const app = document.getElementById('ccjf-app');
    const form = document.getElementById('ccjf-code-form');
    if (!app || !form) return;
    const fields = [...form.querySelectorAll('.cj-code')];
    const checks = [...form.querySelectorAll('input[type="checkbox"]')];
    const initial = fields.map((field) => field.value);
    const initialChecks = checks.map((check) => check.checked);
    const editors = [];
    let submitting = false;
    const forcedDirty = app.dataset.dirty === 'yes';
    const sync = () => editors.forEach((editor) => editor.codemirror.save());
    const dirty = () => forcedDirty || fields.some((field, i) => field.value !== initial[i]) || checks.some((check, i) => check.checked !== initialChecks[i]);
    function update() {
        sync();
        fields.forEach((field) => {
            const output = app.querySelector(`[data-for="${field.id}"]`);
            const bytes = new TextEncoder().encode(field.value).length;
            output.textContent = `${bytes.toLocaleString()} bytes / 256 KiB`;
            output.classList.toggle('is-over-limit', bytes > 262144);
        });
        const status = document.getElementById('ccjf-save-state');
        status.textContent = dirty() ? 'Unsaved changes' : 'Showing saved code';
        status.classList.toggle('is-dirty', dirty());
    }
    fields.forEach((field) => {
        field.addEventListener('input', update);
        const settings = window.ccjfEditors?.[field.dataset.mode];
        if (settings && window.wp?.codeEditor) {
            try {
                const config = { ...settings, codemirror: { ...settings.codemirror, screenReaderLabel: document.querySelector(`label[for="${field.id}"]`).textContent } };
                const editor = window.wp.codeEditor.initialize(field, config);
                editors.push(editor);
                editor.codemirror.on('change', update);
            } catch { /* The visible textarea is the fallback if the optional editor is unavailable. */ }
        }
    });
    checks.forEach((check) => check.addEventListener('change', update));
    const plain = document.getElementById('ccjf-plain');
    plain.hidden = !editors.length;
    plain.addEventListener('click', () => {
        sync();
        editors.forEach((editor) => editor.codemirror.toTextArea());
        editors.length = 0;
        plain.hidden = true;
        update();
        fields[0].focus();
    });
    form.addEventListener('submit', () => { sync(); submitting = true; });
    form.addEventListener('keydown', (event) => {
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') { event.preventDefault(); form.requestSubmit(); }
    });
    window.addEventListener('beforeunload', (event) => {
        sync();
        if (!submitting && dirty()) { event.preventDefault(); event.returnValue = ''; }
    });
    window.addEventListener('pageshow', () => { submitting = false; update(); });
    update();
})();
