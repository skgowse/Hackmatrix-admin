/**
 * HackMatrix 1.0 - Dynamic Registration Controller
 */

document.addEventListener('DOMContentLoaded', function() {
    const teamSizeSelect = document.getElementById('team_size');
    const membersContainer = document.getElementById('dynamicMembersContainer');
    const registrationForm = document.getElementById('registrationForm');
    const submitBtn = document.getElementById('submitBtn');
    const spinner = submitBtn.querySelector('.spinner');
    const btnText = submitBtn.querySelector('.btn-text');
    const teamNameInput = document.getElementById('team_name');
    const teamNameMsg = document.getElementById('team_name_msg');
    
    // Validation states
    const validationStates = {
        teamName: false,
        emails: {},
        mobiles: {}
    };

    // Predefined lists
    const branches = ['AI&DS', 'ECE', 'ECM', 'IT', 'MECH', 'CIVIL', 'EEE', 'CSE', 'CSE-AI', 'CSE-DS', 'CSE-CS', 'AI&ML'];
    const years = [
        { val: '1', label: '1st Year' },
        { val: '2', label: '2nd Year' },
        { val: '3', label: '3rd Year' },
        { val: '4', label: '4th Year' }
    ];

    // Debounce Helper
    function debounce(func, delay = 500) {
        let timer;
        return function(...args) {
            clearTimeout(timer);
            timer = setTimeout(() => func.apply(this, args), delay);
        };
    }

    // Render Dynamic Member Forms
    function renderMemberCards(size) {
        // Save current input values to preserve them across size changes
        const currentData = [];
        for (let i = 0; i < 4; i++) {
            const card = document.querySelector(`.member-card[data-index="${i}"]`);
            if (card) {
                const checkedBranch = card.querySelector('.member-branch-radio:checked');
                currentData[i] = {
                    salutation: card.querySelector('.member-salutation').value,
                    name: card.querySelector('.member-name').value,
                    email: card.querySelector('.member-email').value,
                    mobile: card.querySelector('.member-mobile').value,
                    branch: checkedBranch ? checkedBranch.value : '',
                    year: card.querySelector('.member-year').value
                };
            }
        }

        membersContainer.innerHTML = '';
        
        for (let i = 0; i < size; i++) {
            const isLead = (i === 0);
            const index = i;
            const saved = currentData[i] || { name: '', email: '', mobile: '+91', branch: '', year: '', salutation: '' };
            
            // Build card container
            const card = document.createElement('div');
            card.className = `card member-card ${isLead ? 'lead-card' : ''}`;
            card.setAttribute('data-index', index);
            
            // Badge
            const badge = document.createElement('div');
            badge.className = 'member-badge';
            badge.innerText = isLead ? 'Team Lead' : `Member #${index + 1}`;
            card.appendChild(badge);

            // Title
            const header = document.createElement('div');
            header.className = 'card-header';
            header.innerHTML = `<span class="step-num">${index + 1}</span> <h2>${isLead ? 'Team Leader Details' : `Member ${index + 1} Details`}</h2>`;
            card.appendChild(header);

            // Content Grid
            const grid = document.createElement('div');
            grid.className = 'form-grid-2';
            
            // Generate branch radio inputs
            let branchRadioGrid = '';
            branches.forEach((b, bIdx) => {
                const radioId = `branch_${index}_${bIdx}`;
                const checked = (saved.branch === b) ? 'checked' : '';
                branchRadioGrid += `
                    <label class="radio-label" for="${radioId}">
                        <input type="radio" id="${radioId}" name="members[${index}][branch]" value="${b}" class="member-branch-radio" ${checked}>
                        <span>${b}</span>
                    </label>
                `;
            });

            // Generate salutation options
            const salutations = ['Mr.', 'Miss.', 'Mrs.', 'Ms.'];
            let salutationOptions = '<option value="">Select Salutation</option>';
            salutations.forEach(s => {
                salutationOptions += `<option value="${s}" ${saved.salutation === s ? 'selected' : ''}>${s}</option>`;
            });

            // Generate year options
            let yearOptions = '<option value="">Select Academic Year</option>';
            years.forEach(y => {
                yearOptions += `<option value="${y.val}" ${saved.year === y.val ? 'selected' : ''}>${y.label}</option>`;
            });

            grid.innerHTML = `
                <div class="form-group">
                    <label>Salutation <span class="required">*</span></label>
                    <select name="members[${index}][salutation]" class="form-control member-salutation">
                        ${salutationOptions}
                    </select>
                </div>
                
                <div class="form-group">
                    <label>Full Name <span class="required">*</span></label>
                    <input type="text" name="members[${index}][name]" class="form-control member-name" placeholder="Enter full name" value="${saved.name}">
                </div>
                
                <div class="form-group">
                    <label>Email Address <span class="required">*</span></label>
                    <input type="email" name="members[${index}][email]" class="form-control member-email" placeholder="e.g. member${index+1}@gmail.com" value="${saved.email}">
                    <div class="validation-message member-email-msg"></div>
                </div>
                
                <div class="form-group">
                    <label>Mobile Number <span class="required">*</span></label>
                    <input type="tel" name="members[${index}][mobile]" class="form-control member-mobile" placeholder="+91 XXXXXXXXXX" value="${saved.mobile || '+91'}" pattern="\\+91[0-9]{10}">
                    <div class="validation-message member-mobile-msg"></div>
                </div>

                <div class="form-group">
                    <label>Academic Year <span class="required">*</span></label>
                    <select name="members[${index}][year]" class="form-control member-year">
                        ${yearOptions}
                    </select>
                </div>

                <div class="form-group" style="grid-column: 1 / -1; margin-top: 10px;">
                    <label>Branch / Department <span class="required">*</span></label>
                    <div class="branch-radio-group">
                        ${branchRadioGrid}
                    </div>
                </div>
            `;
            
            card.appendChild(grid);
            membersContainer.appendChild(card);
            
            // Add live validation listeners for this card
            setupMemberListeners(card, index);
        }
    }

    // Set listeners on individual dynamic inputs
    function setupMemberListeners(card, index) {
        const emailInput = card.querySelector('.member-email');
        const emailMsg = card.querySelector('.member-email-msg');
        const mobileInput = card.querySelector('.member-mobile');
        const mobileMsg = card.querySelector('.member-mobile-msg');

        emailInput.addEventListener('input', debounce(function() {
            const email = this.value.trim().toLowerCase();
            if (email === '') {
                emailMsg.innerText = '';
                validationStates.emails[index] = false;
                return;
            }
            
            // Basic regex
            if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                emailMsg.className = 'validation-message invalid';
                emailMsg.innerText = 'Invalid email format.';
                validationStates.emails[index] = false;
                return;
            }

            // Check form internal duplicates
            const allEmails = Array.from(document.querySelectorAll('.member-email'))
                                   .map(el => el.value.trim().toLowerCase())
                                   .filter(val => val !== '');
            const occurrences = allEmails.filter(val => val === email).length;
            if (occurrences > 1) {
                emailMsg.className = 'validation-message invalid';
                emailMsg.innerText = 'This email is entered twice on this form.';
                validationStates.emails[index] = false;
                return;
            }

            // AJAX Check
            emailMsg.className = 'validation-message checking';
            emailMsg.innerText = 'Checking email uniqueness...';

            fetch(`../../api/registration/check-email.php?email=${encodeURIComponent(email)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.available) {
                        emailMsg.className = 'validation-message valid';
                        emailMsg.innerText = '✓ Email is available';
                        validationStates.emails[index] = true;
                    } else {
                        emailMsg.className = 'validation-message invalid';
                        emailMsg.innerText = '❌ ' + data.message;
                        validationStates.emails[index] = false;
                    }
                })
                .catch(() => {
                    emailMsg.innerText = 'Offline validation skipped.';
                    validationStates.emails[index] = true;
                });
        }));

        mobileInput.addEventListener('input', debounce(function() {
            let val = this.value;
            if (!val.startsWith('+91')) {
                val = '+91' + val.replace(/\+91/g, '').replace(/[^0-9]/g, '');
            } else {
                let rest = val.substring(3).replace(/[^0-9]/g, '');
                if (rest.length > 10) rest = rest.substring(0, 10);
                val = '+91' + rest;
            }
            this.value = val;

            let mobile = val.substring(3);

            if (mobile === '') {
                mobileMsg.innerText = '';
                validationStates.mobiles[index] = false;
                return;
            }

            if (mobile.length !== 10) {
                mobileMsg.className = 'validation-message invalid';
                mobileMsg.innerText = 'Must be exactly 10 digits after +91.';
                validationStates.mobiles[index] = false;
                return;
            }

            // Check form duplicates
            const allMobiles = Array.from(document.querySelectorAll('.member-mobile'))
                                     .map(el => el.value.replace(/[^0-9]/g, '').slice(-10))
                                     .filter(val => val !== '');
            if (allMobiles.filter(val => val === mobile).length > 1) {
                mobileMsg.className = 'validation-message invalid';
                mobileMsg.innerText = 'This phone is entered twice on this form.';
                validationStates.mobiles[index] = false;
                return;
            }

            // AJAX Check
            mobileMsg.className = 'validation-message checking';
            mobileMsg.innerText = 'Verifying...';

            fetch(`../../api/registration/check-mobile.php?mobile=${encodeURIComponent(mobile)}`)
                .then(res => res.json())
                .then(data => {
                    if (data.available) {
                        mobileMsg.className = 'validation-message valid';
                        mobileMsg.innerText = '✓ Mobile number is available';
                        validationStates.mobiles[index] = true;
                    } else {
                        mobileMsg.className = 'validation-message invalid';
                        mobileMsg.innerText = '❌ ' + data.message;
                        validationStates.mobiles[index] = false;
                    }
                })
                .catch(() => {
                    mobileMsg.innerText = 'Offline validation skipped.';
                    validationStates.mobiles[index] = true;
                });
        }));
    }

    // Live validation for Team Name
    teamNameInput.addEventListener('input', debounce(function() {
        const name = this.value.trim();
        if (name === '') {
            teamNameMsg.innerText = '';
            validationStates.teamName = false;
            return;
        }

        teamNameMsg.className = 'validation-message checking';
        teamNameMsg.innerText = 'Verifying name...';

        fetch(`../../api/registration/check-team.php?name=${encodeURIComponent(name)}`)
            .then(res => res.json())
            .then(data => {
                if (data.available) {
                    teamNameMsg.className = 'validation-message valid';
                    teamNameMsg.innerText = '✓ Team name is available';
                    validationStates.teamName = true;
                } else {
                    teamNameMsg.className = 'validation-message invalid';
                    teamNameMsg.innerText = '❌ ' + data.message;
                    validationStates.teamName = false;
                }
            })
            .catch(() => {
                teamNameMsg.innerText = 'Offline validation skipped.';
                validationStates.teamName = true;
            });
    }));

    function handleTeamSizeChange(value) {
        const size = parseInt(value);
        if (!isNaN(size) && size >= 2 && size <= 4) {
            renderMemberCards(size);
        } else {
            membersContainer.innerHTML = `
                <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: var(--text-muted); background: rgba(255,255,255,0.01); border: 1px dashed var(--border-color); border-radius: 12px; width: 100%;">
                    Please select a team size to enter member details.
                </div>
            `;
        }
    }

    // Trigger initial render
    handleTeamSizeChange(teamSizeSelect.value);

    // Handle Team Size updates
    teamSizeSelect.addEventListener('change', function() {
        handleTeamSizeChange(this.value);
    });

    // Form Submission Handler
    registrationForm.addEventListener('submit', function(e) {
        e.preventDefault();

        // 1. Double check client validation states
        const size = parseInt(teamSizeSelect.value);
        if (isNaN(size) || size < 2 || size > 4) {
            showToast('Please select a valid team size.', 'warning');
            teamSizeSelect.focus();
            return;
        }

        if (teamNameInput.value.trim() === '') {
            showToast('Please enter a Team Name.', 'warning');
            teamNameInput.focus();
            return;
        }

        const collegeSelect = document.getElementById('college');
        if (collegeSelect.value === '') {
            showToast('Please select your College Name.', 'warning');
            collegeSelect.focus();
            return;
        }

        const domainSelect = document.getElementById('domain');
        if (domainSelect.value === '') {
            showToast('Please select a Hackathon Domain.', 'warning');
            domainSelect.focus();
            return;
        }
        
        if (!validationStates.teamName) {
            showToast('Please choose a valid, unique team name.', 'warning');
            teamNameInput.focus();
            return;
        }

        for (let i = 0; i < size; i++) {
            const card = document.querySelector(`.member-card[data-index="${i}"]`);
            if (card) {
                const salutationVal = card.querySelector('.member-salutation').value;
                if (salutationVal === '') {
                    showToast(`Please select a Salutation for Member #${i+1}.`, 'warning');
                    card.querySelector('.member-salutation').focus();
                    return;
                }

                const nameVal = card.querySelector('.member-name').value.trim();
                if (nameVal === '') {
                    showToast(`Please enter the Full Name for Member #${i+1}.`, 'warning');
                    card.querySelector('.member-name').focus();
                    return;
                }

                const emailVal = card.querySelector('.member-email').value.trim();
                if (emailVal === '') {
                    showToast(`Please enter the Email Address for Member #${i+1}.`, 'warning');
                    card.querySelector('.member-email').focus();
                    return;
                }

                const mobileVal = card.querySelector('.member-mobile').value.trim();
                if (mobileVal === '' || mobileVal === '+91') {
                    showToast(`Please enter the Mobile Number for Member #${i+1}.`, 'warning');
                    card.querySelector('.member-mobile').focus();
                    return;
                }

                const checkedBranch = card.querySelector('.member-branch-radio:checked');
                if (!checkedBranch) {
                    showToast(`Please select a Branch for Member #${i+1}.`, 'warning');
                    return;
                }
                const yearVal = card.querySelector('.member-year').value;
                if (yearVal === '') {
                    showToast(`Please select an Academic Year for Member #${i+1}.`, 'warning');
                    card.querySelector('.member-year').focus();
                    return;
                }
            }

            if (!validationStates.emails[i]) {
                showToast(`Please verify the email address for Member #${i+1}.`, 'warning');
                document.querySelector(`.member-card[data-index="${i}"] .member-email`).focus();
                return;
            }
            if (!validationStates.mobiles[i]) {
                showToast(`Please verify the mobile number for Member #${i+1}.`, 'warning');
                document.querySelector(`.member-card[data-index="${i}"] .member-mobile`).focus();
                return;
            }
        }

        // 2. Disable buttons and show loading animation
        submitBtn.disabled = true;
        btnText.innerText = 'Registering your team...';
        spinner.style.display = 'block';

        const formData = new FormData(this);

        // Send POST request
        fetch('../../api/registration/register.php', {
            method: 'POST',
            body: formData
        })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                // Populate and display success overlay
                document.getElementById('res_team_id').innerText = data.team_id;
                document.getElementById('res_team_name').innerText = data.team_name;
                document.getElementById('res_team_size').innerText = `${data.team_size} Members`;
                
                document.getElementById('successOverlay').style.display = 'flex';
                showToast('Registration Successful!', 'success');
            } else {
                showToast(data.message || 'Registration failed.', 'danger');
                submitBtn.disabled = false;
                btnText.innerText = 'Register Team';
                spinner.style.display = 'none';
            }
        })
        .catch(err => {
            showToast('A network connection error occurred.', 'danger');
            submitBtn.disabled = false;
            btnText.innerText = 'Register Team';
            spinner.style.display = 'none';
        });
    });

    // Custom Toast Notification System
    function showToast(message, type = 'info') {
        const toast = document.createElement('div');
        toast.style.position = 'fixed';
        toast.style.bottom = '20px';
        toast.style.right = '20px';
        toast.style.padding = '12px 24px';
        toast.style.borderRadius = '8px';
        toast.style.color = 'white';
        toast.style.fontSize = '14px';
        toast.style.fontWeight = '600';
        toast.style.boxShadow = '0 10px 20px rgba(0,0,0,0.3)';
        toast.style.zIndex = '99999';
        toast.style.transform = 'translateY(100px)';
        toast.style.opacity = '0';
        toast.style.transition = 'all 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275)';

        if (type === 'success') toast.style.background = 'linear-gradient(135deg, #10b981, #059669)';
        else if (type === 'danger') toast.style.background = 'linear-gradient(135deg, #ef4444, #dc2626)';
        else if (type === 'warning') toast.style.background = 'linear-gradient(135deg, #f59e0b, #d97706)';
        else toast.style.background = 'linear-gradient(135deg, #6366f1, #4f46e5)';

        toast.innerText = message;
        document.body.appendChild(toast);

        // Animate in
        setTimeout(() => {
            toast.style.transform = 'translateY(0)';
            toast.style.opacity = '1';
        }, 50);

        // Remove
        setTimeout(() => {
            toast.style.transform = 'translateY(100px)';
            toast.style.opacity = '0';
            setTimeout(() => toast.remove(), 300);
        }, 4000);
    }
});
