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
})();
