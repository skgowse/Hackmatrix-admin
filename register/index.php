<?php
/**
 * HackMatrix 1.0 - Public Team Registration Portal
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HACKMATRIX 1.0 - Hackathon Team Registration</title>
    
    <!-- Premium Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Registration Page Stylesheet -->
    <link rel="stylesheet" href="css/registration.css">
</head>
<body>
    <!-- Background Animated Glows -->
    <div class="glow glow-1"></div>
    <div class="glow glow-2"></div>
    
    <div class="registration-container">
        <!-- Logo and Heading Header -->
        <header class="reg-header">
            <div class="brand-badge">HACKMATRIX 1.0</div>
            <h1>Hackathon Registration</h1>
            <p class="subtitle">Secure your team's slot for the premier 2-Day Hackathon organized by the Department of Artificial Intelligence & Data Science, VIIT.</p>
            <div class="reg-meta">
                <span><svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg> This is submitted on the day of the hackathon</span>
                <span><svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> Global Encrypted Verification</span>
            </div>
        </header>

        <!-- Main Form Grid -->
        <form id="registrationForm" method="POST" class="reg-form">
            <?php csrfInput(); ?>
            
            <!-- STEP 1: TEAM PROFILE CARD -->
            <section class="card">
                <div class="card-header">
                    <span class="step-num">01</span>
                    <h2>Team Profile</h2>
                </div>
                
                <div class="form-grid-3">
                    <div class="form-group">
                        <label for="team_name">TEAM NAME <span class="required">*</span></label>
                        <input type="text" id="team_name" name="team_name" class="form-control" placeholder="e.g. CyberTitans" autocomplete="off">
                        <div class="validation-message" id="team_name_msg"></div>
                    </div>
                    
                    <div class="form-group">
                        <label for="college">COLLEGE / INSTITUTION <span class="required">*</span></label>
                        <select id="college" name="college" class="form-control" disabled>
                            <option value="">Select College Name</option>
                            <option value="VIIT">VIIT</option>
                            <option value="VIEW">VIEW</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="team_size">TEAM SIZE (MEMBERS) <span class="required">*</span></label>
                        <select id="team_size" name="team_size" class="form-control" disabled>
                            <option value="">Select Team Size</option>
                            <option value="2">2 Members</option>
                            <option value="3">3 Members</option>
                            <option value="4">4 Members</option>
                        </select>
                    </div>
                </div>

                <div style="margin-top: 20px;">
                    <div class="form-group" style="max-width: 500px;">
                        <label for="domain">HACKATHON DOMAIN <span class="required">*</span></label>
                        <select id="domain" name="domain" class="form-control" disabled>
                            <option value="">Select Hackathon Domain</option>
                            <option value="AI & ML">AI & ML (Artificial Intelligence & Machine Learning)</option>
                            <option value="Cloud Computing">Cloud Computing</option>
                            <option value="Cybersecurity">Cybersecurity</option>
                            <option value="Robotics">Robotics</option>
                        </select>
                    </div>
                </div>
            </section>

            <!-- STEP 2: DYNAMIC MEMBERS SECTION -->
            <section class="members-section-header">
                <span class="step-num">02</span>
                <h2>Team Members Details</h2>
                <p>Provide contact and academic details. The first member must be the Team Lead.</p>
            </section>
            
            <div id="dynamicMembersContainer" class="members-grid">
                <!-- Member Cards Generated Dynamically by JS -->
            </div>

            <!-- SUBMISSION BLOCK -->
            <div class="submission-panel">
                <p class="terms-disclaimer">By registering, you confirm that all details provided are accurate and that participant email addresses are unique and belong to your team members.</p>
                
                <button type="submit" id="submitBtn" class="btn btn-primary btn-lg" disabled>
                    <span class="btn-text">Register Team</span>
                    <span class="spinner" style="display: none;"></span>
                </button>
            </div>
        </form>

        <!-- SUCCESS AND ERROR BANNER OVERLAYS -->
        <div id="successOverlay" class="overlay" style="display: none;">
            <div class="overlay-card animate-pop">
                <div class="success-icon">
                    <svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
                </div>
                <h2>Registration Successful!</h2>
                <p class="lead">Your team has been successfully registered for HACKMATRIX 1.0.</p>
                
                <div class="success-details">
                    <div class="success-row">
                        <span class="label">TEAM ID:</span>
                        <span class="val highlight" id="res_team_id">HM26-0001</span>
                    </div>
                    <div class="success-row">
                        <span class="label">TEAM NAME:</span>
                        <span class="val" id="res_team_name">Vision Coders</span>
                    </div>
                    <div class="success-row">
                        <span class="label">MEMBERS:</span>
                        <span class="val" id="res_team_size">3 Members</span>
                    </div>
                </div>
                
                <p class="disclaimer-msg">Keep this Team ID safe. PDF certificates of participation will be issued under this ID, and log instructions have been sent to your emails.</p>
                
                <button onclick="window.location.reload();" class="btn btn-secondary" style="margin-top: 24px; min-width: 180px;">Register New Team</button>
            </div>
        </div>
    </div>

    <!-- Dynamic JavaScript and Validation Controllers -->
    <script src="js/registration.js"></script>
</body>
</html>
