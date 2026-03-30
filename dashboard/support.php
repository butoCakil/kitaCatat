<?php
// ============================================================
// KitaCatat — Dashboard: Hubungi Admin
// ============================================================
require_once __DIR__ . '/../config/config.php';
$pageTitle = 'Hubungi Admin';
require_once __DIR__ . '/layout/header.php';

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

// Ambil nama user
$user = $db->prepare("SELECT name FROM users WHERE id=?");
$user->execute([$userId]);
$userName = $user->fetchColumn() ?: 'User';
?>

<div class="mb-4">
    <h5 class="mb-0 fw-bold">Hubungi Admin</h5>
    <p class="text-muted mb-0" style="font-size:13px">Kirim pertanyaan atau laporan masalah ke admin KitaCatat</p>
</div>

<div class="row justify-content-center">
    <div class="col-lg-7">
        <div class="card" style="height:calc(100vh - 220px);min-height:500px;display:flex;flex-direction:column">

            <!-- Chat Header -->
            <div class="card-header d-flex align-items-center gap-3">
                <div style="width:36px;height:36px;background:var(--danger-light);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:15px;color:var(--danger);flex-shrink:0">
                    <i class="fa-solid fa-shield-halved"></i>
                </div>
                <div>
                    <div style="font-size:14px;font-weight:700">Admin KitaCatat</div>
                    <div id="adminStatus" style="font-size:11px;color:var(--text-muted)">
                        <span class="me-1" style="width:7px;height:7px;background:#22c55e;border-radius:50%;display:inline-block"></span>
                        Aktif
                    </div>
                </div>
                <div class="ms-auto" style="font-size:12px;color:var(--text-muted)">
                    <i class="fa-solid fa-info-circle me-1"></i>
                    Balasan akan dikirim ke WA Anda
                </div>
            </div>

            <!-- Messages Area -->
            <div id="messagesArea" style="flex:1;overflow-y:auto;padding:20px;display:flex;flex-direction:column;gap:10px;background:#f8fafc">
                <div id="loadingMsg" class="text-center text-muted py-4" style="font-size:13px">
                    <i class="fa-solid fa-spinner fa-spin me-2"></i>Memuat percakapan...
                </div>
            </div>

            <!-- Input Area -->
            <div style="padding:14px 16px;border-top:1px solid var(--card-border);background:#fff">
                <div class="d-flex gap-2 align-items-end">
                    <textarea id="msgInput" class="form-control"
                              placeholder="Tulis pesan Anda..."
                              rows="2"
                              style="resize:none;font-size:13.5px;border-radius:10px;border:1.5px solid var(--card-border)"
                              onkeydown="handleKeyDown(event)"></textarea>
                    <button onclick="sendMessage()"
                        class="btn btn-primary"
                        id="sendBtn"
                        style="border-radius:10px;padding:10px 16px;height:fit-content;flex-shrink:0">
                        <i class="fa-solid fa-paper-plane"></i>
                    </button>
                </div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:6px">
                    Enter untuk baris baru · Ctrl+Enter untuk kirim
                </div>
            </div>
        </div>
    </div>
</div>

<?php
ob_start();
?>
<script>
let lastId    = 0;
let polling   = null;
const myName  = <?= json_encode($userName) ?>;

// ============================================================
// Load & Render Pesan
// ============================================================
async function loadMessages(initial = false) {
    try {
        const res  = await fetch('/api/support.php?after=' + (initial ? 0 : lastId));
        const data = await res.json();
        if (!data.success) return;

        if (initial) {
            document.getElementById('loadingMsg').remove();
            if (data.data.length === 0) {
                showEmptyState();
            }
        }

        data.data.forEach(msg => renderMessage(msg));
        if (data.data.length > 0) {
            lastId = Math.max(...data.data.map(m => m.id));
            scrollToBottom();
        }
    } catch(e) {
        if (initial) document.getElementById('loadingMsg').textContent = 'Gagal memuat pesan.';
    }
}

function showEmptyState() {
    const area = document.getElementById('messagesArea');
    area.innerHTML = `
        <div id="emptyState" class="text-center py-5">
            <div style="font-size:40px;margin-bottom:12px">💬</div>
            <div style="font-size:14px;font-weight:600;color:var(--text-primary)">Belum ada percakapan</div>
            <div style="font-size:13px;color:var(--text-muted);margin-top:4px">Kirim pesan pertama Anda ke admin</div>
        </div>`;
}

function renderMessage(msg) {
    // Hapus empty state jika ada
    const empty = document.getElementById('emptyState');
    if (empty) empty.remove();

    const area    = document.getElementById('messagesArea');
    const isUser  = msg.sender === 'user';
    const time    = new Date(msg.created_at).toLocaleTimeString('id-ID', {hour:'2-digit', minute:'2-digit'});
    const date    = new Date(msg.created_at).toLocaleDateString('id-ID', {day:'2-digit', month:'short'});

    const div = document.createElement('div');
    div.style.cssText = 'display:flex;' + (isUser ? 'justify-content:flex-end' : 'justify-content:flex-start');
    div.innerHTML = `
        <div style="max-width:72%;display:flex;flex-direction:column;gap:3px;align-items:${isUser?'flex-end':'flex-start'}">
            <div style="font-size:10.5px;color:var(--text-muted);padding:0 4px">
                ${isUser ? 'Anda' : '🛡️ Admin'} · ${date}, ${time}
            </div>
            <div style="
                background:${isUser ? 'var(--primary)' : '#fff'};
                color:${isUser ? '#fff' : 'var(--text-primary)'};
                padding:10px 14px;
                border-radius:${isUser ? '14px 14px 4px 14px' : '14px 14px 14px 4px'};
                font-size:13.5px;
                line-height:1.55;
                border:${isUser ? 'none' : '1px solid var(--card-border)'};
                box-shadow:0 1px 4px rgba(0,0,0,.06);
                white-space:pre-wrap;word-break:break-word">
                ${escHtml(msg.message)}
            </div>
        </div>`;
    area.appendChild(div);
}

function scrollToBottom() {
    const area = document.getElementById('messagesArea');
    area.scrollTop = area.scrollHeight;
}

// ============================================================
// Kirim Pesan
// ============================================================
async function sendMessage() {
    const input   = document.getElementById('msgInput');
    const message = input.value.trim();
    if (!message) return;

    const btn = document.getElementById('sendBtn');
    btn.disabled = true;
    input.value  = '';

    // Optimistic render
    renderMessage({
        id: Date.now(),
        sender: 'user',
        message,
        created_at: new Date().toISOString()
    });
    scrollToBottom();

    try {
        const res  = await fetch('/api/support.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ action: 'send', message })
        });
        const data = await res.json();
        if (data.success) {
            lastId = Math.max(lastId, data.id);
        }
    } catch(e) {
        console.error('Gagal kirim pesan:', e);
    }

    btn.disabled = false;
    input.focus();
}

function handleKeyDown(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        sendMessage();
    }
}

function escHtml(str) {
    return str.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ============================================================
// Init
// ============================================================
loadMessages(true);
// Polling setiap 5 detik untuk pesan baru dari admin
polling = setInterval(() => loadMessages(false), 5000);
</script>
<?php
$extraScript = ob_get_clean();
require_once __DIR__ . '/layout/footer.php';
?>