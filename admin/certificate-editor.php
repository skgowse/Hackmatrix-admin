<?php
/**
 * HackMatrix 1.0 - Visual Certificate Field Editor
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

// Enforce login
requireLogin();

$pdo = getDBConnection();
$error = '';
$success = false;

// Get Active Template
$template = $pdo->query("SELECT * FROM certificate_templates LIMIT 1")->fetch();

if (!$template) {
    header("Location: templates.php");
    exit();
}

// Handle AJAX Save Coordinates (MUST be processed before header.php prints any HTML)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_coordinates'])) {
    $csrfToken = $_POST['csrf_token'] ?? '';
    
    if (!validateCSRFToken($csrfToken)) {
        jsonResponse(false, 'Invalid security token (CSRF failure).');
    }
    
    $fieldsData = json_decode($_POST['fields_data'] ?? '[]', true);
    
    if (empty($fieldsData)) {
        jsonResponse(false, 'No coordinate data received.');
    }
    
    try {
        $pdo->beginTransaction();
        
        $stmt = $pdo->prepare("INSERT INTO certificate_fields 
            (template_id, field_name, x_position, y_position, font_name, font_size, font_style, text_color, alignment) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
            x_position = VALUES(x_position),
            y_position = VALUES(y_position),
            font_name = VALUES(font_name),
            font_size = VALUES(font_size),
            font_style = VALUES(font_style),
            text_color = VALUES(text_color),
            alignment = VALUES(alignment)");
            
        foreach ($fieldsData as $fd) {
            $stmt->execute([
                $template['id'],
                $fd['field_name'],
                $fd['x_position'],
                $fd['y_position'],
                $fd['font_name'],
                $fd['font_size'],
                $fd['font_style'],
                $fd['text_color'],
                $fd['alignment']
            ]);
        }
        
        $pdo->commit();
        logActivity('SMTP_SETTINGS_CHANGED', 'Updated certificate template field positions.');
        jsonResponse(true, 'Field configuration and positions saved successfully.');
    } catch (Exception $e) {
        $pdo->rollBack();
        jsonResponse(false, 'Database error: ' . $e->getMessage());
    }
}

require_once __DIR__ . '/header.php';

// Load existing fields configuration
$stmtFields = $pdo->prepare("SELECT * FROM certificate_fields WHERE template_id = ?");
$stmtFields->execute([$template['id']]);
$fieldsList = $stmtFields->fetchAll();

// Map DB rows to JS-friendly associative array
$configFields = [];
foreach ($fieldsList as $f) {
    $configFields[$f['field_name']] = [
        'x' => $f['x_position'],
        'y' => $f['y_position'],
        'font_name' => $f['font_name'],
        'font_size' => $f['font_size'],
        'font_style' => $f['font_style'],
        'text_color' => $f['text_color'],
        'alignment' => $f['alignment']
    ];
}
?>

<div class="page-header">
    <div class="page-title">
        <h1>Certificate Editor</h1>
        <p>Visual drag-and-drop workspace to arrange participant data fields on the background template.</p>
    </div>
    <div class="btn-group">
        <button id="saveConfigBtn" class="btn btn-primary">
            <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
            Save Layout Settings
        </button>
    </div>
</div>

<div style="display: grid; grid-template-columns: 280px 1fr; gap: 24px; min-height: 500px;" class="editor-layout">
    <!-- Configuration panel -->
    <div class="card" style="padding: 20px; display: flex; flex-direction: column; gap: 16px;">
        <h3 style="font-size: 15px; font-weight: 700; border-bottom: 1px solid var(--border-color); padding-bottom: 10px; margin-bottom: 4px;">Field Settings</h3>
        
        <div class="form-group">
            <label for="activeFieldSelector">SELECT FIELD</label>
            <select id="activeFieldSelector" class="form-control">
                <option value="participant_name">Name ({{participant_name}})</option>
                <option value="branch">Branch ({{branch}})</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="fieldFont">FONT FAMILY</label>
            <select id="fieldFont" class="form-control font-setting">
                <option value="helvetica">Helvetica (Default)</option>
                <option value="times">Times New Roman</option>
                <option value="courier">Courier</option>
            </select>
        </div>

        <div class="form-group">
            <label for="fieldFontSize">FONT SIZE (PT)</label>
            <input type="number" id="fieldFontSize" class="form-control font-setting" min="8" max="72" value="16">
        </div>

        <div class="form-group">
            <label for="fieldFontStyle">FONT STYLE</label>
            <select id="fieldFontStyle" class="form-control font-setting">
                <option value="">Regular</option>
                <option value="B">Bold</option>
                <option value="I">Italic</option>
                <option value="BI">Bold Italic</option>
            </select>
        </div>

        <div class="form-group">
            <label for="fieldTextColor">TEXT COLOR</label>
            <div style="display: flex; gap: 8px;">
                <input type="color" id="fieldTextColor" class="form-control font-setting" style="width: 50px; padding: 4px; height: 38px;" value="#000000">
                <input type="text" id="fieldTextColorHex" class="form-control" style="flex: 1; font-family: var(--font-mono); font-size: 13px;" value="#000000">
            </div>
        </div>

        <div class="form-group">
            <label for="fieldAlignment">ALIGNMENT</label>
            <select id="fieldAlignment" class="form-control font-setting">
                <option value="L">Left</option>
                <option value="C">Center</option>
                <option value="R">Right</option>
            </select>
        </div>

        <div style="font-size: 11px; color: var(--text-muted); background: rgba(255,255,255,0.02); padding: 12px; border-radius: 8px; border: 1px solid var(--border-color); line-height: 1.5;">
            > [!TIP]<br>
            Use mouse cursor to drag fields directly. Adjust style settings to update preview instantly. Coordinates are relative percentages.
        </div>
    </div>
    
    <!-- Drag workspace canvas -->
    <div class="card" style="padding: 10px; background: #070a13; display: flex; flex-direction: column; overflow: auto; align-items: center; justify-content: center; position: relative;">
        <!-- Relative Wrapper -->
        <div id="canvasWrapper" style="position: relative; box-shadow: 0 10px 40px rgba(0,0,0,0.6); display: inline-block;">
            <!-- Render Target for Image or PDF Canvas -->
            <div id="renderTarget" style="display: block; pointer-events: none;"></div>
            
            <!-- Draggable Fields Overlays -->
            <div id="presented_to_helper" class="editor-helper-text" style="color: #4b5563; font-style: italic; font-family: Helvetica, sans-serif; pointer-events: none; position: absolute; transform: translate(-50%, -50%); white-space: nowrap; text-align: center; font-size: 12px; opacity: 0.85;">This certificate is proudly presented to</div>
            <div id="participant_name_drag" class="draggable-field" data-field="participant_name">Rahul Kumar</div>
            
            <div id="of_helper" class="editor-helper-text" style="color: #4b5563; font-style: italic; font-family: Helvetica, sans-serif; pointer-events: none; position: absolute; transform: translate(-50%, -50%); white-space: nowrap; text-align: center; font-size: 12px; opacity: 0.85;">of</div>
            <div id="branch_drag" class="draggable-field" data-field="branch">Artificial Intelligence & Data Science</div>
            
            <div id="desc_helper" class="editor-helper-text" style="color: #374151; font-family: Helvetica, sans-serif; pointer-events: none; position: absolute; transform: translate(-50%, 0); text-align: center; line-height: 1.4; opacity: 0.9;">for successfully participating in HackMatrix 1.0, a 2-Day Hackathon organized by the Department of Artificial Intelligence & Data Science, VIIT.</div>
        </div>
    </div>
</div>

<!-- Styling for Draggable Fields Overlay -->
<style>
    .draggable-field {
        position: absolute;
        cursor: move;
        user-select: none;
        padding: 4px 8px;
        background: rgba(59, 130, 246, 0.15);
        border: 1px dashed var(--accent-primary);
        border-radius: 4px;
        color: white;
        white-space: nowrap;
        font-size: 14px;
        transform: translate(-50%, -50%); /* Center coordinate mapping anchor */
    }
    
    .draggable-field:hover, .draggable-field.active {
        background: rgba(59, 130, 246, 0.35);
        border-color: white;
        box-shadow: 0 0 10px rgba(59, 130, 246, 0.5);
        z-index: 10;
    }
</style>

<!-- PDF.js CDN library -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.min.js"></script>

<script>
    // Configuration details loaded from PHP DB query
    const config = <?= json_encode($configFields) ?>;
    const templateFilePath = "../<?= e($template['file_path']) ?>";
    const templateOrientation = "<?= e($template['orientation']) ?>";
    
    const wrapper = document.getElementById('canvasWrapper');
    const target = document.getElementById('renderTarget');
    const fieldSelector = document.getElementById('activeFieldSelector');
    
    let containerWidth = 800; // Calculated on render
    let containerHeight = 600;
    
    // Load and render template
    window.addEventListener('load', function() {
        const fileExt = templateFilePath.split('.').pop().toLowerCase();
        
        if (fileExt === 'pdf') {
            renderPDFTemplate();
        } else {
            renderImageTemplate();
        }
    });

    function renderImageTemplate() {
        const img = new Image();
        img.src = templateFilePath;
        img.style.cssText = 'max-width: 800px; width: 100%; display: block;';
        img.onload = function() {
            target.innerHTML = '';
            target.appendChild(img);
            setTimeout(initializeWorkspace, 100);
        };
    }

    function renderPDFTemplate() {
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/2.16.105/pdf.worker.min.js';
        
        pdfjsLib.getDocument(templateFilePath).promise.then(function(pdf) {
            pdf.getPage(1).then(function(page) {
                const canvas = document.createElement('canvas');
                const ctx = canvas.getContext('2d');
                
                // Calculate scale to fit width ~800px
                const viewport = page.getViewport({ scale: 1.0 });
                const scale = 800 / viewport.width;
                const scaledViewport = page.getViewport({ scale: scale });
                
                canvas.width = scaledViewport.width;
                canvas.height = scaledViewport.height;
                
                target.innerHTML = '';
                target.appendChild(canvas);
                
                const renderContext = {
                    canvasContext: ctx,
                    viewport: scaledViewport
                };
                
                page.render(renderContext).promise.then(function() {
                    setTimeout(initializeWorkspace, 100);
                });
            });
        });
    }

    function updateHelperPositions() {
        const nameEl = document.getElementById('participant_name_drag');
        const branchEl = document.getElementById('branch_drag');
        
        const presentedHelper = document.getElementById('presented_to_helper');
        const ofHelper = document.getElementById('of_helper');
        const descHelper = document.getElementById('desc_helper');
        
        // Scale factor: A4 landscape height is 595.27pt.
        const ptToPx = containerHeight / 595.27;
        
        if (nameEl) {
            const namePctY = parseFloat(nameEl.style.top) || 53;
            const namePctX = parseFloat(nameEl.style.left) || 50;
            
            presentedHelper.style.left = namePctX + '%';
            presentedHelper.style.top = `calc(${namePctY}% - ${39.6 * ptToPx}px)`;
            
            ofHelper.style.left = namePctX + '%';
            ofHelper.style.top = `calc(${namePctY}% + ${21.2 * ptToPx}px)`;
        }
        
        if (branchEl) {
            const branchPctY = parseFloat(branchEl.style.top) || 64;
            const branchPctX = parseFloat(branchEl.style.left) || 50;
            
            descHelper.style.left = branchPctX + '%';
            descHelper.style.top = `calc(${branchPctY}% + ${38.2 * ptToPx}px)`;
            descHelper.style.width = (containerWidth * 0.7) + 'px';
            
            const scaledDescSize = Math.max(9, Math.round(11.5 * (containerWidth / 842)));
            descHelper.style.fontSize = scaledDescSize + 'px';
        }
    }

    function initializeWorkspace() {
        const rect = target.getBoundingClientRect();
        containerWidth = rect.width;
        containerHeight = rect.height;
        
        wrapper.style.width = containerWidth + 'px';
        wrapper.style.height = containerHeight + 'px';
        
        // Position fields according to loaded config (or defaults)
        const fields = ['participant_name', 'branch'];
        
        fields.forEach(f => {
            const dragEl = document.getElementById(f + '_drag');
            if (dragEl && config[f]) {
                const cfg = config[f];
                // Position element
                dragEl.style.left = cfg.x + '%';
                dragEl.style.top = cfg.y + '%';
                
                // Apply design configurations visually in editor
                applyFieldStyle(dragEl, cfg);
            }
        });
        
        // Render helper texts
        updateHelperPositions();
        
        // Select first field configuration details
        selectField(fieldSelector.value);
    }
    
    function applyFieldStyle(el, cfg) {
        // Map font sizes from pt to scaled screen pixels
        // A4 page is ~842 pt wide. The editor is 800px wide. Scaling is close to 1:1.
        const scaledSize = Math.max(10, Math.round(cfg.font_size * (containerWidth / 842)));
        
        el.style.fontSize = scaledSize + 'px';
        el.style.fontFamily = cfg.font_name === 'times' ? '"Times New Roman", serif' : (cfg.font_name === 'courier' ? 'Courier, monospace' : 'Helvetica, sans-serif');
        el.style.fontWeight = cfg.font_style.includes('B') ? 'bold' : 'normal';
        el.style.fontStyle = cfg.font_style.includes('I') ? 'italic' : 'normal';
        el.style.color = cfg.text_color;
        
        // Alignment changes transform origin anchor points
        if (cfg.alignment === 'C') {
            el.style.transform = 'translate(-50%, -50%)';
            el.style.textAlign = 'center';
        } else if (cfg.alignment === 'R') {
            el.style.transform = 'translate(-100%, -50%)';
            el.style.textAlign = 'right';
        } else {
            el.style.transform = 'translate(0%, -50%)';
            el.style.textAlign = 'left';
        }
    }

    // Handle field switching
    fieldSelector.addEventListener('change', function() {
        selectField(this.value);
    });
    
    function selectField(fieldName) {
        // Highlight active field visually
        document.querySelectorAll('.draggable-field').forEach(el => el.classList.remove('active'));
        const activeDrag = document.getElementById(fieldName + '_drag');
        if (activeDrag) activeDrag.classList.add('active');
        
        const cfg = config[fieldName] || {
            font_name: 'helvetica',
            font_size: 16,
            font_style: '',
            text_color: '#000000',
            alignment: 'C'
        };
        
        document.getElementById('fieldFont').value = cfg.font_name;
        document.getElementById('fieldFontSize').value = cfg.font_size;
        document.getElementById('fieldFontStyle').value = cfg.font_style;
        document.getElementById('fieldTextColor').value = cfg.text_color;
        document.getElementById('fieldTextColorHex').value = cfg.text_color;
        document.getElementById('fieldAlignment').value = cfg.alignment;
    }
    
    // Style control updates
    document.querySelectorAll('.font-setting').forEach(input => {
        input.addEventListener('input', function() {
            const field = fieldSelector.value;
            if (!config[field]) config[field] = {};
            
            const fieldId = this.id;
            
            if (fieldId === 'fieldFont') config[field].font_name = this.value;
            if (fieldId === 'fieldFontSize') config[field].font_size = parseInt(this.value) || 12;
            if (fieldId === 'fieldFontStyle') config[field].font_style = this.value;
            if (fieldId === 'fieldTextColor') {
                config[field].text_color = this.value;
                document.getElementById('fieldTextColorHex').value = this.value;
            }
            if (fieldId === 'fieldAlignment') config[field].alignment = this.value;
            
            // Sync active element styles
            const dragEl = document.getElementById(field + '_drag');
            if (dragEl) applyFieldStyle(dragEl, config[field]);
            
            updateHelperPositions();
        });
    });
    
    // Sync text color hex back to color picker
    document.getElementById('fieldTextColorHex').addEventListener('input', function() {
        if (/^#[0-9A-F]{6}$/i.test(this.value)) {
            document.getElementById('fieldTextColor').value = this.value;
            const field = fieldSelector.value;
            if (config[field]) {
                config[field].text_color = this.value;
                const dragEl = document.getElementById(field + '_drag');
                if (dragEl) applyFieldStyle(dragEl, config[field]);
            }
        }
    });

    // Draggable Logic using standard Mouse events
    let isDragging = false;
    let dragField = null;
    let dragOffsetX = 0;
    let dragOffsetY = 0;
    
    document.querySelectorAll('.draggable-field').forEach(el => {
        el.addEventListener('mousedown', function(e) {
            isDragging = true;
            dragField = this;
            
            // Select in controls automatically
            const field = this.dataset.field;
            fieldSelector.value = field;
            selectField(field);
            
            const rect = dragField.getBoundingClientRect();
            const wrapperRect = wrapper.getBoundingClientRect();
            
            // Calculate anchor adjustment offsets based on alignment transform
            let anchorX = rect.left + rect.width / 2; // Center default
            if (config[field].alignment === 'L') {
                anchorX = rect.left;
            } else if (config[field].alignment === 'R') {
                anchorX = rect.right;
            }
            
            dragOffsetX = e.clientX - anchorX;
            dragOffsetY = e.clientY - (rect.top + rect.height / 2);
            
            e.preventDefault();
        });
    });
    
    document.addEventListener('mousemove', function(e) {
        if (!isDragging || !dragField) return;
        
        const wrapperRect = wrapper.getBoundingClientRect();
        const fieldName = dragField.dataset.field;
        
        // Target anchor point on mouse coordinates
        let mouseX = e.clientX - wrapperRect.left - dragOffsetX;
        let mouseY = e.clientY - wrapperRect.top - dragOffsetY;
        
        // Bind inside workspace boundary
        mouseX = Math.max(0, Math.min(mouseX, containerWidth));
        mouseY = Math.max(0, Math.min(mouseY, containerHeight));
        
        // Convert to percentages
        const pctX = (mouseX / containerWidth) * 100;
        const pctY = (mouseY / containerHeight) * 100;
        
        // Save to active local configs
        if (!config[fieldName]) config[fieldName] = {};
        config[fieldName].x = parseFloat(pctX.toFixed(2));
        config[fieldName].y = parseFloat(pctY.toFixed(2));
        
        dragField.style.left = config[fieldName].x + '%';
        dragField.style.top = config[fieldName].y + '%';
        
        updateHelperPositions();
    });
    
    document.addEventListener('mouseup', function() {
        isDragging = false;
        dragField = null;
    });
    
    // Save Config Action via AJAX
    document.getElementById('saveConfigBtn').addEventListener('click', function() {
        const fieldsData = [];
        const fields = ['participant_name', 'branch'];
        
        fields.forEach(f => {
            if (config[f]) {
                fieldsData.push({
                    field_name: f,
                    x_position: config[f].x || config[f].x_position || 50,
                    y_position: config[f].y || config[f].y_position || 50,
                    font_name: config[f].font_name || 'helvetica',
                    font_size: config[f].font_size || 16,
                    font_style: config[f].font_style || '',
                    text_color: config[f].text_color || '#000000',
                    alignment: config[f].alignment || 'C'
                });
            }
        });
        
        const formData = new FormData();
        formData.append('save_coordinates', '1');
        formData.append('csrf_token', '<?= generateCSRFToken() ?>');
        formData.append('fields_data', JSON.stringify(fieldsData));
        
        fetch('certificate-editor.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                showToast(data.message, 'success');
            } else {
                showToast(data.message, 'danger');
            }
        })
        .catch(err => {
            showToast('Connection error occurred while saving configs.', 'danger');
        });
    });
</script>

<?php require_once __DIR__ . '/footer.php'; ?>
