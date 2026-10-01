<?php

declare(strict_types=1);

namespace EidCloud\HeadlessAdmin\Ui;

use EidCloud\HeadlessAdmin\Metadata\SchemaRegistry;

/**
 * Embedded micro-admin UI generator with zero external assets/frameworks.
 * Clean, modern, responsive glassmorphism UI with live API testing.
 */
class MicroAdminUi
{
    public static function render(SchemaRegistry $registry, string $apiPrefix = '/api'): string
    {
        $modules = $registry->getModules();
        $moduleList = array_values(array_map(fn($m) => $m->toArray(), $modules));
        $modulesJson = htmlspecialchars(json_encode($moduleList, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EidCloud Headless Admin</title>
    <style>
        :root {
            --bg: #090d16;
            --surface: #131b2e;
            --surface-hover: #1c2640;
            --border: #23314f;
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --primary: #3b82f6;
            --primary-glow: rgba(59, 130, 246, 0.25);
            --accent: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background-color: var(--bg);
            color: var(--text-main);
            font-family: var(--font-family);
            display: flex;
            height: 100vh;
            overflow: hidden;
        }
        aside {
            width: 280px;
            background: var(--surface);
            border-right: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            padding: 24px 16px;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 1.15rem;
            font-weight: 700;
            color: #fff;
            margin-bottom: 28px;
        }
        .brand span {
            background: linear-gradient(135deg, #60a5fa, #a78bfa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .nav-title {
            text-transform: uppercase;
            font-size: 0.75rem;
            font-weight: 600;
            letter-spacing: 0.08em;
            color: var(--text-muted);
            margin-bottom: 12px;
            padding-left: 8px;
        }
        .nav-list { list-style: none; display: flex; flex-direction: column; gap: 4px; }
        .nav-item {
            padding: 10px 12px;
            border-radius: 8px;
            cursor: pointer;
            font-size: 0.9rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--text-muted);
            transition: all 0.2s ease;
        }
        .nav-item:hover, .nav-item.active {
            background: var(--surface-hover);
            color: #fff;
        }
        .nav-item.active {
            border-left: 3px solid var(--primary);
            font-weight: 600;
        }
        .badge {
            font-size: 0.7rem;
            background: var(--border);
            padding: 2px 6px;
            border-radius: 4px;
            color: var(--text-muted);
        }
        main {
            flex: 1;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            background: radial-gradient(circle at 50% 0%, #151d33 0%, var(--bg) 70%);
        }
        header {
            height: 64px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0 32px;
            background: rgba(19, 27, 46, 0.7);
            backdrop-filter: blur(10px);
        }
        .header-title {
            font-size: 1.25rem;
            font-weight: 600;
        }
        .header-controls {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .role-badge {
            font-size: 0.8rem;
            background: rgba(59, 130, 246, 0.15);
            border: 1px solid var(--primary);
            color: #93c5fd;
            padding: 4px 10px;
            border-radius: 12px;
            font-weight: 500;
        }
        .content {
            flex: 1;
            padding: 32px;
            overflow-y: auto;
            display: flex;
            flex-direction: column;
            gap: 20px;
        }
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        .action-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            gap: 16px;
        }
        .search-input {
            background: var(--bg);
            border: 1px solid var(--border);
            padding: 8px 14px;
            border-radius: 6px;
            color: #fff;
            font-size: 0.875rem;
            width: 280px;
        }
        .btn {
            background: var(--primary);
            color: #fff;
            border: none;
            padding: 8px 16px;
            border-radius: 6px;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            transition: opacity 0.2s;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn:hover { opacity: 0.9; }
        .btn-outline {
            background: transparent;
            border: 1px solid var(--border);
            color: var(--text-muted);
        }
        .btn-outline:hover {
            color: #fff;
            border-color: var(--text-muted);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.875rem;
            text-align: left;
        }
        th {
            background: rgba(255,255,255,0.02);
            color: var(--text-muted);
            font-weight: 600;
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
        }
        td {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
            color: var(--text-main);
        }
        tr:hover td {
            background: rgba(255,255,255,0.015);
        }
        .endpoints-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 16px;
            margin-top: 20px;
        }
        .ep-card {
            background: #0f1627;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .ep-method {
            font-weight: 700;
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 4px;
            width: 60px;
            text-align: center;
        }
        .get { background: rgba(59, 130, 246, 0.2); color: #60a5fa; }
        .post { background: rgba(16, 185, 129, 0.2); color: #34d399; }
        .put { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
        .delete { background: rgba(239, 68, 68, 0.2); color: #f87171; }
        .ep-path { font-family: monospace; font-size: 0.85rem; color: #cbd5e1; }
        pre {
            background: #0b0f19;
            padding: 14px;
            border-radius: 8px;
            overflow-x: auto;
            font-size: 0.8rem;
            color: #a5b4fc;
            border: 1px solid var(--border);
        }
    </style>
</head>
<body>
    <aside>
        <div class="brand">
            🎛️ <span>EidCloud Admin</span>
        </div>
        <div class="nav-title">Modules</div>
        <ul class="nav-list" id="moduleNav"></ul>
    </aside>
    <main>
        <header>
            <div class="header-title" id="moduleTitle">Dashboard</div>
            <div class="header-controls">
                <span class="role-badge">Role: admin</span>
                <a id="exportBtn" href="#" class="btn btn-outline" target="_blank">Export CSV</a>
            </div>
        </header>
        <div class="content" id="mainContent">
            <div class="card">
                <div class="action-bar">
                    <input type="text" id="searchInput" class="search-input" placeholder="Search records...">
                    <button class="btn" id="refreshBtn">Refresh Data</button>
                </div>
                <div style="overflow-x: auto;">
                    <table id="dataTable">
                        <thead id="tableHead"></thead>
                        <tbody id="tableBody"></tbody>
                    </table>
                </div>
            </div>

            <div class="card">
                <h3 style="font-size: 1rem; margin-bottom: 12px; color: #fff;">Instant RESTful Endpoints</h3>
                <div class="endpoints-grid" id="endpointsGrid"></div>
            </div>

            <div class="card">
                <h3 style="font-size: 1rem; margin-bottom: 12px; color: #fff;">Schema Definition</h3>
                <pre id="schemaJson"></pre>
            </div>
        </div>
    </main>

    <script>
        const modules = $modulesJson;
        const apiPrefix = '$apiPrefix';
        let currentModule = modules[0] || null;

        const navEl = document.getElementById('moduleNav');
        const titleEl = document.getElementById('moduleTitle');
        const theadEl = document.getElementById('tableHead');
        const tbodyEl = document.getElementById('tableBody');
        const endpointsEl = document.getElementById('endpointsGrid');
        const schemaJsonEl = document.getElementById('schemaJson');
        const exportBtnEl = document.getElementById('exportBtn');
        const searchInput = document.getElementById('searchInput');

        function renderNav() {
            navEl.innerHTML = '';
            modules.forEach(m => {
                const li = document.createElement('li');
                li.className = 'nav-item ' + (currentModule && currentModule.id === m.id ? 'active' : '');
                li.innerHTML = `<span>\${m.title}</span><span class="badge">\${Object.keys(m.fields).length} fields</span>`;
                li.onclick = () => selectModule(m);
                navEl.appendChild(li);
            });
        }

        function selectModule(m) {
            currentModule = m;
            renderNav();
            renderModule();
        }

        function renderModule() {
            if (!currentModule) {
                titleEl.textContent = 'No Modules Registered';
                return;
            }

            titleEl.textContent = currentModule.title;
            exportBtnEl.href = `\${apiPrefix}/\${currentModule.id}/export?role=admin`;
            schemaJsonEl.textContent = JSON.stringify(currentModule, null, 2);

            // Render Endpoints
            endpointsEl.innerHTML = `
                <div class="ep-card"><span class="ep-method get">GET</span><span class="ep-path">\${apiPrefix}/\${currentModule.id}</span></div>
                <div class="ep-card"><span class="ep-method get">GET</span><span class="ep-path">\${apiPrefix}/\${currentModule.id}/{id}</span></div>
                <div class="ep-card"><span class="ep-method post">POST</span><span class="ep-path">\${apiPrefix}/\${currentModule.id}</span></div>
                <div class="ep-card"><span class="ep-method put">PUT</span><span class="ep-path">\${apiPrefix}/\${currentModule.id}/{id}</span></div>
                <div class="ep-card"><span class="ep-method delete">DELETE</span><span class="ep-path">\${apiPrefix}/\${currentModule.id}/{id}</span></div>
                <div class="ep-card"><span class="ep-method get">GET</span><span class="ep-path">\${apiPrefix}/\${currentModule.id}/export</span></div>
            `;

            fetchRecords();
        }

        async function fetchRecords() {
            if (!currentModule) return;
            const search = searchInput.value.trim();
            const url = `\${apiPrefix}/\${currentModule.id}?role=admin` + (search ? `&search=\${encodeURIComponent(search)}` : '');
            
            try {
                const res = await fetch(url);
                const json = await res.json();
                renderTable(json.data || []);
            } catch (err) {
                console.error(err);
            }
        }

        function renderTable(rows) {
            const fields = Object.keys(currentModule.fields);
            
            // Header
            theadEl.innerHTML = '<tr>' + fields.map(f => `<th>\${currentModule.fields[f].label || f}</th>`).join('') + '</tr>';
            
            // Rows
            if (!rows || rows.length === 0) {
                tbodyEl.innerHTML = `<tr><td colspan="\${fields.length}" style="text-align:center; color:#64748b; padding: 24px;">No records found.</td></tr>`;
                return;
            }

            tbodyEl.innerHTML = rows.map(r => {
                return '<tr>' + fields.map(f => `<td>\${r[f] !== undefined && r[f] !== null ? r[f] : '<em style="color:#475569">null</em>'}</td>`).join('') + '</tr>';
            }).join('');
        }

        document.getElementById('refreshBtn').addEventListener('click', fetchRecords);
        searchInput.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') fetchRecords();
        });

        renderNav();
        renderModule();
    </script>
</body>
</html>
HTML;
    }
}
