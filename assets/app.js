(() => {
    const form = document.querySelector('#license-form');
    if (!form) {
        return;
    }

    const disciplineInputs = Array.from(form.querySelectorAll('[data-disciplines] input'));
    const section = form.querySelector('[data-competitions-section]');
    const rows = Array.from(form.querySelectorAll('[data-competition]'));

    const updateForm = () => {
        const selected = disciplineInputs.filter((input) => input.checked).map((input) => input.value);
        const rowLimit = selected.length === 0 ? 0 : 4 + (selected.length - 1) * 2;
        disciplineInputs[0].setCustomValidity(selected.length ? '' : 'Wybierz co najmniej jedną dyscyplinę.');
        section.hidden = selected.length === 0;

        rows.forEach((row, index) => {
            const visible = index < rowLimit;
            row.hidden = !visible;
            row.disabled = !visible;

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
            urlField.hidden = mzssToggle.checked;
            urlInput.disabled = mzssToggle.checked;
            const active = visible && (
                textInputs.some((input) => !input.disabled && input.value.trim() !== '') ||
                remarks.value.trim() !== '' || categoryInputs.some((input) => input.checked) ||
                calendarInputs.some((input) => input.checked)
            );
            textInputs.forEach((input) => {
                input.required = active && !input.disabled;
            });
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

    const rowName = (row) => row.querySelector('input[name$="[name]"]');
    const rowDate = (row) => row.querySelector('input[name$="[date]"]');

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
    };

    const alreadyListed = (start) => rows.some((row) => {
        const checked = row.querySelector('.row-options .category-options input:checked');
        return !row.disabled && rowName(row).value.trim() === start.name &&
            rowDate(row).value === start.date && checked && checked.value === start.category;
    });

    const applyStarts = (starts) => {
        const found = new Set(starts.map((start) => start.category));
        disciplineInputs.forEach((input) => {
            if (found.has(input.value)) {
                input.checked = true;
            }
        });
        updateForm();

        let inserted = 0;
        let skipped = 0;
        let needsUrl = false;
        starts.forEach((start) => {
            if (alreadyListed(start)) {
                return;
            }
            const row = rows.find((candidate) => !candidate.hidden && !candidate.disabled &&
                rowName(candidate).value.trim() === '');
            if (!row) {
                skipped += 1;
                return;
            }
            fillRow(row, start);
            needsUrl = needsUrl || start.calendar !== 'MZSS';
            inserted += 1;
        });
        updateForm();

        let message = 'Znaleziono startów: ' + starts.length + ', dodano: ' + inserted + '.';
        if (skipped > 0) {
            message += ' Pozostałe (' + skipped + ') nie zmieściły się w dostępnych miejscach.';
        }
        if (needsUrl) {
            message += ' Dla zawodów spoza kalendarza MZSS uzupełnij link do strony zawodów.';
        }
        return message + ' Sprawdź poprawność danych przed wysłaniem.';
    };

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
