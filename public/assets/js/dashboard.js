const STATUS_SLUGS = ['open', 'in_progress', 'resolved', 'closed'];
const PRIORITY_SLUGS = ['low', 'medium', 'high'];

const STATUS_LABELS = {
    open: 'Aberto',
    in_progress: 'Em andamento',
    resolved: 'Resolvido',
    closed: 'Fechado',
};

const PRIORITY_LABELS = {
    low: 'Baixa',
    medium: 'Média',
    high: 'Alta',
};

const STATUS_COLORS = ['#f97316', '#2563eb', '#22c55e', '#475569'];
const PRIORITY_COLORS = ['#0ea5e9', '#6366f1', '#ef4444'];

const state = {
    statusChart: null,
    priorityChart: null,
    activeStatus: null,
    activePriority: null,
};

const ensureChartDefaults = () => {
    if (typeof Chart === 'undefined') return;
    Chart.defaults.font.family = "'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif";
    Chart.defaults.font.size = 13;
    Chart.defaults.color = '#0f172a';
    Chart.defaults.plugins.tooltip.backgroundColor = '#0f172a';
    Chart.defaults.plugins.tooltip.borderRadius = 12;
};

const initStatusPieChart = () => {
    const canvas = document.getElementById('statusPieChart');
    const panel = document.querySelector('.panel--charts');
    if (!canvas || !panel || typeof Chart === 'undefined') return;

    const data = [
        Number(panel.dataset.statusOpen) || 0,
        Number(panel.dataset.statusInProgress) || 0,
        Number(panel.dataset.statusResolved) || 0,
        Number(panel.dataset.statusClosed) || 0,
    ];

    state.statusChart = new Chart(canvas.getContext('2d'), {
        type: 'pie',
        data: {
            labels: STATUS_SLUGS.map((slug) => STATUS_LABELS[slug]),
            datasets: [
                {
                    data,
                    backgroundColor: STATUS_COLORS,
                    hoverOffset: 12,
                },
            ],
        },
        options: {
            plugins: {
                legend: { display: false },
            },
        },
    });

    canvas.addEventListener('click', (event) => {
        if (!state.statusChart) return;
        const points = state.statusChart.getElementsAtEventForMode(event, 'nearest', { intersect: true }, true);
        if (!points.length) return;
        const index = points[0].index;
        toggleStatusFilter(STATUS_SLUGS[index]);
    });
};

const initPriorityPieChart = () => {
    const canvas = document.getElementById('priorityPieChart');
    const panel = document.querySelector('.panel--charts');
    if (!canvas || !panel || typeof Chart === 'undefined') return;

    const data = [
        Number(panel.dataset.priorityLow) || 0,
        Number(panel.dataset.priorityMedium) || 0,
        Number(panel.dataset.priorityHigh) || 0,
    ];

    state.priorityChart = new Chart(canvas.getContext('2d'), {
        type: 'pie',
        data: {
            labels: PRIORITY_SLUGS.map((slug) => PRIORITY_LABELS[slug]),
            datasets: [
                {
                    data,
                    backgroundColor: PRIORITY_COLORS,
                    hoverOffset: 12,
                },
            ],
        },
        options: {
            plugins: {
                legend: { display: false },
            },
        },
    });

    canvas.addEventListener('click', (event) => {
        if (!state.priorityChart) return;
        const points = state.priorityChart.getElementsAtEventForMode(event, 'nearest', { intersect: true }, true);
        if (!points.length) return;
        const index = points[0].index;
        togglePriorityFilter(PRIORITY_SLUGS[index]);
    });
};

const toggleStatusFilter = (slug) => {
    const nextSlug = state.activeStatus === slug ? null : slug;
    state.activeStatus = nextSlug;
    setActiveSlice(state.statusChart, nextSlug, STATUS_SLUGS);
    applyTableFilters();
};

const togglePriorityFilter = (slug) => {
    const nextSlug = state.activePriority === slug ? null : slug;
    state.activePriority = nextSlug;
    setActiveSlice(state.priorityChart, nextSlug, PRIORITY_SLUGS);
    applyTableFilters();
};

const setActiveSlice = (chart, slug, slugMap) => {
    if (!chart) return;
    if (!slug) {
        chart.setActiveElements([]);
        chart.update();
        return;
    }
    const index = slugMap.indexOf(slug);
    if (index === -1) return;
    chart.setActiveElements([{ datasetIndex: 0, index }]);
    chart.update();
};

const applyTableFilters = () => {
    const table = document.getElementById('operationalTable');
    const filterInput = document.getElementById('ticketFilter');
    if (!table) return;
    const rows = table.querySelectorAll('tbody tr');
    const searchTerm = filterInput ? filterInput.value.toLowerCase().trim() : '';

    rows.forEach((row) => {
        // Ignora linhas de mensagem vazia
        if (row.textContent.includes('Nenhum registro') || row.textContent.includes('Nada fechado')) {
            row.style.display = 'none';
            return;
        }
        
        const rowStatus = row.dataset.status;
        const rowPriority = row.dataset.priority;
        const text = row.textContent.toLowerCase();
        
        // Busca pelo número do chamado (primeira célula)
        const firstCell = row.querySelector('td:first-child');
        const ticketNumber = firstCell ? firstCell.textContent.toLowerCase().trim() : '';
        
        // Verifica se o termo de busca corresponde ao número do chamado ou ao texto completo
        const cleanSearchTerm = searchTerm.replace('#', '').trim();
        let matchesSearch = true;
        
        if (searchTerm) {
            matchesSearch = ticketNumber.includes(cleanSearchTerm) || text.includes(searchTerm);
        }
        
        const matchesStatus = !state.activeStatus || rowStatus === state.activeStatus;
        const matchesPriority = !state.activePriority || rowPriority === state.activePriority;
        row.style.display = matchesSearch && matchesStatus && matchesPriority ? '' : 'none';
    });

    updateHint(
        'statusFilterHint',
        state.activeStatus,
        STATUS_LABELS,
        'Clique em um segmento para filtrar por status.',
    );
    updateHint(
        'priorityFilterHint',
        state.activePriority,
        PRIORITY_LABELS,
        'Clique em um segmento para filtrar por prioridade.',
    );

    renderStatusLegend();
    renderPriorityLegend();
};

const updateHint = (elementId, slug, labelMap, defaultMessage) => {
    const element = document.getElementById(elementId);
    if (!element) return;
    if (!slug) {
        element.textContent = defaultMessage;
        return;
    }
    const label = labelMap[slug] ?? slug;
    // Usar textContent para prevenir XSS, mas criar strong manualmente se necessário
    const strong = document.createElement('strong');
    strong.textContent = label;
    element.textContent = 'Filtro aplicado: ';
    element.appendChild(strong);
    element.appendChild(document.createTextNode('. Clique novamente para limpar.'));
};

const renderLegend = (containerId, slugList, colors, labelMap, activeSlug, handler) => {
    const container = document.getElementById(containerId);
    if (!container) return;
    container.innerHTML = '';
    slugList.forEach((slug, index) => {
        const item = document.createElement('button');
        item.type = 'button';
        item.className = `chart-legend__item${activeSlug === slug ? ' is-active' : ''}`;
        
        // Criar elementos de forma segura para prevenir XSS
        const dot = document.createElement('span');
        dot.className = 'chart-legend__dot';
        dot.style.background = colors[index];
        
        const labelText = document.createTextNode(labelMap[slug] ?? slug);
        item.appendChild(dot);
        item.appendChild(labelText);
        
        item.addEventListener('click', () => handler(slug));
        container.appendChild(item);
    });
};

const renderStatusLegend = () => {
    renderLegend('statusLegend', STATUS_SLUGS, STATUS_COLORS, STATUS_LABELS, state.activeStatus, toggleStatusFilter);
};

const renderPriorityLegend = () => {
    renderLegend(
        'priorityLegend',
        PRIORITY_SLUGS,
        PRIORITY_COLORS,
        PRIORITY_LABELS,
        state.activePriority,
        togglePriorityFilter,
    );
};

const exportTableToExcel = (table, filename) => {
    if (!table) return;
    const rows = Array.from(table.querySelectorAll('tr'));
    const csv = rows
        .map((row) =>
            Array.from(row.querySelectorAll('th, td'))
                .map((cell) => `"${cell.textContent.trim().replace(/"/g, '""')}"`)
                .join(';'),
        )
        .join('\n');

    const blob = new Blob(['\ufeff' + csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `${filename}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
};

let exportLibsPromise = null;

const loadScriptOnce = (src) =>
    new Promise((resolve, reject) => {
        if (document.querySelector(`script[src="${src}"]`)) {
            resolve();
            return;
        }
        const script = document.createElement('script');
        script.src = src;
        script.defer = true;
        script.onload = resolve;
        script.onerror = reject;
        document.head.appendChild(script);
    });

const loadScriptSequential = async (sources) => {
    for (const src of sources) {
        try {
            await loadScriptOnce(src);
            return true;
        } catch (_) {
            // tenta próximo
        }
    }
    return false;
};

const ensureExportLibs = async () => {
    if (window.jspdf && window.html2canvas) return true;
    if (!exportLibsPromise) {
        const jspdfSources = [
            '/assets/js/vendor/jspdf.umd.min.js',
            'https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js',
            'https://cdn.jsdelivr.net/npm/jspdf@2.5.1/dist/jspdf.umd.min.js',
            'https://unpkg.com/jspdf@2.5.1/dist/jspdf.umd.min.js',
        ];
        const html2canvasSources = [
            '/assets/js/vendor/html2canvas.min.js',
            'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js',
            'https://cdn.jsdelivr.net/npm/html2canvas@1.4.1/dist/html2canvas.min.js',
            'https://unpkg.com/html2canvas@1.4.1/dist/html2canvas.min.js',
        ];

        exportLibsPromise = (async () => {
            const okPdf = await loadScriptSequential(jspdfSources);
            const okCanvas = await loadScriptSequential(html2canvasSources);
            return okPdf && okCanvas;
        })();
    }
    const loaded = await exportLibsPromise;
    return loaded !== false && window.jspdf && window.html2canvas;
};

const exportTableToPDF = async (table, filename) => {
    if (!table) {
        alert('Tabela não encontrada para exportação.');
        return;
    }

    const ready = await ensureExportLibs();
    if (!ready) {
        alert('Bibliotecas de exportação não foram carregadas.');
        return;
    }

    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ orientation: 'landscape' });
    const canvas = await window.html2canvas(table, { scale: 2 });
    const imgData = canvas.toDataURL('image/png');
    const pageWidth = doc.internal.pageSize.getWidth() - 20;
    const imgHeight = (canvas.height * pageWidth) / canvas.width;

    doc.addImage(imgData, 'PNG', 10, 10, pageWidth, imgHeight);
    doc.save(`${filename}.pdf`);
};

const runReportExport = (type) => {
    const mainTable = document.getElementById('operationalTable');
    const closedTable = document.getElementById('closedTable');

    let targetTable = mainTable;
    let filename = 'tdesk_relatorio';

    if (type?.includes('closed')) {
        targetTable = closedTable;
        filename = 'tdesk_fechados';
    }

    if (!targetTable) {
        alert('Tabela não encontrada para exportação.');
        return;
    }

    if (type?.startsWith('excel')) {
        exportTableToExcel(targetTable, filename);
    } else {
        exportTableToPDF(targetTable, filename);
    }
};

const initReportMenu = () => {
    const trigger = document.querySelector('.menu-trigger');
    const menu = document.getElementById('reportMenu');
    if (!trigger || !menu) return;

    trigger.addEventListener('click', (event) => {
        event.stopPropagation();
        menu.classList.toggle('is-open');
    });

    document.addEventListener('click', (event) => {
        if (!menu.contains(event.target) && !trigger.contains(event.target)) {
            menu.classList.remove('is-open');
        }
    });

    menu.querySelectorAll('[data-report]').forEach((button) => {
        button.addEventListener('click', () => {
            runReportExport(button.dataset.report);
            menu.classList.remove('is-open');
        });
    });
};

const initScrollShortcut = () => {
    const button = document.getElementById('openTicketButton');
    const section = document.getElementById('quick-actions');
    if (!button || !section) return;

    button.addEventListener('click', () => {
        if (section.tagName === 'DETAILS' && !section.open) {
            section.open = true;
        }
        section.scrollIntoView({ behavior: 'smooth' });
    });
};

const applyClosedTableFilter = () => {
    const table = document.getElementById('closedTable');
    const filterInput = document.getElementById('closedTicketFilter');
    if (!table) return;
    const rows = table.querySelectorAll('tbody tr');
    const searchTerm = filterInput ? filterInput.value.toLowerCase().trim() : '';

    rows.forEach((row) => {
        // Ignora linhas de mensagem vazia
        if (row.textContent.includes('Nada fechado') || row.textContent.includes('Nenhum registro')) {
            row.style.display = 'none';
            return;
        }
        
        const text = row.textContent.toLowerCase();
        
        // Busca pelo número do chamado (primeira célula)
        const firstCell = row.querySelector('td:first-child');
        const ticketNumber = firstCell ? firstCell.textContent.toLowerCase().trim() : '';
        
        // Verifica se o termo de busca corresponde ao número do chamado ou ao texto completo
        const cleanSearchTerm = searchTerm.replace('#', '').trim();
        let matchesSearch = true;
        
        if (searchTerm) {
            matchesSearch = ticketNumber.includes(cleanSearchTerm) || text.includes(searchTerm);
        }
        
        row.style.display = matchesSearch ? '' : 'none';
    });
};

const initTicketFilter = () => {
    const filterInput = document.getElementById('ticketFilter');
    if (!filterInput) return;

    const handleFilter = () => {
        applyTableFilters();
    };

    filterInput.addEventListener('input', handleFilter);
    filterInput.addEventListener('keyup', handleFilter);
    filterInput.addEventListener('paste', () => {
        setTimeout(handleFilter, 10);
    });
};

const initClosedTicketFilter = () => {
    const filterInput = document.getElementById('closedTicketFilter');
    if (!filterInput) return;

    const handleFilter = () => {
        applyClosedTableFilter();
    };

    filterInput.addEventListener('input', handleFilter);
    filterInput.addEventListener('keyup', handleFilter);
    filterInput.addEventListener('paste', () => {
        setTimeout(handleFilter, 10);
    });
};

const initReportActions = () => {
    document.querySelectorAll('[data-report-action]').forEach((button) => {
        button.addEventListener('click', () => {
            runReportExport(button.dataset.reportAction);
        });
    });
};

const initSidebarNavigation = () => {
    const sidebar = document.getElementById('appSidebar');
    const toggle = document.getElementById('sidebarToggle');
    const overlay = document.getElementById('sidebarOverlay');
    const shell = document.querySelector('.dashboard-shell');
    if (!sidebar || !toggle || !shell) return;

    const close = () => {
        sidebar.classList.remove('is-open');
        shell.classList.remove('sidebar-open');
        toggle.setAttribute('aria-expanded', 'false');
        overlay?.classList.remove('is-active');
    };

    const open = () => {
        sidebar.classList.add('is-open');
        shell.classList.add('sidebar-open');
        toggle.setAttribute('aria-expanded', 'true');
        overlay?.classList.add('is-active');
    };

    const toggleSidebar = () => {
        if (sidebar.classList.contains('is-open')) {
            close();
        } else {
            open();
        }
    };

    toggle.addEventListener('click', toggleSidebar);
    toggle.addEventListener('keyup', (event) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            toggleSidebar();
        }
    });

    overlay?.addEventListener('click', close);

    sidebar.querySelectorAll('.sidebar-link').forEach((link) => {
        link.addEventListener('click', () => {
            close();
        });
    });
};

document.addEventListener('DOMContentLoaded', () => {
    ensureChartDefaults();
    initStatusPieChart();
    initPriorityPieChart();
    applyTableFilters();
    initSidebarNavigation();
    initReportMenu();
    initReportActions();
    initScrollShortcut();
    initTicketFilter();
    initClosedTicketFilter();
});

