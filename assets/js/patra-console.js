(() => {
    'use strict';

    const consolePage = document.querySelector('.sn-patra-console');
    if (!consolePage) return;

    const status = consolePage.querySelector('[data-patra-copy-status]');
    const timers = new WeakMap();

    async function copyText(value) {
        if (navigator.clipboard && window.isSecureContext) {
            try {
                await navigator.clipboard.writeText(value);
                return true;
            } catch (error) {
                // Fall back when the browser blocks the Clipboard API.
            }
        }

        const field = document.createElement('textarea');
        const focused = document.activeElement;
        field.className = 'sn-patra-copy-buffer';
        field.value = value;
        field.readOnly = true;
        consolePage.append(field);
        field.select();
        try {
            return document.execCommand('copy');
        } catch (error) {
            return false;
        } finally {
            field.remove();
            if (focused instanceof HTMLElement) focused.focus({ preventScroll: true });
        }
    }

    consolePage.querySelectorAll('[data-patra-copy]').forEach(button => {
        const source = document.getElementById(button.dataset.patraCopy);
        if (!source || !consolePage.contains(source)) return;

        const label = button.querySelector('span');
        const defaultLabel = label.textContent;
        button.hidden = false;
        button.addEventListener('click', async () => {
            const value = source instanceof HTMLInputElement ? source.value : source.textContent;
            const copied = await copyText(value);
            clearTimeout(timers.get(button));
            label.textContent = copied ? 'کپی شد' : 'کپی نشد';
            status.textContent = copied ? 'متن کپی شد.' : 'متن را انتخاب و دستی کپی کنید.';
            if (copied) button.dataset.state = 'copied';
            else delete button.dataset.state;

            timers.set(button, setTimeout(() => {
                label.textContent = defaultLabel;
                delete button.dataset.state;
            }, 1900));
        });
    });

    const tabList = consolePage.querySelector('[role="tablist"]');
    const tabs = Array.from(tabList.querySelectorAll('[role="tab"]'));
    const panels = tabs.map(tab => document.getElementById(tab.getAttribute('aria-controls')));

    function showSample(index) {
        tabs.forEach((tab, current) => {
            const selected = current === index;
            tab.setAttribute('aria-selected', String(selected));
            tab.tabIndex = selected ? 0 : -1;
            panels[current].hidden = !selected;
        });
    }

    panels.forEach((panel, index) => {
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('aria-labelledby', tabs[index].id);
    });
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => showSample(index));
        tab.addEventListener('keydown', event => {
            let next;
            switch (event.key) {
                case 'ArrowLeft': next = (index + 1) % tabs.length; break;
                case 'ArrowRight': next = (index + tabs.length - 1) % tabs.length; break;
                case 'Home': next = 0; break;
                case 'End': next = tabs.length - 1; break;
                default: return;
            }
            event.preventDefault();
            showSample(next);
            tabs[next].focus();
        });
    });
    showSample(0);
    tabList.hidden = false;

    const json = document.getElementById('sn-patra-json-example');
    const content = json.textContent;
    const highlighted = document.createDocumentFragment();
    const tokens = /("(?:\\.|[^"\\])*")(\s*:)?|\b(?:true|false|null)\b|-?\d+(?:\.\d+)?(?:[eE][+-]?\d+)?/g;
    let last = 0;
    for (const match of content.matchAll(tokens)) {
        const token = document.createElement('span');
        token.className = match[2] ? 'sn-patra-json-key' : (match[1] ? 'sn-patra-json-string' : 'sn-patra-json-literal');
        token.textContent = match[1] || match[0];
        highlighted.append(content.slice(last, match.index), token, match[2] || '');
        last = match.index + match[0].length;
    }
    highlighted.append(content.slice(last));
    json.replaceChildren(highlighted);
})();
