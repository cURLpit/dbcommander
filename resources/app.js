$(function(){

  // ── API CLIENT ─────────────────────────────────────────────
  const API_BASE = '/api';

  // Active panel connection name – sent as X-Connection header with every API call
  // If null, the server uses the default connection
  let panelConnection = { left: null, right: null };
  let activeApiSide   = 'left'; // which panel the current API call is made on behalf of

  function apiFetch(url, options = {}) {
    const conn = panelConnection[activeApiSide];
    const headers = { ...(options.headers || {}) };
    if (conn) headers['X-Connection'] = conn;
    return fetch(url, { ...options, headers });
  }

  const Api = {
    async getConnections() {
      const r = await fetch(`${API_BASE}/connections`);
      if (!r.ok) throw new Error(`HTTP ${r.status}`);
      return r.json();
    },
    async getDatabases() {
      const r = await apiFetch(`${API_BASE}/databases`);
      if (!r.ok) throw new Error(`HTTP ${r.status}`);
      return r.json();
    },
    async getTables(db) {
      const r = await apiFetch(`${API_BASE}/databases/${encodeURIComponent(db)}/tables`);
      if (!r.ok) throw new Error(`HTTP ${r.status}`);
      return r.json();
    },
    async getRows(db, table, params = {}) {
      const qs = new URLSearchParams({ limit: 200, ...params }).toString();
      const r = await apiFetch(`${API_BASE}/tables/${encodeURIComponent(db)}/${encodeURIComponent(table)}/rows?${qs}`);
      if (!r.ok) throw new Error(`HTTP ${r.status}`);
      return r.json();
    },
    async getStructure(db, table) {
      const r = await apiFetch(`${API_BASE}/tables/${encodeURIComponent(db)}/${encodeURIComponent(table)}/structure`);
      if (!r.ok) throw new Error(`HTTP ${r.status}`);
      return r.json();
    },
    async dropTable(db, table, type = 'TABLE') {
      const r = await apiFetch(`${API_BASE}/tables/${encodeURIComponent(db)}/${encodeURIComponent(table)}?type=${type}`, {
        method: 'DELETE',
      });
      if (!r.ok) {
        const err = await r.json().catch(() => ({ error: `HTTP ${r.status}` }));
        throw new Error(err.error || `HTTP ${r.status}`);
      }
      return r.json();
    },
    async getAllRows(db, table, limit = 50000) {
      const qs = new URLSearchParams({ limit, offset: 0 }).toString();
      const r  = await apiFetch(`${API_BASE}/tables/${encodeURIComponent(db)}/${encodeURIComponent(table)}/rows?${qs}`);
      if (!r.ok) throw new Error(`HTTP ${r.status}`);
      return r.json();
    },
    async runSql(db, sql) {
      const r = await apiFetch(`${API_BASE}/sql`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ db, sql }),
      });
      if (!r.ok) {
        const err = await r.json().catch(() => ({ error: `HTTP ${r.status}` }));
        throw new Error(err.error || `HTTP ${r.status}`);
      }
      return r.json();
    },
    async updateRow(db, table, where, set) {
      const r = await apiFetch(`${API_BASE}/tables/${encodeURIComponent(db)}/${encodeURIComponent(table)}/rows`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ where, set }),
      });
      if (!r.ok) {
        const err = await r.json().catch(() => ({ error: `HTTP ${r.status}` }));
        throw new Error(err.error || `HTTP ${r.status}`);
      }
      return r.json();
    },
    async copyTable(srcDb, srcTable, tgtDb, tgtTable, srcConn, tgtConn, mode = 'append') {
      const headers = { 'Content-Type': 'application/json' };
      if (srcConn) headers['X-Connection']        = srcConn;
      if (tgtConn) headers['X-Connection-Target'] = tgtConn;
      const r = await fetch(`${API_BASE}/copy`, {
        method: 'POST',
        headers,
        body: JSON.stringify({
          source: { db: srcDb, table: srcTable },
          target: { db: tgtDb, table: tgtTable },
          mode,
        }),
      });
      if (!r.ok) {
        const err = await r.json().catch(() => ({ error: `HTTP ${r.status}` }));
        throw new Error(err.error || `HTTP ${r.status}`);
      }
      return r.json();
    },
    async copyDatabase(srcDb, tgtDb, srcConn, tgtConn, mode = 'append') {
      const headers = { 'Content-Type': 'application/json' };
      if (srcConn) headers['X-Connection']        = srcConn;
      if (tgtConn) headers['X-Connection-Target'] = tgtConn;
      const r = await fetch(`${API_BASE}/copy-database`, {
        method: 'POST',
        headers,
        body: JSON.stringify({
          source: { db: srcDb },
          target: { db: tgtDb },
          mode,
        }),
      });
      if (!r.ok) {
        const err = await r.json().catch(() => ({ error: `HTTP ${r.status}` }));
        throw new Error(err.error || `HTTP ${r.status}`);
      }
      return r.json();
    },
    async createTable(db, name) {
      const r = await apiFetch(`${API_BASE}/databases/${encodeURIComponent(db)}/tables`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name }),
      });
      if (!r.ok) {
        const err = await r.json().catch(() => ({ error: `HTTP ${r.status}` }));
        throw new Error(err.error || `HTTP ${r.status}`);
      }
      return r.json();
    },
    async createDatabase(name) {
      const r = await apiFetch(`${API_BASE}/databases`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ name }),
      });
      if (!r.ok) {
        const err = await r.json().catch(() => ({ error: `HTTP ${r.status}` }));
        throw new Error(err.error || `HTTP ${r.status}`);
      }
      return r.json();
    },
    async addColumn(db, table, column) {
      const r = await apiFetch(`${API_BASE}/tables/${encodeURIComponent(db)}/${encodeURIComponent(table)}/structure`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'add', column }),
      });
      if (!r.ok) {
        const err = await r.json().catch(() => ({ error: `HTTP ${r.status}` }));
        throw new Error(err.error || `HTTP ${r.status}`);
      }
      return r.json();
    },
    async dropColumn(db, table, columnName) {
      const r = await apiFetch(`${API_BASE}/tables/${encodeURIComponent(db)}/${encodeURIComponent(table)}/structure`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'drop', column: columnName }),
      });
      if (!r.ok) {
        const err = await r.json().catch(() => ({ error: `HTTP ${r.status}` }));
        throw new Error(err.error || `HTTP ${r.status}`);
      }
      return r.json();
    },
    async modifyColumn(db, table, column) {
      const r = await apiFetch(`${API_BASE}/tables/${encodeURIComponent(db)}/${encodeURIComponent(table)}/structure`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ column }),
      });
      if (!r.ok) {
        const err = await r.json().catch(() => ({ error: `HTTP ${r.status}` }));
        throw new Error(err.error || `HTTP ${r.status}`);
      }
      return r.json();
    },
  };

  // ── PANEL DATA MODEL ───────────────────────────────────────
  // Each panel tracks: connection, current db, current level
  // Levels: 'databases' | 'tables' | 'rows'
  const panelMeta = {
    left:  { db: null, table: null, level: 'databases', connection: null },
    right: { db: null, table: null, level: 'databases', connection: null },
  };

  // Structure cache: db.table → columns[]
  const structureCache = {};

  function setLoading(side, msg = 'Loading...') {
    $(`#list-${side}`).html(
      `<div style="color:var(--nc-cyan);padding:4px">${msg}</div>`
    );
  }

  function setError(side, msg) {
    $(`#list-${side}`).html(
      `<div style="color:var(--nc-red);padding:4px">✗ ${msg}</div>`
    );
  }

  async function loadDatabases(side) {
    setLoading(side);
    activeApiSide = side;
    try {
      const data = await Api.getDatabases();
      const items = [
       /* { name: '..', type: 'parent', rows: null, icon: '↑' },*/
        ...data.databases.map(d => ({
          name: d.name, type: 'db', rows: null, icon: '▶'
        }))
      ];
      panelMeta[side] = { db: null, table: null, level: 'databases', connection: panelConnection[side] };
      state.panels[side].data     = items;
      state.panels[side].cursor   = 0;
      state.panels[side].selected = new Set();
      updateBreadcrumb(side);
      renderAll();
    } catch(e) {
      setError(side, e.message);
    }
  }

  async function loadTables(side, db) {
    setLoading(side, `Loading ${db}...`);
    activeApiSide = side;
    try {
      const data = await Api.getTables(db);
      const iconMap = { TABLE: '▦', VIEW: '◈', PROCEDURE: 'λ', FUNCTION: 'λ', TRIGGER: '⚡' };
      const items = [
        { name: '..', type: 'parent', rows: null, icon: '↑' },
        ...data.tables.map(t => ({
          name:     t.name,
          type:     t.type === 'BASE TABLE' ? 'TABLE' : t.type,
          rows:     t.rows,
          icon:     iconMap[t.type] ?? '▦',
          engine:   t.engine,
          modified: t.modified ?? null,
        })),
        ...data.routines.map(r => ({
          name: r.name, type: r.type, rows: null, icon: 'λ', modified: r.modified ?? null,
        })),
        ...data.triggers.map(t => ({
          name: t.name, type: 'TRIGGER', rows: null, icon: '⚡', modified: null,
        })),
      ];
      panelMeta[side] = { db, table: null, level: 'tables', connection: panelConnection[side] };
      state.panels[side].data     = items;
      state.panels[side].cursor   = 1;
      state.panels[side].selected = new Set();
      state.panels[side].sortBy   = 'name';
      state.panels[side].sortDir  = 'asc';
      state.panels[side].filter   = '';
      $(`#filter-input-${side}`).val('');
      updateBreadcrumb(side);
      renderAll();
    } catch(e) {
      setError(side, e.message);
    }
  }

  async function loadStructureCache(db, table) {
    const key = `${db}.${table}`;
    if (structureCache[key]) return structureCache[key];
    try {
      const data = await Api.getStructure(db, table);
      structureCache[key] = data;
      return data;
    } catch(e) {
      return null;
    }
  }

  // ── STATE ──────────────────────────────────────────────────
  const state = {
    active: 'left',
    panels: {
      left:  { key: 'left',  cursor: 0, data: [], selected: new Set(), sortBy: 'name', sortDir: 'asc', filter: '' },
      right: { key: 'right', cursor: 0, data: [], selected: new Set(), sortBy: 'name', sortDir: 'asc', filter: '' },
    }
  };

  function formatModified(val) {
    if (!val) return '';
    // "2024-03-15 14:22:01" → "03-15 14:22"
    const m = String(val).match(/\d{4}-(\d{2}-\d{2}) (\d{2}:\d{2})/);
    return m ? `${m[1]} ${m[2]}` : String(val).slice(0, 16);
  }

  function sortedData(p, meta) {
    const parent = p.data.filter(i => i.type === 'parent');
    const rest   = p.data.filter(i => i.type !== 'parent');
    if (meta.level !== 'tables') return [...parent, ...rest]; // db szinten ne sortoljon

    const { sortBy, sortDir } = p;
    const sorted = [...rest].sort((a, b) => {
      let va = a[sortBy], vb = b[sortBy];
      if (va == null) va = sortBy === 'rows' ? -1 : '';
      if (vb == null) vb = sortBy === 'rows' ? -1 : '';
      if (sortBy === 'rows') { va = Number(va); vb = Number(vb); }
      const cmp = va < vb ? -1 : va > vb ? 1 : 0;
      return sortDir === 'asc' ? cmp : -cmp;
    });
    return [...parent, ...sorted];
  }

  // ── RENDER ─────────────────────────────────────────────────
  function renderPanel(side) {
    const p    = state.panels[side];
    const meta = panelMeta[side];
    const filterStr = p.filter.toLowerCase();
    let data = sortedData(p, meta);

    // Apply filter (parent entry always visible)
    if (filterStr && meta.level === 'tables') {
      data = data.filter(item => item.type === 'parent' || item.name.toLowerCase().includes(filterStr));
    }

    const $list = $(`#list-${side}`).empty();

    // Show filter bar
    const $fb = $(`#filter-${side}`);
    if (p.filter && meta.level === 'tables') {
      $fb.addClass('visible');
      const matchCount = data.filter(i => i.type !== 'parent').length;
      $(`#filter-count-${side}`).text(`${matchCount} match`);
    } else {
      $fb.removeClass('visible');
    }

    // Column header sort indicators
    const $hdr = $(`#colheader-${side}`);
    $hdr.find('[data-sort]').removeClass('sort-asc sort-desc');
    if (meta.level === 'tables') {
      $hdr.find(`[data-sort="${p.sortBy}"]`)
        .addClass(p.sortDir === 'asc' ? 'sort-asc' : 'sort-desc');
      $hdr.find('[data-sort="modified"]').show();
    } else {
      $hdr.find('[data-sort="modified"]').hide();
    }

    let visibleIdx = 0;
    data.forEach((item) => {
      const origIdx  = p.data.indexOf(item);
      const isSelected = p.selected.has(origIdx);
      const isCursor   = p.cursor === origIdx && side === state.active;

      let nameClass = '';
      if (item.type === 'db')       nameClass = 'item-dir';
      if (item.type === 'TABLE')     nameClass = 'item-table';
      if (item.type === 'VIEW')      nameClass = 'item-view';
      if (item.type === 'PROCEDURE') nameClass = 'item-proc';
      if (item.type === 'TRIGGER')   nameClass = 'item-trigger';
      if (item.type === 'parent')    nameClass = 'item-dir';

      const rowsStr = item.rows != null
        ? item.rows >= 1000000 ? (item.rows/1000000).toFixed(1)+'M'
          : item.rows >= 1000  ? (item.rows/1000).toFixed(1)+'k'
          : String(item.rows)
        : '';
      const typeStr = item.type === 'parent' ? '' : item.type;
      const dateStr = meta.level === 'tables' ? formatModified(item.modified) : '';

      // Highlight filter match
      let displayName = item.name;
      if (filterStr && item.type !== 'parent') {
        const idx = item.name.toLowerCase().indexOf(filterStr);
        if (idx >= 0) {
          displayName =
            item.name.slice(0, idx) +
            `<mark>${item.name.slice(idx, idx + filterStr.length)}</mark>` +
            item.name.slice(idx + filterStr.length);
        }
      }

      const zebraClass = item.type === 'parent' ? '' : (visibleIdx % 2 === 0 ? 'rv-even' : 'rv-odd');
      if (item.type !== 'parent') visibleIdx++;

      const $row = $('<div class="panel-item">')
        .toggleClass('cursor',   isCursor)
        .toggleClass('selected', isSelected)
        .addClass(zebraClass)
        .attr('data-idx', origIdx)
        .html(`
          <span class="item-icon">${item.icon||' '}</span>
          <span class="item-name ${nameClass}">${displayName}</span>
          <span class="item-type">${typeStr}</span>
          <span class="item-rows">${rowsStr}</span>
          <span class="item-date">${dateStr}</span>
        `);
      $list.append($row);
    });

    // scroll cursor into view
    const $cursor = $list.find('.cursor');
    if ($cursor.length) {
      const top = $cursor.position()?.top || 0;
      const lh = $cursor.outerHeight();
      const lTop = $list.scrollTop();
      const lH = $list.height();
      if (top < 0) $list.scrollTop(lTop + top);
      else if (top + lh > lH) $list.scrollTop(lTop + top + lh - lH);
    }
  }


  // ── FKEY CONTEXT ──────────────────────────────────────────
  // Declarative fkey definitions per context.
  // Each entry: label string or null (= '──')
  const fkeyDefs = {
    databases: {
      3: null,
      4: null,
      5: 'Copy DB',
      6: null,
      7: 'Create',
      8: 'Drop',
    },
    tables: {
      3: 'View',
      4: 'Edit',
      5: 'Copy',
      6: 'Move',
      7: 'Create',
      8: 'Drop',
    },
  };

  // Keys that are always the same regardless of context
  const fkeyFixed = {
    1: 'Help',
    2: 'SQL Ed',
    9: 'Export',
    10: 'Quit',
  };

  function renderFkeyBar() {
    const meta    = panelMeta[state.active];
    const p       = state.panels[state.active];
    const item    = p.data[p.cursor];
    const isParent = item?.type === 'parent';
    const ctx     = fkeyDefs[meta.level] || {};

    for (let f = 1; f <= 10; f++) {
      const $btn = $(`#fkeybar [data-f="${f}"]`);
      if (!$btn.length) continue;

      let label;
      if (fkeyFixed[f] !== undefined) {
        label = fkeyFixed[f];
      } else if (isParent) {
        label = null;
      } else {
        label = ctx[f] !== undefined ? ctx[f] : null;
      }

      $btn.find('.fkey-label').text(label ?? '──');
      $btn.toggleClass('fkey-disabled', label === null);
    }
  }

  function isFkeyDisabled(f) {
    return $(`#fkeybar [data-f="${f}"]`).hasClass('fkey-disabled');
  }

  function renderAll() {
    renderPanel('left');
    renderPanel('right');
    updateStatus();
    updateActivePanel();
    updateQuickView();
    renderFkeyBar();
  }

  function updateActivePanel() {
    $('#panel-left').toggleClass('active',  state.active === 'left');
    $('#panel-right').toggleClass('active', state.active === 'right');
  }

  function updateStatus() {
    const p = state.panels[state.active];
    const item = p.data[p.cursor];
    const total = p.data.filter(x => x.type !== 'parent').length;
    const sel = p.selected.size;
    $('#status-left').text((item ? item.name : '') + '  |  ' + total + ' objects');
    $('#status-mid').text(sel ? sel + ' selected' : '');
  }

  function updateQuickView() {
    const p    = state.panels[state.active];
    const item = p.data[p.cursor];
    const meta = panelMeta[state.active];

    if (!item || item.type === 'parent' || !meta.db) {
      $('#quickview').removeClass('visible');
      return;
    }

    if (item.type === 'TABLE' || item.type === 'VIEW') {
      const key = `${meta.db}.${item.name}`;
      if (structureCache[key]) {
        renderQuickView(item.name, item, structureCache[key]);
      } else {
        loadStructureCache(meta.db, item.name);
        $('#quickview').removeClass('visible');
      }
    } else {
      $('#quickview').removeClass('visible');
    }
  }

  // ── KEYBOARD ───────────────────────────────────────────────
  $(document).on('keydown', function(e) {
    // If row viewer is open, its handler takes over (except in panel mode, where the other panel stays active)
    if ($('#rowviewer').hasClass('visible') && !$('#rowviewer').hasClass('panel-mode')) return;
    // If structure editor is open, its handler takes over
    if ($('#structedit').hasClass('visible') && !$('#structedit').hasClass('panel-mode')) return;
    // Ha SQL result viewer nyitva van
    if ($('#sqlresult').hasClass('visible')) {
      if (e.key === 'Escape' || e.key === 'F10') { e.preventDefault(); closeSqlResult(); }
      return;
    }
    // If a dialog is open, handle ESC only
    if ($('#dialog-overlay').hasClass('visible')) {
      if (e.key === 'Escape') closeDialog();
      return;
    }

    if ($('#cmdline-input').is(':focus')) {
      if (e.key === 'Escape') { $('#cmdline-input').val(''); $('#cmdline-input').blur(); return; }
      if (e.key === 'Enter') {
        const sql = $('#cmdline-input').val().trim();
        if (sql) {
          $('#cmdline-input').val('');
          $('#cmdline-input').blur();
          runSqlAndShow(sql);
        }
        return;
      }
      return;
    }

    // Quick filter – any letter or digit triggers it at table level
    const meta = panelMeta[state.active];
    if (meta.level === 'tables' && e.key.length === 1 && !e.ctrlKey && !e.altKey && !e.metaKey) {
      const p = state.panels[state.active];
      p.filter += e.key;
      $(`#filter-input-${state.active}`).val(p.filter);
      renderAll();
      return;
    }

    const p = state.panels[state.active];

    switch(e.key) {
      case 'ArrowUp':    e.preventDefault(); moveCursor(-1); break;
      case 'ArrowDown':  e.preventDefault(); moveCursor(+1); break;
      case 'PageUp':     e.preventDefault(); moveCursor(-10); break;
      case 'PageDown':   e.preventDefault(); moveCursor(+10); break;
      case 'Home':       e.preventDefault(); p.cursor = 0; renderAll(); break;
      case 'End':        e.preventDefault(); p.cursor = p.data.length-1; renderAll(); break;
      case 'Tab':        e.preventDefault(); switchPanel(); break;
      case 'Insert':     e.preventDefault(); toggleSelect(); break;
      case ' ':          e.preventDefault(); toggleSelect(); break;
      case 'Escape':
        // Clear filter if active
        if (state.panels[state.active].filter) {
          state.panels[state.active].filter = '';
          $(`#filter-input-${state.active}`).val('');
          renderAll();
          return;
        }
        break;
      case 'Enter':
        e.preventDefault();
        // If filter is active and only 1 match, navigate into it
        if (state.panels[state.active].filter) {
          const fp = state.panels[state.active];
          const fmeta = panelMeta[state.active];
          const matches = fp.data.filter(i => i.type !== 'parent' && i.name.toLowerCase().includes(fp.filter.toLowerCase()));
          if (matches.length === 1) {
            fp.cursor = fp.data.indexOf(matches[0]);
            fp.filter = '';
            $(`#filter-input-${state.active}`).val('');
            renderAll();
            enterItem();
          }
        } else {
          enterItem();
        }
        break;
      case 'Backspace':
        e.preventDefault();
        if (state.panels[state.active].filter) {
          const p = state.panels[state.active];
          p.filter = p.filter.slice(0, -1);
          $(`#filter-input-${state.active}`).val(p.filter);
          renderAll();
        } else {
          goUp();
        }
        break;
      case 'F1':         e.preventDefault(); showHelp(); break;
      case 'F2':         e.preventDefault(); doSqlEditor(); break;
      case 'F3':         e.preventDefault(); if (!isFkeyDisabled(3)) { e.shiftKey ? doView('other') : doView(); } break;
      case 'F4':         e.preventDefault(); if (!isFkeyDisabled(4)) { e.shiftKey ? doEdit('other') : doEdit(); } break;
      case 'F5':         e.preventDefault(); if (!isFkeyDisabled(5)) doCopy(); break;
      case 'F6':         e.preventDefault(); if (!isFkeyDisabled(6)) doMove(); break;
      case 'F7':         e.preventDefault(); if (!isFkeyDisabled(7)) doCreate(); break;
      case 'F8':         e.preventDefault(); if (!isFkeyDisabled(8)) doDrop(); break;
      case 'F9':         e.preventDefault(); doExport(); break;
      case 'F10':        e.preventDefault(); doQuit(); break;
    }
    // SQL quick access
    if (e.key === ':' || (e.ctrlKey && e.key === 'p')) {
      e.preventDefault();
      $('#cmdline-input').focus();
    }
  });

  function moveCursor(delta) {
    const p = state.panels[state.active];
    p.cursor = Math.max(0, Math.min(p.data.length-1, p.cursor + delta));
    renderAll();
  }

  function switchPanel() {
    state.active = state.active === 'left' ? 'right' : 'left';
    renderAll();
  }

  function toggleSelect() {
    const p = state.panels[state.active];
    const item = p.data[p.cursor];
    if (!item || item.type === 'parent') return;
    if (p.selected.has(p.cursor)) p.selected.delete(p.cursor);
    else p.selected.add(p.cursor);
    moveCursor(+1);
  }

  function enterItem() {
    const p    = state.panels[state.active];
    const item = p.data[p.cursor];
    const meta = panelMeta[state.active];
    if (!item) return;

    if (item.type === 'parent') { goUp(); return; }

    if (meta.level === 'databases' && item.type === 'db') {
      loadTables(state.active, item.name);
    } else if (meta.level === 'tables' && (item.type === 'TABLE' || item.type === 'VIEW')) {
      // Enter = quick view toggle (not row viewer – use F3 or dblclick for that)
      showQuickView(meta.db, item.name);
    }
  }

  function renderQuickView(table, itemMeta, structData) {
    // itemMeta: {rows, engine, type} – from the panel list
    // structData: {columns, indexes, foreign_keys} – from the structure cache
    const cols    = structData.columns     || [];
    const idxs    = structData.indexes     || [];
    const fks     = structData.foreign_keys|| [];
    const pkCols  = cols.filter(c => c.key === 'PRI').map(c => c.name);

    // Header
    $('#qv-title').text(table);
    $('#qv-meta').text(itemMeta.type === 'VIEW' ? 'VIEW' : (itemMeta.engine || ''));

    // Stats row
    const rowCount = itemMeta.rows != null
      ? itemMeta.rows >= 1000000 ? (itemMeta.rows/1000000).toFixed(1)+'M'
        : itemMeta.rows >= 1000  ? (itemMeta.rows/1000).toFixed(1)+'k'
        : String(itemMeta.rows)
      : '?';

    $('#qv-stats').html(
      `<span>Rows: <span class="qv-val">${rowCount}</span></span>` +
      `<span>Cols: <span class="qv-val">${cols.length}</span></span>` +
      `<span>Idx: <span class="qv-val">${idxs.length}</span></span>` +
      (fks.length ? `<span>FK: <span class="qv-val">${fks.length}</span></span>` : '') +
      (pkCols.length ? `<span>PK: <span class="qv-val">${pkCols.join(', ')}</span></span>` : '')
    );

    // Column list
    const $cols = $('#qv-cols').empty();
    cols.forEach(c => {
      const flags = [];
      if (c.key === 'PRI') flags.push('PK');
      else if (c.key === 'UNI') flags.push('UQ');
      else if (c.key === 'MUL') flags.push('IDX');
      if (!c.nullable && c.key !== 'PRI') flags.push('NN');
      if (c.default !== null && c.default !== undefined && c.default !== '') flags.push(`=${c.default}`);

      $('<div>').addClass('qv-col-row').append(
        $('<span>').addClass('qv-col-name').text(c.name),
        $('<span>').addClass('qv-col-type').text(c.full_type || c.type || ''),
        $('<span>').addClass('qv-col-flags').text(flags.join(' '))
      ).appendTo($cols);
    });

    $('#quickview').addClass('visible');
  }

  function showQuickView(db, table) {
    // Fetch item metadata from the panel list (rows, engine, type)
    const p    = state.panels[state.active];
    const meta = panelMeta[state.active];
    const item = p.data.find(i => i.name === table) || {};

    const key = `${db}.${table}`;
    if (structureCache[key]) {
      renderQuickView(table, item, structureCache[key]);
    } else {
      // Show what we know immediately, then refresh
      $('#qv-title').text(table);
      $('#qv-meta').text('loading…');
      $('#qv-stats').empty();
      $('#qv-cols').html('<div style="color:#555;padding:2px 0">Loading structure…</div>');
      $('#quickview').addClass('visible');
      loadStructureCache(db, table).then(data => {
        if (data && $('#quickview').hasClass('visible')) {
          renderQuickView(table, item, data);
        }
      });
    }
  }

  function goUp() {
    const meta = panelMeta[state.active];
    if (meta.level === 'tables') {
      loadDatabases(state.active);
    }
  }

  function updateBreadcrumb(side) {
    const meta = panelMeta[side];
    const conn = meta.connection || panelConnection[side] || 'local';
    let titleText, crumbHtml;

    if (meta.level === 'databases') {
      titleText = `[ ${conn} ]`;
      crumbHtml = `<span class="breadcrumb-seg">${conn}</span>`;
    } else if (meta.level === 'tables') {
      titleText = `[ ${conn} / ${meta.db} ]`;
      crumbHtml =
        `<span class="breadcrumb-seg">${conn}</span>` +
        `<span class="breadcrumb-sep"> ▸ </span>` +
        `<span style="color:var(--nc-bright)">${meta.db}</span>`;
    }

    $(`#panel-${side} .panel-title`).text(titleText);
    $(`#panel-${side} .panel-breadcrumb`).html(crumbHtml);
  }

  // ── CLICK HANDLERS ─────────────────────────────────────────
  let lastClick = { side: null, idx: null, time: 0 };

  $(document).on('click', '.panel-item', function(e) {
    e.stopPropagation();
    const side = $(this).closest('.panel').data('side');
    const idx  = parseInt($(this).attr('data-idx'));
    const now  = Date.now();

    // Panel toggle
    if (side !== state.active) { state.active = side; }
    state.panels[side].cursor = idx;
    renderAll();

    // Quick view azonnal single click-re
    const meta = panelMeta[side];
    const item = state.panels[side].data[idx];
    if (item && meta.db && (item.type === 'TABLE' || item.type === 'VIEW')) {
      showQuickView(meta.db, item.name);
    } else {
      $('#quickview').removeClass('visible');
    }

    const isDbl = lastClick.side === side && lastClick.idx === idx && (now - lastClick.time) < 400;
    lastClick = { side, idx, time: now };
    if (isDbl) {
      lastClick.time = 0; // reset so it does not trigger a third time
      const meta = panelMeta[side];
      const item = state.panels[side].data[idx];
      if (!item) return;
      if (item.type === 'parent') {
        goUp();
      } else if (meta.level === 'databases' && item.type === 'db') {
        loadTables(side, item.name);
      } else if (meta.level === 'tables' && (item.type === 'TABLE' || item.type === 'VIEW')) {
        doView();
      }
    }
  });

  // ── STRUCTURE EDITOR ──────────────────────────────────────
  let se = { db: null, table: null, columns: [], original: [], dirty: new Set(), pendingDrops: [], cursor: 0, side: 'left' };

  async function openStructEdit(db, table, target) {
    se.db     = db;
    se.table  = table;
    se.cursor = -1;
    se.side   = state.active;
    se._cleanup = placeOverlay($('#structedit'), target || 'active');

    const isPanelMode = (target || 'active') === 'other';
    $('#se-panel-ind').text(isPanelMode ? '⊞ panel' : '⊡ full').toggleClass('active', isPanelMode);

    $('#structedit-title').text(`Structure: ${db} › ${table}`);
    $('#structedit-status').text('Loading…');
    $('#structedit-body').empty();
    $('#structedit').addClass('visible');

    try {
      const data = await Api.getStructure(db, table);
      structureCache[`${db}.${table}`] = data;
      se.columns      = data.columns.map(c => ({ ...c }));
      se.original     = data.columns.map(c => ({ ...c }));
      se.dirty        = new Set();
      se.pendingDrops = [];
      seRender();
    } catch (err) {
      $('#structedit-status').text('Error: ' + err.message);
    }
  }

  function seRender() {
    const $body = $('#structedit-body').empty();
    se.columns.forEach((col, i) => {
      const isCursor = se.cursor === i;
      const isPk     = col.key === 'PRI';
      const $row     = $('<div>').addClass('se-row')
        .addClass(i % 2 === 0 ? 'se-even' : 'se-odd')
        .toggleClass('se-cursor', isCursor)
        .attr('data-ci', i);

      $('<span>').addClass('se-col-num').text(col.position).appendTo($row);
      $('<span>').addClass('se-col-name' + (isPk ? ' se-pk' : '')).text((isPk ? '🔑 ' : '') + col.name).appendTo($row);

      // Type – editable when cursor is on row
      if (isCursor) {
        $('<input>').addClass('se-input se-input-type').attr({ 'data-field': 'full_type', autocomplete: 'off', spellcheck: 'false' })
          .val(col.full_type).on('input', () => seMarkDirtyFromInputs()).appendTo($row);
      } else {
        $('<span>').addClass('se-col-type').text(col.full_type).appendTo($row);
      }

      // NULL indicator – checkbox only on cursor row, text indicator otherwise
      const $nullWrap = $('<span>').addClass('se-col-null').appendTo($row);
      if (isCursor) {
        $('<input type="checkbox">').addClass('se-checkbox').attr('data-field', 'nullable')
          .prop('checked', col.nullable)
          .prop('disabled', isPk)
          .on('change', () => seMarkDirtyFromInputs())
          .appendTo($nullWrap);
      } else {
        $('<span>').text(col.nullable ? '✓' : '').appendTo($nullWrap);
      }

      // Default – editable when cursor is on row
      if (isCursor) {
        $('<input>').addClass('se-input se-input-default').attr({ 'data-field': 'default', autocomplete: 'off', spellcheck: 'false', placeholder: 'NULL' })
          .val(col.default ?? '').on('input', () => seMarkDirtyFromInputs()).appendTo($row);
      } else {
        $('<span>').addClass('se-col-default').text(col.default ?? '').appendTo($row);
      }

      // Comment – editable when cursor is on row
      if (isCursor) {
        $('<input>').addClass('se-input se-input-comment').attr({ 'data-field': 'comment', autocomplete: 'off', spellcheck: 'false' })
          .val(col.comment ?? '').on('input', () => seMarkDirtyFromInputs()).appendTo($row);
      } else {
        $('<span>').addClass('se-col-comment').text(col.comment ?? '').appendTo($row);
      }

      $body.append($row);
    });

    // Scroll cursor into view
    const $cur = $body.find('.se-cursor');
    if ($cur.length) {
      const top  = $cur.position()?.top || 0;
      const lh   = $cur.outerHeight();
      const bTop = $body.scrollTop();
      const bH   = $body.height();
      if (top < 0) $body.scrollTop(bTop + top);
      else if (top + lh > bH) $body.scrollTop(bTop + top + lh - bH);
    }

    $('#structedit-info').text(`${se.columns.length} columns`);
    $('#structedit-status').text('');

    // Focus first input field when cursor is on row
    $body.find('.se-cursor .se-input').first().focus();
  }

  // Read changes from the cursor row
  // Called on every input/checkbox change – marks cursor row dirty immediately
  // so ESC (which reverts input values before keydown fires) doesn't lose the change
  function seMarkDirtyFromInputs() {
    if (se.cursor === -1) return;
    const orig = se.original[se.cursor];
    if (orig === null) return; // new column, already dirty
    const $row = $('#structedit-body .se-cursor');
    if (!$row.length) return;
    // Read live values directly
    const liveType     = $row.find('[data-field="full_type"]').val()  ?? se.columns[se.cursor].full_type;
    const liveNullable = $row.find('[data-field="nullable"]').prop('checked') ?? se.columns[se.cursor].nullable;
    const liveDefault  = $row.find('[data-field="default"]').val()    ?? '';
    const liveComment  = $row.find('[data-field="comment"]').val()    ?? '';
    if (
      liveType     !== orig.full_type ||
      liveNullable !== orig.nullable  ||
      String(liveDefault) !== String(orig.default  ?? '') ||
      String(liveComment) !== String(orig.comment  ?? '')
    ) {
      se.dirty.add(se.cursor);
    } else {
      se.dirty.delete(se.cursor);
    }
  }

  function seReadCurrentRow() {
    if (se.cursor === -1) return;
    const $row = $('#structedit-body .se-cursor');
    if (!$row.length) return;
    const col = se.columns[se.cursor];
    $row.find('.se-input').each(function() {
      const field = $(this).attr('data-field');
      if (field) col[field] = $(this).val();
    });
    $row.find('.se-checkbox').each(function() {
      const field = $(this).attr('data-field');
      if (field) col[field] = $(this).prop('checked');
    });
    // Mark dirty if changed from original
    const orig = se.original[se.cursor];
    if (orig === null) return; // new column, already in dirty
    if (orig && (
      col.full_type !== orig.full_type ||
      col.nullable  !== orig.nullable  ||
      String(col.default  ?? '') !== String(orig.default  ?? '') ||
      String(col.comment  ?? '') !== String(orig.comment  ?? '')
    )) {
      se.dirty.add(se.cursor);
    } else {
      se.dirty.delete(se.cursor);
    }
  }

  async function seSaveRow() {
    seReadCurrentRow();
    if (se.dirty.size === 0 && se.pendingDrops.length === 0) {
      $('#structedit-status').text('No changes to save.');
      return;
    }

    activeApiSide = se.side;
    $('#structedit-status').text('Saving…');
    try {
      for (const colName of se.pendingDrops) {
        await Api.dropColumn(se.db, se.table, colName);
      }
      for (const i of [...se.dirty]) {
        const col = se.columns[i];
        if (col._isNew) {
          await Api.addColumn(se.db, se.table, col);
        } else {
          await Api.modifyColumn(se.db, se.table, col);
        }
      }
      const data = await Api.getStructure(se.db, se.table);
      structureCache[`${se.db}.${se.table}`] = data;
      se.columns      = data.columns.map(c => ({ ...c }));
      se.original     = data.columns.map(c => ({ ...c }));
      se.dirty        = new Set();
      se.pendingDrops = [];
      se.cursor       = Math.min(se.cursor, se.columns.length - 1);
      seRender();
      $('#structedit-status').text('✓ Saved');
      loadStructureCache(se.db, se.table);
    } catch (err) {
      $('#structedit-status').text('Error: ' + err.message);
    }
  }

  function seClose() {
    function doClose() {
      $('#structedit').removeClass('visible');
      if (se._cleanup) { se._cleanup(); se._cleanup = null; }
      updateQuickView();
    }

    const hasChanges = se.dirty.size > 0 || se.pendingDrops.length > 0;
    if (!hasChanges) {
      seReadCurrentRow();
      doClose();
      return;
    }

    $('#dialog-title').text('Unsaved changes');
    $('#dialog-body').html('You have unsaved changes. Save before closing?');
    const $btns = $('#dialog-buttons').empty();

    $('<button class="dialog-btn">').text('Save').on('click', async () => {
      closeDialog();
      await seSaveRow();
      doClose();
    }).appendTo($btns);

    $('<button class="dialog-btn secondary">').text('Discard').on('click', () => {
      closeDialog();
      doClose();
    }).appendTo($btns);

    $('<button class="dialog-btn secondary">').text('Cancel').on('click', closeDialog).appendTo($btns);

    $('#dialog-overlay').addClass('visible');
    $btns.find('button').first().focus();
  }

  $('#se-fkey-save').on('click', seSaveRow);
  $('#se-fkey-add').on('click', seAddColumn);
  $('#se-fkey-drop').on('click', seDropColumn);
  $('#se-fkey-close').on('click', seClose);

  function seAddColumn() {
    const $input = $('<input type="text" class="dialog-input">').attr('placeholder', 'column_name');
    const $type  = $('<input type="text" class="dialog-input" style="margin-top:6px">').attr({ placeholder: 'varchar(255)', value: 'varchar(255)' });
    const $body  = $('<div>').append(
      $('<div style="color:var(--nc-fg);margin-bottom:6px">').text('Column name:'),
      $input,
      $('<div style="color:var(--nc-fg);margin:8px 0 6px">').text('Type:'),
      $type,
    );
    showDialog('F7 Add Column', $body[0].outerHTML, ['Add', 'Cancel'], () => {
      const name     = $('#dialog-overlay input.dialog-input').eq(0).val().trim();
      const fullType = $('#dialog-overlay input.dialog-input').eq(1).val().trim() || 'varchar(255)';
      if (!name) return;
      // Draft only – saved on F2
      const newCol = { name, full_type: fullType, nullable: true, default: null, comment: '', key: '', extra: '', position: se.columns.length + 1, _isNew: true };
      se.columns.push(newCol);
      se.original.push(null);
      const newIdx = se.columns.length - 1;
      se.dirty.add(newIdx);
      se.cursor = newIdx;
      seRender();
      $('#structedit-status').text(`${name} added – press F2 to save`);
    });
    setTimeout(() => {
      const $inp = $('#dialog-overlay input.dialog-input').first().focus();
      $inp.on('keydown', function(e) {
        if (e.key === 'Enter') { e.preventDefault(); $('#dialog-buttons button:first').click(); }
      });
    }, 50);
  }

  function seDropColumn() {
    const col = se.columns[se.cursor];
    if (!col || se.cursor === -1) return;
    if (col.key === 'PRI') {
      showDialog('⚠ Cannot Drop', `<span style="color:var(--nc-yellow)">Primary key columns cannot be dropped here.</span>`, ['OK']);
      return;
    }
    const safeName = $('<div>').text(col.name).html();
    showDialog('⚠ Drop Column',
      `Drop column <span style="color:var(--nc-red);font-weight:bold">${safeName}</span>?<br><br>` +
      `<span style="color:var(--nc-yellow)">This operation cannot be undone!</span>`,
      ['Drop!', 'Cancel'],
      () => {
        const dropIdx = se.cursor;
        const isNew   = !!col._isNew;
        if (!isNew) se.pendingDrops.push(col.name);
        se.columns.splice(dropIdx, 1);
        se.original.splice(dropIdx, 1);
        // Renumber dirty indices
        const newDirty = new Set();
        se.dirty.forEach(i => {
          if (i === dropIdx) return;
          newDirty.add(i > dropIdx ? i - 1 : i);
        });
        se.dirty  = newDirty;
        se.cursor = Math.min(dropIdx, se.columns.length - 1);
        seRender();
        $('#structedit-status').text(`${col.name} removed – press F2 to save`);
      }
    );
  }

  // Row click sets cursor
  $(document).on('click', '#structedit-body .se-row', function() {
    seReadCurrentRow();
    se.cursor = parseInt($(this).attr('data-ci'));
    seRender();
  });

  // Structure editor keyboard handler
  $(document).on('keydown', function(e) {
    if (!$('#structedit').hasClass('visible')) return;
    if ($('#dialog-overlay').hasClass('visible')) return;
    // If an input is focused, Enter and Tab behave naturally
    const inInput = $(e.target).hasClass('se-input');
    switch (e.key) {
      case 'F10':
        e.preventDefault(); seClose(); break;
      case 'Escape':
        e.preventDefault(); seMarkDirtyFromInputs(); seClose(); break;
      case 'F2':
        e.preventDefault(); seSaveRow(); break;
      case 'F7':
        e.preventDefault(); seAddColumn(); break;
      case 'F8':
        e.preventDefault(); seDropColumn(); break;
      case 'ArrowUp':
        if (!inInput) { e.preventDefault(); seReadCurrentRow(); se.cursor = Math.max(0, se.cursor === -1 ? 0 : se.cursor - 1); seRender(); }
        break;
      case 'ArrowDown':
        if (!inInput) { e.preventDefault(); seReadCurrentRow(); se.cursor = Math.min(se.columns.length - 1, se.cursor + 1); seRender(); }
        break;
      case 'Enter':
        if (!inInput) { e.preventDefault(); seRender(); } // refresh = focus the input
        break;
    }
  });
  $(document).on('click', '.rv-cell', function(e) {
    e.stopPropagation();
    const col  = $(this).data('col');
    const full = $(this).attr('data-full');
    const isNull = $(this).attr('data-isnull') === '1';
    if (isNull) { hideCellPopup(); return; }

    const display = full || $(this).text();
    // Only show tooltip if there is something to show (truncated or long value)
    $('#cell-popup-label').text(col + ':');
    $('#cell-popup-value').text(display);

    // Position tooltip next to the cursor
    const pw = 500, ph = 200;
    let left = e.clientX + 8;
    let top  = e.clientY + 8;
    if (left + pw > window.innerWidth - 8)  left = e.clientX - pw - 8;
    if (top  + ph > window.innerHeight - 8) top  = e.clientY - ph - 8;
    $('#cell-popup').css({ left, top }).addClass('visible');
  });

  // SQL result cells too
  $(document).on('click', '.sqr-cell', function(e) {
    e.stopPropagation();
    const col  = $(this).data('col');
    const full = $(this).attr('data-full');
    const isNull = $(this).attr('data-isnull') === '1';
    if (isNull) { hideCellPopup(); return; }

    $('#cell-popup-label').text(col + ':');
    $('#cell-popup-value').text(full || $(this).text());

    let left = e.clientX + 8, top = e.clientY + 8;
    if (left + 500 > window.innerWidth - 8)  left = e.clientX - 508;
    if (top  + 200 > window.innerHeight - 8) top  = e.clientY - 208;
    $('#cell-popup').css({ left, top }).addClass('visible');
  });

  $(document).on('click', function(e) {
    if (!$(e.target).hasClass('rv-cell') && !$(e.target).hasClass('sqr-cell') && !$(e.target).closest('#cell-popup').length) {
      hideCellPopup();
    }
  });

  function hideCellPopup() { $('#cell-popup').removeClass('visible'); }

  // ── SQL RESULT VIEWER ──────────────────────────────────────
  async function runSqlAndShow(sql) {
    const meta = panelMeta[state.active];
    const db   = meta.db || null;

    $('#sqlresult-query').text(sql);
    $('#sqlresult-colheader').empty();
    $('#sqlresult-body').html('<div style="color:var(--nc-cyan);padding:8px">Running…</div>');
    $('#sqlresult-status').text('');
    $('#sqlresult').addClass('visible');

    try {
      const data = await Api.runSql(db, sql);
      renderSqlResult(sql, data);
    } catch (err) {
      $('#sqlresult-body').html(`<div id="sqlresult-error">Error: ${$('<div>').text(err.message).html()}</div>`);
      $('#sqlresult-status').text('Error');
    }
  }

  function renderSqlResult(sql, data) {
    // data: { columns: [...], rows: [...], affected?: int, time?: float }
    const cols = data.columns || [];
    const rows = data.rows    || [];

    if (!cols.length && data.affected != null) {
      // Non-SELECT (INSERT/UPDATE/DELETE)
      $('#sqlresult-colheader').empty();
      $('#sqlresult-body').html(
        `<div style="color:var(--nc-green);padding:12px;font-size:13px">` +
        `✓ Query OK — ${data.affected} row(s) affected` +
        (data.time != null ? `<br><span style="color:#555;font-size:11px">${data.time}ms</span>` : '') +
        `</div>`
      );
      $('#sqlresult-status').text(`${data.affected} rows affected`);
      return;
    }

    // Calculate column widths
    const colWidths = cols.map(c => Math.min(40, Math.max(c.length, 8)));
    rows.slice(0, 200).forEach(row => {
      cols.forEach((col, ci) => {
        const val = Array.isArray(row) ? row[ci] : row[col];
        const len = val != null ? Math.min(40, String(val).length) : 0;
        colWidths[ci] = Math.max(colWidths[ci], len);
      });
    });

    // Header
    const $hdr = $('#sqlresult-colheader').empty();
    $('<div>').css({ width: 48, flexShrink: 0 }).appendTo($hdr);
    cols.forEach((col, ci) => {
      $('<div>').addClass('sqr-col-hdr')
        .css('width', colWidths[ci] * 8 + 8)
        .text(col)
        .appendTo($hdr);
    });

    // Rows
    const $body = $('#sqlresult-body').empty();
    if (!rows.length) {
      $body.html('<div style="color:var(--nc-yellow);padding:8px">(empty result set)</div>');
    } else {
      rows.forEach((row, ri) => {
        const $row = $('<div>').addClass('sqr-row').addClass(ri % 2 === 0 ? 'sqr-even' : 'sqr-odd');
        $('<div>').addClass('sqr-row-num').text(ri + 1).appendTo($row);
        cols.forEach((col, ci) => {
          const val = Array.isArray(row) ? row[ci] : row[col];
          const isNull = val === null || val === undefined;
          const display = isNull ? 'NULL' : String(val);
          const w = colWidths[ci] * 8 + 8;
          const truncated = display.length > colWidths[ci] ? display.slice(0, colWidths[ci]-1)+'…' : display;
          $('<div>').addClass('sqr-cell' + (isNull ? ' sqr-null' : ''))
            .css('width', w)
            .text(truncated)
            .attr('data-col', col)
            .attr('data-full', isNull ? '' : display)
            .attr('data-isnull', isNull ? '1' : '0')
            .appendTo($row);
        });
        $body.append($row);
      });
    }

    const timeStr = data.time != null ? `  (${data.time}ms)` : '';
    $('#sqlresult-status').text(`${rows.length} rows${timeStr}`);
  }

  function closeSqlResult() {
    $('#sqlresult').removeClass('visible');
    hideCellPopup();
  }

  $('#sqlresult-fkey-close').on('click', closeSqlResult);

  // Copy SQL gomb (F5)
  $('#sqlresult-fkeys .fkey:nth-child(5)').on('click', function() {
    const sql = $('#sqlresult-query').text();
    if (sql) navigator.clipboard?.writeText(sql);
  });
  $(document).on('click', '.panel-colheader [data-sort]', function(e) {
    e.stopPropagation();
    const side   = $(this).data('side');
    const sortBy = $(this).data('sort');
    const p      = state.panels[side];
    const meta   = panelMeta[side];
    if (meta.level !== 'tables') return;
    if (p.sortBy === sortBy) {
      p.sortDir = p.sortDir === 'asc' ? 'desc' : 'asc';
    } else {
      p.sortBy  = sortBy;
      p.sortDir = sortBy === 'modified' ? 'desc' : 'asc'; // date: newest first by default
    }
    state.active = side;
    renderAll();
  });

  $(document).on('click', '#fkeybar .fkey:not(.fkey-disabled)', function() {
    const f = parseInt($(this).data('f'));
    const fkeys = {1:showHelp, 2:doSqlEditor, 3:doView, 4:doEdit, 5:doCopy, 6:doMove, 7:doCreate, 8:doDrop, 9:doExport, 10:doQuit};
    if (fkeys[f]) fkeys[f]();
  });

  $(document).on('click', '.panel', function(e) {
    if ($(e.target).closest('.panel-item').length) return;
    const side = $(this).data('side');
    if (side && side !== state.active) { state.active = side; renderAll(); }
  });

  // ── ACTIONS ────────────────────────────────────────────────
  function currentItem() {
    const p = state.panels[state.active];
    return p.data[p.cursor];
  }

  // ── ROW VIEWER ─────────────────────────────────────────────
  const rv = {
    db: null, table: null,
    columns: [],       // [{name, full_type}]
    colWidths: [],     // computed pixel widths
    rows: [],          // loaded rows (arrays)
    total: 0,
    offset: 0,
    limit: 200,
    cursor: 0,         // row index within loaded rows
    orderBy: null,
    direction: 'ASC',
    loading: false,
  };

  // ── OVERLAY PLACEMENT HELPER ───────────────────────────────
  // target: 'active' | 'other'
  // Returns a cleanup function to call on close
  function placeOverlay($overlay, target) {
    const otherSide = state.active === 'left' ? 'right' : 'left';
    const targetSide = target === 'other' ? otherSide : state.active;
    const $panel = $('#panel-' + targetSide);

    if (target === 'other') {
      $overlay.addClass('panel-mode').appendTo($panel);
      $panel.addClass('panel-overlay-host');
      return () => {
        $overlay.removeClass('panel-mode').appendTo('#app');
        $panel.removeClass('panel-overlay-host');
      };
    }
    // fullscreen – marad az #app-ban
    return () => {};
  }

  function doView(target) {
    const p    = state.panels[state.active];
    const item = p.data[p.cursor];
    const meta = panelMeta[state.active];
    if (!item || item.type === 'parent') return;
    if (item.type !== 'TABLE' && item.type !== 'VIEW') return;
    openRowViewer(meta.db, item.name, target || 'active');
  }

  async function openRowViewer(db, table, target) {
    rv.db = db; rv.table = table;
    rv.rows = []; rv.offset = 0; rv.cursor = 0;
    rv.orderBy = null; rv.direction = 'ASC';
    rv._cleanup = placeOverlay($('#rowviewer'), target || 'active');

    const isPanelMode = target === 'other';
    $('#rv-panel-ind').text(isPanelMode ? '⊞ panel' : '⊡ full').toggleClass('active', isPanelMode);

    $('#rv-title').text(`${db} › ${table}`);
    $('#rv-colheader').empty();
    $('#rv-body').html('<div class="rv-loading">Loading…</div>');
    $('#rv-info').text('');
    $('#rowviewer').addClass('visible');

    const struct = await loadStructureCache(db, table);
    if (struct) rv.columns = struct.columns;
    await rvLoadPage();
  }

  async function rvLoadPage(append = false) {
    if (rv.loading) return;
    rv.loading = true;

    try {
      const params = {
        limit: rv.limit,
        offset: rv.offset,
        count: 1,
      };
      if (rv.orderBy) {
        params.order_by  = rv.orderBy;
        params.direction = rv.direction;
      }

      const data = await Api.getRows(rv.db, rv.table, params);

      // Columns: prefer structure cache, fall back to keys of first row
      if (!rv.columns.length) {
        const colNames = data.columns || (data.rows.length ? Object.keys(data.rows[0]) : []);
        rv.columns = colNames.map(n => ({ name: n, full_type: '' }));
      }

      rv.total = data.total ?? rv.rows.length;

      if (append) {
        rv.rows = rv.rows.concat(data.rows);
      } else {
        rv.rows = data.rows;
        rv.cursor = 0;
      }

      rvComputeWidths(data.columns || rv.columns.map(c => c.name));
      rvRender();

    } catch(e) {
      $('#rv-body').html(`<div class="rv-loading" style="color:var(--nc-red)">Error: ${e.message}</div>`);
    }

    rv.loading = false;
  }

  const RV_MIN_COL = 6;
  const RV_MAX_COL = 40;

  function rvComputeWidths(colNames) {
    rv.colWidths = colNames.map((name, ci) => {
      let max = Math.max(RV_MIN_COL, name.length);
      for (let ri = 0; ri < Math.min(50, rv.rows.length); ri++) {
        const row = rv.rows[ri];
        const val = Array.isArray(row) ? row[ci] : row[name];
        if (val !== null && val !== undefined) {
          max = Math.max(max, String(val).length);
        }
      }
      return Math.min(RV_MAX_COL, max);
    });
  }

  function rvRender() {
    const colNames = rv.columns.map(c => c.name);

    // Column header
    const $hdr = $('#rv-colheader').empty();
    $('<div>').addClass('rv-col-hdr').css('width', 48).text('#').appendTo($hdr);
    colNames.forEach((name, ci) => {
      const w = (rv.colWidths[ci] || 12) * 8 + 8;
      const $h = $('<div>').addClass('rv-col-hdr')
        .css('width', w)
        .text(name.length > rv.colWidths[ci] ? name.slice(0, rv.colWidths[ci]-1)+'…' : name)
        .attr('data-ci', ci);
      if (rv.orderBy === name) $h.addClass(rv.direction === 'ASC' ? 'sort-asc' : 'sort-desc');
      $hdr.append($h);
    });

    // Rows
    const $body = $('#rv-body').empty();
    if (!rv.rows.length) {
      $body.html('<div class="rv-loading" style="color:var(--nc-yellow)">(empty table)</div>');
    } else {
      rv.rows.forEach((row, ri) => {
        const $row = $('<div>').addClass('rv-row').attr('data-ri', ri);
        if (ri === rv.cursor) $row.addClass('rv-cursor');
        $row.addClass(ri % 2 === 0 ? 'rv-even' : 'rv-odd');
        $('<div>').addClass('rv-row-num').text(rv.offset + ri + 1).appendTo($row);
        rv.columns.forEach((col, ci) => {
          const val = Array.isArray(row) ? row[ci] : row[col.name];
          const w = (rv.colWidths[ci] || 12) * 8 + 8;
          const isNull = val === null || val === undefined;
          const display = isNull ? 'NULL' : String(val);
          const truncated = display.length > rv.colWidths[ci] ? display.slice(0, rv.colWidths[ci]-1)+'…' : display;
          $('<div>').addClass('rv-cell' + (isNull ? ' rv-null' : ''))
            .css('width', w)
            .text(truncated)
            .attr('data-col', col.name)
            .attr('data-full', isNull ? '' : display)
            .attr('data-isnull', isNull ? '1' : '0')
            .appendTo($row);
        });
        $body.append($row);
      });

      // Scroll cursor into view
      const $cur = $body.find('.rv-cursor');
      if ($cur.length) {
        const bTop = $body.scrollTop();
        const bH   = $body.height();
        const rTop = $cur.position().top + bTop;
        if (rTop < bTop) $body.scrollTop(rTop);
        else if (rTop + $cur.outerHeight() > bTop + bH) $body.scrollTop(rTop - bH + $cur.outerHeight() + 4);
      }
    }

    // Status
    const showing = rv.rows.length
      ? `Row ${rv.cursor + 1 + rv.offset} of ${rv.total ?? '?'}  (${rv.rows.length} loaded)`
      : 'No rows';
    $('#rv-status-left').text(showing);
    $('#rv-info').text(rv.total != null ? `${rv.total} rows` : '');
    $('#rv-titlebar .rv-title').text(`${rv.db} › ${rv.table}`);
  }

  // Row viewer keyboard
  $(document).on('keydown', function(e) {
    if (!$('#rowviewer').hasClass('visible')) return;
    if ($('#editrow').hasClass('visible')) return;
    switch(e.key) {
      case 'Escape':
      case 'F10':
        e.preventDefault(); rvClose(); break;
      case 'F4':
        e.preventDefault(); openEditRow(); break;
      case 'ArrowUp':
        e.preventDefault(); rvMoveCursor(-1); break;
      case 'ArrowDown':
        e.preventDefault(); rvMoveCursor(+1); break;
      case 'PageUp':
        e.preventDefault(); rvMoveCursor(-20); break;
      case 'PageDown':
        e.preventDefault(); rvMoveCursor(+20); break;
      case 'Home':
        e.preventDefault(); rv.cursor = 0; rvRender(); break;
      case 'End':
        e.preventDefault(); rv.cursor = rv.rows.length - 1; rvRender(); break;
    }
  });

  async function rvMoveCursor(delta) {
    const newCursor = rv.cursor + delta;
    // Load more if near end
    if (newCursor >= rv.rows.length - 10 && rv.rows.length < rv.total) {
      rv.offset += rv.limit;
      await rvLoadPage(true);
      rv.cursor = Math.min(newCursor, rv.rows.length - 1);
      rvRender();
    } else {
      rv.cursor = Math.max(0, Math.min(rv.rows.length - 1, newCursor));
      rvRender();
    }
  }

  // Column header click → sort
  $(document).on('click', '#rv-colheader .rv-col-hdr', function() {
    const ci   = $(this).data('ci');
    if (ci === undefined) return;
    const name = rv.columns[ci]?.name;
    if (!name) return;
    if (rv.orderBy === name) {
      rv.direction = rv.direction === 'ASC' ? 'DESC' : 'ASC';
    } else {
      rv.orderBy = name;
      rv.direction = 'ASC';
    }
    rv.offset = 0;
    rvLoadPage(false);
  });

  // Row click → move cursor
  $(document).on('click', '#rv-body .rv-row', function() {
    rv.cursor = parseInt($(this).attr('data-ri'));
    rvRender();
  });

  // F3 / F10 buttons
  $('#rv-fkey-close, #rv-fkey-reload').on('click', function() {
    const f = parseInt($(this).find('.fkey-num').text());
    if (f === 10) rvClose();
    if (f === 3)  { rv.offset = 0; rvLoadPage(false); }
  });

  $(document).on('click', '#rv-fkeys .fkey', function() {
    const f = parseInt($(this).find('.fkey-num').text());
    if (f === 4) openEditRow();
  });

  function rvClose() {
    $('#rowviewer').removeClass('visible');
    if (rv._cleanup) { rv._cleanup(); rv._cleanup = null; }
  }

  // ── EDIT ROW OVERLAY ──────────────────────────────────────
  let er = { db: null, table: null, columns: [], pkCols: [], originalRow: null };

  async function openEditRow() {
    if (!rv.rows.length) return;
    const row = rv.rows[rv.cursor];
    if (!row) return;

    er.db    = rv.db;
    er.table = rv.table;

    // Structure needed for PK and type info
    const key = `${rv.db}.${rv.table}`;
    let structure = structureCache[key];
    if (!structure) {
      structure = await loadStructureCache(rv.db, rv.table);
    }
    er.columns     = structure?.columns || rv.columns.map(c => ({ name: c.name, full_type: '', key: '' }));
    er.pkCols      = er.columns.filter(c => c.key === 'PRI').map(c => c.name);
    er.originalRow = row;

    // If no PK, all columns become WHERE – show warning
    const hasPk = er.pkCols.length > 0;

    // Form build
    const $body = $('#editrow-body').empty();
    er.columns.forEach(col => {
      const val     = Array.isArray(row) ? row[rv.columns.findIndex(c => c.name === col.name)] : row[col.name];
      const isPk    = er.pkCols.includes(col.name);
      const display = val === null ? '' : String(val);

      const $field = $('<div>').addClass('er-field');
      $('<div>').addClass('er-col-name' + (isPk ? ' er-pk' : '')).text((isPk ? '🔑 ' : '') + col.name).appendTo($field);
      $('<input>').addClass('er-input')
        .attr({ 'data-col': col.name, 'data-original': display, autocomplete: 'off', spellcheck: 'false' })
        .val(display)
        .prop('readonly', isPk)
        .appendTo($field);
      $('<div>').addClass('er-type').text(col.full_type || '').appendTo($field);
      $body.append($field);
    });

    // Focus first non-PK field
    $('#editrow-body .er-input:not([readonly])').first().focus();

    $('#editrow-title').text(`Edit: ${rv.db} › ${rv.table}`);
    $('#editrow-info').text(`Row ${rv.cursor + 1 + rv.offset} of ${rv.total ?? '?'}`);
    $('#editrow-status').text(hasPk ? '' : '⚠ No PK – all columns used in WHERE');
    $('#editrow').addClass('visible');
  }

  async function saveEditRow() {
    const set   = {};
    const where = {};

    // WHERE: PK values (or all original values if no PK)
    const whereKeys = er.pkCols.length > 0 ? er.pkCols : er.columns.map(c => c.name);
    whereKeys.forEach(colName => {
      const val = Array.isArray(er.originalRow)
        ? er.originalRow[er.columns.findIndex(c => c.name === colName)]
        : er.originalRow[colName];
      where[colName] = val;
    });

    // SET: changed, non-PK fields
    let changed = 0;
    $('#editrow-body .er-input:not([readonly])').each(function() {
      const col      = $(this).attr('data-col');
      const original = $(this).attr('data-original');
      const current  = $(this).val();
      if (current !== original) {
        set[col] = current === '' ? null : current;
        changed++;
      }
    });

    if (changed === 0) {
      $('#editrow-status').text('No changes to save.');
      return;
    }

    $('#editrow-status').text('Saving…');

    try {
      const result = await Api.updateRow(er.db, er.table, where, set);
      if (result.affected === 0) {
        $('#editrow-status').text('⚠ No rows affected – row may have changed');
      } else {
        closeEditRow();
        // Update the current row in the row viewer
        rv.offset = 0;
        rvLoadPage(false);
      }
    } catch (err) {
      $('#editrow-status').text('Error: ' + err.message);
    }
  }

  function closeEditRow() {
    $('#editrow').removeClass('visible');
  }

  $('#er-fkey-save').on('click', saveEditRow);
  $('#er-fkey-close').on('click', closeEditRow);

  // Edit overlay keyboard
  $(document).on('keydown', function(e) {
    if (!$('#editrow').hasClass('visible')) return;
    if (e.key === 'Escape' || e.key === 'F10') { e.preventDefault(); closeEditRow(); }
    if (e.key === 'F2') { e.preventDefault(); saveEditRow(); }
  });

  function doSqlEditor() {
    showDialog('SQL Editor',
      `<span style="color:var(--nc-fg)">SQL editor panel</span><br><br>` +
      `<span style="color:var(--nc-cyan)">F2</span> <span style="color:var(--nc-fg)">– Full SQL editor with result viewer</span><br>` +
      `<span style="color:var(--nc-fg);font-size:11px">Tip: use <span style="color:var(--nc-yellow)">:</span> or <span style="color:var(--nc-yellow)">Ctrl+P</span> for quick SQL command line</span>`,
      ['OK']);
  }

  function doExport() {
    const item = currentItem();
    const meta = panelMeta[state.active];
    if (!item || item.type === 'parent' || !meta.db) {
      showDialog('Export', `<span style="color:var(--nc-yellow)">Select a table first</span>`, ['OK']);
      return;
    }
    if (item.type !== 'TABLE' && item.type !== 'VIEW') {
      showDialog('Export', `<span style="color:var(--nc-yellow)">Only tables and views can be exported.</span>`, ['OK']);
      return;
    }

    const safeDb  = $('<div>').text(meta.db).html();
    const safeTbl = $('<div>').text(item.name).html();

    showDialog('Export: ' + item.name,
      `<span style="color:var(--nc-fg)">Format:</span><br><br>` +
      `<div style="display:flex;gap:8px;flex-wrap:wrap">` +
        `<button class="dialog-btn" id="exp-csv">CSV</button>` +
        `<button class="dialog-btn" id="exp-json">JSON</button>` +
        `<button class="dialog-btn" id="exp-sql">SQL INSERT</button>` +
      `</div><br>` +
      `<span style="color:var(--nc-fg);font-size:11px">Source: <span style="color:var(--nc-cyan)">${safeDb}.${safeTbl}</span> (max 50 000 rows)</span>` +
      `<div id="exp-status" style="color:var(--nc-yellow);font-size:11px;margin-top:4px"></div>`,
      ['Cancel']
    );

    async function runExport(format) {
      $('#exp-status').text('Loading rows…');
      $('#exp-csv, #exp-json, #exp-sql').prop('disabled', true);
      try {
        const data = await Api.getAllRows(meta.db, item.name);
        const rows = data.rows || [];
        const cols = data.columns || [];
        $('#exp-status').text(`${rows.length} rows – generating file…`);

        let content, mime, ext;

        if (format === 'csv') {
          const header = cols.map(c => csvCell(c)).join(',');
          const lines  = rows.map(row =>
            cols.map(c => csvCell(row[c] ?? '')).join(',')
          );
          content = [header, ...lines].join('\r\n');
          mime = 'text/csv';
          ext  = 'csv';
        } else if (format === 'json') {
          content = JSON.stringify(rows, null, 2);
          mime = 'application/json';
          ext  = 'json';
        } else { // sql
          const tbl   = '`' + item.name.replace(/`/g, '``') + '`';
          const colList = cols.map(c => '`' + c.replace(/`/g, '``') + '`').join(', ');
          const inserts = rows.map(row => {
            const vals = cols.map(c => {
              const v = row[c];
              if (v === null || v === undefined) return 'NULL';
              if (typeof v === 'number') return String(v);
              return "'" + String(v).replace(/\\/g, '\\\\').replace(/'/g, "\\'") + "'";
            }).join(', ');
            return `INSERT INTO ${tbl} (${colList}) VALUES (${vals});`;
          });
          content = `-- Export: ${meta.db}.${item.name}\n-- Generated: ${new Date().toISOString()}\n\n` + inserts.join('\n');
          mime = 'text/plain';
          ext  = 'sql';
        }

        // Download
        const blob = new Blob([content], { type: mime });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = `${meta.db}_${item.name}.${ext}`;
        a.click();
        URL.revokeObjectURL(url);
        closeDialog();
      } catch (err) {
        $('#exp-status').css('color', 'var(--nc-red)').text('Error: ' + err.message);
        $('#exp-csv, #exp-json, #exp-sql').prop('disabled', false);
      }
    }

    function csvCell(val) {
      const s = String(val ?? '');
      if (s.includes(',') || s.includes('"') || s.includes('\n')) {
        return '"' + s.replace(/"/g, '""') + '"';
      }
      return s;
    }

    // Buttons inside the dialog
    $(document).one('click', '#exp-csv',  () => runExport('csv'));
    $(document).one('click', '#exp-json', () => runExport('json'));
    $(document).one('click', '#exp-sql',  () => runExport('sql'));
  }

  function doEdit(target) {
    const item = currentItem();
    const meta = panelMeta[state.active];
    if (!item || item.type === 'parent') return;
    if (meta.level === 'tables' && (item.type === 'TABLE' || item.type === 'VIEW')) {
      openStructEdit(meta.db, item.name, target || 'active');
    }
  }

  function doCopy() {
    const meta = panelMeta[state.active];

    if (meta.level === 'databases') {
      const item = currentItem();
      if (!item || item.type === 'parent') {
        showDialog('Copy Database', 'Select a DATABASE to copy.', ['OK']);
        return;
      }

      const srcSide = state.active;
      const tgtSide = srcSide === 'left' ? 'right' : 'left';
      const srcConn = panelConnection[srcSide];
      const tgtConn = panelConnection[tgtSide];
      const connNote = srcConn !== tgtConn
        ? '<br><span style="color:var(--nc-green);font-size:11px">\u2756 Cross-connection copy</span>'
        : '';

      const $input = $('<input type="text" class="dialog-input">').val(item.name + '_copy');
      const $body = $('<div>').append(
        $('<div style="margin-bottom:8px">').html(
          'Copy database <span style="color:var(--nc-cyan)">' + (srcConn || 'local') + ' / ' + item.name + '</span>' + connNote
        ),
        $('<div style="color:var(--nc-fg);margin-bottom:4px;font-size:11px">').text('Target database name:'),
        $input,
        $('<div style="margin-top:10px;font-size:11px;color:var(--nc-fg)">').html(
          'Target connection: <span style="color:var(--nc-yellow)">' + (tgtConn || 'local') + '</span>'
        ),
        $('<div style="margin-top:10px;font-size:11px">').append(
          $('<span style="color:var(--nc-fg)">Mode:\u00a0</span>'),
          $('<label style="margin-right:12px;cursor:pointer">').append(
            $('<input type="radio" name="db-copy-mode" value="append" checked style="margin-right:4px">'),
            $('<span style="color:var(--nc-cyan)">append</span>')
          ),
          $('<label style="cursor:pointer">').append(
            $('<input type="radio" name="db-copy-mode" value="replace" style="margin-right:4px">'),
            $('<span style="color:var(--nc-yellow)">replace</span>')
          )
        )
      );

      showDialog('F5 Copy Database', $body[0].outerHTML, ['Copy', 'Cancel'], async () => {
        const tgtDb = $('#dialog-overlay input.dialog-input').val().trim();
        if (!tgtDb) return;
        const mode = $('#dialog-overlay input[name="db-copy-mode"]:checked').val() || 'append';

        const srcEsc = $('<div>').text(item.name).html();
        const tgtEsc = $('<div>').text(tgtDb).html();
        const srcLabel = '<span style="color:var(--nc-cyan)">' + (srcConn || 'local') + ' / ' + srcEsc + '</span>';
        const tgtLabel = '<span style="color:var(--nc-yellow)">' + (tgtConn || 'local') + ' / ' + tgtEsc + '</span>';

        const $prog = $('<div>').css({
          position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.7)',
          display: 'flex', alignItems: 'center', justifyContent: 'center',
          zIndex: 700, color: 'var(--nc-cyan)', fontFamily: 'monospace',
          flexDirection: 'column', gap: '8px',
        }).append(
          $('<div>').text('Copying database ' + item.name + '\u2026'),
          $('<div>').attr('id', 'copy-db-progress').css({ color: 'var(--nc-fg)', fontSize: '12px' }).text('Starting\u2026')
        ).appendTo('body');

        try {
          activeApiSide = srcSide;
          const result = await Api.copyDatabase(item.name, tgtDb, srcConn, tgtConn, mode);
          $prog.remove();

          const detailRows = (result.details || [])
            .map(d =>
              '<tr>'
              + '<td style="color:var(--nc-cyan);padding-right:16px">' + $('<div>').text(d.table).html() + '</td>'
              + '<td style="color:var(--nc-fg)">' + d.rows_inserted + ' rows</td>'
              + '</tr>'
            ).join('');
          const detailHtml = detailRows
            ? '<table style="margin-top:8px;font-size:11px;width:100%">' + detailRows + '</table>'
            : '';

          showDialog('Copy Complete',
            'Copied ' + srcLabel + '<br>\u2192 ' + tgtLabel + '<br><br>'
            + '<span style="color:var(--nc-green)">' + result.tables_copied + ' table(s),\u00a0' + result.rows_inserted + ' total rows inserted.</span>'
            + detailHtml,
            ['OK']
          );
          loadDatabases(tgtSide);
        } catch(e) {
          $prog.remove();
          showDialog('Copy Failed', '<span style="color:var(--nc-red)">' + $('<div>').text(e.message).html() + '</span>', ['OK']);
        }
      });

      setTimeout(() => {
        const $inp = $('#dialog-overlay input.dialog-input').focus().select();
        $inp.on('keydown', function(e) {
          if (e.key === 'Enter') { e.preventDefault(); $('#dialog-buttons button:first').click(); }
        });
      }, 50);
      return;
    }

    const item  = currentItem();
    if (!item || item.type === 'parent' || item.type !== 'TABLE') {
      showDialog('Copy Table', 'Select a TABLE to copy.', ['OK']);
      return;
    }

    const srcSide  = state.active;
    const tgtSide  = srcSide === 'left' ? 'right' : 'left';
    const srcMeta  = panelMeta[srcSide];
    const tgtMeta  = panelMeta[tgtSide];
    const srcConn  = panelConnection[srcSide];
    const tgtConn  = panelConnection[tgtSide];

    if (!srcMeta.db) {
      showDialog('Copy Table', 'Source panel: select a database first.', ['OK']);
      return;
    }
    if (!tgtMeta.db) {
      showDialog('Copy Table', 'Target panel: open a database on the other panel first.', ['OK']);
      return;
    }

    const srcLabel = `<span style="color:var(--nc-cyan)">${srcConn || 'local'} / ${srcMeta.db} / ${item.name}</span>`;
    const tgtLabel = `<span style="color:var(--nc-yellow)">${tgtConn || 'local'} / ${tgtMeta.db} / ${item.name}</span>`;
    const connNote = srcConn !== tgtConn
      ? `<br><span style="color:var(--nc-green);font-size:11px">✦ Cross-connection copy</span>`
      : '';

    showDialog(
      'Copy Table',
      `Copy ${srcLabel}<br>→ ${tgtLabel}${connNote}<br><br>` +
      `<span style="font-size:11px;color:var(--nc-fg)">Mode: </span>` +
      `<span style="font-size:11px;color:var(--nc-cyan)">append</span>`,
      ['Copy', 'Replace', 'Cancel'],
      async (btn) => {
        if (btn === 'Cancel') return;
        const mode = btn === 'Replace' ? 'replace' : 'append';

        // Progress overlay
        const $prog = $('<div>').css({
          position: 'fixed', inset: 0, background: 'rgba(0,0,0,0.7)',
          display: 'flex', alignItems: 'center', justifyContent: 'center',
          zIndex: 700, color: 'var(--nc-cyan)', fontFamily: 'monospace',
          flexDirection: 'column', gap: '8px',
        }).append(
          $('<div>').text(`Copying ${item.name}…`),
          $('<div>').attr('id', 'copy-progress').css({ color: 'var(--nc-fg)', fontSize: '12px' }).text('Starting…')
        ).appendTo('body');

        try {
          const result = await Api.copyTable(
            srcMeta.db, item.name,
            tgtMeta.db, item.name,
            srcConn, tgtConn,
            mode
          );
          $prog.remove();
          showDialog('Copy Complete',
            `Copied ${srcLabel}<br>→ ${tgtLabel}<br><br>` +
            `<span style="color:var(--nc-green)">${result.inserted} rows inserted.</span>`,
            ['OK']
          );
          // Refresh target panel
          loadTables(tgtSide, tgtMeta.db);
        } catch(e) {
          $prog.remove();
          showDialog('Copy Failed', `<span style="color:var(--nc-red)">${e.message}</span>`, ['OK']);
        }
      }
    );
  }

  function doMove() {
    const meta = panelMeta[state.active];
    const item = currentItem();

    if (meta.level === 'tables') {
      if (!item || item.type === 'parent' || item.type !== 'TABLE') {
        showDialog('Move Table', 'Select a TABLE to move.', ['OK']);
        return;
      }
      // Move = Copy + Drop – not yet implemented
      showDialog('Move Table', '<span style="color:var(--nc-yellow)">Move is not yet implemented.</span>', ['OK']);
      return;
    }
  }

  function doCreate() {
    const meta = panelMeta[state.active];

    if (meta.level === 'databases') {
      // Create Database
      const $input = $('<input type="text" class="dialog-input">').attr('placeholder', 'database_name');
      const $body  = $('<div>').append(
        $('<div style="color:var(--nc-fg);margin-bottom:8px">').text('Create new database:'),
        $input
      );

      showDialog('F7 Create Database', $body[0].outerHTML, ['Create', 'Cancel'], async () => {
        const name = $('#dialog-overlay input.dialog-input').val().trim();
        if (!name) return;
        activeApiSide = state.active;
        try {
          await Api.createDatabase(name);
          await loadDatabases(state.active);
          // Move cursor to the new database
          const items = state.panels[state.active].data;
          const idx   = items.findIndex(i => i.name === name);
          if (idx >= 0) state.panels[state.active].cursor = idx;
          renderPanel(state.active);
        } catch(e) {
          showDialog('✗ Create Failed', `<span style="color:var(--nc-red)">${$('<div>').text(e.message).html()}</span>`, ['OK']);
        }
      });

      // Focus input after dialog opens, Enter triggers Create
      setTimeout(() => {
        const $inp = $('#dialog-overlay input.dialog-input').focus();
        $inp.on('keydown', function(e) {
          if (e.key === 'Enter') { e.preventDefault(); $('#dialog-buttons button:first').click(); }
        });
      }, 50);

    } else if (meta.level === 'tables') {
      const $input = $('<input type="text" class="dialog-input">').attr('placeholder', 'table_name');
      const $body  = $('<div>').append(
        $('<div style="color:var(--nc-fg);margin-bottom:8px">').text('Create new table in ' + meta.db + ':'),
        $input
      );

      showDialog('F7 Create Table', $body[0].outerHTML, ['Create', 'Cancel'], async () => {
        const name = $('#dialog-overlay input.dialog-input').val().trim();
        if (!name) return;
        activeApiSide = state.active;
        try {
          await Api.createTable(meta.db, name);
          await loadTables(state.active, meta.db);
          // Move cursor to new table, then open Structure Editor
          const items = state.panels[state.active].data;
          const idx   = items.findIndex(i => i.name === name);
          if (idx >= 0) state.panels[state.active].cursor = idx;
          renderPanel(state.active);
          // Open Structure Editor so user can add columns immediately
          openStructEdit(meta.db, name, 'active');
        } catch(e) {
          showDialog('✗ Create Failed', `<span style="color:var(--nc-red)">${$('<div>').text(e.message).html()}</span>`, ['OK']);
        }
      });

      setTimeout(() => {
        const $inp = $('#dialog-overlay input.dialog-input').focus();
        $inp.on('keydown', function(e) {
          if (e.key === 'Enter') { e.preventDefault(); $('#dialog-buttons button:first').click(); }
        });
      }, 50);
    }
  }

  function doDrop() {
    const item = currentItem();
    const meta = panelMeta[state.active];
    if (!item || item.type === 'parent') return;

    // Database level – drop database
    if (meta.level === 'databases' && item.type === 'db') {
      const safeName = $('<div>').text(item.name).html();
      showDialog('⚠ Drop Database',
        `Drop database <span style="color:var(--nc-red);font-weight:bold">${safeName}</span>?<br><br>` +
        `<span style="color:var(--nc-yellow)">All tables and data will be permanently deleted!</span><br><br>` +
        `<span style="color:var(--nc-fg);font-size:11px">Will execute: <span style="color:var(--nc-cyan)">DROP DATABASE \`${safeName}\`</span></span>`,
        ['Drop!', 'Cancel'],
        async () => {
          activeApiSide = state.active;
          try {
            await Api.runSql('', `DROP DATABASE \`${item.name.replace(/`/g, '``')}\``);
            loadDatabases(state.active);
          } catch (err) {
            showDialog('Error', `<span style="color:var(--nc-red)">${$('<div>').text(err.message).html()}</span>`, ['OK']);
          }
        }
      );
      return;
    }

    // Table level
    if (!meta.db) return;
    if (item.type !== 'TABLE' && item.type !== 'VIEW') {
      showDialog('⚠ Drop', `<span style="color:var(--nc-yellow)">Only tables and views can be dropped here.</span>`, ['OK']);
      return;
    }

    const objType = item.type === 'VIEW' ? 'VIEW' : 'TABLE';
    const safeDb  = $('<div>').text(meta.db).html();
    const safeTbl = $('<div>').text(item.name).html();

    showDialog('⚠ Drop ' + objType,
      `Drop <span style="color:var(--nc-red);font-weight:bold">${safeDb}.${safeTbl}</span>?<br><br>` +
      `<span style="color:var(--nc-yellow)">This operation cannot be undone!</span><br><br>` +
      `<span style="color:var(--nc-fg);font-size:11px">Type will be executed: <span style="color:var(--nc-cyan)">DROP ${objType} ${safeDb}.${safeTbl}</span></span>`,
      ['Drop!', 'Cancel'],
      async () => {
        try {
          await Api.dropTable(meta.db, item.name, objType);
          // Refresh panel
          loadTables(state.active, meta.db);
        } catch (err) {
          showDialog('Error', `<span style="color:var(--nc-red)">${$('<div>').text(err.message).html()}</span>`, ['OK']);
        }
      }
    );
  }

  function doQuit() {
    showDialog('Quit DBCommander', 'Are you sure you want to quit?', ['Quit','Cancel']);
  }

  function showHelp() {
    showDialog('Keyboard Shortcuts',
      `<table style="color:var(--nc-fg);font-size:12px;border-spacing:4px 2px">
        <tr><td style="color:var(--nc-cyan)">Tab</td><td>Switch panel</td><td style="color:var(--nc-cyan)">Enter</td><td>Open / enter</td></tr>
        <tr><td style="color:var(--nc-cyan)">Insert/Space</td><td>Select item</td><td style="color:var(--nc-cyan)">Backspace</td><td>Go up</td></tr>
        <tr><td style="color:var(--nc-cyan)">F2</td><td>SQL Editor</td><td style="color:var(--nc-cyan)">F3</td><td>View table rows</td></tr>
        <tr><td style="color:var(--nc-cyan)">F4</td><td>Edit row</td><td style="color:var(--nc-cyan)">F5</td><td>Copy table</td></tr>
        <tr><td style="color:var(--nc-cyan)">F7</td><td>Create</td><td style="color:var(--nc-cyan)">F8</td><td>Drop</td></tr>
        <tr><td style="color:var(--nc-cyan)">F9</td><td>Export</td><td style="color:var(--nc-cyan)">F10</td><td>Quit</td></tr>
        <tr><td style="color:var(--nc-cyan)">: or Ctrl+P</td><td colspan="3">Focus SQL command line</td></tr>
        <tr><td style="color:var(--nc-cyan)">PgUp/PgDn</td><td colspan="3">Scroll list fast</td></tr>
      </table>`,
      ['OK']);
  }

  // ── DIALOG ─────────────────────────────────────────────────
  function showDialog(title, body, buttons, onConfirm) {
    $('#dialog-title').text(title);
    $('#dialog-body').html(body);
    const $btns = $('#dialog-buttons').empty();
    buttons.forEach((label, i) => {
      const $b = $('<button class="dialog-btn">').text(label);
      if (i > 0) $b.addClass('secondary');
      $b.on('click', function() {
        closeDialog();
        if (i === 0 && onConfirm) onConfirm();
      });
      $btns.append($b);
    });
    $('#dialog-overlay').addClass('visible');
    $btns.find('button').first().focus();
  }

  function closeDialog() {
    $('#dialog-overlay').removeClass('visible');
  }

  $(document).on('keydown', function(e) {
    if (e.key === 'Escape' && $('#dialog-overlay').hasClass('visible') && !$('#structedit').hasClass('visible')) closeDialog();
  });

  // ── CLOCK ──────────────────────────────────────────────────
  function updateClock() {
    const now = new Date();
    const h = String(now.getHours()).padStart(2,'0');
    const m = String(now.getMinutes()).padStart(2,'0');
    const s = String(now.getSeconds()).padStart(2,'0');
    $('#clock').text(`${h}:${m}:${s}`);
  }
  setInterval(updateClock, 1000);
  updateClock();

  if (window.__clientIp) $('#client-ip').text(window.__clientIp);

  // ── THEME ──────────────────────────────────────────────────
  function setTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme === 'dbc' ? 'dbc' : '');
    $('#check-nc').text(theme === 'nc'  ? '✓' : ' ');
    $('#check-dbc').text(theme === 'dbc' ? '✓' : ' ');
    try { localStorage.setItem('dbc-theme', theme); } catch(e) {}
  }

  try {
    const saved = localStorage.getItem('dbc-theme');
    if (saved) setTheme(saved);
  } catch(e) {}

  // ── DROPDOWN MENU ──────────────────────────────────────────
  let dropdownOpen = false;

  $('#menu-left-conn').on('click',  () => openConnPicker('left'));
  $('#menu-right-conn').on('click', () => openConnPicker('right'));

  // Panel title click handler
  $(document).on('click', '.panel-title', function() {
    const side = $(this).closest('.panel').attr('id').replace('panel-', '');
    openConnPicker(side);
  });

  $('#menu-options').on('click', function(e) {
    e.stopPropagation();
    dropdownOpen = !dropdownOpen;
    if (dropdownOpen) {
      const r = $('#menu-options')[0].getBoundingClientRect();
      $('#dropdown-options').css({ top: r.bottom + 'px', left: r.left + 'px' }).addClass('visible');
      $('#menu-options').addClass('active');
    } else {
      $('#dropdown-options').removeClass('visible');
      $('#menu-options').removeClass('active');
    }
  });

  $('#dropdown-options').on('click', '.menu-dd-item', function(e) {
    e.stopPropagation();
    const action = $(this).data('action');
    if (action === 'theme-nc')  setTheme('nc');
    if (action === 'theme-dbc') setTheme('dbc');
    dropdownOpen = false;
    $('#dropdown-options').removeClass('visible');
    $('#menu-options').removeClass('active');
  });

  $(document).on('click.dropdown', function() {
    if (dropdownOpen) {
      dropdownOpen = false;
      $('#dropdown-options').removeClass('visible');
      $('#menu-options').removeClass('active');
    }
  });

  // ── PANEL RESIZE ───────────────────────────────────────────
  let resizing   = false;
  let resizeX    = 0;
  let resizePct  = 50;

  $('#panel-resize').on('mousedown', function(e) {
    e.preventDefault();
    resizing  = true;
    resizeX   = e.clientX;
    const totalW = $('#panels').innerWidth() - 5; // 5 = handle width
    resizePct = ($('#panel-left').outerWidth() / totalW) * 100;
    $('#panel-resize').addClass('dragging');
  });

  $(document).on('mousemove.resize', function(e) {
    if (!resizing) return;
    const totalW = $('#panels').innerWidth() - 5;
    const delta  = e.clientX - resizeX;
    resizeX      = e.clientX;
    resizePct    = Math.max(15, Math.min(85, resizePct + (delta / totalW * 100)));
    $('#panel-left').css('flex',  `0 0 ${resizePct}%`);
    $('#panel-right').css('flex', `0 0 ${100 - resizePct}%`);
  });

  $(document).on('mouseup.resize', function() {
    if (resizing) {
      resizing = false;
      $('#panel-resize').removeClass('dragging');
      $('body').css('cursor', '');
    }
  });

  // ── CONNECTION PICKER ──────────────────────────────────────
  let cp = { connections: [], cursor: 0, targetSide: null };

  async function openConnPicker(side) {
    cp.targetSide = side;
    cp.cursor     = 0;
    $('#connpicker-list').html('<div style="padding:4px 12px;color:var(--nc-cyan)">Loading…</div>');
    $('#connpicker').addClass('visible');

    try {
      const data    = cp.connections.length ? { connections: cp.connections } : await Api.getConnections();
      cp.connections = data.connections;
      // Set cursor to the current connection
      const current = panelConnection[side];
      const idx     = cp.connections.findIndex(c => c.name === current);
      cp.cursor     = idx >= 0 ? idx : cp.connections.findIndex(c => c.default) || 0;
      cpRender();
    } catch(e) {
      $('#connpicker-list').html(`<div style="padding:4px 12px;color:var(--nc-red)">Error: ${e.message}</div>`);
    }
  }

  function cpRender() {
    const $list = $('#connpicker-list').empty();
    cp.connections.forEach((conn, i) => {
      $('<div>').addClass('conn-item').toggleClass('cursor', i === cp.cursor)
        .attr('data-ci', i)
        .append($('<span>').addClass('conn-item-name').text(conn.name))
        .append($('<span>').addClass('conn-item-host').text(`${conn.host}:${conn.port}`))
        .append($('<span>').addClass('conn-item-driver').text(conn.driver))
        .append(conn.default ? $('<span>').addClass('conn-item-default').text('★') : '')
        .appendTo($list);
    });
  }

  function cpSelect() {
    const conn = cp.connections[cp.cursor];
    if (!conn) return;
    panelConnection[cp.targetSide] = conn.name;
    $('#connpicker').removeClass('visible');
    loadDatabases(cp.targetSide);
  }

  function cpClose() {
    $('#connpicker').removeClass('visible');
  }

  $(document).on('click', '#connpicker-list .conn-item', function() {
    cp.cursor = parseInt($(this).attr('data-ci'));
    cpSelect();
  });

  $(document).on('keydown', function(e) {
    if (!$('#connpicker').hasClass('visible')) return;
    switch(e.key) {
      case 'ArrowUp':   e.preventDefault(); cp.cursor = Math.max(0, cp.cursor - 1); cpRender(); break;
      case 'ArrowDown': e.preventDefault(); cp.cursor = Math.min(cp.connections.length - 1, cp.cursor + 1); cpRender(); break;
      case 'Enter':     e.preventDefault(); cpSelect(); break;
      case 'Escape':    e.preventDefault(); cpClose(); break;
    }
  });

  // ── INIT ───────────────────────────────────────────────────
  // ── Browser back button → ESC ────────────────────────────
  // Push a dummy state on load so the back button has somewhere to go.
  // When popstate fires (back pressed), simulate ESC to close the topmost layer.
  history.pushState({ dbcommander: true }, '');

  window.addEventListener('popstate', function () {
    // Re-push so the back button is always available
    history.pushState({ dbcommander: true }, '');
    // Simulate ESC on the document
    $(document).trigger($.Event('keydown', { key: 'Escape', keyCode: 27, which: 27, bubbles: true }));
  });

  // ── Init ─────────────────────────────────────────────────
  (async function init() {
    try {
      const data = await Api.getConnections();
      cp.connections = data.connections;

      // Default connection for both panels
      panelConnection.left  = data.default;
      panelConnection.right = data.default;

      // If only 1 connection, auto-connect
      // If multiple, picker only on Ctrl+open or panel title click
      loadDatabases('left');
      loadDatabases('right');
    } catch(e) {
      setError('left',  e.message);
      setError('right', e.message);
    }
  })();
});