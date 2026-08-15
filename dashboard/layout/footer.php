</div><!-- .content-area -->

    <!-- Footer legal -->
    <div style="padding:14px 24px;border-top:1px solid var(--card-border);display:flex;align-items:center;justify-content:center;gap:16px;font-size:11.5px;color:var(--text-muted)">
        <span>© 2026 KitaCatat &mdash; <a href="https://simbiot.id" target="_blank" style="color:#374151;text-decoration:none">Simbiot.id Production</a></span>
        <span style="color:var(--card-border)">|</span>
        <a href="/privacy-policy.html" target="_blank" style="color:var(--text-muted);text-decoration:none" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--text-muted)'">Kebijakan Privasi</a>
        <span style="color:var(--card-border)">|</span>
        <a href="/terms-of-service.html" target="_blank" style="color:var(--text-muted);text-decoration:none" onmouseover="this.style.color='var(--primary)'" onmouseout="this.style.color='var(--text-muted)'">Syarat &amp; Ketentuan</a>
    </div>

</div><!-- .main-wrapper -->

<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap/5.3.2/js/bootstrap.bundle.min.js"></script>

<script>
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
}
// Tutup sidebar saat klik di luar
document.addEventListener('click', function(e) {
    const sidebar = document.getElementById('sidebar');
    const toggle  = document.querySelector('.sidebar-toggle');
    if (window.innerWidth <= 768 && sidebar.classList.contains('open')) {
        if (!sidebar.contains(e.target) && !toggle.contains(e.target)) {
            sidebar.classList.remove('open');
        }
    }
});
</script>

<?php if (isset($extraScript)) echo $extraScript; ?>

<!-- Install App banner (mini, kanan bawah) -->
<div id="kcInstallBanner" style="position:fixed;right:16px;bottom:82px;z-index:9999;display:none;align-items:center;gap:10px;background:#1e293b;color:#fff;border-radius:14px;padding:10px 12px;box-shadow:0 10px 30px rgba(0,0,0,.35);max-width:260px;font-family:var(--font-main)">
    <img src="/assets/img/icon/android-chrome-192x192.png" alt="" style="width:32px;height:32px;border-radius:8px;flex-shrink:0">
    <div style="flex:1;font-size:13px;font-weight:700;line-height:1.3"></div>
    <button id="kcInstallBtn" style="background:transparent;border:none;color:#4ade80;font-size:12.5px;font-weight:700;padding:4px 6px;cursor:pointer">Install</button>
    <button id="kcInstallClose" aria-label="Tutup" style="background:rgba(255,255,255,.12);border:none;color:#fff;width:24px;height:24px;border-radius:50%;font-size:13px;cursor:pointer;flex-shrink:0">✕</button>
</div>

<!-- Tombol melayang Chat WA (selalu tampil, bukan install — langsung buka wa.me) -->
<a href="https://wa.me/6285111308087" target="_blank" rel="noopener"
   id="kcWaFloat"`
   title="Chat KitaCatat"
   style="position:fixed;right:16px;bottom:16px;z-index:9998;width:52px;height:52px;border-radius:50%;
          background:#25D366;color:#fff;display:flex;align-items:center;justify-content:center;
          box-shadow:0 8px 20px rgba(0,0,0,.3);text-decoration:none;font-size:26px">
    <i class="fa-brands fa-whatsapp"></i>
</a>

<script>
(function () {
    var banner = document.getElementById('kcInstallBanner');
    var deferredPrompt = null;

    if (localStorage.getItem('kc_app_installed') === '1') return;

    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        deferredPrompt = e;
        if (!localStorage.getItem('kc_install_dismissed')) {
            banner.style.display = 'flex';
        }
    });

    document.getElementById('kcInstallBtn').addEventListener('click', function () {
        banner.style.display = 'none';
        if (deferredPrompt) {
            deferredPrompt.prompt();
            deferredPrompt = null;
        }
    });

    document.getElementById('kcInstallClose').addEventListener('click', function () {
        banner.style.display = 'none';
        localStorage.setItem('kc_install_dismissed', '1');
    });

    window.addEventListener('appinstalled', function () {
        banner.style.display = 'none';
        localStorage.setItem('kc_app_installed', '1');
    });
})();
</script>

</body>
</html>
