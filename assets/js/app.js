/**
 * Website Migrator v1.0.0 — Real Backend Controller for Bolt UI
 * Crafted by @kazuhamoe
 */

(function () {
  'use strict';

  // ---- Mobile Menu ----
  const menuBtn = document.getElementById('mobileMenuBtn');
  const mobileNav = document.getElementById('mobileNav');
  if (menuBtn && mobileNav) {
    menuBtn.addEventListener('click', function () {
      mobileNav.classList.toggle('open');
    });
  }

  // ---- Password Toggle ----
  document.querySelectorAll('.toggle-btn[data-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      const targetId = btn.getAttribute('data-toggle');
      const input = document.getElementById(targetId);
      if (!input) return;
      const eyeShow = btn.querySelector('.eye-show');
      const eyeHide = btn.querySelector('.eye-hide');
      if (input.type === 'password') {
        input.type = 'text';
        if (eyeShow) eyeShow.style.display = 'none';
        if (eyeHide) eyeHide.style.display = 'block';
      } else {
        input.type = 'password';
        if (eyeShow) eyeShow.style.display = 'block';
        if (eyeHide) eyeHide.style.display = 'none';
      }
    });
  });

  // ---- Toast Notification System ----
  window.showToast = function (type, title, message) {
    const container = document.getElementById('toastContainer');
    if (!container) return;

    const toast = document.createElement('div');
    toast.className = `toast ${type}`;

    let iconSvg = '';
    if (type === 'success') {
      iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
    } else if (type === 'error') {
      iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>';
    } else {
      iconSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
    }

    toast.innerHTML = `
      <div class="toast-icon ${type}">${iconSvg}</div>
      <div class="toast-content">
        <div class="toast-title">${escapeHtml(title)}</div>
        <div class="toast-message">${escapeHtml(message)}</div>
      </div>
      <button type="button" class="toast-close" aria-label="Close">&times;</button>
    `;

    toast.querySelector('.toast-close').addEventListener('click', () => {
      toast.remove();
    });

    container.appendChild(toast);

    setTimeout(() => {
      if (toast.parentNode) toast.remove();
    }, 5000);
  };

  function escapeHtml(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.innerText = str;
    return div.innerHTML;
  }

  function addTerminalLine(containerId, tag, text, tagClass = 'ready') {
    const container = document.getElementById(containerId);
    if (!container) return;
    const lines = container.querySelectorAll('.terminal-line').length + 1;
    const lnum = lines < 10 ? '0' + lines : lines;

    const line = document.createElement('div');
    line.className = 'terminal-line';
    line.innerHTML = `<span class="lnum">${lnum}</span><span class="tag ${tagClass}">[${escapeHtml(tag)}]</span><span class="text"> ${escapeHtml(text)}</span>`;
    container.appendChild(line);
    container.scrollTop = container.scrollHeight;
  }

  function logEvent(tag, text, tagClass = 'ready') {
    addTerminalLine('mainTerminalBody', tag, text, tagClass);
    addTerminalLine('exportTerminalBody', tag, text, tagClass);
    addTerminalLine('importTerminalBody', tag, text, tagClass);
  }

  // Clear & Copy Terminal Controls
  const btnClearTerminal = document.getElementById('btnClearTerminal');
  if (btnClearTerminal) {
    btnClearTerminal.addEventListener('click', () => {
      const body = document.getElementById('mainTerminalBody');
      if (body) {
        body.innerHTML = '<div class="terminal-line"><span class="lnum">01</span><span class="tag ready">[Ready]</span><span class="text"> Console log reset. Realtime monitoring active.</span></div>';
        showToast('info', 'Console Cleared', 'Terminal activity history has been reset.');
      }
    });
  }

  const btnCopyTerminal = document.getElementById('btnCopyTerminal');
  if (btnCopyTerminal) {
    btnCopyTerminal.addEventListener('click', () => {
      const body = document.getElementById('mainTerminalBody');
      if (!body) return;
      const lines = Array.from(body.querySelectorAll('.terminal-line')).map(l => l.innerText.trim()).join('\n');
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(lines).then(() => {
          showToast('success', 'Logs Copied', 'Terminal log contents copied to clipboard.');
        }).catch(() => {
          showToast('info', 'Copy', 'Please select text manually to copy.');
        });
      } else {
        showToast('info', 'Copy', 'Please select text manually to copy.');
      }
    });
  }

  // Real-time Settings Listeners
  const excludeMedia = document.getElementById('excludeMedia');
  if (excludeMedia) {
    excludeMedia.addEventListener('change', (e) => {
      logEvent('Config', `Media files exclusion (wp-content/uploads): ${e.target.checked ? 'ENABLED' : 'DISABLED'}`, 'config');
    });
  }

  // =========================================================
  // EXPORT SCOPE & DIRECTORY PICKER CONTROLS
  // =========================================================
  const scopeCards = document.querySelectorAll('.scope-card');
  const sectionSourceDb = document.getElementById('sectionSourceDb');
  const sectionSourceDir = document.getElementById('sectionSourceDir');
  const dbDimmedBadge = document.getElementById('dbDimmedBadge');
  const btnTestExportDb = document.getElementById('btnTestExportDb');
  const exportSourceDir = document.getElementById('exportSourceDir');
  const btnPresetCurrent = document.getElementById('btnPresetCurrent');
  const btnPresetParent = document.getElementById('btnPresetParent');
  const selectSiblingFolder = document.getElementById('selectSiblingFolder');

  // Scope Selector (Full vs Files Only vs DB Only)
  scopeCards.forEach(card => {
    card.addEventListener('click', () => {
      scopeCards.forEach(c => c.classList.remove('active'));
      card.classList.add('active');
      const radio = card.querySelector('input[name="exportScope"]');
      if (radio) radio.checked = true;

      const scope = radio ? radio.value : 'full';
      if (scope === 'files_only') {
        if (sectionSourceDb) sectionSourceDb.classList.add('dimmed-section');
        if (sectionSourceDir) sectionSourceDir.classList.remove('dimmed-section');
        if (dbDimmedBadge) dbDimmedBadge.style.display = 'inline-flex';
        if (btnTestExportDb) btnTestExportDb.disabled = true;
        logEvent('Scope', 'Mode: Hanya Berkas Website (Tanpa DB) dipilih. Backup database MySQL akan dilewati.', 'config');
        showToast('info', 'Mode Tanpa DB', 'Paket migrasi hanya akan berisi berkas website tanpa database.');
      } else if (scope === 'db_only') {
        if (sectionSourceDb) sectionSourceDb.classList.remove('dimmed-section');
        if (sectionSourceDir) sectionSourceDir.classList.add('dimmed-section');
        if (dbDimmedBadge) dbDimmedBadge.style.display = 'none';
        if (btnTestExportDb) btnTestExportDb.disabled = false;
        logEvent('Scope', 'Mode: Hanya Database (.SQL Saja) dipilih. Pengompresan file website dilewati.', 'config');
        showToast('info', 'Mode SQL Saja', 'Ekspor hanya akan menghasilkan berkas dump MySQL (.sql).');
      } else {
        if (sectionSourceDb) sectionSourceDb.classList.remove('dimmed-section');
        if (sectionSourceDir) sectionSourceDir.classList.remove('dimmed-section');
        if (dbDimmedBadge) dbDimmedBadge.style.display = 'none';
        if (btnTestExportDb) btnTestExportDb.disabled = false;
        logEvent('Scope', 'Mode: Paket Lengkap (File + Database) dipilih.', 'config');
      }
    });
  });

  // Real-time Directory Probe & AutoDetector
  async function detectDirectory(path) {
    const cleanPath = path ? path.trim() : '';
    if (!cleanPath) return;

    const detectedPathText = document.getElementById('detectedPathText');
    const detectedCmsName = document.getElementById('detectedCmsName');
    if (detectedPathText) detectedPathText.textContent = cleanPath;

    logEvent('Scan', `Memindai direktori: ${cleanPath}...`, 'scan');

    try {
      const fd = new FormData();
      fd.append('action', 'detect_dir');
      fd.append('path', cleanPath);

      const apiEndpoint = location.pathname.endsWith('import.php') ? 'import.php' : 'index.php';
      const res = await fetch(apiEndpoint, { method: 'POST', body: fd });
      const json = await res.json();

      if (json.success) {
        if (detectedCmsName) detectedCmsName.textContent = json.name;
        if (json.detected && json.credentials) {
          // Export DB inputs
          const exportDbHost = document.getElementById('exportDbHost');
          const exportDbName = document.getElementById('exportDbName');
          const exportDbUser = document.getElementById('exportDbUser');
          const exportDbPass = document.getElementById('exportDbPass');
          if (exportDbHost && json.credentials.host) exportDbHost.value = json.credentials.host;
          if (exportDbName && json.credentials.database) exportDbName.value = json.credentials.database;
          if (exportDbUser && json.credentials.username) exportDbUser.value = json.credentials.username;
          if (exportDbPass && json.credentials.password !== undefined) exportDbPass.value = json.credentials.password;

          // Import DB inputs (if user is targeting an existing CMS or wants suggestion)
          const importDbHost = document.getElementById('importDbHost');
          const importDbName = document.getElementById('importDbName');
          const importDbUser = document.getElementById('importDbUser');
          const importDbPass = document.getElementById('importDbPass');
          if (importDbHost && json.credentials.host && !importDbName.value) importDbHost.value = json.credentials.host;
          if (importDbName && json.credentials.database && !importDbName.value) importDbName.value = json.credentials.database;
          if (importDbUser && json.credentials.username && !importDbUser.value) importDbUser.value = json.credentials.username;
          if (importDbPass && json.credentials.password !== undefined && !importDbPass.value) importDbPass.value = json.credentials.password;

          logEvent('Detector', `${json.name} terdeteksi! Kredensial database dimuat.`, 'ready');
          showToast('success', `${json.name} Terdeteksi`, 'Kredensial database berhasil dimuat dari konfigurasi.');
        } else {
          logEvent('Detector', `Direktori siap (${json.name}).`, 'info');
        }
      }
    } catch (e) {
      logEvent('Warning', `Pemeriksaan direktori: ${e.message}`, 'warning');
    }
  }

  window.detectDirectory = detectDirectory;

  // Source Folder Presets & Handlers (index.php)
  window.handlePresetClick = function (path, btn) {
    if (!path) return;
    document.querySelectorAll('#sectionSourceDir .btn-preset').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    if (exportSourceDir) {
      exportSourceDir.value = path;
      exportSourceDir.style.borderColor = 'var(--accent)';
      setTimeout(() => { exportSourceDir.style.borderColor = ''; }, 800);
    }
    const pathText = document.getElementById('detectedPathText');
    if (pathText) pathText.textContent = path;
    logEvent('Source', `Direktori sumber diatur ke: ${path}`, 'config');
    detectDirectory(path);
  };

  window.handleSiblingFolderChange = function (val) {
    if (!val) return;
    document.querySelectorAll('#sectionSourceDir .btn-preset').forEach(b => b.classList.remove('active'));
    if (exportSourceDir) {
      exportSourceDir.value = val;
      exportSourceDir.style.borderColor = 'var(--accent)';
      setTimeout(() => { exportSourceDir.style.borderColor = ''; }, 800);
    }
    const pathText = document.getElementById('detectedPathText');
    if (pathText) pathText.textContent = val;
    logEvent('Source', `Folder sumber dipilih dari server: ${val}`, 'config');
    detectDirectory(val);
  };

  // Target Folder Presets & Handlers (import.php)
  const importTargetDir = document.getElementById('importTargetDir');
  const selectTargetSubfolder = document.getElementById('selectTargetSubfolder');

  window.handleImportPresetClick = function (path, btn) {
    if (!path) return;
    document.querySelectorAll('#sectionTargetDir .btn-preset').forEach(b => b.classList.remove('active'));
    if (btn) btn.classList.add('active');
    if (importTargetDir) {
      importTargetDir.value = path;
      importTargetDir.style.borderColor = 'var(--accent)';
      setTimeout(() => { importTargetDir.style.borderColor = ''; }, 800);
    }
    logEvent('Target', `Direktori target instalasi diatur ke: ${path}`, 'config');
    detectDirectory(path);
  };

  window.handleTargetSubfolderChange = function (val) {
    if (!val) return;
    document.querySelectorAll('#sectionTargetDir .btn-preset').forEach(b => b.classList.remove('active'));
    if (importTargetDir) {
      importTargetDir.value = val;
      importTargetDir.style.borderColor = 'var(--accent)';
      setTimeout(() => { importTargetDir.style.borderColor = ''; }, 800);
    }
    logEvent('Target', `Folder target/subdomain dipilih: ${val}`, 'config');
    detectDirectory(val);
  };

  if (selectTargetSubfolder) {
    selectTargetSubfolder.addEventListener('change', (e) => {
      window.handleTargetSubfolderChange(e.target.value);
    });
    selectTargetSubfolder.addEventListener('input', (e) => {
      window.handleTargetSubfolderChange(e.target.value);
    });
  }

  if (importTargetDir) {
    importTargetDir.addEventListener('change', () => {
      detectDirectory(importTargetDir.value);
    });
  }

  // Directory Presets
  if (btnPresetCurrent) {
    btnPresetCurrent.addEventListener('click', () => {
      window.handlePresetClick(btnPresetCurrent.dataset.path, btnPresetCurrent);
    });
  }

  if (btnPresetParent) {
    btnPresetParent.addEventListener('click', () => {
      window.handlePresetClick(btnPresetParent.dataset.path, btnPresetParent);
    });
  }

  if (selectSiblingFolder) {
    selectSiblingFolder.addEventListener('change', (e) => {
      window.handleSiblingFolderChange(e.target.value);
    });
    selectSiblingFolder.addEventListener('input', (e) => {
      window.handleSiblingFolderChange(e.target.value);
    });
  }

  if (exportSourceDir) {
    exportSourceDir.addEventListener('change', () => {
      detectDirectory(exportSourceDir.value);
    });
  }

  // Folder Browser Modal
  const folderBrowserModal = document.getElementById('folderBrowserModal');
  const btnOpenFolderBrowser = document.getElementById('btnOpenFolderBrowser');
  const btnOpenFolderBrowserImport = document.getElementById('btnOpenFolderBrowserImport');
  const btnCloseFolderBrowser = document.getElementById('btnCloseFolderBrowser');
  const btnCancelFolderBrowser = document.getElementById('btnCancelFolderBrowser');
  const btnBrowserGoParent = document.getElementById('btnBrowserGoParent');
  const btnSelectCurrentFolder = document.getElementById('btnSelectCurrentFolder');
  const browserCurrentPathEl = document.getElementById('browserCurrentPath');
  const browserFolderList = document.getElementById('browserFolderList');

  let browserCurrentPath = '';
  let browserParentPath = null;
  let selectedFolderPath = '';
  let activeBrowserTarget = 'export';

  async function openFolderBrowser(initialPath, targetType = 'export') {
    activeBrowserTarget = targetType;
    if (folderBrowserModal) folderBrowserModal.style.display = 'flex';
    const fallback = targetType === 'import'
      ? (importTargetDir?.value || '')
      : (exportSourceDir?.value || '');
    await loadBrowserPath(initialPath || fallback);
  }

  function closeFolderBrowser() {
    if (folderBrowserModal) folderBrowserModal.style.display = 'none';
  }

  async function loadBrowserPath(targetPath) {
    if (!browserFolderList) return;
    browserFolderList.innerHTML = '<div style="text-align:center;padding:24px;color:var(--text-muted);font-size:13px;">Memuat direktori server...</div>';

    try {
      const fd = new FormData();
      fd.append('action', 'list_dirs');
      fd.append('path', targetPath);

      const apiEndpoint = location.pathname.endsWith('import.php') ? 'import.php' : 'index.php';
      const res = await fetch(apiEndpoint, { method: 'POST', body: fd });
      const json = await res.json();

      if (!json.success) throw new Error(json.message || 'Gagal memuat direktori.');

      browserCurrentPath = json.current_path;
      browserParentPath = json.parent_path;
      selectedFolderPath = browserCurrentPath;

      if (browserCurrentPathEl) browserCurrentPathEl.textContent = browserCurrentPath;
      if (btnBrowserGoParent) btnBrowserGoParent.disabled = !browserParentPath;

      if (!json.folders || json.folders.length === 0) {
        browserFolderList.innerHTML = '<div style="text-align:center;padding:24px;color:var(--text-muted);font-size:13px;">Tidak ada subfolder di direktori ini.</div>';
        return;
      }

      browserFolderList.innerHTML = '';
      json.folders.forEach(f => {
        const item = document.createElement('div');
        item.className = 'folder-item';
        item.innerHTML = `
          <div class="folder-item-left">
            <span style="font-size:18px;">📁</span>
            <span class="folder-item-name">${escapeHtml(f.name)}</span>
          </div>
          <div>
            <span class="folder-item-tag ${f.tag_class}">${escapeHtml(f.tag)}</span>
          </div>
        `;

        item.addEventListener('click', () => {
          document.querySelectorAll('.folder-item').forEach(i => i.classList.remove('selected'));
          item.classList.add('selected');
          selectedFolderPath = f.path;
        });

        item.addEventListener('dblclick', () => {
          loadBrowserPath(f.path);
        });

        browserFolderList.appendChild(item);
      });
    } catch (err) {
      browserFolderList.innerHTML = `<div style="color:var(--error);padding:16px;">Gagal memuat: ${escapeHtml(err.message)}</div>`;
    }
  }

  if (btnOpenFolderBrowser) {
    btnOpenFolderBrowser.addEventListener('click', () => {
      openFolderBrowser(exportSourceDir?.value, 'export');
    });
  }
  if (btnOpenFolderBrowserImport) {
    btnOpenFolderBrowserImport.addEventListener('click', () => {
      openFolderBrowser(importTargetDir?.value, 'import');
    });
  }
  if (btnCloseFolderBrowser) btnCloseFolderBrowser.addEventListener('click', closeFolderBrowser);
  if (btnCancelFolderBrowser) btnCancelFolderBrowser.addEventListener('click', closeFolderBrowser);
  if (btnBrowserGoParent) {
    btnBrowserGoParent.addEventListener('click', () => {
      if (browserParentPath) loadBrowserPath(browserParentPath);
    });
  }
  if (btnSelectCurrentFolder) {
    btnSelectCurrentFolder.addEventListener('click', () => {
      const chosen = selectedFolderPath || browserCurrentPath;
      if (chosen) {
        if (activeBrowserTarget === 'import' && importTargetDir) {
          importTargetDir.value = chosen;
          document.querySelectorAll('#sectionTargetDir .btn-preset').forEach(b => b.classList.remove('active'));
          detectDirectory(chosen);
        } else if (exportSourceDir) {
          exportSourceDir.value = chosen;
          document.querySelectorAll('#sectionSourceDir .btn-preset').forEach(b => b.classList.remove('active'));
          detectDirectory(chosen);
        }
      }
      closeFolderBrowser();
    });
  }

  // =========================================================
  // EXPORT ENGINE (index.php)
  // =========================================================
  const btnStartExportReal = document.getElementById('btnStartExportReal');

  if (btnTestExportDb) {
    btnTestExportDb.addEventListener('click', async () => {
      btnTestExportDb.disabled = true;
      btnTestExportDb.innerHTML = 'Connecting...';
      showToast('info', 'Testing Connection', 'Connecting to source MySQL database...');

      const host = document.getElementById('exportDbHost')?.value || 'localhost';
      const port = document.getElementById('exportDbPort')?.value || '3306';
      const database = document.getElementById('exportDbName')?.value || '';
      const username = document.getElementById('exportDbUser')?.value || '';

      logEvent('Database', `Testing MySQL handshake to ${host}:${port} (db: '${database}', user: '${username}')...`, 'database');

      try {
        const fd = new FormData();
        fd.append('action', 'test_db');
        fd.append('host', host);
        fd.append('port', port);
        fd.append('database', database);
        fd.append('username', username);
        fd.append('password', document.getElementById('exportDbPass')?.value || '');

        const res = await fetch('index.php', { method: 'POST', body: fd });
        const json = await res.json();
        if (json.success) {
          showToast('success', 'Connected', json.message);
          logEvent('Database', `MySQL Connection Verified: ${json.message}`, 'ready');
        } else {
          showToast('error', 'Connection Failed', json.message);
          logEvent('Error', `MySQL Connection Failed: ${json.message}`, 'error');
        }
      } catch (err) {
        showToast('error', 'Network Error', err.message);
        logEvent('Error', `Network / Server Error: ${err.message}`, 'error');
      } finally {
        btnTestExportDb.disabled = false;
        btnTestExportDb.innerHTML = `
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
          </svg> Test Connection`;
      }
    });
  }

  if (btnStartExportReal) {
    btnStartExportReal.addEventListener('click', async () => {
      const scope = document.querySelector('input[name="exportScope"]:checked')?.value || 'full';
      const sourceDirPath = document.getElementById('exportSourceDir')?.value?.trim() || '';

      if (scope !== 'db_only' && !sourceDirPath) {
        showToast('error', 'Validasi Gagal', 'Harap pilih direktori website sumber terlebih dahulu.');
        return;
      }

      const confirmMsg = scope === 'files_only'
        ? 'Mulai memaketkan berkas website (tanpa database)?'
        : (scope === 'db_only' ? 'Mulai mengekspor database MySQL saja?' : 'Mulai membuat paket migrasi lengkap (file + database)?');

      if (!confirm(confirmMsg)) return;

      logEvent('Package', `Memulai alur ekspor [Mode: ${scope}]...`, 'package');

      const configEl = document.getElementById('exportConfig');
      const progressEl = document.getElementById('exportProgress');
      const completeEl = document.getElementById('exportComplete');

      if (configEl) configEl.style.display = 'none';
      if (progressEl) progressEl.style.display = 'block';

      showToast('info', 'Proses Dimulai', 'Memproses paket migrasi...');

      const percentEl = document.getElementById('exportPercent');
      const textEl = document.getElementById('exportProgressText');
      const subEl = document.getElementById('exportProgressSub');
      const barEl = document.getElementById('exportProgressBar');
      const stepEls = document.querySelectorAll('#exportPipelineSteps .pipeline-step');
      const connectorEls = document.querySelectorAll('#exportPipelineSteps .pipeline-connector');

      function updatePipeline(activeStepIdx, percent, text, sub) {
        if (percentEl) percentEl.textContent = `${percent}%`;
        if (textEl) textEl.textContent = text;
        if (subEl) subEl.textContent = sub;
        if (barEl) barEl.style.width = `${percent}%`;

        stepEls.forEach((el, idx) => {
          el.classList.remove('done', 'active');
          if (idx < activeStepIdx) {
            el.classList.add('done');
            el.querySelector('.pipeline-step-circle').innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
          } else if (idx === activeStepIdx) {
            el.classList.add('active');
            el.querySelector('.pipeline-step-circle').innerHTML = '<div class="inner"></div>';
          }
        });

        connectorEls.forEach((el, idx) => {
          if (idx < activeStepIdx) {
            el.classList.add('done');
          } else {
            el.classList.remove('done');
          }
        });
      }

      try {
        // Step 1: Export Database (jika bukan files_only)
        if (scope !== 'files_only') {
          updatePipeline(0, 20, 'Streaming export database MySQL...', 'Mengekspor tabel & baris via PDO');
          logEvent('Database', 'Streaming MySQL database export via PDO to migrator_database.sql...', 'database');

          const dbRes = await fetch('index.php', {
            method: 'POST',
            body: new URLSearchParams({
              action: 'export_database',
              host: document.getElementById('exportDbHost').value,
              port: document.getElementById('exportDbPort').value,
              database: document.getElementById('exportDbName').value,
              username: document.getElementById('exportDbUser').value,
              password: document.getElementById('exportDbPass').value,
            })
          });

          const dbJson = await dbRes.json();
          if (!dbJson.success) throw new Error(dbJson.message || 'Database export failed');
          logEvent('Database', `Database dump sukses: ${dbJson.tables} tabel, ${dbJson.rows} baris diekspor.`, 'database');
        } else {
          logEvent('Database', 'Mode Hanya Berkas Website aktif: Tahap database dilewati.', 'info');
        }

        // Step 2: Create ZIP Archive (jika bukan db_only)
        if (scope !== 'db_only') {
          updatePipeline(1, 55, 'Mengompresi berkas website ke ZIP...', 'Menyatukan berkas dan menyaring exclude');
          logEvent('Package', `Mengompresi direktori "${sourceDirPath}" ke migrator_package.zip...`, 'package');

          const zipRes = await fetch('index.php', {
            method: 'POST',
            body: new URLSearchParams({
              action: 'create_zip',
              source_dir: sourceDirPath,
              include_db: scope === 'full' ? '1' : '0',
              exclude_media: document.getElementById('excludeMedia')?.checked ? '1' : '0',
            })
          });

          const zipJson = await zipRes.json();
          if (!zipJson.success) throw new Error(zipJson.message || 'Kompresi ZIP gagal.');
          logEvent('Package', `Kompresi selesai: ${zipJson.total_files} berkas dipaketkan (${(zipJson.file_size / 1024 / 1024).toFixed(2)} MB).`, 'package');

          const zipMeta = document.getElementById('packageZipMeta');
          if (zipMeta) {
            zipMeta.textContent = `${(zipJson.file_size / 1024 / 1024).toFixed(2)} MB • ${scope === 'files_only' ? 'Files Only (Tanpa DB)' : 'Full Package (File + DB)'}`;
          }
        } else {
          logEvent('Package', 'Mode Hanya Database aktif: Pengompresan file website dilewati.', 'info');
        }

        // Step 3: Complete
        updatePipeline(3, 100, 'Paket migrasi siap diunduh!', 'Finalisasi paket migrasi berhasil');
        logEvent('Complete', 'Paket migrasi berhasil dibuat dan siap diunduh!', 'ready');

        setTimeout(() => {
          if (progressEl) progressEl.style.display = 'none';
          if (completeEl) completeEl.style.display = 'block';
          showToast('success', 'Paket Siap', 'Website Anda berhasil dipaketkan.');
        }, 1000);

      } catch (err) {
        logEvent('Error', `Build Error: ${err.message}`, 'error');
        showToast('error', 'Ekspor Gagal', err.message);
        alert('Gagal mengekspor: ' + err.message);
      }
    });
  }

  // =========================================================
  // IMPORT ENGINE (import.php)
  // =========================================================
  const dropzoneReal = document.getElementById('dropzoneReal');
  const realFileInput = document.getElementById('realFileInput');
  const btnTestImportDb = document.getElementById('btnTestImportDb');
  const btnStartImportReal = document.getElementById('btnStartImportReal');
  const btnSelfDestructReal = document.getElementById('btnSelfDestructReal');

  // URL Preview live synchronization
  const importOldUrl = document.getElementById('importOldUrl');
  const importNewUrl = document.getElementById('importNewUrl');
  const urlPreviewOld = document.getElementById('urlPreviewOld');
  const urlPreviewNew = document.getElementById('urlPreviewNew');

  if (importOldUrl && urlPreviewOld) {
    importOldUrl.addEventListener('input', () => {
      urlPreviewOld.textContent = importOldUrl.value.trim() || 'old-domain.com';
    });
  }
  if (importNewUrl && urlPreviewNew) {
    importNewUrl.addEventListener('input', () => {
      urlPreviewNew.textContent = importNewUrl.value.trim() || 'new-domain.com';
    });
  }

  // Real Drag and Drop Upload
  if (dropzoneReal && realFileInput) {
    ['dragenter', 'dragover'].forEach(name => {
      dropzoneReal.addEventListener(name, (e) => {
        e.preventDefault();
        dropzoneReal.classList.add('dragover');
      });
    });

    ['dragleave', 'drop'].forEach(name => {
      dropzoneReal.addEventListener(name, (e) => {
        e.preventDefault();
        dropzoneReal.classList.remove('dragover');
      });
    });

    dropzoneReal.addEventListener('drop', (e) => {
      const files = e.dataTransfer.files;
      if (files.length > 0) handleRealUpload(files[0]);
    });

    realFileInput.addEventListener('change', () => {
      if (realFileInput.files.length > 0) handleRealUpload(realFileInput.files[0]);
    });
  }

  async function handleRealUpload(file) {
    const uploadingEl = document.getElementById('uploadUploading');
    const uploadedEl = document.getElementById('uploadUploaded');
    const nameEl = document.getElementById('uploadingFileName');
    const metaEl = document.getElementById('uploadingFileMeta');

    if (dropzoneReal) dropzoneReal.style.display = 'none';
    if (uploadingEl) uploadingEl.style.display = 'block';
    if (uploadedEl) uploadedEl.style.display = 'none';

    if (nameEl) nameEl.textContent = file.name;
    if (metaEl) metaEl.textContent = `Uploading ${(file.size / 1024 / 1024).toFixed(2)} MB...`;

    showToast('info', 'Upload Started', `Uploading ${file.name}...`);

    const fd = new FormData();
    fd.append('action', 'upload_file');
    fd.append('package_file', file);

    try {
      const res = await fetch('import.php', { method: 'POST', body: fd });
      const json = await res.json();
      if (!json.success) throw new Error(json.message);

      if (uploadingEl) uploadingEl.style.display = 'none';
      if (uploadedEl) uploadedEl.style.display = 'block';

      const upName = document.getElementById('uploadedFileName');
      const upMeta = document.getElementById('uploadedFileMeta');
      if (upName) upName.textContent = file.name;
      if (upMeta) upMeta.textContent = `${json.formatted_size} • Uploaded successfully`;

      showToast('success', 'Upload Complete', `${file.name} uploaded successfully.`);
    } catch (e) {
      if (uploadingEl) uploadingEl.style.display = 'none';
      if (dropzoneReal) dropzoneReal.style.display = 'block';
      showToast('error', 'Upload Failed', e.message);
      alert('Upload error: ' + e.message);
    }
  }

  window.resetUpload = function () {
    if (dropzoneReal) dropzoneReal.style.display = 'block';
    const uploadingEl = document.getElementById('uploadUploading');
    const uploadedEl = document.getElementById('uploadUploaded');
    if (uploadingEl) uploadingEl.style.display = 'none';
    if (uploadedEl) uploadedEl.style.display = 'none';
    if (realFileInput) realFileInput.value = '';
  };

  // Test Target DB
  if (btnTestImportDb) {
    btnTestImportDb.addEventListener('click', async () => {
      btnTestImportDb.disabled = true;
      btnTestImportDb.innerHTML = 'Connecting...';
      showToast('info', 'Testing Connection', 'Connecting to target MySQL database...');

      try {
        const fd = new FormData();
        fd.append('action', 'test_db');
        fd.append('host', document.getElementById('importDbHost').value);
        fd.append('port', document.getElementById('importDbPort').value);
        fd.append('database', document.getElementById('importDbName').value);
        fd.append('username', document.getElementById('importDbUser').value);
        fd.append('password', document.getElementById('importDbPass').value);

        const res = await fetch('import.php', { method: 'POST', body: fd });
        const json = await res.json();
        if (json.success) {
          showToast('success', 'Connected', json.message);
        } else {
          showToast('error', 'Connection Failed', json.message);
        }
      } catch (err) {
        showToast('error', 'Network Error', err.message);
      } finally {
        btnTestImportDb.disabled = false;
        btnTestImportDb.innerHTML = `
          <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
          </svg> Test Connection`;
      }
    });
  }

  // =========================================================
  // IMPORT SCOPE SELECTOR & TARGET CONTROLS (import.php)
  // =========================================================
  const importScopeCards = document.querySelectorAll('.import-scope-card');
  const sectionTargetDir = document.getElementById('sectionTargetDir');
  const sectionTargetDb = document.getElementById('sectionTargetDb');
  const sectionDomainReplace = document.getElementById('sectionDomainReplace');
  const targetDirDimmedBadge = document.getElementById('targetDirDimmedBadge');
  const importDbDimmedBadge = document.getElementById('importDbDimmedBadge');
  const urlReplaceDimmedBadge = document.getElementById('urlReplaceDimmedBadge');

  importScopeCards.forEach(card => {
    card.addEventListener('click', () => {
      importScopeCards.forEach(c => c.classList.remove('active'));
      card.classList.add('active');
      const radio = card.querySelector('input[name="importScope"]');
      if (radio) radio.checked = true;

      const scope = radio ? radio.value : 'full';
      if (scope === 'files_only') {
        if (sectionTargetDir) sectionTargetDir.classList.remove('dimmed-section');
        if (targetDirDimmedBadge) targetDirDimmedBadge.style.display = 'none';

        if (sectionTargetDb) sectionTargetDb.classList.add('dimmed-section');
        if (importDbDimmedBadge) importDbDimmedBadge.style.display = 'inline-flex';
        if (btnTestImportDb) btnTestImportDb.disabled = true;

        if (sectionDomainReplace) sectionDomainReplace.classList.add('dimmed-section');
        if (urlReplaceDimmedBadge) urlReplaceDimmedBadge.style.display = 'inline-flex';

        logEvent('Scope', 'Mode Impor: Hanya Berkas Website (Tanpa Database) dipilih. Database & Ganti URL dilewati.', 'config');
        showToast('info', 'Mode Tanpa DB', 'Hanya berkas website yang akan diekstrak ke direktori target.');
      } else if (scope === 'db_only') {
        if (sectionTargetDir) sectionTargetDir.classList.add('dimmed-section');
        if (targetDirDimmedBadge) targetDirDimmedBadge.style.display = 'inline-flex';

        if (sectionTargetDb) sectionTargetDb.classList.remove('dimmed-section');
        if (importDbDimmedBadge) importDbDimmedBadge.style.display = 'none';
        if (btnTestImportDb) btnTestImportDb.disabled = false;

        if (sectionDomainReplace) sectionDomainReplace.classList.remove('dimmed-section');
        if (urlReplaceDimmedBadge) urlReplaceDimmedBadge.style.display = 'none';

        logEvent('Scope', 'Mode Impor: Hanya Database (.SQL Saja) dipilih. Ekstraksi berkas dilewati, hanya MySQL yang direstore.', 'config');
        showToast('info', 'Mode SQL Saja', 'Hanya database yang akan diimpor ke MySQL.');
      } else {
        if (sectionTargetDir) sectionTargetDir.classList.remove('dimmed-section');
        if (targetDirDimmedBadge) targetDirDimmedBadge.style.display = 'none';

        if (sectionTargetDb) sectionTargetDb.classList.remove('dimmed-section');
        if (importDbDimmedBadge) importDbDimmedBadge.style.display = 'none';
        if (btnTestImportDb) btnTestImportDb.disabled = false;

        if (sectionDomainReplace) sectionDomainReplace.classList.remove('dimmed-section');
        if (urlReplaceDimmedBadge) urlReplaceDimmedBadge.style.display = 'none';

        logEvent('Scope', 'Mode Impor: Restore Lengkap (File + Database + Ganti URL) dipilih.', 'config');
      }
    });
  });

  // Start Real Import & Restoration
  if (btnStartImportReal) {
    btnStartImportReal.addEventListener('click', async () => {
      const scopeRadio = document.querySelector('input[name="importScope"]:checked');
      const scope = scopeRadio ? scopeRadio.value : 'full';

      const targetDirInput = document.getElementById('importTargetDir');
      const targetDir = targetDirInput ? targetDirInput.value.trim() : '';

      const host = document.getElementById('importDbHost')?.value?.trim() || 'localhost';
      const port = document.getElementById('importDbPort')?.value?.trim() || '3306';
      const dbName = document.getElementById('importDbName')?.value?.trim() || '';
      const dbUser = document.getElementById('importDbUser')?.value?.trim() || '';
      const pass = document.getElementById('importDbPass')?.value || '';
      const oldUrl = document.getElementById('importOldUrl')?.value?.trim() || '';
      const newUrl = document.getElementById('importNewUrl')?.value?.trim() || '';

      // Validasi berdasarkan Scope
      if (scope !== 'db_only' && !targetDir) {
        showToast('error', 'Direktori Kosong', 'Harap masukkan direktori target ekstraksi website.');
        if (targetDirInput) targetDirInput.focus();
        return;
      }

      if (scope !== 'files_only') {
        if (!dbName || !dbUser) {
          showToast('error', 'Informasi Kurang', 'Harap masukkan Nama Database dan Username MySQL.');
          document.getElementById('importDbName')?.focus();
          return;
        }
      }

      const confirmMsg = scope === 'files_only'
        ? `Mulai mengekstrak berkas website ke direktori "${targetDir}" (tanpa database)?`
        : (scope === 'db_only'
           ? `Mulai merestore database MySQL ke database "${dbName}" (.sql saja)?`
           : `Mulai instalasi & restore website lengkap ke "${targetDir}" dan database "${dbName}"?`);

      if (!confirm(confirmMsg)) return;

      const configEl = document.getElementById('importConfig');
      const progressEl = document.getElementById('importProgress');
      const completeEl = document.getElementById('importComplete');

      if (configEl) configEl.style.display = 'none';
      if (progressEl) progressEl.style.display = 'block';

      showToast('info', 'Restorasi Dimulai', `Memproses restorasi website [Mode: ${scope}]...`);

      const percentEl = document.getElementById('importPercent');
      const textEl = document.getElementById('importProgressText');
      const subEl = document.getElementById('importProgressSub');
      const barEl = document.getElementById('importProgressBar');
      const stepEls = document.querySelectorAll('#importPipelineSteps .pipeline-step');
      const connectorEls = document.querySelectorAll('#importPipelineSteps .pipeline-connector');

      function updatePipeline(activeStepIdx, percent, text, sub) {
        if (percentEl) percentEl.textContent = `${percent}%`;
        if (textEl) textEl.textContent = text;
        if (subEl) subEl.textContent = sub;
        if (barEl) barEl.style.width = `${percent}%`;

        stepEls.forEach((el, idx) => {
          el.classList.remove('done', 'active');
          if (idx < activeStepIdx) {
            el.classList.add('done');
            el.querySelector('.pipeline-step-circle').innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
          } else if (idx === activeStepIdx) {
            el.classList.add('active');
            el.querySelector('.pipeline-step-circle').innerHTML = '<div class="inner"></div>';
          }
        });

        connectorEls.forEach((el, idx) => {
          if (idx < activeStepIdx) {
            el.classList.add('done');
          } else {
            el.classList.remove('done');
          }
        });
      }

      try {
        // Step 1: Chunked Extract (jika bukan db_only)
        if (scope !== 'db_only') {
          updatePipeline(0, 10, 'Mengekstrak arsip ZIP bertahap...', `Target: ${targetDir}`);
          addTerminalLine('importTerminalBody', 'Extract', `Memulai ekstraksi arsip ke "${targetDir}"...`, 'extract');

          let startIndex = 0;
          let zipDone = false;

          while (!zipDone) {
            const fd = new FormData();
            fd.append('action', 'extract_chunk');
            fd.append('target_dir', targetDir);
            fd.append('start_index', startIndex);

            const r = await fetch('import.php', { method: 'POST', body: fd });
            const res = await r.json();
            if (!res.success) throw new Error(res.message);

            startIndex = res.next_index;
            zipDone = res.done;
            const factor = (scope === 'files_only') ? 0.9 : 0.4;
            const pct = Math.round(res.percent * factor);
            updatePipeline(1, pct, res.message, `${res.extracted_chunk} berkas per langkah`);
            addTerminalLine('importTerminalBody', 'Extract', res.message, 'extract');
          }
          addTerminalLine('importTerminalBody', 'Files', 'Seluruh berkas website berhasil diekstrak.', 'restore');
        } else {
          addTerminalLine('importTerminalBody', 'Files', 'Mode Hanya Database aktif: Ekstraksi berkas dilewati.', 'info');
        }

        // Step 2: Chunked SQL Import (jika bukan files_only)
        if (scope !== 'files_only') {
          updatePipeline(2, 45, 'Mengimpor database MySQL dump...', 'Mengeksekusi batch SQL');
          addTerminalLine('importTerminalBody', 'Database', 'Memulai impor batch database SQL...', 'database');

          let offset = 0;
          let sqlDone = false;

          while (!sqlDone) {
            const fd = new FormData();
            fd.append('action', 'import_sql_chunk');
            fd.append('host', host);
            fd.append('port', port);
            fd.append('database', dbName);
            fd.append('username', dbUser);
            fd.append('password', pass);
            fd.append('target_dir', targetDir);
            fd.append('offset', offset);

            const r = await fetch('import.php', { method: 'POST', body: fd });
            const res = await r.json();
            if (!res.success) throw new Error(res.message);

            offset = res.offset;
            sqlDone = res.done;
            const base = (scope === 'db_only') ? 0 : 40;
            const factor = (scope === 'db_only') ? 0.6 : 0.4;
            const pct = base + Math.round(res.percent * factor);
            updatePipeline(2, pct, res.message, `Offset: ${offset} bytes`);
            addTerminalLine('importTerminalBody', 'Database', res.message, 'database');
          }
          addTerminalLine('importTerminalBody', 'Database', 'Restorasi database MySQL selesai.', 'database');

          // Step 3: Search & Replace Domain (jika bukan files_only)
          updatePipeline(3, 85, 'Mengganti domain & menghitung ulang serialized length...', `${oldUrl || 'N/A'} → ${newUrl || 'N/A'}`);
          addTerminalLine('importTerminalBody', 'URL', 'Mengganti URL lama dengan URL baru di tabel database...', 'ready');

          const srFd = new FormData();
          srFd.append('action', 'search_replace');
          srFd.append('target_dir', targetDir);
          srFd.append('host', host);
          srFd.append('port', port);
          srFd.append('database', dbName);
          srFd.append('username', dbUser);
          srFd.append('password', pass);
          srFd.append('old_url', oldUrl);
          srFd.append('new_url', newUrl);

          const srR = await fetch('import.php', { method: 'POST', body: srFd });
          const srRes = await srR.json();
          if (!srRes.success) throw new Error(srRes.message);
          addTerminalLine('importTerminalBody', 'URL', srRes.message, 'ready');
        } else {
          addTerminalLine('importTerminalBody', 'Database', 'Mode Hanya Berkas aktif: Impor database dan penggantian URL dilewati.', 'info');
        }

        // Step 4: Finalize
        updatePipeline(4, 100, 'Restorasi selesai!', 'Website siap digunakan');
        addTerminalLine('importTerminalBody', 'Complete', 'Proses migrasi/restorasi telah berhasil diselesaikan!', 'ready');

        const resDir = document.getElementById('resultTargetDir');
        const resDb = document.getElementById('resultDatabaseStatus');
        const resUrl = document.getElementById('resultTargetUrl');
        const openBtn = document.getElementById('btnOpenWebsite');

        if (resDir) {
          resDir.textContent = (scope === 'db_only') ? 'Dilewati (Mode Database Saja)' : targetDir;
        }
        if (resDb) {
          if (scope === 'files_only') {
            resDb.textContent = 'Dilewati (Mode Berkas Saja)';
            resDb.style.color = 'var(--text-muted)';
          } else {
            resDb.textContent = 'Restored & Verified';
            resDb.style.color = 'var(--success)';
          }
        }
        if (resUrl) resUrl.textContent = newUrl || location.origin;
        if (openBtn && newUrl) openBtn.href = newUrl;

        setTimeout(() => {
          if (progressEl) progressEl.style.display = 'none';
          if (completeEl) completeEl.style.display = 'block';
          showToast('success', 'Restorasi Berhasil', 'Website Anda telah berhasil direstore.');
        }, 1200);

      } catch (err) {
        addTerminalLine('importTerminalBody', 'Error', err.message, 'ready');
        showToast('error', 'Restorasi Gagal', err.message);
        alert('Gagal memproses restorasi: ' + err.message);
      }
    });
  }

  // Self-Destruct
  if (btnSelfDestructReal) {
    btnSelfDestructReal.addEventListener('click', async () => {
      if (!confirm('Permanently delete the migration package (.zip) and SQL dump from this server?')) return;
      try {
        const fd = new FormData();
        fd.append('action', 'self_destruct');
        const r = await fetch('import.php', { method: 'POST', body: fd });
        const res = await r.json();
        if (res.success) {
          showToast('success', 'Cleaned Up', res.message);
          btnSelfDestructReal.disabled = true;
          btnSelfDestructReal.innerHTML = 'Package Deleted';
        }
      } catch (e) {
        showToast('error', 'Failed', e.message);
      }
    });
  }

})();
