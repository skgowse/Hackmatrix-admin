<?php
/**
 * HackMatrix 1.0 - Team-Based Participant Management
 */

@set_time_limit(0);
@ini_set('memory_limit', '512M');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Enforce login
requireLogin();

$pdo = getDBConnection();
$error = '';
$successMsg = '';

// Load configurations
$search = trim($_GET['search'] ?? '');
$domainFilter = trim($_GET['domain'] ?? '');
$sizeFilter = trim($_GET['team_size'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$limit = 10;
$page = max(1, intval($_GET['page'] ?? 1));
$offset = ($page - 1) * $limit;

// Database query construction
$queryStr = "SELECT t.*, 
             (SELECT name FROM team_members WHERE team_id = t.id AND role = 'Team Lead' LIMIT 1) AS team_lead,
             (SELECT email FROM team_members WHERE team_id = t.id AND role = 'Team Lead' LIMIT 1) AS lead_email
             FROM teams t WHERE 1=1";
$params = [];

if ($search !== '') {
    $queryStr .= " AND (t.team_id LIKE ? OR t.team_name LIKE ? OR t.college LIKE ? OR t.project_title LIKE ? 
                   OR EXISTS (SELECT 1 FROM team_members WHERE team_id = t.id AND (name LIKE ? OR email LIKE ? OR mobile LIKE ?)))";
    $searchWild = '%' . $search . '%';
    $params = array_merge($params, [$searchWild, $searchWild, $searchWild, $searchWild, $searchWild, $searchWild, $searchWild]);
}

if ($domainFilter !== '') {
    $queryStr .= " AND t.domain = ?";
    $params[] = $domainFilter;
}

if ($sizeFilter !== '') {
    $queryStr .= " AND t.team_size = ?";
    $params[] = intval($sizeFilter);
}

if ($statusFilter !== '') {
    $queryStr .= " AND t.status = ?";
    $params[] = $statusFilter;
}

try {
    // Count total rows for pagination
    $countQueryStr = "SELECT COUNT(*) FROM (" . $queryStr . ") AS count_table";
    $stmtCount = $pdo->prepare($countQueryStr);
    $stmtCount->execute($params);
    $totalRows = $stmtCount->fetchColumn();
    $totalPages = ceil($totalRows / $limit);
    
    // Retrieve paginated records
    $queryStr .= " ORDER BY t.id DESC LIMIT $limit OFFSET $offset";
    $stmt = $pdo->prepare($queryStr);
    $stmt->execute($params);
    $teams = $stmt->fetchAll();
    
    // Get unique domains for filter selector
    $domains = ['AI & ML', 'Cloud Computing', 'Cybersecurity', 'Robotics'];
} catch (Exception $e) {
    $error = 'Database error: ' . $e->getMessage();
}

require_once __DIR__ . '/header.php';
?>

<div class="page-header">
    <div class="page-title">
        <h1>Participant Management</h1>
        <p>Monitor registrations, manage teams, edit details, and export datasets.</p>
    </div>
    <div class="btn-group">
        <button onclick="openAddTeamModal()" class="btn btn-primary">
            <svg viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
            Add Team
        </button>
        <button onclick="triggerExport('csv')" class="btn btn-secondary">
            <svg viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Export CSV
        </button>
        <button onclick="triggerExport('excel')" class="btn btn-secondary" style="border-color: var(--success); color: #34d399;">
            <svg viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
            Export Excel
        </button>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert alert-danger">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        <?= e($error) ?>
    </div>
<?php endif; ?>

<!-- Listing & Filters Section -->
<div class="card" style="padding: 18px 24px;">
    <!-- Combined Filter Panel -->
    <form method="GET" class="table-controls" style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
        <div class="search-box" style="flex: 1; min-width: 250px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" name="search" class="form-control" placeholder="Search Team ID, name, email, college..." value="<?= e($search) ?>">
        </div>
        
        <select name="domain" class="form-control" style="max-width: 180px; margin-bottom: 0;">
            <option value="">All Domains</option>
            <?php foreach ($domains as $d): ?>
                <option value="<?= e($d) ?>" <?= $domainFilter === $d ? 'selected' : '' ?>><?= e($d) ?></option>
            <?php endforeach; ?>
        </select>
        
        <select name="team_size" class="form-control" style="max-width: 140px; margin-bottom: 0;">
            <option value="">Any Size</option>
            <option value="2" <?= $sizeFilter === '2' ? 'selected' : '' ?>>2 Members</option>
            <option value="3" <?= $sizeFilter === '3' ? 'selected' : '' ?>>3 Members</option>
            <option value="4" <?= $sizeFilter === '4' ? 'selected' : '' ?>>4 Members</option>
        </select>
        
        <select name="status" class="form-control" style="max-width: 140px; margin-bottom: 0;">
            <option value="">Any Status</option>
            <option value="ACTIVE" <?= $statusFilter === 'ACTIVE' ? 'selected' : '' ?>>ACTIVE</option>
            <option value="INACTIVE" <?= $statusFilter === 'INACTIVE' ? 'selected' : '' ?>>INACTIVE</option>
        </select>
        
        <button type="submit" class="btn btn-primary" style="padding: 10px 20px;">Filter</button>
        
        <?php if ($search !== '' || $domainFilter !== '' || $sizeFilter !== '' || $statusFilter !== ''): ?>
            <a href="participants.php" class="btn btn-secondary" style="padding: 10px 16px;">Clear</a>
        <?php endif; ?>
    </form>
    
    <!-- Table Grid -->
    <div class="table-container" style="margin-top: 20px;">
        <table class="table">
            <thead>
                <tr>
                    <th>Team ID</th>
                    <th>Team Name</th>
                    <th>Team Lead</th>
                    <th>Lead Email</th>
                    <th>Size</th>
                    <th>Domain</th>
                    <th>College</th>
                    <th>Status</th>
                    <th style="text-align: right; width: 180px;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($teams) && count($teams) > 0): ?>
                    <?php foreach ($teams as $t): ?>
                        <tr>
                            <td style="font-family: var(--font-mono); font-weight: 700; font-size: 13px; color: var(--accent-primary);">
                                <?= e($t['team_id']) ?>
                            </td>
                            <td style="font-weight: 600; color: white;">
                                <?= e($t['team_name']) ?>
                            </td>
                            <td><?= e($t['team_lead'] ?: 'N/A') ?></td>
                            <td style="font-size: 13px; color: var(--text-muted);"><?= e($t['lead_email'] ?: 'N/A') ?></td>
                            <td>
                                <span class="tag" style="background: rgba(99, 102, 241, 0.1); color: #a5b4fc; font-weight: 600;">
                                    <?= e($t['team_size']) ?> Members
                                </span>
                            </td>
                            <td style="font-size: 13px; font-weight: 500; color: #e2e8f0;"><?= e($t['domain']) ?></td>
                            <td style="font-size: 13px; max-width: 150px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;">
                                <?= e($t['college']) ?>
                            </td>
                            <td>
                                <span class="tag <?= $t['status'] === 'ACTIVE' ? 'tag-success' : 'tag-danger' ?>">
                                    <?= e($t['status']) ?>
                                </span>
                            </td>
                            <td style="text-align: right;">
                                <div style="display: inline-flex; gap: 8px;">
                                    <button onclick="viewTeam(<?= $t['id'] ?>)" class="btn btn-secondary" style="padding: 6px 10px; font-size: 12px; border-color: rgba(255,255,255,0.1);">
                                        View
                                    </button>
                                    <button onclick="editTeam(<?= $t['id'] ?>)" class="btn btn-secondary" style="padding: 6px 10px; font-size: 12px; border-color: var(--accent-primary); color: #a5b4fc;">
                                        Edit
                                    </button>
                                    <button onclick="confirmDelete(<?= $t['id'] ?>, '<?= e($t['team_id']) ?>', '<?= e($t['team_name']) ?>')" class="btn btn-danger" style="padding: 6px 10px; font-size: 12px;">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            No teams found.
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div class="pagination" style="margin-top: 20px;">
            <span style="font-size: 13px; color: var(--text-muted);">
                Showing <?= $offset + 1 ?> to <?= min($offset + $limit, $totalRows) ?> of <?= $totalRows ?> teams
            </span>
            <div class="pagination-links">
                <?php if ($page > 1): ?>
                    <a href="participants.php?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&domain=<?= urlencode($domainFilter) ?>&team_size=<?= urlencode($sizeFilter) ?>&status=<?= urlencode($statusFilter) ?>">&laquo; Prev</a>
                <?php endif; ?>
                
                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <?php if ($i == $page): ?>
                        <span class="active"><?= $i ?></span>
                    <?php else: ?>
                        <a href="participants.php?page=<?= $i ?>&search=<?= urlencode($search) ?>&domain=<?= urlencode($domainFilter) ?>&team_size=<?= urlencode($sizeFilter) ?>&status=<?= urlencode($statusFilter) ?>"><?= $i ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                
                <?php if ($page < $totalPages): ?>
                    <a href="participants.php?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&domain=<?= urlencode($domainFilter) ?>&team_size=<?= urlencode($sizeFilter) ?>&status=<?= urlencode($statusFilter) ?>">Next &raquo;</a>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<!-- ==================== DETAILED VIEW / EDIT MODAL ==================== -->
<div id="teamDetailsModal" class="modal-overlay" style="display: none; position: fixed; top: 0; left: 0; width:100%; height:100%; background:rgba(0,0,0,0.8); align-items:center; justify-content:center; z-index:1000; padding:20px;">
    <div class="modal" style="width:100%; max-width: 850px; background:#0f172a; border: 1px solid var(--border-color); border-radius:16px; padding:30px; box-shadow:0 10px 40px rgba(0,0,0,0.5); display:flex; flex-direction:column; max-height:90vh; overflow-y:auto;">
        <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid var(--border-color); padding-bottom:15px; margin-bottom:20px;">
            <h3 id="modalTitle" style="color:white; font-size:18px; font-weight:700;">Team Details</h3>
            <button onclick="closeModal()" style="background:none; border:none; color:var(--text-muted); font-size:24px; cursor:pointer;">&times;</button>
        </div>
        
        <form id="teamModalForm" onsubmit="handleFormSubmit(event)">
            <?php csrfInput(); ?>
            <input type="hidden" name="team_db_id" id="team_db_id">
            
            <!-- TEAM PROFILE DETAILS -->
            <div id="modalTeamProfile" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom:24px;">
                <div class="form-group">
                    <label>Team Name</label>
                    <input type="text" name="team_name" id="modal_team_name" class="form-control" required placeholder="e.g. Vision Coders">
                </div>
                
                <div class="form-group">
                    <label>College / Institution</label>
                    <select name="college" id="modal_college" class="form-control" required>
                        <option value="">Select College Name</option>
                        <option value="VIIT">VIIT</option>
                        <option value="VIEW">VIEW</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Team Size</label>
                    <select name="team_size" id="modal_team_size" class="form-control" required>
                        <option value="">Select Team Size</option>
                        <option value="2">2 Members</option>
                        <option value="3">3 Members</option>
                        <option value="4">4 Members</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Domain</label>
                    <select name="domain" id="modal_domain" class="form-control" required>
                        <option value="">Select Domain</option>
                        <option value="AI & ML">AI & ML</option>
                        <option value="Cloud Computing">Cloud Computing</option>
                        <option value="Cybersecurity">Cybersecurity</option>
                        <option value="Robotics">Robotics</option>
                    </select>
                </div>

                <input type="hidden" name="project_title" id="modal_project_title">
                
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" id="modal_status" class="form-control">
                        <option value="ACTIVE">ACTIVE</option>
                        <option value="INACTIVE">INACTIVE</option>
                    </select>
                </div>
            </div>
            
            <!-- TEAM MEMBERS SECTION -->
            <h4 style="color:white; font-size:15px; font-weight:700; margin-bottom:15px; border-bottom:1px solid var(--border-color); padding-bottom:8px;">
                Team Members
            </h4>
            
            <div id="modalMembersContainer" style="display:grid; grid-template-columns:1fr; gap:20px; max-height:40vh; overflow-y:auto; padding-right:5px;">
                <!-- Members cards generated dynamically -->
            </div>
            
            <!-- BUTTONS -->
            <div class="btn-group" style="margin-top: 30px; justify-content: flex-end; display:flex; gap:10px;">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Close</button>
                <button type="submit" id="saveTeamBtn" class="btn btn-primary">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
    const teamModal = document.getElementById('teamDetailsModal');
    const modalForm = document.getElementById('teamModalForm');
    const membersContainer = document.getElementById('modalMembersContainer');
    const modalTitle = document.getElementById('modalTitle');
    const saveBtn = document.getElementById('saveTeamBtn');
    const sizeSelect = document.getElementById('modal_team_size');
    
    let activeMode = 'view'; // 'view', 'edit', 'add'
    let existingMembersData = []; // Cached when editing
    
    // Options
    const years = ['1', '2', '3', '4'];
    const branches = ['AIDS', 'AIML', 'CSE', 'IT', 'ECE', 'EEE', 'MECH', 'CIVIL', 'CS', 'Other'];

    // Render forms inside the modal based on size selection
    function renderModalMembers(size) {
        membersContainer.innerHTML = '';
        
        for (let i = 0; i < size; i++) {
            const isLead = (i === 0);
            const index = i;
            const saved = existingMembersData[i] || { id: '', name: '', email: '', mobile: '', branch: '', year: '' };
            const disabled = activeMode === 'view' ? 'disabled' : '';
            
            const card = document.createElement('div');
            card.style.background = 'rgba(255,255,255,0.02)';
            card.style.border = '1px solid var(--border-color)';
            card.style.borderRadius = '10px';
            card.style.padding = '15px';
            card.style.position = 'relative';
            card.style.borderLeft = isLead ? '4px solid #f59e0b' : '4px solid var(--accent-primary)';
            
            // Hidden member ID
            let idField = saved.id ? `<input type="hidden" name="members[${index}][id]" value="${saved.id}">` : '';
            
            let branchOptions = '<option value="">Select Branch</option>';
            branches.forEach(b => {
                branchOptions += `<option value="${b}" ${saved.branch === b ? 'selected' : ''}>${b}</option>`;
            });

            let yearOptions = '<option value="">Select Year</option>';
            years.forEach(y => {
                yearOptions += `<option value="${y}" ${saved.year === y ? 'selected' : ''}>${y} Year</option>`;
            });
            
            card.innerHTML = `
                ${idField}
                <div style="font-weight:700; color:white; font-size:12px; margin-bottom:12px; text-transform:uppercase; letter-spacing:1px; display:flex; justify-content:space-between;">
                    <span>${isLead ? 'Team Lead' : 'Member #' + (index + 1)}</span>
                    <span style="color:var(--text-muted); font-size:11px;">${saved.certificate_id || 'Pending Generation'}</span>
                </div>
                <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px;">
                    <div class="form-group">
                        <label style="font-size:10px;">Full Name</label>
                        <input type="text" name="members[${index}][name]" class="form-control" required value="${e(saved.name)}" ${disabled}>
                    </div>
                    <div class="form-group">
                        <label style="font-size:10px;">Email Address</label>
                        <input type="email" name="members[${index}][email]" class="form-control" required value="${e(saved.email)}" ${disabled}>
                    </div>
                    <div class="form-group">
                        <label style="font-size:10px;">Mobile Number</label>
                        <input type="text" name="members[${index}][mobile]" class="form-control" required value="${e(saved.mobile)}" ${disabled} pattern="[0-9]{10}">
                    </div>
                    <div class="form-group" style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                        <div>
                            <label style="font-size:10px;">Branch</label>
                            <select name="members[${index}][branch]" class="form-control" ${disabled}>
                                ${branchOptions}
                            </select>
                        </div>
                        <div>
                            <label style="font-size:10px;">Year</label>
                            <select name="members[${index}][year]" class="form-control" ${disabled}>
                                ${yearOptions}
                            </select>
                        </div>
                    </div>
                </div>
            `;
            
            membersContainer.appendChild(card);
        }
    }
    
    // Bind change listener to the team size selector inside modal
    sizeSelect.addEventListener('change', function() {
        if (activeMode !== 'view') {
            // Re-read current UI inputs to cache them temporarily
            const uiData = [];
            for (let i = 0; i < 4; i++) {
                const nameInput = document.querySelector(`input[name="members[${i}][name]"]`);
                if (nameInput) {
                    const idInput = document.querySelector(`input[name="members[${i}][id]"]`);
                    uiData[i] = {
                        id: idInput ? idInput.value : '',
                        name: nameInput.value,
                        email: document.querySelector(`input[name="members[${i}][email]"]`).value,
                        mobile: document.querySelector(`input[name="members[${i}][mobile]"]`).value,
                        branch: document.querySelector(`select[name="members[${i}][branch]"]`).value,
                        year: document.querySelector(`select[name="members[${i}][year]"]`).value
                    };
                }
            }
            existingMembersData = uiData;
            renderModalMembers(parseInt(this.value));
        }
    });

    // View Team Modal trigger
    function viewTeam(id) {
        activeMode = 'view';
        modalTitle.innerText = 'Team Details (Read Only)';
        saveBtn.style.display = 'none';
        toggleFormInputs(true);
        loadTeamData(id);
    }
    
    // Edit Team Modal trigger
    function editTeam(id) {
        activeMode = 'edit';
        modalTitle.innerText = 'Edit Team Details';
        saveBtn.style.display = 'block';
        saveBtn.innerText = 'Update Team';
        toggleFormInputs(false);
        loadTeamData(id);
    }
    
    // Add Team Modal trigger
    function openAddTeamModal() {
        activeMode = 'add';
        modalTitle.innerText = 'Add Team Manually';
        saveBtn.style.display = 'block';
        saveBtn.innerText = 'Register Team';
        toggleFormInputs(false);
        
        // Reset all inputs
        document.getElementById('team_db_id').value = '';
        document.getElementById('modal_team_name').value = '';
        document.getElementById('modal_college').value = '';
        document.getElementById('modal_team_size').value = '3';
        document.getElementById('modal_domain').value = 'AI & ML';
        document.getElementById('modal_project_title').value = '';
        document.getElementById('modal_status').value = 'ACTIVE';
        
        existingMembersData = [];
        renderModalMembers(3);
        teamModal.style.display = 'flex';
    }

    // Load data from server API
    function loadTeamData(id) {
        fetch(`../api/admin/participant-view.php?id=${id}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('team_db_id').value = data.team.id;
                    document.getElementById('modal_team_name').value = data.team.team_name;
                    document.getElementById('modal_college').value = data.team.college;
                    document.getElementById('modal_team_size').value = data.team.team_size;
                    document.getElementById('modal_domain').value = data.team.domain;
                    document.getElementById('modal_project_title').value = data.team.project_title;
                    document.getElementById('modal_status').value = data.team.status;
                    
                    existingMembersData = data.members;
                    renderModalMembers(parseInt(data.team.team_size));
                    teamModal.style.display = 'flex';
                } else {
                    showToast(data.message, 'danger');
                }
            })
            .catch(() => showToast('Failed to load team data from server.', 'danger'));
    }

    function toggleFormInputs(disabled) {
        const fields = ['modal_team_name', 'modal_college', 'modal_team_size', 'modal_domain', 'modal_project_title', 'modal_status'];
        fields.forEach(f => {
            document.getElementById(f).disabled = disabled;
        });
    }

    function closeModal() {
        teamModal.style.display = 'none';
    }

    // Form Submission (Add or Update)
    function handleFormSubmit(e) {
        e.preventDefault();
        
        const form = document.getElementById('teamModalForm');
        const formData = new FormData(form);
        
        // Determine correct endpoint based on mode
        let endpoint = '../api/admin/participant-update.php';
        if (activeMode === 'add') {
            endpoint = '../api/registration/register.php';
        }
        
        saveBtn.disabled = true;
        saveBtn.innerText = 'Saving changes...';
        
        fetch(endpoint, {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            saveBtn.disabled = false;
            saveBtn.innerText = 'Save Changes';
            
            if (data.success) {
                showToast(data.message, 'success');
                setTimeout(() => window.location.reload(), 1000);
            } else {
                showToast(data.message, 'danger');
            }
        })
        .catch(() => {
            saveBtn.disabled = false;
            saveBtn.innerText = 'Save Changes';
            showToast('Failed to save data. Network error.', 'danger');
        });
    }

    // Confirm Deletion
    function confirmDelete(id, code, name) {
        const confirmation = confirm(`Are you sure you want to delete this team?\n\nTeam ID: ${code}\nTeam Name: ${name}\n\nWarning: All associated team members, certificates, and email logs will be deleted permanently.`);
        if (confirmation) {
            const formData = new FormData();
            formData.append('id', id);
            formData.append('csrf_token', '<?= generateCSRFToken() ?>');
            
            fetch('../api/admin/participant-delete.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    showToast(data.message, 'success');
                    setTimeout(() => window.location.reload(), 1000);
                } else {
                    showToast(data.message, 'danger');
                }
            })
            .catch(() => showToast('Connection error during deletion.', 'danger'));
        }
    }

    // Export routing helper
    function triggerExport(format) {
        const queryParams = new URLSearchParams(window.location.search);
        let endpoint = format === 'csv' ? '../api/admin/export-csv.php' : '../api/admin/export-excel.php';
        window.location.href = endpoint + '?' + queryParams.toString();
    }

    // Utility string HTML escaper
    function e(str) {
        if (!str) return '';
        return str.replace(/&/g, '&amp;')
                  .replace(/</g, '&lt;')
                  .replace(/>/g, '&gt;')
                  .replace(/"/g, '&quot;')
                  .replace(/'/g, '&#039;');
    }
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
