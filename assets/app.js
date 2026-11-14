(() => {
    const form = document.querySelector('#license-form');
    if (!form) {
        return;
    }

    const disciplineInputs = Array.from(form.querySelectorAll('[data-disciplines] input'));
    const section = form.querySelector('[data-competitions-section]');
    let rows = Array.from(form.querySelectorAll('[data-competition]'));
    const competitionList = form.querySelector('.competition-list');
    let lookupExpandedCount = 0;
    let lookupReport = null;
    const pdfRowLimit = 8;
    const rowName = (row) => row.querySelector('input[name$="[name]"]');
    const rowDate = (row) => row.querySelector('input[name$="[date]"]');
    const rowHasData = (row) => Array.from(row.querySelectorAll('input')).some((input) =>
        input.matches('[data-mzss-toggle]') ? false :
            input.type === 'checkbox' || input.type === 'radio' ? input.checked : input.value.trim() !== ''
    );
    lookupExpandedCount = rows.reduce((count, row, index) =>
        rowName(row).value.trim() !== '' ? index + 1 : count, 0
    );

    const updateForm = () => {
        const selected = disciplineInputs.filter((input) => input.checked).map((input) => input.value);
        const standardRowLimit = selected.length === 0 ? 0 : 4 + (selected.length - 1) * 2;
        const rowLimit = Math.max(standardRowLimit, lookupExpandedCount);
        disciplineInputs[0].setCustomValidity(selected.length ? '' : 'Wybierz co najmniej jedną dyscyplinę.');
        section.hidden = selected.length === 0;

        rows.forEach((row, index) => {
            const visible = index < rowLimit;
            row.hidden = !visible;
            row.disabled = !visible;
            row.querySelector('[data-remove-start]').disabled = !visible || !rowHasData(row);

            const categoryInputs = Array.from(row.querySelectorAll('.row-options .category-options input'));
            const calendarInputs = Array.from(row.querySelectorAll('[data-calendar-options] input'));
            categoryInputs.forEach((input) => {
                const available = selected.includes(input.value);
                input.disabled = !available;
                input.closest('[data-category-option]').hidden = !available;
                if (!available) {
                    input.checked = false;
                }
                input.required = false;
            });

            const mzssToggle = row.querySelector('[data-mzss-toggle]');
            const urlField = row.querySelector('[data-url-field]');
            const urlInput = urlField.querySelector('input');
            const textInputs = Array.from(row.querySelectorAll('[data-required-when-active]'));
            const remarks = row.querySelector('.remarks-field input');
            const hasUrl = urlInput.value.trim() !== '';
            urlField.hidden = mzssToggle.checked && !hasUrl;
            urlInput.disabled = mzssToggle.checked && !hasUrl;
            const active = visible && (
                textInputs.some((input) => !input.disabled && input.value.trim() !== '') ||
                remarks.value.trim() !== '' || categoryInputs.some((input) => input.checked) ||
                calendarInputs.some((input) => input.checked)
            );
            textInputs.forEach((input) => {
                input.required = input !== urlInput && active && !input.disabled;
            });
            urlInput.required = active && !mzssToggle.checked;
            const firstCategory = categoryInputs.find((input) => !input.disabled);
            if (firstCategory) {
                firstCategory.required = active;
            }
            calendarInputs[0].required = active;
        });
    };

    form.addEventListener('input', updateForm);
    form.addEventListener('change', updateForm);
    updateForm();

    const lookupButton = form.querySelector('[data-lookup-button]');
    const lookupStatus = form.querySelector('[data-lookup-status]');
    const field = (name) => form.querySelector('[name="' + name + '"]');

    const updateRowIndex = (row, index) => {
        row.dataset.index = String(index);
        row.querySelector('.row-index').textContent = String(index + 1).padStart(2, '0');
        row.classList.toggle('competition-row-overflow', index >= pdfRowLimit);
        if (index >= pdfRowLimit) {
            row.dataset.overflowRow = '';
        } else {
            delete row.dataset.overflowRow;
        }
        row.querySelectorAll('[name]').forEach((input) => {
            input.name = input.name.replace(/competitions\[\d+\]/, 'competitions[' + index + ']');
        });
    };

    const addOverflowRow = () => {
        const template = rows[Math.min(pdfRowLimit - 1, rows.length - 1)];
        const row = template.cloneNode(true);
        row.hidden = false;
        row.disabled = false;
        delete row.dataset.foundStart;
        row.querySelectorAll('input').forEach((input) => {
            if (input.type === 'checkbox' || input.type === 'radio') {
                input.checked = false;
            } else {
                input.value = '';
            }
            input.required = false;
        });
        updateRowIndex(row, rows.length);
        rows.push(row);
        competitionList.appendChild(row);
        return row;
    };

    const ensureRowCount = (count) => {
        while (rows.length < count) {
            addOverflowRow();
        }
    };

    const renderLookupStatus = () => {
        if (!lookupReport) {
            return;
        }

        const filledRows = rows.filter((row) => rowName(row).value.trim() !== '').length;
        const overflowCount = rows.slice(pdfRowLimit).filter(rowHasData).length;
        const disciplineCounts = lookupReport.counts;
        const countSummary = Object.keys(disciplineCounts)
            .filter((discipline) => disciplineCounts[discipline] > 0)
            .map((discipline) => disciplineCounts[discipline] + ' × ' + {
                pistol: 'pistolet',
                rifle: 'karabin',
                shotgun: 'strzelba',
            }[discipline])
            .join(', ');
        let message = 'Znaleziono ' + lookupReport.found + ' startów (' + countSummary +
            '); w formularzu: ' + filledRows + '.';
        if (lookupReport.alreadyListed > 0) {
            message += ' Już wpisane: ' + lookupReport.alreadyListed + '.';
        }
        if (overflowCount > 0) {
            message += ' PDF mieści maksymalnie 8 startów — usuń nadmiarowe pozycje (liczba: ' +
                overflowCount + ') przed pobraniem lub wysłaniem.';
        }
        if (lookupReport.removed > 0) {
            message += ' Usunięto z formularza ' + lookupReport.removed +
                ' znalezionych startów — upewnij się, że wykaz nadal jest kompletny.';
        }
        if (lookupReport.missing > 0) {
            message += ' Nie dodano wszystkich znalezionych startów (brakuje: ' +
                lookupReport.missing + '); sprawdź formularz.';
        }
        if (lookupReport.missingRemarks > 0) {
            message += ' Brak nazwy konkurencji w uwagach dla ' + lookupReport.missingRemarks +
                ' pozycji — sprawdź uwagi ręcznie.';
        }
        if (lookupReport.needsUrl) {
            message += ' Dla zawodów spoza kalendarza MZSS uzupełnij link do strony zawodów.';
        }
        lookupStatus.textContent = message + ' Sprawdź poprawność danych przed wysłaniem.';
        lookupStatus.classList.toggle(
            'lookup-warning',
            overflowCount > 0 || lookupReport.missing > 0 || lookupReport.missingRemarks > 0
        );
    };

    const fillRow = (row, start) => {
        rowName(row).value = start.name;
        rowDate(row).value = start.date;
        row.querySelector('input[name$="[location]"]').value = start.location;
        const category = row.querySelector('.row-options .category-options input[value="' + start.category + '"]');
        if (category) {
            category.checked = true;
        }
        const calendar = row.querySelector('[data-calendar-options] input[value="' + start.calendar + '"]');
        if (calendar) {
            calendar.checked = true;
        }
        row.querySelector('[data-mzss-toggle]').checked = start.calendar === 'MZSS';
        row.querySelector('[data-url-field] input').value = start.url || '';
        row.querySelector('.remarks-field input').value = start.remarks || '';
    };

    const listedRow = (start) => rows.find((row) => {
        const checked = row.querySelector('.row-options .category-options input:checked');
        return !row.disabled && rowName(row).value.trim() === start.name &&
            rowDate(row).value === start.date && checked && checked.value === start.category &&
            row.querySelector('.remarks-field input').value.trim() === (start.remarks || '');
    });
    const alreadyListed = (start) => Boolean(listedRow(start));

    const applyStarts = (starts) => {
        const found = new Set(starts.map((start) => start.category));
        disciplineInputs.forEach((input) => {
            if (found.has(input.value)) {
                input.checked = true;
            }
        });

        const lastFilledIndex = rows.reduce((lastIndex, row, index) =>
            rowName(row).value.trim() !== '' ? index : lastIndex, -1
        );
        lookupExpandedCount = Math.max(lookupExpandedCount, lastFilledIndex + 1);
        updateForm();

        const alreadyListedCount = starts.filter(alreadyListed).length;
        const filledCount = rows.filter((row) => rowName(row).value.trim() !== '').length;
        const neededRows = filledCount + starts.length - alreadyListedCount;
        lookupExpandedCount = Math.max(lookupExpandedCount, neededRows);
        ensureRowCount(lookupExpandedCount);
        updateForm();

        let inserted = 0;
        let alreadyListedTotal = 0;
        let missing = 0;
        let needsUrl = false;
        starts.forEach((start) => {
            const existingRow = listedRow(start);
            if (existingRow) {
                const urlInput = existingRow.querySelector('[data-url-field] input');
                if (urlInput.value.trim() === '' && start.url) {
                    urlInput.value = start.url;
                }
                existingRow.dataset.foundStart = '1';
                alreadyListedTotal += 1;
                return;
            }
            const row = rows.find((candidate) => !candidate.hidden && !candidate.disabled &&
                rowName(candidate).value.trim() === '');
            if (!row) {
                missing += 1;
                return;
            }
            fillRow(row, start);
            row.dataset.foundStart = '1';
            needsUrl = needsUrl || (start.calendar !== 'MZSS' && !String(start.url || '').trim());
            inserted += 1;
        });
        updateForm();

        const counts = { pistol: 0, rifle: 0, shotgun: 0 };
        starts.forEach((start) => {
            if (Object.prototype.hasOwnProperty.call(counts, start.category)) {
                counts[start.category] += 1;
            }
        });
        lookupReport = {
            found: starts.length,
            alreadyListed: alreadyListedTotal,
            removed: 0,
            missing,
            missingRemarks: starts.filter((start) => !String(start.remarks || '').trim()).length,
            needsUrl,
            counts,
        };
        renderLookupStatus();
        return lookupStatus.textContent;
    };

    competitionList.addEventListener('click', (event) => {
        const button = event.target.closest('[data-remove-start]');
        if (!button) {
            return;
        }
        const row = button.closest('[data-competition]');
        if (!row) {
            return;
        }

        if (lookupReport && row.dataset.foundStart === '1') {
            lookupReport.removed += 1;
        }
        rows = rows.filter((candidate) => candidate !== row);
        row.remove();
        rows.forEach(updateRowIndex);
        ensureRowCount(pdfRowLimit);
        lookupExpandedCount = rows.reduce((count, candidate, index) =>
            rowHasData(candidate) ? index + 1 : count, 0
        );
        updateForm();
        renderLookupStatus();
    });

    form.addEventListener('submit', (event) => {
        const overflowRows = rows.slice(pdfRowLimit).filter(rowHasData);
        if (overflowRows.length === 0) {
            return;
        }
        event.preventDefault();
        lookupStatus.textContent = 'PDF mieści maksymalnie 8 startów — usuń nadmiarowe pozycje (liczba: ' +
            overflowRows.length + ') przed pobraniem lub wysłaniem.';
        lookupStatus.classList.add('lookup-warning');
        overflowRows[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
    });

    if (lookupButton && lookupStatus) {
        lookupButton.addEventListener('click', async () => {
            const lastName = field('applicant[last_name]').value.trim();
            const license = field('applicant[license]').value.trim();
            if (lastName === '' || license === '') {
                lookupStatus.textContent = 'Podaj nazwisko i numer licencji, aby wyszukać starty.';
                return;
            }

            const body = new FormData();
            body.set('csrf_token', field('csrf_token').value);
            body.set('last_name', lastName);
            body.set('license', license);
            lookupButton.disabled = true;
            lookupStatus.textContent = 'Szukam startów…';
            try {
                const response = await fetch('lookup.php', { method: 'POST', body, credentials: 'same-origin' });
                const data = await response.json();
                if (!data.ok) {
                    lookupStatus.textContent = data.error;
                } else if (data.starts.length === 0) {
                    lookupReport = null;
                    lookupStatus.classList.remove('lookup-warning');
                    lookupStatus.textContent = 'Nie znaleziono startów dla podanych danych. Wpisz je ręcznie.';
                } else {
                    lookupStatus.textContent = applyStarts(data.starts);
                }
            } catch (error) {
                lookupStatus.textContent = 'Nie udało się wyszukać startów. Wpisz je ręcznie.';
            } finally {
                lookupButton.disabled = false;
            }
        });
    }
})();
