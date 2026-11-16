(() => {
    const filterForm = document.getElementById('table-filters');
    const table = document.querySelector('.data-table');
    if (!filterForm || !table) return;

    let filterTimer;
    const submitFilters = () => {
        clearTimeout(filterTimer);
        filterTimer = setTimeout(() => filterForm.submit(), 200);
    };

    table.querySelectorAll('.filter-row input, .filter-row select').forEach((control) => {
        control.addEventListener(control.tagName === 'SELECT' ? 'change' : 'input', submitFilters);
    });

    const pageSize = document.getElementById('rows-per-page');
    if (pageSize) {
        pageSize.addEventListener('change', () => filterForm.submit());
    }

    const storageKey = `table-columns:${new URLSearchParams(location.search).get('table')}`;
    let hiddenColumns = [];
    try {
        hiddenColumns = JSON.parse(localStorage.getItem(storageKey) || '[]');
        if (!Array.isArray(hiddenColumns)) hiddenColumns = [];
    } catch (error) {
        console.error('Unable to load saved column visibility.', error);
        hiddenColumns = [];
    }

    const setColumnVisibility = (column, visible) => {
        table.querySelectorAll('[data-column]').forEach((cell) => {
            if (cell.dataset.column !== column) return;
            if (cell.tagName === 'TH' && !cell.closest('.filter-row')) {
                cell.classList.toggle('column-hidden', !visible);
            } else {
                cell.hidden = !visible;
            }
        });
        const toggle = Array.from(table.querySelectorAll('[data-toggle-column]'))
            .find((button) => button.dataset.toggleColumn === column);
        if (toggle) {
            toggle.setAttribute('aria-label', `${visible ? 'Hide' : 'Show'} ${column}`);
            toggle.title = `${visible ? 'Hide' : 'Show'} column`;
            toggle.setAttribute('aria-pressed', visible ? 'true' : 'false');
        }
    };

    hiddenColumns.forEach((column) => setColumnVisibility(column, false));
    table.querySelectorAll('[data-toggle-column]').forEach((button) => {
        button.addEventListener('click', () => {
            const column = button.dataset.toggleColumn;
            const currentlyVisible = !button.closest('th').classList.contains('column-hidden');
            const visible = !currentlyVisible;
            let clearActiveFilter = false;
            if (!visible) {
                const filterCell = Array.from(table.querySelectorAll('.filter-row th'))
                    .find((cell) => cell.dataset.column === column);
                const input = filterCell && filterCell.querySelector('input');
                const operator = filterCell && filterCell.querySelector('select');
                const activeFilter = input && input.value.trim() !== ''
                    || operator && ['null', 'not_null'].includes(operator.value);
                if (activeFilter) {
                    clearActiveFilter = true;
                    if (input) input.value = '';
                    if (operator) operator.value = operator.querySelector('option[value="contains"]')
                        ? 'contains' : 'eq';
                }
            }
            setColumnVisibility(column, visible);
            hiddenColumns = hiddenColumns.filter((item) => item !== column);
            if (!visible) hiddenColumns.push(column);
            try {
                localStorage.setItem(storageKey, JSON.stringify(hiddenColumns));
            } catch (error) {
                console.error('Unable to save column visibility.', error);
            }
            if (clearActiveFilter) submitFilters();
        });
    });

    const closeMenus = () => {
        document.querySelectorAll('.action-menu.open').forEach((menu) => {
            menu.classList.remove('open');
            menu.style.top = '';
            menu.style.left = '';
            const trigger = menu.parentElement.querySelector('.action-menu-button');
            if (trigger) trigger.setAttribute('aria-expanded', 'false');
        });
    };

    const openMenu = (row, x, y) => {
        closeMenus();
        const menu = row.querySelector('.action-menu');
        const trigger = row.querySelector('.action-menu-button');
        if (!menu || !trigger || !menu.children.length) return;
        menu.classList.add('open');
        trigger.setAttribute('aria-expanded', 'true');
        const bounds = menu.getBoundingClientRect();
        menu.style.left = `${Math.max(8, Math.min(x, innerWidth - bounds.width - 8))}px`;
        menu.style.top = `${Math.max(8, Math.min(y, innerHeight - bounds.height - 8))}px`;
    };

    table.querySelectorAll('.data-row').forEach((row) => {
        const trigger = row.querySelector('.action-menu-button');
        if (trigger) {
            trigger.addEventListener('click', (event) => {
                event.stopPropagation();
                const menu = row.querySelector('.action-menu');
                if (menu.classList.contains('open')) {
                    closeMenus();
                } else {
                    const bounds = trigger.getBoundingClientRect();
                    openMenu(row, bounds.right - 180, bounds.bottom);
                }
            });
        }
        row.addEventListener('contextmenu', (event) => {
            if (event.target.closest('a, button, input, select')) return;
            event.preventDefault();
            openMenu(row, event.clientX, event.clientY);
        });
        row.querySelectorAll('[data-confirm]').forEach((link) => {
            link.addEventListener('click', (event) => {
                if (!confirm(link.dataset.confirm)) event.preventDefault();
            });
        });
    });

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.row-actions')) closeMenus();
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') closeMenus();
    });

    const dialog = document.getElementById('record-dialog');
    const openDialogButton = document.getElementById('open-record-dialog');
    if (dialog) {
        if (dialog.open && typeof dialog.showModal === 'function') {
            dialog.close();
            dialog.showModal();
        }
        if (openDialogButton) openDialogButton.addEventListener('click', () => dialog.showModal());
        dialog.querySelectorAll('.dialog-close').forEach((button) => {
            button.addEventListener('click', () => dialog.close());
        });
        dialog.addEventListener('click', (event) => {
            if (event.target === dialog) dialog.close();
        });
    }
})();
