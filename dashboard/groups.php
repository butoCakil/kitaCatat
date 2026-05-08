<?php
require_once __DIR__ . '/../config/config.php';
$pageTitle = 'Grup';
require_once __DIR__ . '/layout/header.php';

$db     = getDB();
$userId = (int)$_SESSION['user_id'];

$stmt = $db->prepare(
    "SELECT g.*, gm.role,
        (SELECT COUNT(*) FROM group_members WHERE group_id = g.id) AS member_count
     FROM groups g
     JOIN group_members gm ON gm.group_id = g.id AND gm.user_id = :uid
     ORDER BY g.created_at DESC"
);
$stmt->execute([':uid' => $userId]);
$groups = $stmt->fetchAll();
?>

<div class="d-flex align-items-center justify-content-between mb-4 flex-wrap gap-2">
    <div>
        <h5 class="mb-0 fw-bold">Manajemen Grup</h5>
        <p class="text-muted mb-0" style="font-size:13px">Kelola grup keluarga, komunitas, atau kepanitiaan</p>
    </div>
    <button class="btn btn-sm btn-primary" onclick="openAddModal()">
        <i class="fa-solid fa-plus me-1"></i> Buat Grup Baru
    </button>
</div>

<div class="alert mb-4" style="background:#f0fdf4;border:1px solid #bbf7d0;border-radius:var(--radius);font-size:13px">
    <i class="fa-solid fa-circle-info me-2" style="color:var(--primary)"></i>
    <strong>Shared Catatan:</strong> Jika diaktifkan, catatan pribadi semua anggota otomatis ikut dihitung dalam rekap grup.
    Tanpa shared, gunakan prefix alias di pesan WA. Contoh: <code>keluarga: makan siang 50rb</code>
</div>

<?php if (empty($groups)): ?>
<div class="card">
    <div class="card-body text-center py-5">
        <i class="fa-solid fa-users fa-3x text-muted mb-3 d-block" style="opacity:.3"></i>
        <h6 class="text-muted fw-normal">Belum ada grup</h6>
        <p class="text-muted mb-3" style="font-size:13px">Buat grup untuk mencatat keuangan bersama</p>
        <button class="btn btn-sm btn-primary" onclick="openAddModal()">
            <i class="fa-solid fa-plus me-1"></i>Buat Grup Pertama
        </button>
    </div>
</div>
<?php else: ?>
<div class="row g-3">
    <?php foreach ($groups as $g): ?>
    <div class="col-md-6 col-lg-4">
        <div class="card h-100">
            <div class="card-body">
                <div class="d-flex align-items-start justify-content-between mb-3">
                    <div class="d-flex align-items-center gap-3">
                        <div style="width:44px;height:44px;background:<?= $g['is_shared']?'var(--primary-light)':'#f1f5f9' ?>;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:18px">
                            <?= $g['is_shared'] ? '&#128106;' : '&#128101;' ?>
                        </div>
                        <div>
                            <div style="font-weight:700;font-size:14px"><?= htmlspecialchars($g['name']) ?></div>
                            <div style="font-size:11px;color:var(--text-muted)">
                                <?= $g['member_count'] ?> anggota &bull;
                                <?= $g['role'] === 'owner' ? '<span style="color:var(--primary);font-weight:600">Owner</span>' : 'Member' ?>
                            </div>
                        </div>
                    </div>
                    <?php if ($g['role'] === 'owner'): ?>
                    <div class="dropdown">
                        <button type="button" class="btn btn-sm btn-outline-secondary" style="padding:4px 8px" data-bs-toggle="dropdown">
                            <i class="fa-solid fa-ellipsis-vertical"></i>
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end shadow-sm" style="font-size:13px;min-width:160px">
                            <li><a class="dropdown-item" href="#" onclick='openEditModal(<?= htmlspecialchars(json_encode($g)) ?>)'>
                                <i class="fa-solid fa-pen me-2 text-muted"></i>Edit Grup</a></li>
                            <li><a class="dropdown-item" href="#" onclick='openMembersModal(<?= $g["id"] ?>, <?= json_encode($g["name"]) ?>, true)'>
                                <i class="fa-solid fa-users me-2 text-muted"></i>Kelola Anggota</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item text-danger" href="#" onclick='deleteGroup(<?= $g["id"] ?>, <?= json_encode($g["name"]) ?>)'>
                                <i class="fa-solid fa-trash me-2"></i>Hapus Grup</a></li>
                        </ul>
                    </div>
                    <?php else: ?>
                    <div class="d-flex gap-1">
                        <button type="button" class="btn btn-sm btn-outline-primary" style="font-size:11px;padding:4px 10px"
                            onclick='openMembersModal(<?= $g["id"] ?>, <?= json_encode($g["name"]) ?>, false)'>
                            <i class="fa-solid fa-tags me-1"></i>Alias
                        </button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" style="font-size:11px;padding:4px 10px"
                            onclick='leaveGroup(<?= $g["id"] ?>, <?= json_encode($g["name"]) ?>)'>
                            Keluar
                        </button>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <?php if ($g['alias']): ?>
                    <span style="background:#f1f5f9;border-radius:6px;padding:3px 10px;font-size:11px;font-weight:600;font-family:var(--font-mono)">
                        <i class="fa-solid fa-at me-1 text-muted"></i><?= htmlspecialchars($g['alias']) ?>
                    </span>
                    <?php endif; ?>
                    <span style="background:<?= $g['is_shared']?'var(--primary-light)':'#f1f5f9' ?>;border-radius:6px;padding:3px 10px;font-size:11px;font-weight:600;color:<?= $g['is_shared']?'var(--primary)':'var(--text-muted)' ?>">
                        <i class="fa-solid <?= $g['is_shared']?'fa-link':'fa-link-slash' ?> me-1"></i>
                        <?= $g['is_shared'] ? 'Shared Catatan ON' : 'Shared Catatan OFF' ?>
                    </span>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Modal Grup -->
<div class="modal fade" id="groupModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:var(--radius)">
            <div class="modal-header border-bottom" style="padding:16px 20px">
                <h6 class="modal-title fw-bold" id="groupModalTitle">Buat Grup Baru</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:20px">
                <input type="hidden" id="groupId">
                <div class="mb-3">
                    <label class="form-label" style="font-size:12px;font-weight:600">Nama Grup</label>
                    <input type="text" id="groupName" class="form-control" placeholder="Contoh: Keluarga Inti" maxlength="100">
                </div>
                <div class="mb-3">
                    <label class="form-label" style="font-size:12px;font-weight:600">Alias / Prefix WA <span class="text-muted fw-normal">(opsional)</span></label>
                    <input type="text" id="groupAlias" class="form-control" placeholder="Contoh: keluarga, hut" maxlength="30" style="font-family:var(--font-mono)">
                    <div class="form-text">Contoh pemakaian: <code>hut: belanja hadiah 500rb</code></div>
                </div>
                <div class="mb-1">
                    <label class="form-label" style="font-size:12px;font-weight:600">Shared Catatan</label>
                    <div class="d-flex align-items-center justify-content-between p-3" style="background:#f8fafc;border-radius:var(--radius-sm);border:1px solid var(--card-border)">
                        <div>
                            <div style="font-size:13px;font-weight:600">Aktifkan Shared Catatan</div>
                            <div style="font-size:12px;color:var(--text-muted)">Catatan pribadi anggota otomatis masuk rekap grup</div>
                        </div>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" id="groupShared" style="width:40px;height:22px;cursor:pointer">
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top" style="padding:14px 20px">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Batal</button>
                <button type="button" class="btn btn-sm btn-primary" onclick="saveGroup()">
                    <i class="fa-solid fa-floppy-disk me-1"></i>Simpan
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Modal Anggota -->
<div class="modal fade" id="membersModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content" style="border-radius:var(--radius)">
            <div class="modal-header border-bottom" style="padding:16px 20px">
                <h6 class="modal-title fw-bold" id="membersModalTitle">Anggota Grup</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" style="padding:20px">
                <div class="mb-3 d-flex gap-2" id="addMemberRow">
                    <input type="text" id="newMemberWA" class="form-control form-control-sm" placeholder="Nomor WA anggota baru (628xxx)" style="font-family:var(--font-mono)">
                    <button type="button" class="btn btn-sm btn-primary" onclick="addMember()" style="white-space:nowrap">
                        <i class="fa-solid fa-plus me-1"></i>Tambah
                    </button>
                </div>
                <div id="membersList" style="min-height:80px"></div>
            </div>
        </div>
    </div>
</div>

<!-- JS: dirender setelah Bootstrap via $extraScript di footer -->
<?php
$myUserId   = (int)$_SESSION['user_id'];
$jsMyUserId = json_encode($myUserId);
ob_start();
?>
<script>
var groupModal   = new bootstrap.Modal(document.getElementById('groupModal'));
var membersModal = new bootstrap.Modal(document.getElementById('membersModal'));
var currentGroupId     = null;
var currentUserIsOwner = false;
var MY_USER_ID = <?php echo $jsMyUserId; ?>;

function openAddModal() {
    document.getElementById('groupModalTitle').textContent = 'Buat Grup Baru';
    document.getElementById('groupId').value    = '';
    document.getElementById('groupName').value  = '';
    document.getElementById('groupAlias').value = '';
    document.getElementById('groupShared').checked = false;
    groupModal.show();
}

function openEditModal(g) {
    document.getElementById('groupModalTitle').textContent = 'Edit Grup';
    document.getElementById('groupId').value    = g.id;
    document.getElementById('groupName').value  = g.name;
    document.getElementById('groupAlias').value = g.alias || '';
    document.getElementById('groupShared').checked = (g.is_shared == 1);
    groupModal.show();
}

async function saveGroup() {
    var id     = document.getElementById('groupId').value;
    var name   = document.getElementById('groupName').value.trim();
    var alias  = document.getElementById('groupAlias').value.trim().toLowerCase();
    var shared = document.getElementById('groupShared').checked ? 1 : 0;
    if (!name) { alert('Nama grup wajib diisi.'); return; }
    var method = id ? 'PUT' : 'POST';
    var url    = id ? '/api/groups.php?id=' + id : '/api/groups.php';
    var res    = await fetch(url, { method: method, headers: {'Content-Type':'application/json'}, body: JSON.stringify({name:name, alias:alias, is_shared:shared}) });
    var data   = await res.json();
    if (data.success) { groupModal.hide(); location.reload(); }
    else alert(data.message || 'Gagal menyimpan.');
}

async function deleteGroup(id, name) {
    if (!confirm('Hapus grup "' + name + '"?')) return;
    var res  = await fetch('/api/groups.php?id=' + id, { method: 'DELETE' });
    var data = await res.json();
    if (data.success) location.reload();
    else alert(data.message || 'Gagal menghapus.');
}

async function leaveGroup(id, name) {
    if (!confirm('Keluar dari grup "' + name + '"?')) return;
    var res  = await fetch('/api/groups.php?id=' + id + '&action=leave', { method: 'DELETE' });
    var data = await res.json();
    if (data.success) location.reload();
    else alert(data.message || 'Gagal keluar dari grup.');
}

async function openMembersModal(groupId, groupName, isOwner) {
    currentGroupId     = groupId;
    currentUserIsOwner = isOwner ? true : false;
    document.getElementById('membersModalTitle').textContent = 'Anggota — ' + groupName;
    document.getElementById('newMemberWA').value = '';
    document.getElementById('addMemberRow').style.display = isOwner ? '' : 'none';
    await loadMembers();
    membersModal.show();
}

async function loadMembers() {
    var res  = await fetch('/api/groups.php?id=' + currentGroupId + '&action=members');
    var data = await res.json();
    var list = document.getElementById('membersList');
    if (!data.data || !data.data.length) {
        list.innerHTML = '<div class="text-muted text-center py-3" style="font-size:13px">Belum ada anggota lain</div>';
        return;
    }
    var html = '';
    for (var i = 0; i < data.data.length; i++) {
        var m        = data.data[i];
        var isSelf   = (parseInt(m.user_id) === MY_USER_ID);
        var isOwnerRow = (m.role === 'owner');
        var canAlias   = !isSelf;
        var canRemove  = !isOwnerRow && currentUserIsOwner;
        var initial    = m.name ? m.name.charAt(0).toUpperCase() : '?';
        var selfLabel  = isSelf ? ' <span style="font-size:10px;color:var(--text-muted);font-weight:400">(Anda)</span>' : '';
        var roleLabel  = isOwnerRow ? '<span style="color:var(--primary);font-weight:600">Owner</span>' : '<span style="color:var(--text-muted)">Member</span>';
        var removeBtn  = canRemove ? '<button type="button" class="btn btn-sm btn-outline-danger" style="padding:2px 8px;font-size:11px" onclick="removeMember(' + m.user_id + ')"><i class="fa-solid fa-xmark"></i></button>' : '';
        var aliasVal   = m.my_alias || '';
        var aliasRow   = '';
        if (canAlias) {
            aliasRow  = '<div class="d-flex gap-2 ps-1 mt-2">';
            aliasRow += '<input type="text" id="alias_' + m.user_id + '" value="' + aliasVal + '" ';
            aliasRow += 'placeholder="Alias untuk ' + (m.name || 'anggota ini') + '" ';
            aliasRow += 'class="form-control form-control-sm" style="font-family:var(--font-mono);font-size:12px;max-width:240px" maxlength="50">';
            aliasRow += '<button type="button" id="aliasBtn_' + m.user_id + '" ';
            aliasRow += 'class="btn btn-sm btn-outline-primary" style="font-size:11px;padding:3px 10px;white-space:nowrap" ';
            aliasRow += 'onclick="saveAlias(' + m.user_id + ')">';
            aliasRow += '<i class="fa-solid fa-check me-1"></i>Simpan</button></div>';
            aliasRow += '<div style="font-size:10px;color:var(--text-muted);margin-top:4px;padding-left:4px">Contoh WA: <code>transfer ' + (aliasVal || 'nama_alias') + ' 500rb</code></div>';
        }
        html += '<div class="py-2 border-bottom">';
        html += '<div class="d-flex align-items-center justify-content-between">';
        html += '<div class="d-flex align-items-center gap-2">';
        html += '<div style="width:32px;height:32px;background:var(--primary-light);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;color:var(--primary)">' + initial + '</div>';
        html += '<div><div style="font-size:13px;font-weight:600">' + (m.name || '—') + selfLabel + '</div>';
        html += '<div style="font-size:11px;color:var(--text-muted);font-family:var(--font-mono)">' + m.wa_number + '</div></div></div>';
        html += '<div class="d-flex align-items-center gap-2">' + roleLabel + ' ' + removeBtn + '</div>';
        html += '</div>' + aliasRow + '</div>';
    }
    list.innerHTML = html;
}

async function addMember() {
    var wa = document.getElementById('newMemberWA').value.trim();
    if (!wa) { alert('Masukkan nomor WA.'); return; }
    var res  = await fetch('/api/groups.php?id=' + currentGroupId + '&action=add_member', { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify({wa_number:wa}) });
    var data = await res.json();
    if (data.success) { document.getElementById('newMemberWA').value = ''; loadMembers(); }
    else alert(data.message || 'Gagal menambah anggota.');
}

async function removeMember(memberId) {
    if (!confirm('Keluarkan anggota ini dari grup?')) return;
    var res  = await fetch('/api/groups.php?id=' + currentGroupId + '&action=remove_member', { method:'DELETE', headers:{'Content-Type':'application/json'}, body: JSON.stringify({user_id:memberId}) });
    var data = await res.json();
    if (data.success) loadMembers();
    else alert(data.message || 'Gagal mengeluarkan anggota.');
}

async function saveAlias(memberId) {
    var input = document.getElementById('alias_' + memberId);
    var btn   = document.getElementById('aliasBtn_' + memberId);
    var alias = input ? input.value.trim() : '';
    var res   = await fetch('/api/groups.php?id=' + currentGroupId + '&action=update_alias', { method:'PUT', headers:{'Content-Type':'application/json'}, body: JSON.stringify({user_id:memberId, alias:alias}) });
    var data  = await res.json();
    if (data.success) {
        if (btn) {
            var orig = btn.innerHTML;
            btn.innerHTML = '<i class="fa-solid fa-check me-1"></i>Tersimpan!';
            btn.className = btn.className.replace('btn-outline-primary','btn-success');
            setTimeout(function(){ btn.innerHTML = orig; btn.className = btn.className.replace('btn-success','btn-outline-primary'); }, 1500);
        }
    } else {
        alert(data.message || 'Gagal menyimpan alias.');
    }
}
</script>
<?php
$extraScript = ob_get_clean();
require_once __DIR__ . '/layout/footer.php';
?>
