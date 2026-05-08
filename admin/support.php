<?php
// ============================================================
// KitaCatat — Admin: Support Chat
// ============================================================
require_once __DIR__ . '/../config/config.php';
$pageTitle = 'Support Chat';
require_once __DIR__ . '/layout/header.php';
?>

<div class="mb-3">
    <h5 class="mb-0 fw-bold">Support Chat</h5>
    <p class="text-muted mb-0" style="font-size:13px">Percakapan dengan user</p>
</div>

<div class="card" id="chatCard" style="height:calc(100vh - 180px);min-height:500px;display:flex;flex-direction:row;overflow:hidden">

    <!-- Sidebar: Daftar User -->
    <div id="inboxPanel" style="width:280px;border-right:1px solid var(--card-border);display:flex;flex-direction:column;flex-shrink:0">
        <div style="padding:14px 16px;border-bottom:1px solid var(--card-border)">
            <input type="text" id="inboxSearch" class="form-control form-control-sm"
                   placeholder="Cari user..."
                   oninput="filterInbox(this.value)">
        </div>
        <div id="inboxList" style="flex:1;overflow-y:auto">
            <div class="text-center text-muted py-4" style="font-size:13px">
                <i class="fa-solid fa-spinner fa-spin me-2"></i>Memuat...
            </div>
        </div>
    </div>

    <!-- Chat Panel -->
    <div id="chatPanel" style="flex:1;display:flex;flex-direction:column;min-width:0">
        <!-- Tombol back untuk mobile -->
        <div class="mobile-back-btn" onclick="backToInbox()">
            <i class="fa-solid fa-arrow-left"></i> Kembali ke daftar
        </div>

        <!-- Empty state -->
        <div id="chatEmpty" style="flex:1;display:flex;align-items:center;justify-content:center">
            <div class="text-center">
                <div style="font-size:48px;margin-bottom:12px">💬</div>
                <div style="font-weight:600;font-size:15px">Pilih percakapan</div>
                <div style="font-size:13px;color:var(--text-muted);margin-top:4px">Klik nama user di sebelah kiri</div>
            </div>
        </div>

        <!-- Active chat -->
        <div id="chatActive" style="display:none;flex-direction:column;height:100%">

            <!-- Chat Header -->
            <div style="padding:14px 20px;border-bottom:1px solid var(--card-border);display:flex;align-items:center;gap:12px;background:#fff">
                <div id="chatAvatar" style="width:36px;height:36px;background:#dbeafe;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px;color:#2563eb;flex-shrink:0">?</div>
                <div>
                    <div id="chatName" style="font-size:14px;font-weight:700">—</div>
                    <div id="chatWA" style="font-size:11px;color:var(--text-muted);font-family:var(--font-mono)">—</div>
                </div>
                <a id="chatWALink" href="#" target="_blank" class="ms-auto btn btn-sm btn-outline-success" style="font-size:11px;display:none">
                    <i class="fa-brands fa-whatsapp me-1"></i>Buka WA
                </a>
            </div>

            <!-- Messages -->
            <div id="messagesArea" style="flex:1;overflow-y:auto;padding:20px;display:flex;flex-direction:column;gap:10px;background:#f8fafc"></div>

            <!-- Input -->
            <div style="padding:14px 16px;border-top:1px solid var(--card-border);background:#fff">
                <div class="d-flex gap-2 align-items-end">
                    <textarea id="adminMsgInput" class="form-control"
                              placeholder="Tulis balasan..."
                              rows="2"
                              style="resize:none;font-size:13.5px;border-radius:10px;border:1.5px solid var(--card-border)"
                              onkeydown="handleKeyDown(event)"></textarea>
                    <button onclick="sendReply()" id="sendBtn"
                        class="btn btn-danger"
                        style="border-radius:10px;padding:10px 16px;height:fit-content;flex-shrink:0">
                        <i class="fa-solid fa-paper-plane"></i>
                    </button>
                </div>
                <div style="font-size:11px;color:var(--text-muted);margin-top:5px">
                    <i class="fa-brands fa-whatsapp me-1" style="color:#25D366"></i>
                    Balasan akan dikirim ke WA user · Ctrl+Enter untuk kirim
                </div>
            </div>
        </div>

    </div>
</div>

<?php
ob_start();
?>
<style>
@media (max-width: 768px) {
    #inboxPanel {
        width: 100% !important;
        border-right: none !important;
        border-bottom: 1px solid var(--card-border);
    }
    #chatPanel {
        display: none;
        width: 100% !important;
    }
    #chatPanel.mobile-open {
        display: flex !important;
    }
    #inboxPanel.mobile-hidden {
        display: none !important;
    }
    .mobile-back-btn {
        display: flex !important;
    }
}
.mobile-back-btn {
    display: none;
    align-items: center;
    gap: 8px;
    padding: 10px 16px;
    border-bottom: 1px solid var(--card-border);
    background: #fff;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    color: var(--admin-primary, #dc2626);
}
.mobile-back-btn:hover { background: #fef2f2; }
</style>
<script>
let currentUserId   = null;
let currentUserName = null;
let currentUserWA   = null;
let lastId          = 0;
let polling         = null;
let allInbox        = [];

// ============================================================
// Load Inbox
// ============================================================
async function loadInbox() {
    const res  = await fetch('/api/support.php?action=inbox');
    const data = await res.json();
    if (!data.success) return;
    allInbox = data.data;
    renderInbox(allInbox);
}

function renderInbox(items) {
    const list = document.getElementById('inboxList');
    if (!items.length) {
        list.innerHTML = '<div class="text-center text-muted py-5" style="font-size:13px">Belum ada percakapan</div>';
        return;
    }
    list.innerHTML = items.map(u => `
        <div class="inbox-item" data-id="${u.user_id}" data-name="${escHtml(u.name||'')}" data-wa="${escHtml(u.wa_number)}"
            onclick="openChat(${u.user_id}, ${JSON.stringify(u.name||'').replace(/"/g,'&quot;')}, '${u.wa_number}')"
            style="padding:12px 16px;cursor:pointer;border-bottom:1px solid var(--card-border);
            background:${u.user_id===currentUserId?'#f0fdf4':'#fff'};transition:background .1s"
            onmouseover="this.style.background='#f8fafc'"
            onmouseout="this.style.background='${u.user_id===currentUserId?'#f0fdf4':'#fff'}'">
            <div class="d-flex align-items-center gap-2">
                <div style="width:34px;height:34px;background:#dbeafe;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:#2563eb;flex-shrink:0">
                    ${(u.name||'?')[0].toUpperCase()}
                </div>
                <div style="flex:1;min-width:0">
                    <div style="display:flex;align-items:center;justify-content:space-between">
                        <span style="font-size:13px;font-weight:600">${escHtml(u.name||'—')}</span>
                        ${u.unread_count > 0 ? `<span style="background:#dc2626;color:#fff;font-size:10px;font-weight:700;padding:1px 6px;border-radius:10px">${u.unread_count}</span>` : ''}
                    </div>
                    <div style="font-size:11.5px;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:180px">
                        ${escHtml((u.last_message||'').substring(0,50))}
                    </div>
                    <div style="font-size:10px;color:var(--text-muted);margin-top:1px">
                        ${u.last_at ? new Date(u.last_at).toLocaleDateString('id-ID',{day:'2-digit',month:'short',hour:'2-digit',minute:'2-digit'}) : ''}
                    </div>
                </div>
            </div>
        </div>
    `).join('');
}

function filterInbox(q) {
    const filtered = q ? allInbox.filter(u =>
        (u.name||'').toLowerCase().includes(q.toLowerCase()) ||
        u.wa_number.includes(q)
    ) : allInbox;
    renderInbox(filtered);
}

// ============================================================
// Buka Percakapan
// ============================================================
async function openChat(userId, name, wa) {
    currentUserId   = userId;
    currentUserName = name;
    currentUserWA   = wa;
    lastId          = 0;

    // Update UI
    document.getElementById('chatEmpty').style.display  = 'none';
    document.getElementById('chatActive').style.display = 'flex';
    document.getElementById('chatName').textContent     = name || '—';
    document.getElementById('chatWA').textContent       = wa;
    document.getElementById('chatAvatar').textContent   = (name||'?')[0].toUpperCase();
    document.getElementById('messagesArea').innerHTML   = '';

    // Link WA
    const waLink = document.getElementById('chatWALink');
    waLink.href  = 'https://wa.me/' + wa;
    waLink.style.display = '';

    // Load pesan
    if (polling) clearInterval(polling);
    await loadMessages(true);
    polling = setInterval(() => loadMessages(false), 4000);

    // Update highlight di inbox
    document.querySelectorAll('.inbox-item').forEach(el => {
        el.style.background = parseInt(el.dataset.id) === userId ? '#f0fdf4' : '#fff';
    });

    document.getElementById('adminMsgInput').focus();
    showChatPanel();
}

// ============================================================
// Load Pesan
// ============================================================
async function loadMessages(initial = false) {
    if (!currentUserId) return;
    const res  = await fetch(`/api/support.php?user_id=${currentUserId}&after=${lastId}`);
    const data = await res.json();
    if (!data.success) return;

    if (initial && data.data.length === 0) {
        document.getElementById('messagesArea').innerHTML =
            '<div class="text-center text-muted py-4" style="font-size:13px">Belum ada pesan</div>';
        return;
    }

    data.data.forEach(msg => renderMessage(msg));
    if (data.data.length > 0) {
        lastId = Math.max(...data.data.map(m => parseInt(m.id)));
        scrollToBottom();
        // Reload inbox untuk update badge
        loadInbox();
    }
}

function renderMessage(msg) {
    const area   = document.getElementById('messagesArea');
    const isUser = msg.sender === 'user';
    const time   = new Date(msg.created_at).toLocaleTimeString('id-ID',{hour:'2-digit',minute:'2-digit'});
    const date   = new Date(msg.created_at).toLocaleDateString('id-ID',{day:'2-digit',month:'short'});

    // Hapus empty state
    const empty = area.querySelector('.text-center.text-muted');
    if (empty) empty.remove();

    const div = document.createElement('div');
    div.style.cssText = 'display:flex;' + (isUser ? 'justify-content:flex-start' : 'justify-content:flex-end');
    div.innerHTML = `
        <div style="max-width:72%;display:flex;flex-direction:column;gap:3px;align-items:${isUser?'flex-start':'flex-end'}">
            <div style="font-size:10.5px;color:var(--text-muted);padding:0 4px">
                ${isUser ? escHtml(currentUserName||'User') : '🛡️ Admin'} · ${date}, ${time}
            </div>
            <div style="
                background:${isUser ? '#fff' : 'var(--admin-primary,#dc2626)'};
                color:${isUser ? 'var(--text-primary)' : '#fff'};
                padding:10px 14px;
                border-radius:${isUser ? '14px 14px 14px 4px' : '14px 14px 4px 14px'};
                font-size:13.5px;line-height:1.55;
                border:${isUser ? '1px solid var(--card-border)' : 'none'};
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
// Kirim Balasan
// ============================================================
async function sendReply() {
    if (!currentUserId) return;
    const input   = document.getElementById('adminMsgInput');
    const message = input.value.trim();
    if (!message) return;

    const btn = document.getElementById('sendBtn');
    btn.disabled = true;
    input.value  = '';

    // Optimistic render
    renderMessage({
        id: Date.now(),
        sender: 'admin',
        message,
        created_at: new Date().toISOString()
    });
    scrollToBottom();

    try {
        const res  = await fetch('/api/support.php', {
            method: 'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({ action: 'send', user_id: currentUserId, message })
        });
        const data = await res.json();
        if (data.success) lastId = Math.max(lastId, data.id);
    } catch(e) {
        console.error('Gagal kirim:', e);
    }

    btn.disabled = false;
    input.focus();
}

function handleKeyDown(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
        e.preventDefault();
        sendReply();
    }
}

function escHtml(str) {
    if (!str) return '';
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// ============================================================
// Mobile: toggle panel
// ============================================================
function backToInbox() {
    document.getElementById('inboxPanel').classList.remove('mobile-hidden');
    document.getElementById('chatPanel').classList.remove('mobile-open');
}

function showChatPanel() {
    if (window.innerWidth <= 768) {
        document.getElementById('inboxPanel').classList.add('mobile-hidden');
        document.getElementById('chatPanel').classList.add('mobile-open');
    }
}

// ============================================================
// Init
// ============================================================
loadInbox();
// Refresh inbox setiap 10 detik
setInterval(loadInbox, 10000);
</script>
<?php
$extraScript = ob_get_clean();
require_once __DIR__ . '/layout/footer.php';
?>
