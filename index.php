<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#0e6073">
    <meta name="description" content="AquaTrack is a water refilling station management system for Gaeven's Water Refilling Station.">
    <title>AquaTrack | Water Station Management System</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <!-- Navigation -->
    <header class="site-header">
        <div class="container header-inner">
            <a class="brand" href="index.php" aria-label="AquaTrack Home">
                <span class="brand-symbol" aria-hidden="true">
                    <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M20 4C20 4 8 18.5 8 26a12 12 0 0 0 24 0C32 18.5 20 4 20 4Z" fill="currentColor"/>
                        <path d="M12 27c4-4 9 4 16-1" stroke="white" stroke-width="2.5" stroke-linecap="round"/>
                    </svg>
                </span>
                <span class="brand-name">AquaTrack<small>Gaeven's Water Refilling Station</small></span>
            </a>

            <nav class="main-nav" aria-label="Main navigation">
                <a href="#home">Home</a>
                <a href="#about">About</a>
                <a href="#features">Features</a>
            </nav>

            <a class="header-login" href="auth/login.php">Staff Login <span aria-hidden="true">→</span></a>
        </div>
    </header>

    <main>
        <!-- Welcome -->
        <section class="hero" id="home">
            <div class="container hero-grid">
                <div class="hero-content">
                    <span class="hero-kicker">WELCOME TO AQUATRACK</span>
                    <h1>Water Refilling Station <span>Management System</span></h1>
                    <p class="hero-description">
                        A simple web-based system for Gaeven's Water Refilling Station
                        to organize customer orders, deliveries, inventory, and payments.
                    </p>
                    <div class="hero-actions">
                        <a class="btn btn-primary" href="auth/login.php">Login to AquaTrack <span aria-hidden="true">→</span></a>
                        <a class="btn btn-outline" href="#about">Learn More</a>
                    </div>
                    <p class="hero-note">For authorized station personnel only.</p>
                </div>

                <!-- Simple water-gallon illustration (no image files needed) -->
                <div class="hero-visual" aria-hidden="true">
                    <div class="art-circle"></div>
                    <span class="art-bubble art-bubble-one"></span>
                    <span class="art-bubble art-bubble-two"></span>
                    <span class="art-bubble art-bubble-three"></span>
                    <svg class="gallon-art" viewBox="0 0 300 360" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <ellipse cx="150" cy="338" rx="94" ry="12" fill="#0B7484" fill-opacity=".13"/>
                        <rect x="120" y="25" width="60" height="22" rx="6" fill="#0D6174"/>
                        <rect x="125" y="43" width="50" height="35" rx="8" fill="#B3EAF0" stroke="#51A8B8" stroke-width="3"/>
                        <path d="M108 72c0-8 7-14 15-14h54c8 0 15 6 15 14v14c0 13 43 29 43 57v151c0 18-14 32-32 32H97c-18 0-32-14-32-32V143c0-28 43-44 43-57V72Z" fill="#E0F8FA" stroke="#4D9DAF" stroke-width="4"/>
                        <path d="M69 173c39-13 72 10 105 5 25-3 42-14 57-8v122c0 17-13 30-30 30H99c-17 0-30-13-30-30V173Z" fill="#8EDBE7" fill-opacity=".65"/>
                        <path d="M70 174c40-15 73 11 104 5 26-4 42-15 57-8" stroke="#46B5C8" stroke-width="3"/>
                        <path d="M94 117c-8 15-11 31-11 54v114" stroke="white" stroke-opacity=".8" stroke-width="9" stroke-linecap="round"/>
                        <rect x="84" y="198" width="132" height="79" rx="12" fill="white"/>
                        <path d="M84 249c43-15 83 16 132-2v18c0 7-5 12-12 12H96c-7 0-12-5-12-12v-16Z" fill="#D6F3F1"/>
                        <path d="M150 207c0 0-10 12-10 18a10 10 0 1 0 20 0c0-6-10-18-10-18Z" fill="#20A6BC"/>
                        <text x="150" y="247" text-anchor="middle" fill="#135669" font-size="20" font-weight="800" font-family="Arial, sans-serif">AquaTrack</text>
                        <text x="150" y="263" text-anchor="middle" fill="#4B8992" font-size="8.5" letter-spacing="1" font-family="Arial, sans-serif">GAEVEN'S WATER STATION</text>
                    </svg>
                    <div class="art-caption">Clean water. Organized records.</div>
                </div>
            </div>
        </section>

        <!-- About -->
        <section class="about section-padding" id="about">
            <div class="container about-inner">
                <span class="section-label">ABOUT THE SYSTEM</span>
                <h2>What is AquaTrack?</h2>
                <p>
                    AquaTrack is a school project developed for Gaeven's Water Refilling Station.
                    Its purpose is to help replace manual recordkeeping with a more organized
                    way of handling daily station operations.
                </p>
            </div>
        </section>

        <!-- Key modules -->
        <section class="features section-padding" id="features">
            <div class="container">
                <div class="features-heading">
                    <span class="section-label">MAIN FEATURES</span>
                    <h2>What can AquaTrack manage?</h2>
                    <p>Tools designed around the everyday tasks of a water refilling station.</p>
                </div>

                <div class="features-grid">
                    <article class="feature-card">
                        <span class="feature-icon" aria-hidden="true">01</span>
                        <h3>Customers & Orders</h3>
                        <p>Keep customer information and order records in one place.</p>
                    </article>
                    <article class="feature-card">
                        <span class="feature-icon" aria-hidden="true">02</span>
                        <h3>Deliveries</h3>
                        <p>Record delivery details and monitor delivery status.</p>
                    </article>
                    <article class="feature-card">
                        <span class="feature-icon" aria-hidden="true">03</span>
                        <h3>Inventory</h3>
                        <p>Monitor gallons, materials, supplies, and available stock.</p>
                    </article>
                    <article class="feature-card">
                        <span class="feature-icon" aria-hidden="true">04</span>
                        <h3>Sales & Payments</h3>
                        <p>Organize payments, balances, and sales transactions.</p>
                    </article>
                </div>
            </div>
        </section>

        <!-- Login reminder -->
        <section class="portal-section">
            <div class="container portal-inner">
                <div>
                    <h2>Already part of the station team?</h2>
                    <p>Sign in with the account provided by your administrator.</p>
                </div>
                <a class="btn btn-primary" href="auth/login.php">Go to Login <span aria-hidden="true">→</span></a>
            </div>
        </section>
    </main>

    <footer class="site-footer">
        <div class="container footer-inner">
            <p>&copy; <?php echo date('Y'); ?> AquaTrack. Gaeven's Water Refilling Station.</p>
            <p>IT 314 — Web Systems and Technologies Project</p>
        </div>
    </footer>
</body>
</html>
