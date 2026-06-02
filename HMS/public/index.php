<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/auth.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>HOSTEL MANAGEMENT SYSTEM</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Plus+Jakarta+Sans:wght@500;600;700;800&display=swap" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="styles.css?v=<?php echo (string)(@filemtime(__DIR__ . '/styles.css') ?: time()); ?>" rel="stylesheet">
</head>
<body class="hms-landing-page">
<nav class="navbar navbar-expand-lg app-topnav app-topnav--guest sticky-top">
    <div class="container">
        <a class="app-topnav-brand" href="<?php echo hms_url(); ?>">
            <span class="app-topnav-brand-mark" aria-hidden="true">H</span>
            <span class="app-topnav-brand-text">Hostel MS</span>
        </a>
        <button class="navbar-toggler app-topnav-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navMain" aria-controls="navMain" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="navMain">
            <div class="app-topnav-actions ms-lg-auto">
                <a class="app-topnav-link" href="login.php">Login</a>
                <a class="app-topnav-link" href="register.php">Register</a>
                <a class="btn app-topnav-cta" href="login.php">Sign in</a>
            </div>
        </div>
    </div>
</nav>

<main class="py-5">
    <div class="container">
        <div class="hero-surface mb-4">
            <div class="inner">
                <span class="hero-chip mb-3">Smart Accommodation Platform</span>
                <h1 class="display-5 fw-bold mb-3 hero-title-single-line">
                    ENHANCED HOSTEL OPERATIONS FOR UGANDAN UNIVERSITIES
                </h1>
                <p class="lead mb-4" style="max-width: 780px;">
                    Digitize room allocation, payments, maintenance, and oversight with a secure web platform
                    designed for the realities of Uganda's education ecosystem.
                </p>
                <a href="login.php" class="btn btn-light btn-lg me-2">Log in</a>
                <a href="register.php" class="btn btn-outline-light btn-lg">Student Register</a>
            </div>
        </div>

        <div class="row g-4 hms-feature-grid">
            <div class="col-lg-4">
                <div class="card h-100 p-3 hms-feature-card">
                    <img class="feature-image mb-3" src="assets/images/feature-students-real.png" alt="Students walking on campus">
                    <h5 class="fw-semibold">Student Experience</h5>
                    <p class="text-muted small mb-0">
                        Self-service room requests, transparent payment records, and faster maintenance response.
                    </p>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card h-100 p-3 hms-feature-card">
                    <img class="feature-image mb-3" src="assets/images/feature-operations-real.png" alt="Operational control center dashboard">
                    <h5 class="fw-semibold">Operational Control</h5>
                    <p class="text-muted small mb-0">
                        Wardens manage hostels, room occupancy, approvals, and maintenance workflows in one place.
                    </p>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card h-100 p-3 hms-feature-card overlay-accent">
                    <img class="feature-image mb-3" src="assets/images/feature-payments-real.png" alt="Pesapal and Airtel digital payment options">
                    <h5 class="fw-semibold">Real Digital Payments</h5>
                    <p class="text-muted small mb-0">
                        Integrated Pesapal workflow for secure online payments and payment status verification.
                    </p>
                </div>
            </div>
        </div>

        <div class="contact-cta card mt-4 p-4">
            <p class="contact-cta-kicker mb-2">For Hostel Owners</p>
            <h2 class="h5 fw-semibold mb-2">Interested in using HOSTEL MANAGEMENT SYSTEM?</h2>
            <p class="text-muted mb-3">Contact the HMS admins directly on WhatsApp or email.</p>
            <div class="contact-cta-grid">
                <div class="contact-cta-item">
                    <div class="small text-muted mb-2">WhatsApp</div>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="contact-cta-link" href="https://wa.me/256787221531" target="_blank" rel="noopener noreferrer">
                            +256787221531
                        </a>
                        <a class="contact-cta-link" href="https://wa.me/256744016985" target="_blank" rel="noopener noreferrer">
                            +256744016985
                        </a>
                    </div>
                </div>
                <div class="contact-cta-item">
                    <div class="small text-muted mb-2">Email</div>
                    <div class="d-flex flex-wrap gap-2">
                        <a class="contact-cta-link" href="<?php echo e(hms_gmail_compose_url('shamirah0mar915@gmail.com')); ?>" target="_blank" rel="noopener noreferrer">shamirah0mar915@gmail.com</a>
                        <a class="contact-cta-link" href="<?php echo e(hms_gmail_compose_url('joymarynl203@gmail.com')); ?>" target="_blank" rel="noopener noreferrer">joymarynl203@gmail.com</a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php
require_once __DIR__ . '/../lib/footer.php';
layout_site_footer();
?>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

