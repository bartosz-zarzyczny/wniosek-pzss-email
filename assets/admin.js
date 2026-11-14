(() => {
    const root = document.querySelector('[data-admin]');
    if (!root) {
        return;
    }

    const csrf = root.dataset.csrf;
    const runButton = root.querySelector('[data-run]');
    const pythonButton = root.querySelector('[data-python]');
    const statusBox = root.querySelector('[data-status]');
    const table = root.querySelector('[data-table]');
    const tbody = table.querySelector('tbody');
    const progress = root.querySelector('[data-progress]');
    const bar = root.querySelector('[data-progress-bar]');
    const yearInput = root.querySelector('[data-year]');
    const forceInput = root.querySelector('[data-force]');
    const manualForm = document.querySelector('[data-manual-import]');
    const manualButton = document.querySelector('[data-manual-submit]');
    const manualStatus = document.querySelector('[data-manual-status]');

    const statusLabels = {
        imported: 'zaimportowano',
        current: 'aktualny',
        empty: 'pominięto (brak licencji)',
        unknown_discipline: 'pominięto (dyscyplina)',
        error: 'błąd',
        new: 'oczekuje',
    };

    async function api(action, data = {}) {
        const body = new URLSearchParams({ csrf_token: csrf, action, ...data });
        const response = await fetch('admin_api.php', { method: 'POST', body, credentials: 'same-origin' });
        const payload = await response.json().catch(() => ({ ok: false, error: 'Nieprawidłowa odpowiedź serwera.' }));
        if (!response.ok || !payload.ok) {
            throw new Error(payload.error || 'Błąd serwera.');
        }
        return payload;
    }

    function cell(row, text) {
        const td = document.createElement('td');
        td.textContent = text;
        row.appendChild(td);
        return td;
    }

    function setBusy(busy) {
        runButton.disabled = busy;
        pythonButton.disabled = busy;
        manualButton.disabled = busy;
    }

    manualForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        const body = new FormData(manualForm);
        body.set('action', 'manual_import');
        setBusy(true);
        manualStatus.textContent = 'Wysyłanie i odczyt PDF…';
        try {
            const response = await fetch('admin_api.php', { method: 'POST', body, credentials: 'same-origin' });
            const payload = await response.json().catch(() => ({
                ok: false,
                error: 'Nieprawidłowa odpowiedź serwera.',
            }));
            if (!response.ok || !payload.ok) {
                throw new Error(payload.error || 'Błąd serwera.');
            }
            manualStatus.textContent = payload.message;
        } catch (error) {
            manualStatus.textContent = error.message;
        } finally {
            setBusy(false);
        }
    });

    pythonButton.addEventListener('click', async () => {
        setBusy(true);
        statusBox.textContent = 'Sprawdzanie Pythona…';
        try {
            const result = await api('python');
            statusBox.textContent = (result.ok ? 'Python działa: ' : 'Problem z Pythonem: ') + result.message;
        } catch (error) {
            statusBox.textContent = error.message;
        } finally {
            setBusy(false);
        }
    });

    runButton.addEventListener('click', async () => {
        setBusy(true);
        tbody.replaceChildren();
        table.hidden = true;
        progress.hidden = true;
        statusBox.textContent = 'Skanowanie kalendarza…';
        try {
            const scan = await api('scan', { year: yearInput.value });
            const items = scan.items;
            if (items.length === 0) {
                statusBox.textContent = 'Nie znaleziono plików z wynikami dla roku ' + scan.year + '.';
                return;
            }

            const rows = items.map((item) => {
                const row = document.createElement('tr');
                cell(row, item.file);
                cell(row, (item.date || '?') + ' · ' + item.title);
                const result = cell(row, statusLabels[item.status] || item.status);
                tbody.appendChild(row);
                return result;
            });
            table.hidden = false;
            progress.hidden = false;

            const counts = { downloaded: 0, errors: 0 };
            for (let index = 0; index < items.length; index++) {
                statusBox.textContent = `Przetwarzanie ${index + 1} / ${items.length}…`;
                bar.style.width = (index / items.length) * 100 + '%';
                try {
                    const result = await api('process', { url: items[index].url, force: forceInput.checked ? '1' : '0' });
                    rows[index].textContent = (statusLabels[result.status] || result.status) + ' — ' + result.message;
                    if (result.status === 'error') {
                        counts.errors++;
                    }
                } catch (error) {
                    rows[index].textContent = 'błąd — ' + error.message;
                    counts.errors++;
                }
            }
            bar.style.width = '100%';
            statusBox.textContent = `Gotowe: ${items.length} plików, błędów: ${counts.errors}. Odśwież stronę, by zobaczyć liczniki.`;
        } catch (error) {
            statusBox.textContent = error.message;
        } finally {
            setBusy(false);
        }
    });
})();
