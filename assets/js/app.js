/**
 * Website Migrator - Packager Frontend Controller
 */

document.addEventListener('DOMContentLoaded', () => {
    const btnTestDb = document.getElementById('btnTestDb');
    const btnStartExport = document.getElementById('btnStartExport');
    const formExport = document.getElementById('formExport');
    const terminal = document.getElementById('terminalContent');
    const progressBar = document.getElementById('progressBar');
    const progressText = document.getElementById('progressText');
    const progressStatus = document.getElementById('progressStatus');
    const resultCard = document.getElementById('resultCard');
    const downloadZipBtn = document.getElementById('downloadZipBtn');
    const downloadInstallerBtn = document.getElementById('downloadInstallerBtn');

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

    // 1. Test Database Connection
    if (btnTestDb) {
        btnTestDb.addEventListener('click', async () => {
            const host = document.getElementById('dbHost').value;
            const port = document.getElementById('dbPort').value;
            const db = document.getElementById('dbName').value;
            const user = document.getElementById('dbUser').value;
            const pass = document.getElementById('dbPass').value;

            btnTestDb.disabled = true;
            btnTestDb.innerHTML = '⏳ Menghubungkan...';
            log('Menguji koneksi database MySQL...', 'info');

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
                log('Gagal menghubungi server backend: ' + err.message, 'error');
            } finally {
                btnTestDb.disabled = false;
                btnTestDb.innerHTML = '🔍 Uji Koneksi DB';
            }
        });
    }

    // 2. Start Packaging / Export Process
    if (btnStartExport) {
        btnStartExport.addEventListener('click', async () => {
            if (!confirm('Mulai proses backup dan pembuatan paket migrasi?')) {
                return;
            }

            btnStartExport.disabled = true;
            btnStartExport.innerHTML = '⏳ Sedang Memproses...';
            if (resultCard) resultCard.style.display = 'none';

            log('=== MEMULAI PROSES PEMAKETAN SITUS ===', 'info');
            setProgress(5, 'Menginisialisasi...');

            const formData = new FormData(formExport);
            formData.append('action', 'export_package');

            try {
                // Step 1: Export Database
                log('Langkah 1/3: Mengekspor skema & data database MySQL...', 'info');
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
                if (!dbJson.success) {
                    throw new Error(dbJson.message || 'Gagal mengekspor database.');
                }
                log(`Database berhasil diekspor: ${dbJson.tables} tabel, ${dbJson.rows} baris data (${(dbJson.file_size / 1024 / 1024).toFixed(2)} MB).`, 'success');
                setProgress(50, 'Database berhasil diekspor.');

                // Step 2: Mengompres berkas website + SQL ke dalam ZIP
                log('Langkah 2/3: Mengompres berkas website dan database ke dalam arsip ZIP...', 'info');
                setProgress(60, 'Mengompres arsip ZIP...');

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
                if (!zipJson.success) {
                    throw new Error(zipJson.message || 'Gagal mengompres berkas.');
                }
                log(`Kompresi selesai: ${zipJson.total_files} berkas dimasukkan (${(zipJson.file_size / 1024 / 1024).toFixed(2)} MB).`, 'success');
                setProgress(90, 'Arsip ZIP selesai dibuat.');

                // Step 3: Siapkan berkas installer.php mandiri
                log('Langkah 3/3: Menyiapkan standalone installer.php...', 'info');
                setProgress(100, 'Paket migrasi siap!');
                log('🎉 PEMAKETAN BERHASIL TUNTAS 100%!', 'success');
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
});
