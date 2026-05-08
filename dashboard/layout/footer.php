</div><!-- .content-area -->

    <!-- Footer legal -->
    <div style="padding:14px 24px;border-top:1px solid var(--card-border);display:flex;align-items:center;justify-content:center;gap:16px;font-size:11.5px;color:var(--text-muted)">
        <span>© 2026 KitaCatat &mdash; Bendz Production</span>
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

</body>
</html>
