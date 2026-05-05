(function() {
    'use strict';

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function normalizeFilename(filename, extension) {
        const base = String(filename || 'financial_export')
            .replace(/\.[^.]+$/, '')
            .replace(/[^a-z0-9_-]+/gi, '_')
            .replace(/^_+|_+$/g, '') || 'financial_export';
        return `${base}.${extension}`;
    }

    function formatCell(value, type) {
        if (type === 'currency') {
            const amount = Number(value || 0);
            return `PHP ${amount.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        }
        if (type === 'number') {
            const amount = Number(value || 0);
            return amount.toLocaleString();
        }
        return value ?? '';
    }

    function buildWorkbookHtml(options) {
        const title = options.title || 'Financial Export';
        const subtitle = options.subtitle || '';
        const generatedAt = new Date().toLocaleString();
        const sections = options.sections || [];

        let body = `
            <div class="report-header">
                <div class="eyebrow">ATIERA Financial Management System</div>
                <h1>${escapeHtml(title)}</h1>
                ${subtitle ? `<p>${escapeHtml(subtitle)}</p>` : ''}
                <p class="generated">Generated ${escapeHtml(generatedAt)}</p>
            </div>
        `;

        sections.forEach(section => {
            body += `<section><h2>${escapeHtml(section.title || 'Details')}</h2>`;
            if (section.summary && section.summary.length) {
                body += '<div class="summary-grid">';
                section.summary.forEach(item => {
                    body += `
                        <div class="summary-card">
                            <span>${escapeHtml(item.label)}</span>
                            <strong>${escapeHtml(formatCell(item.value, item.type))}</strong>
                        </div>
                    `;
                });
                body += '</div>';
            }

            const columns = section.columns || [];
            const rows = section.rows || [];
            if (columns.length) {
                body += '<table><thead><tr>';
                columns.forEach(column => {
                    body += `<th>${escapeHtml(column.label || column.key || '')}</th>`;
                });
                body += '</tr></thead><tbody>';

                rows.forEach(row => {
                    const rowClass = row && row.__total ? ' class="total-row"' : '';
                    body += `<tr${rowClass}>`;
                    columns.forEach(column => {
                        const rawValue = typeof column.value === 'function' ? column.value(row) : row?.[column.key];
                        const align = column.type === 'currency' || column.type === 'number' ? ' class="num"' : '';
                        body += `<td${align}>${escapeHtml(formatCell(rawValue, column.type))}</td>`;
                    });
                    body += '</tr>';
                });

                if (!rows.length) {
                    body += `<tr><td colspan="${columns.length}" class="empty">No records available</td></tr>`;
                }
                body += '</tbody></table>';
            }
            body += '</section>';
        });

        return `<!doctype html>
<html>
<head>
<meta charset="utf-8">
<style>
body { font-family: Arial, Helvetica, sans-serif; color: #1f2937; margin: 24px; }
.report-header { border-bottom: 3px solid #1f4e79; padding-bottom: 14px; margin-bottom: 22px; }
.eyebrow { color: #64748b; font-size: 11px; text-transform: uppercase; letter-spacing: .08em; }
h1 { color: #1f4e79; font-size: 24px; margin: 6px 0; }
h2 { color: #1f4e79; font-size: 16px; margin: 24px 0 10px; }
p { margin: 3px 0; }
.generated { color: #64748b; font-size: 12px; }
.summary-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin: 12px 0 16px; }
.summary-card { border: 1px solid #d7dee8; background: #f8fafc; padding: 10px; }
.summary-card span { display: block; color: #64748b; font-size: 11px; text-transform: uppercase; }
.summary-card strong { display: block; margin-top: 4px; font-size: 16px; }
table { border-collapse: collapse; width: 100%; margin-bottom: 18px; }
th { background: #1f4e79; color: #ffffff; padding: 8px; border: 1px solid #1f4e79; text-align: left; }
td { padding: 7px 8px; border: 1px solid #d7dee8; vertical-align: top; }
tbody tr:nth-child(even) td { background: #f8fafc; }
.num { text-align: right; white-space: nowrap; }
.total-row td { background: #e8f0f7 !important; font-weight: bold; }
.empty { text-align: center; color: #64748b; }
</style>
</head>
<body>${body}</body>
</html>`;
    }

    function downloadWorkbook(options) {
        const html = buildWorkbookHtml(options || {});
        const blob = new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        link.href = url;
        link.download = normalizeFilename(options.filename, 'xls');
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
        URL.revokeObjectURL(url);
    }

    function parseCsvLine(line) {
        const cells = [];
        let current = '';
        let quoted = false;
        for (let i = 0; i < line.length; i++) {
            const char = line[i];
            const next = line[i + 1];
            if (char === '"' && quoted && next === '"') {
                current += '"';
                i++;
            } else if (char === '"') {
                quoted = !quoted;
            } else if (char === ',' && !quoted) {
                cells.push(current);
                current = '';
            } else {
                current += char;
            }
        }
        cells.push(current);
        return cells;
    }

    function downloadTextReport(text, filename, title) {
        const cleaned = String(text || '').replace(/^data:text\/csv;charset=utf-8,/, '');
        const lines = cleaned.split(/\r?\n/);
        const sections = [];
        let reportTitle = title || 'Financial Report';
        let subtitle = '';
        let currentSection = null;

        lines.forEach(rawLine => {
            const line = rawLine.trim();
            if (!line) {
                return;
            }

            const cells = parseCsvLine(line);
            if (cells.length === 1) {
                if (!sections.length && !currentSection && reportTitle === 'Financial Report') {
                    reportTitle = cells[0];
                    return;
                }
                if (/^(period|as of):/i.test(cells[0])) {
                    subtitle = cells[0];
                    return;
                }
                currentSection = { title: cells[0], columns: [], rows: [] };
                sections.push(currentSection);
                return;
            }

            if (!currentSection) {
                currentSection = { title: 'Details', columns: [], rows: [] };
                sections.push(currentSection);
            }

            if (!currentSection.columns.length) {
                currentSection.columns = cells.map((cell, index) => ({
                    key: `c${index}`,
                    label: cell,
                    type: /amount|balance|total|profit|cash|expense|revenue|asset|liabilit|equity/i.test(cell) ? 'currency' : 'text'
                }));
                return;
            }

            const row = {};
            currentSection.columns.forEach((column, index) => {
                row[column.key] = cells[index] ?? '';
            });
            if (/^total|^net|liabilities & equity/i.test(String(cells[0] || ''))) {
                row.__total = true;
            }
            currentSection.rows.push(row);
        });

        downloadWorkbook({
            title: reportTitle,
            subtitle,
            filename,
            sections
        });
    }

    window.DocumentExporter = {
        downloadWorkbook,
        buildWorkbookHtml,
        downloadTextReport
    };
})();
