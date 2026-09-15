/**
 * Website Migrator - Frontend Controller (Dual-Mode: Packager & Deployer)
 */

window.switchMode = function (mode) {
    const btnExport = document.getElementById('tabBtnExport');
    const btnImport = document.getElementById('tabBtnImport');
    const secExport = document.getElementById('sectionExport');
    const secImport = document.getElementById('sectionImport');

    if (mode === 'export') {
        btnExport.classList.add('active');
        btnImport.classList.remove('active');
        secExport.style.display = 'block';
        secImport.style.display = 'none';
    } else {
        btnImport.classList.add('active');
        btnExport.classList.remove('active');
        secImport.style.display = 'block';
        secExport.style.display = 'none';
    }
};

document.addEventListener('DOMContentLoaded', () => {
    // Shared Elements
    const terminal = document.getElementById('terminalContent');
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    const progressStatus = document.getElementById('progressStatus');

    function log(message, type = 'info') {
        if (!terminal) return;
        const now = new Date();
        const timeStr = now.toTimeString().split(' ')[0];
        const line = document.createElement('div');
        line.className = `log-line log-${type}`;
        line.innerHTML = `<span class="log-time">[${timeStr}]</span> ${escapeHtml(message)}`;
        terminal.appendChild(line);
        terminal.scrollTop = terminal.scrollHeight;
    }

    function setProgress(percent, statusText) {
        if (progressBar) progressBar.style.width = `${percent}%`;
        if (progressText) progressText.innerText = `${percent}%`;
        if (progressStatus && statusText) progressStatus.innerText = statusText;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.innerText = text;
        return div.innerHTML;
    }

    // =========================================================
    // MODE 1: EXPORT / PACKAGER
    // =========================================================
    const btnTestDb = document.getElementById('btnTestDb');
    const btnStartExport = document.getElementById('btnStartExport');
    const resultCard = document.getElementById('resultCard');

    if (btnTestDb) {
        btnTestDb.addEventListener('click', async () => {
            const host = document.getElementById('dbHost').value;
            const port = document.getElementById('dbPort').value;
            const db = document.getElementById('dbName').value;
            const user = document.getElementById('dbUser').value;
            const pass = document.getElementById('dbPass').value;

            btnTestDb.disabled = true;
            btnTestDb.innerHTML = '⏳ Menghubungkan...';
            log('Menguji koneksi database MySQL sumber...', 'info');

            try {
                const formData = new FormData();
                formData.append('action', 'test_db');
                formData.append('host', host);
                formData.append('port', port);
                formData.append('database', db);
                formData.append('username', user);
                formData.append('password', pass);

                const res = await fetch('index.php', { method: 'POST', body: formData });
                const json = await res.json();

                if (json.success) {
                    log(json.message, 'success');
                    alert('✅ ' + json.message);
                } else {
                    log(json.message, 'error');
                    alert('❌ ' + json.message);
                }
            } catch (err) {
                log('Gagal menghubungi server: ' + err.message, 'error');
            } finally {
                btnTestDb.disabled = false;
                btnTestDb.innerHTML = '🔍 Uji Koneksi DB';
            }
        });
    }

    if (btnStartExport) {
        btnStartExport.addEventListener('click', async () => {
            if (!confirm('Mulai proses backup dan pembuatan paket migrasi?')) return;

            btnStartExport.disabled = true;
            btnStartExport.innerHTML = '⏳ Sedang Memproses...';
            if (resultCard) resultCard.style.display = 'none';

            log('=== MEMULAI PROSES PEMAKETAN SITUS (EXPORT) ===', 'info');
            setProgress(5, 'Menginisialisasi pemaketan...');

            try {
                // Step 1: Dump Database
                log('Langkah 1/3: Mengekspor skema & data MySQL...', 'info');
                setProgress(20, 'Dumping database...');

                const dbRes = await fetch('index.php', {
                    method: 'POST',
                    body: new URLSearchParams({
                        action: 'export_database',
                        host: document.getElementById('dbHost').value,
                        port: document.getElementById('dbPort').value,
                        database: document.getElementById('dbName').value,
                        username: document.getElementById('dbUser').value,
                        password: document.getElementById('dbPass').value,
                    })
                });

                const dbJson = await dbRes.json();
                if (!dbJson.success) throw new Error(dbJson.message || 'Gagal export database.');
                log(`Database berhasil diekspor: ${dbJson.tables} tabel, ${dbJson.rows} baris (${(dbJson.file_size / 1024 / 1024).toFixed(2)} MB).`, 'success');
                setProgress(50, 'Database berhasil diekspor.');

                // Step 2: Create ZIP
                log('Langkah 2/3: Mengompres berkas website + database ke ZIP...', 'info');
                setProgress(60, 'Mengompres ZIP...');

                const zipRes = await fetch('index.php', {
                    method: 'POST',
                    body: new URLSearchParams({
                        action: 'create_zip',
                        source_dir: document.getElementById('sourceDir').value,
                        exclude_cache: document.getElementById('excludeCache')?.checked ? '1' : '0',
                        exclude_media: document.getElementById('excludeMedia')?.checked ? '1' : '0',
                    })
                });

                const zipJson = await zipRes.json();
                if (!zipJson.success) throw new Error(zipJson.message || 'Gagal mengompres berkas.');
                log(`Kompresi tuntas: ${zipJson.total_files} berkas dimasukkan (${(zipJson.file_size / 1024 / 1024).toFixed(2)} MB).`, 'success');
                setProgress(90, 'Arsip ZIP selesai dibuat.');

                // Step 3: Siap
                setProgress(100, 'Paket migrasi siap!');
                log('🎉 PEMAKETAN TUNTAS 100%!', 'success');
                log('Silakan unduh migrator_package.zip dan installer.php untuk dipasang di hosting baru.', 'info');

                if (resultCard) {
                    resultCard.style.display = 'block';
                    resultCard.scrollIntoView({ behavior: 'smooth' });
                }
            } catch (err) {
                log('❌ Terjadi kesalahan: ' + err.message, 'error');
                setProgress(0, 'Proses dihentikan karena kesalahan.');
                alert('Terjadi kesalahan: ' + err.message);
            } finally {
                btnStartExport.disabled = false;
                btnStartExport.innerHTML = '🚀 Mulai Pemaketan (Export)';
            }
        });
    }

    // =========================================================
    // MODE 2: IMPORT / DEPLOYER
    // =========================================================
    const dropzone = document.getElementById('dropzoneZip');
    const fileInput = document.getElementById('fileUploadInput');
    const btnTestImportDb = document.getElementById('btnTestImportDb');
    const btnStartImport = document.getElementById('btnStartImport');
    const zipStatusText = document.getElementById('zipFileStatus');
    const zipBadge = document.getElementById('zipFileBadge');

    // Drag & Drop
    if (dropzone && fileInput) {
        ['dragenter', 'dragover'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                dropzone.classList.add('dragover');
            });
        });

        ['dragleave', 'drop'].forEach(eventName => {
            dropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                dropzone.classList.remove('dragover');
            });
        });

        dropzone.addEventListener('drop', (e) => {
            const files = e.dataTransfer.files;
            if (files.length > 0) handleFileUpload(files[0]);
        });

        fileInput.addEventListener('change', () => {
            if (fileInput.files.length > 0) handleFileUpload(fileInput.files[0]);
        });
    }

    async function handleFileUpload(file) {
        log(`Mengunggah berkas ${file.name} (${(file.size / 1024 / 1024).toFixed(2)} MB)...`, 'info');
        setProgress(20, `Mengunggah ${file.name}...`);

        const fd = new FormData();
        fd.append('action', 'upload_import_file');
        fd.append('package_file', file);

        try {
            const res = await fetch('index.php', { method: 'POST', body: fd });
            const json = await res.json();
            if (!json.success) throw new Error(json.message);

            log(json.message, 'success');
            setProgress(100, 'Unggahan berhasil!');
            if (zipStatusText) zipStatusText.innerText = `${file.name} (Tersedia)`;
            if (zipBadge) {
                zipBadge.innerText = '✓ Siap';
                zipBadge.className = 'req-badge pass';
            }
        } catch (e) {
            log('Gagal mengunggah: ' + e.message, 'error');
            alert('Gagal unggah: ' + e.message);
        }
    }

    // Uji DB Tujuan
    if (btnTestImportDb) {
        btnTestImportDb.addEventListener('click', async () => {
            const host = document.getElementById('importDbHost').value;
            const port = document.getElementById('importDbPort').value;
            const db   = document.getElementById('importDbName').value;
            const user = document.getElementById('importDbUser').value;
            const pass = document.getElementById('importDbPass').value;

            btnTestImportDb.disabled = true;
            btnTestImportDb.innerText = '⏳ Menguji...';
            log('Menguji koneksi database tujuan...', 'info');

            try {
                const fd = new FormData();
                fd.append('action', 'test_db');
                fd.append('host', host);
                fd.append('port', port);
                fd.append('database', db);
                fd.append('username', user);
                fd.append('password', pass);

                const res = await fetch('index.php', { method: 'POST', body: fd });
                const json = await res.json();
                if (json.success) {
                    log(json.message, 'success');
                    alert('✅ ' + json.message);
                } else {
                    log(json.message, 'error');
                    alert('❌ ' + json.message);
                }
            } catch (e) {
                log('Error: ' + e.message, 'error');
            } finally {
                btnTestImportDb.disabled = false;
                btnTestImportDb.innerText = '🔍 Uji Koneksi DB Tujuan';
            }
        });
    }

    // Jalankan Restore di Mode Impor
    if (btnStartImport) {
        btnStartImport.addEventListener('click', async () => {
            if (!confirm('Mulai proses ekstraksi dan restorasi ke server ini?')) return;

            btnStartImport.disabled = true;
            btnStartImport.innerText = '⏳ Sedang Memulihkan...';
            log('=== MEMULAI RESTORASI WEBSITE (IMPORT & AUTO-EXTRACT) ===', 'info');

            const targetDir = document.getElementById('targetExtractDir').value;
            const host = document.getElementById('importDbHost').value;
            const port = document.getElementById('importDbPort').value;
            const db   = document.getElementById('importDbName').value;
            const user = document.getElementById('importDbUser').value;
            const pass = document.getElementById('importDbPass').value;
            const oldUrl = document.getElementById('importOldUrl').value;
            const newUrl = document.getElementById('importNewUrl').value;

            try {
                // 1. Ekstrak ZIP Bertahap
                log('Langkah 1/3: Mengekstrak berkas ZIP ke direktori tujuan...', 'info');
                let startIndex = 0;
                let zipDone = false;

                while (!zipDone) {
                    const fd = new FormData();
                    fd.append('action', 'extract_import_chunk');
                    fd.append('target_dir', targetDir);
                    fd.append('start_index', startIndex);

                    const r = await fetch('index.php', { method: 'POST', body: fd });
                    const res = await r.json();
                    if (!res.success) throw new Error(res.message);

                    startIndex = res.next_index;
                    zipDone = res.done;
                    const pct = Math.round(res.percent * 0.4); // 0-40%
                    setProgress(pct, res.message);
                    log(res.message, 'info');
                }
                log('Seluruh berkas ZIP berhasil diekstrak!', 'success');

                // 2. Impor Database SQL Bertahap
                log('Langkah 2/3: Mengimpor database MySQL...', 'info');
                let offset = 0;
                let sqlDone = false;

                while (!sqlDone) {
                    const fd = new FormData();
                    fd.append('action', 'import_sql_chunk');
                    fd.append('host', host);
                    fd.append('port', port);
                    fd.append('database', db);
                    fd.append('username', user);
                    fd.append('password', pass);
                    fd.append('offset', offset);

                    const r = await fetch('index.php', { method: 'POST', body: fd });
                    const res = await r.json();
                    if (!res.success) throw new Error(res.message);

                    offset = res.offset;
                    sqlDone = res.done;
                    const pct = 40 + Math.round(res.percent * 0.4); // 40-80%
                    setProgress(pct, res.message);
                    log(res.message, 'info');
                }
                log('Database MySQL berhasil dipulihkan!', 'success');

                // 3. Search & Replace Domain
                if (oldUrl && newUrl && oldUrl !== newUrl) {
                    log('Langkah 3/3: Melakukan Search & Replace domain/URL...', 'info');
                    setProgress(90, 'Menyesuaikan domain...');

                    const srFd = new FormData();
                    srFd.append('action', 'search_replace');
                    srFd.append('host', host);
                    srFd.append('port', port);
                    srFd.append('database', db);
                    srFd.append('username', user);
                    srFd.append('password', pass);
                    srFd.append('old_url', oldUrl);
                    srFd.append('new_url', newUrl);

                    const srR = await fetch('index.php', { method: 'POST', body: srFd });
                    const srRes = await srR.json();
                    if (!srRes.success) throw new Error(srRes.message);
                    log(srRes.message, 'success');
                }

                setProgress(100, 'Restorasi selesai 100%!');
                log('🎉 RESTORASI WEBSITE TUNTAS DENGAN SEMPURNA!', 'success');
                alert('🎉 Selamat! Website Anda berhasil dipulihkan dan siap digunakan.');
            } catch (err) {
                log('❌ Terjadi kesalahan: ' + err.message, 'error');
                setProgress(0, 'Restorasi gagal.');
                alert('Kesalahan restorasi: ' + err.message);
            } finally {
                btnStartImport.disabled = false;
                btnStartImport.innerText = '🚀 Ekstrak Berkas & Restore Database Sekarang';
            }
        });
    }
});
