<?php
// Public landing page — showcases available residences and handles booking and report submissions.
require_once __DIR__ . '/config/database.php';
$db = getDB();

$availableRooms = $db->query("SELECT r.*, rt.charge_period FROM rooms r LEFT JOIN room_types rt ON rt.name = r.room_type WHERE r.status = 'available' ORDER BY r.rental_price ASC")->fetchAll();

// Attach a gallery of admin-uploaded images (primary + room_images) per residence
$galleryByRoom = [];
foreach ($db->query("SELECT room_id, image FROM room_images ORDER BY room_id, id")->fetchAll() as $g) {
    $galleryByRoom[$g['room_id']][] = $g['image'];
}
foreach ($availableRooms as &$room) {
    // Fall back to the first gallery image if primary is missing
    if (empty($room['image']) && !empty($galleryByRoom[$room['id']])) {
        $room['image'] = $galleryByRoom[$room['id']][0];
    }
    $gallery = [];
    if (!empty($room['image']) && is_file(__DIR__ . '/uploads/rooms/' . $room['image'])) {
        $gallery[] = 'uploads/rooms/' . $room['image'];
    }
    foreach ($galleryByRoom[$room['id']] ?? [] as $gi) {
        $gi = trim($gi);
        if ($gi !== ($room['image'] ?? null) && is_file(__DIR__ . '/uploads/rooms/' . $gi)) {
            $gallery[] = 'uploads/rooms/' . $gi;
        }
    }
    $room['gallery'] = $gallery;
}
unset($room);
$totalRooms = $db->query("SELECT COUNT(*) FROM rooms")->fetchColumn();
$occupiedRooms = $db->query("SELECT COUNT(*) FROM rooms WHERE status='occupied'")->fetchColumn();
$maintenanceRooms = $db->query("SELECT COUNT(*) FROM rooms WHERE status='maintenance'")->fetchColumn();
$totalTenants = $db->query("SELECT COUNT(*) FROM users WHERE role='tenant' AND is_active=1")->fetchColumn();

$success = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf = $_POST['csrf_token'] ?? '';
    if (!validateCSRFToken($csrf)) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $full_name = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $room_id = !empty($_POST['room_id']) ? intval($_POST['room_id']) : null;
        $preferred_date = $_POST['preferred_date'] ?? null;
        $message = trim($_POST['message'] ?? '');
        $payment_type = $_POST['payment_type'] ?? 'none';
        $payment_method = $_POST['payment_method'] ?? 'paystack';

        $emailCheck = validateEmailDetailed($email);
        if (empty($full_name) || empty($phone) || empty($email)) {
            $error = 'Please provide your name, phone number, and email address.';
        } elseif (!validatePhone($phone)) {
            $error = 'Phone number must be exactly 10 digits.';
        } elseif (!$emailCheck['valid']) {
            $error = $emailCheck['message'];
        } else {
            $payment_amount = 0;
            $payment_reference = '';
            $payment_status = 'pending';

            if ($payment_type !== 'none' && $room_id) {
                $room = $db->prepare("SELECT rental_price FROM rooms WHERE id = ?");
                $room->execute([$room_id]);
                $roomData = $room->fetch();
                if ($roomData) {
                    $payment_amount = $payment_type === 'down_payment'
                        ? round(floatval($roomData['rental_price']) * 0.5, 2)
                        : round(floatval($roomData['rental_price']), 2);
                }
            }

            if ($payment_type !== 'none' && $payment_method === 'bank_transfer') {
                $payment_status = 'pending';
            } elseif ($payment_type !== 'none' && $payment_method === 'cash') {
                $payment_status = 'pending';
            }

            $stmt = $db->prepare("INSERT INTO booking_requests (full_name, email, phone, room_id, preferred_date, message, payment_type, payment_amount, payment_method, payment_reference, payment_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$full_name, $email, $phone, $room_id, $preferred_date, $message, $payment_type, $payment_amount, $payment_method, $payment_reference, $payment_status]);
            $bookingId = $db->lastInsertId();

            // Mark residence as occupied when a booking with payment is made
            if ($payment_type !== 'none' && $room_id) {
                $db->prepare("UPDATE rooms SET status = 'occupied' WHERE id = ? AND status = 'available'")->execute([$room_id]);
            }

            $admins = $db->query("SELECT id, phone FROM users WHERE role = 'admin'")->fetchAll();
            $room_label = $room_id ? " (Residence #" . $room_id . ")" : "";
            $payment_note = $payment_type !== 'none' ? " Payment: " . formatCurrency($payment_amount) . "." : "";
            $notifMsg = "$full_name has submitted a booking request$room_label.$payment_note";
            if ($admins) {
                $placeholders = implode(',', array_fill(0, count($admins), '(?, ?, ?, ?)'));
                $notifSql = "INSERT INTO notifications (user_id, title, message, type) VALUES $placeholders";
                $notifParams = [];
                foreach ($admins as $admin) {
                    $notifParams[] = $admin['id'];
                    $notifParams[] = 'New Booking Request';
                    $notifParams[] = $notifMsg;
                    $notifParams[] = 'info';
                }
                $db->prepare($notifSql)->execute($notifParams);
                sendSMS($admins[0]['phone'], "New booking request from $full_name$room_label. Phone: $phone." . $payment_note);
            }

            sendSMS($phone, "Dear $full_name, your booking request has been received" . ($payment_type !== 'none' ? " with a payment of " . formatCurrency($payment_amount) : "") . ". PK's Luxury Apartments will contact you within 24 hours.");

            $success = 'Your booking request has been submitted! We will contact you within 24 hours.';

            // If Paystack payment was selected, initialize it now
            if ($payment_type !== 'none' && $payment_method === 'paystack' && $payment_amount > 0) {
                $email_for_ps = !empty($email) ? $email : 'guest@pkluxury.com';
                $reference = 'PKBOOK-' . strtoupper(bin2hex(random_bytes(8)));
                $metadata = ['ams_type' => 'booking', 'booking_id' => intval($bookingId), 'tenant_id' => 0];

                $payload = [
                    'email' => $email_for_ps,
                    'amount' => intval(round($payment_amount * 100)),
                    'currency' => 'GHS',
                    'reference' => $reference,
                    'callback_url' => PAYSTACK_CALLBACK_URL . '&from=booking',
                    'metadata' => $metadata,
                ];

                require_once __DIR__ . '/config/paystack_helper.php';
                $res = paystackApiCall('POST', '/transaction/initialize', $payload);
                if ($res && isset($res['status']) && $res['status'] === true) {
                    header('Location: ' . $res['data']['authorization_url']);
                    exit;
                }
                // If Paystack init fails, the booking is still saved — just show success
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PK's Luxury Apartments — Premium Living in Haatso, Accra</title>
    <meta name="description" content="Experience premium apartment living at PK's Luxury Apartments in Haatso, Accra. Modern residences, excellent amenities, and affordable prices.">
    <link rel="preconnect" href="https://unpkg.com" crossorigin>
    <link rel="stylesheet" href="https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css">
    <link rel="stylesheet" href="css/style.css?v=18">
    <script>
    (function(){
        var saved = localStorage.getItem('pk-theme');
        var dark = saved || (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
        document.documentElement.setAttribute('data-theme', dark === 'dark' ? 'dark' : 'light');
    })();
    </script>
</head>
<body style="background:#fff;">

<!-- ============ TOP NAVIGATION ============ -->
<nav class="booking-nav" id="bookingNav">
    <div class="booking-nav-brand">
        <i class='bx bx-home' style="font-size:1.3rem;"></i> <span class="brand-gold">PK's</span> Luxury Apartments
    </div>
    <div class="booking-nav-links">
        <a href="#about">About</a>
        <a href="#rooms">Residence</a>
        <a href="#amenities">Amenities</a>
        <a href="#location">Location</a>
        <button class="theme-toggle" id="themeToggle" onclick="toggleTheme()" title="Toggle dark mode" style="background:rgba(255,255,255,0.08);border-color:rgba(255,255,255,0.2);color:#fff;">
            <i class='bx bx-moon'></i>
        </button>
        <a href="javascript:void(0)" class="btn-signin" onclick="openBookingModal()">Book Now</a>
        <a href="login.php" class="btn-signin" style="background:transparent;border:1.5px solid rgba(255,255,255,0.3);">Sign In</a>
    </div>
</nav>

<!-- ============ HERO SECTION ============ -->
<section class="booking-hero" id="home" style="background:linear-gradient(135deg, rgba(27,42,74,0.78) 0%, rgba(37,52,90,0.62) 50%, rgba(201,162,39,0.18) 100%), url('uploads/pk2.jpg') center/cover no-repeat;">
    <div class="booking-hero-content">
        <div class="booking-hero-badge">Haatso, Accra &bull; Ghana</div>
        <h1>Premium Living<br>at <span>PK's</span> Luxury</h1>
        <p>Discover modern, comfortable apartments in the heart of Haatso. Affordable luxury with world-class amenities and exceptional service.</p>
        <div class="booking-hero-actions">
            <a href="#rooms" class="btn-hero-primary">View Residences</a>
            <a href="javascript:void(0)" class="btn-hero-outline" onclick="openBookingModal()" style="background:linear-gradient(135deg, var(--accent), var(--accent-dark));border-color:var(--accent);color:#fff;">Book a View</a>
        </div>
        <div class="booking-hero-stats">
            <div class="booking-hero-stat">
                <h3><?= $totalRooms ?></h3>
                <p>Total Residences</p>
            </div>
            <div class="booking-hero-stat">
                <h3><?= $totalRooms - $occupiedRooms - $maintenanceRooms ?></h3>
                <p>Available Now</p>
            </div>
            <div class="booking-hero-stat">
                <h3><?= $totalTenants ?>+</h3>
                <p>Happy Tenants</p>
            </div>
            <div class="booking-hero-stat">
                <h3>24/7</h3>
                <p>Security</p>
            </div>
        </div>
    </div>
</section>

<!-- ============ ABOUT SECTION ============ -->
<section class="booking-section" id="about">
    <div class="booking-container">
        <div class="about-grid">
            <div class="about-image-placeholder reveal-left" <?= file_exists(__DIR__ . '/uploads/about.jpg') ? 'style="padding:0;overflow:hidden;"' : '' ?>>
                <?php if (file_exists(__DIR__ . '/uploads/about.jpg')): ?>
                    <img src="uploads/about.jpg" alt="About PK's Luxury Apartments" style="width:100%;height:100%;object-fit:cover;border-radius:var(--radius-lg);display:block;">
                <?php else: ?>
                    <i class='bx bx-building-house' style="font-size:5rem;"></i>
                <?php endif; ?>
            </div>
            <div class="about-content reveal-right">
                <div class="section-header" style="text-align:left;margin-bottom:0;">
                    <div class="section-tag">About Us</div>
                </div>
                <h3>A Place You'll Be Proud to Call Home</h3>
                <p>PK's Luxury Apartments is a premier residential property located in the vibrant neighborhood of Haatso, Accra. We offer a range of modern living spaces designed to meet the needs of young professionals, families, and students.</p>
                <p>Our commitment to quality, security, and resident satisfaction makes us the preferred choice for discerning tenants in Accra.</p>
                <div class="about-features">
                    <div class="about-feature">
                        <span class="check"><i class='bx bx-check'></i></span> Secure environment
                    </div>
                    <div class="about-feature">
                        <span class="check"><i class='bx bx-check'></i></span> Fast WiFi included
                    </div>
                    <div class="about-feature">
                        <span class="check"><i class='bx bx-check'></i></span> Clean water supply
                    </div>
                    <div class="about-feature">
                        <span class="check"><i class='bx bx-check'></i></span> Regular maintenance
                    </div>
                    <div class="about-feature">
                        <span class="check"><i class='bx bx-check'></i></span> Flexible payment plans
                    </div>
                    <div class="about-feature">
                        <span class="check"><i class='bx bx-check'></i></span> Friendly management
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============ AMENITIES SECTION ============ -->
<section class="booking-section booking-section-dark" id="amenities">
    <div class="booking-container">
        <div class="section-header reveal">
            <div class="section-tag" style="color:var(--accent);">What We Offer</div>
            <h2>World-Class Amenities</h2>
            <p>Everything you need for a comfortable and convenient lifestyle</p>
        </div>
        <div class="amenities-grid stagger">
            <div class="amenity-card">
                <div class="amenity-icon"><i class='bx bx-wifi'></i></div>
                <h4>High-Speed WiFi</h4>
                <p>Free internet access in every room for work and entertainment</p>
            </div>
            <div class="amenity-card">
                <div class="amenity-icon"><i class='bx bx-lock-alt'></i></div>
                <h4>24/7 Security</h4>
                <p>Round-the-clock security with CCTV surveillance for your safety</p>
            </div>
            <div class="amenity-card">
                <div class="amenity-icon"><i class='bx bx-droplet'></i></div>
                <h4>Reliable Water</h4>
                <p>Continuous water supply with backup storage systems</p>
            </div>
            <div class="amenity-card">
                <div class="amenity-icon"><i class='bx bx-bolt-circle'></i></div>
                <h4>Stable Power</h4>
                <p>Backup generator ensures uninterrupted electricity supply</p>
            </div>
            <div class="amenity-card">
                <div class="amenity-icon"><i class='bx bx-wrench'></i></div>
                <h4>Maintenance</h4>
                <p>Quick response maintenance team for all your repair needs</p>
            </div>
            <div class="amenity-card">
                <div class="amenity-icon"><i class='bx bx-car'></i></div>
                <h4>Parking Space</h4>
                <p>Dedicated parking area for tenants with vehicles</p>
            </div>
            <div class="amenity-card">
                <div class="amenity-icon"><i class='bx bx-leaf'></i></div>
                <h4>Clean Environment</h4>
                <p>Regular cleaning and waste management services</p>
            </div>
            <div class="amenity-card">
                <div class="amenity-icon"><i class='bx bx-map'></i></div>
                <h4>Prime Location</h4>
                <p>Close to shops, restaurants, schools, and public transport</p>
            </div>
        </div>
    </div>
</section>

<!-- ============ ROOMS SECTION ============ -->
<section class="booking-section" id="rooms">
    <div class="booking-container">
        <div class="section-header reveal">
            <div class="section-tag">Our Residences</div>
            <h2>Find Your Perfect Space</h2>
            <p>Choose from our range of beautifully designed residences to suit your lifestyle and budget</p>
        </div>

        <?php if ($availableRooms): ?>
        <div class="room-showcase-grid stagger">
            <?php foreach ($availableRooms as $r):
                $hasImg = !empty($r['image']) && is_file(__DIR__ . '/uploads/rooms/' . $r['image']);
                $amenityList = array_slice(explode(',', $r['amenities'] ?? ''), 0, 3);
            ?>
            <div class="room-showcase-card" data-gallery="<?= sanitize(json_encode($r['gallery'])) ?>" data-title="Residence <?= sanitize($r['room_number']) ?>" onclick="openRoomSlideshow(this)">
                <div class="room-showcase-img">
                        <?php if ($hasImg): ?>
                        <img src="uploads/rooms/<?= sanitize($r['image']) ?>" alt="Residence <?= sanitize($r['room_number']) ?>">
                    <?php else: ?>
                        <i class='bx bx-home' style="font-size:3rem;"></i>
                    <?php endif; ?>
                    <span class="room-showcase-badge"><?= sanitize(ucfirst($r['room_type'])) ?></span>
                    <span class="room-showcase-view"><i class='bx bx-expand'></i> View</span>
                </div>
                <div class="room-showcase-body">
                    <h4>Residence <?= sanitize($r['room_number']) ?></h4>
                    <div class="meta">
                        Floor <?= $r['floor'] ?>
                        <?= $r['description'] ? ' &bull; ' . sanitize(substr($r['description'], 0, 50)) . '...' : '' ?>
                    </div>
                    <?php if ($amenityList): ?>
                    <div class="room-showcase-amenities">
                        <?php foreach ($amenityList as $a): ?>
                            <span><?= sanitize(trim($a)) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div class="room-showcase-price">
                        <span class="price">GH&#8373; <?= number_format($r['rental_price'], 0) ?></span>
                        <span class="period">/<?= ($r['charge_period'] ?? 'monthly') === 'daily' ? 'day' : 'month' ?></span>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div style="text-align:center;padding:40px;color:var(--text-muted);">
            <p>All residences are currently occupied. Check back soon or submit a booking request to be notified when residences become available.</p>
        </div>
        <?php endif; ?>
    </div>
</section>

<!-- ============ TESTIMONIALS ============ -->
<section class="booking-section booking-section-alt">
    <div class="booking-container">
        <div class="section-header reveal">
            <div class="section-tag">Testimonials</div>
            <h2>What Our Tenants Say</h2>
            <p>Hear from people who call PK's Luxury Apartments home</p>
        </div>
        <div class="testimonials-grid stagger">
            <div class="testimonial-card">
                <div class="testimonial-text">
                    Living at PK's Luxury Apartments has been a wonderful experience. The rooms are clean, the WiFi is fast, and the management is very responsive to any issues.
                </div>
                <div class="testimonial-author">
                    <div class="testimonial-avatar">AA</div>
                    <div>
                        <div class="testimonial-name">Ama Asante</div>
                        <div class="testimonial-role">Tenant since 2024</div>
                    </div>
                </div>
            </div>
            <div class="testimonial-card">
                <div class="testimonial-text">
                    The location is perfect — close to everything I need in Haatso. The rent is affordable for the quality you get. I highly recommend PK's Luxury Apartments.
                </div>
                <div class="testimonial-author">
                    <div class="testimonial-avatar">KB</div>
                    <div>
                        <div class="testimonial-name">Kwame Boateng</div>
                        <div class="testimonial-role">Tenant since 2025</div>
                    </div>
                </div>
            </div>
            <div class="testimonial-card">
                <div class="testimonial-text">
                    Great security, reliable water and power supply. The online payment system makes rent payment so convenient. Best apartment I've lived in Accra.
                </div>
                <div class="testimonial-author">
                    <div class="testimonial-avatar">EM</div>
                    <div>
                        <div class="testimonial-name">Efua Mensah</div>
                        <div class="testimonial-role">Tenant since 2024</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============ LOCATION SECTION ============ -->
<section class="booking-section" id="location">
    <div class="booking-container">
        <div class="location-grid">
            <a href="https://maps.app.goo.gl/yG677vTeCGbUZYUZ6" target="_blank" rel="noopener" style="text-decoration:none;color:inherit;display:block;">
                <div class="location-map-placeholder" style="padding:0;overflow:hidden;border-radius:var(--radius);min-height:300px;">
                    <iframe src="https://maps.google.com/maps?q=Haatso,+Accra,+Ghana&t=&z=15&ie=UTF8&iwloc=&output=embed" width="100%" height="350" style="border:0;border-radius:var(--radius);min-height:300px;" allowfullscreen="" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>
                </div>
            </a>
            <div class="location-details">
                <div class="section-header" style="text-align:left;margin-bottom:20px;">
                    <div class="section-tag">Our Location</div>
                </div>
                <h3>Conveniently Located in Haatso</h3>
                <div class="location-item">
                    <div class="location-item-icon"><i class='bx bx-map-pin'></i></div>
                    <div class="location-item-text">
                        <strong>Address</strong>
                        <a href="https://maps.app.goo.gl/yG677vTeCGbUZYUZ6" target="_blank" rel="noopener" style="text-decoration:none;color:inherit;">
                            <span>Haatso, Accra, Ghana <i class='bx bx-link-external' style="font-size:0.7rem;"></i></span>
                        </a>
                    </div>
                </div>
                <div class="location-item">
                    <div class="location-item-icon"><i class='bx bx-car'></i></div>
                    <div class="location-item-text">
                        <strong>Transport</strong>
                        <span>Easy access to tro-tro stations and taxi ranks</span>
                    </div>
                </div>
                <div class="location-item">
                    <div class="location-item-icon"><i class='bx bx-store'></i></div>
                    <div class="location-item-text">
                        <strong>Markets & Shops</strong>
                        <span>Walking distance to local markets and shopping centers</span>
                    </div>
                </div>
                <div class="location-item">
                    <div class="location-item-icon"><i class='bx bx-phone'></i></div>
                    <div class="location-item-text">
                        <strong>Contact Us</strong>
                        <span>024 123 4567 &bull; info@pkluxury.com</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ============ SEND A REPORT ============ -->
<section class="booking-section" id="report">
    <div class="booking-container">
        <div class="section-header reveal">
            <div class="section-tag">Contact</div>
            <h2>Send Us a Report</h2>
            <p>Have a question, suggestion, or complaint? Let us know and we'll respond promptly.</p>
        </div>
        <div style="max-width:640px;margin:0 auto;">
            <div id="publicReportSuccess" style="display:none;padding:20px 24px;background:var(--success-bg);border-radius:var(--radius-lg);color:var(--success);text-align:center;font-weight:600;margin-bottom:24px;font-size:0.95rem;border:2px solid var(--success);line-height:1.6;">
                <i class="bx bx-check-circle" style="font-size:1.8rem;display:block;margin-bottom:6px;"></i>
                <span></span>
            </div>
            <form id="publicReportForm" onsubmit="submitPublicReport(event)" style="background:var(--bg-card);padding:32px;border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);border:1px solid var(--border);">
                <div class="form-row">
                    <div class="form-group">
                        <label style="font-weight:700;font-size:0.85rem;">Your Name <span style="color:var(--danger);">*</span></label>
                        <input type="text" name="reporter_name" class="form-control" placeholder="Full name" required maxlength="100">
                    </div>
                    <div class="form-group">
                        <label style="font-weight:700;font-size:0.85rem;">Phone Number</label>
                        <input type="tel" name="reporter_phone" class="form-control" placeholder="024XXXXXXX" pattern="[0-9]{10}" maxlength="10" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
                    </div>
                </div>
                <div class="form-group">
                    <label style="font-weight:700;font-size:0.85rem;">Email Address</label>
                    <input type="email" name="reporter_email" class="form-control" placeholder="e.g. name@example.com" pattern="[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}">
                </div>
                <div class="form-group">
                    <label style="font-weight:700;font-size:0.85rem;">Category <span style="color:var(--danger);">*</span></label>
                    <select name="category" class="form-control" required>
                        <option value="general">General Inquiry</option>
                        <option value="complaint">Complaint</option>
                        <option value="suggestion">Suggestion</option>
                        <option value="maintenance">Maintenance Issue</option>
                        <option value="feedback">Feedback</option>
                        <option value="other">Other</option>
                    </select>
                </div>
                <div class="form-group">
                    <label style="font-weight:700;font-size:0.85rem;">Message <span style="color:var(--danger);">*</span></label>
                    <textarea name="message" class="form-control" rows="5" placeholder="Describe your report in detail..." required maxlength="1000" style="min-height:120px;"></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-block btn-lg" style="display:flex;align-items:center;justify-content:center;gap:8px;">
                    <i class='bx bx-send'></i> Submit Report
                </button>
            </form>
        </div>
    </div>
</section>
</section>

<!-- Booking Success Modal -->
<div id="bookingSuccessModal" class="modal-overlay" onclick="if(event.target===this)closeBookingSuccessModal()">
    <div style="background:#1B2A4A;border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);max-width:460px;width:92%;padding:44px 32px 36px;text-align:center;position:relative;margin:20px;border:1px solid rgba(255,255,255,0.1);">
        <button onclick="closeBookingSuccessModal()" style="position:absolute;top:12px;right:16px;background:none;border:none;font-size:1.5rem;cursor:pointer;color:rgba(255,255,255,0.5);line-height:1;" aria-label="Close">&times;</button>
        <div style="width:76px;height:76px;border-radius:50%;background:rgba(76,175,80,0.15);display:flex;align-items:center;justify-content:center;margin:0 auto 18px;border:2px solid rgba(76,175,80,0.4);">
            <i class="bx bx-check-circle" style="font-size:2.6rem;color:#4CAF50;"></i>
        </div>
        <h3 style="font-size:1.25rem;margin-bottom:10px;color:#FFFFFF;font-weight:700;">Booking Request Submitted</h3>
        <p style="color:rgba(255,255,255,0.7);font-size:0.9rem;line-height:1.6;margin-bottom:28px;">We've received your request and will contact you soon.</p>
        <button onclick="closeBookingSuccessModal()" style="background:linear-gradient(135deg,#4CAF50,#388E3C);color:#fff;border:none;padding:12px 32px;border-radius:var(--radius-sm);font-size:0.95rem;font-weight:600;cursor:pointer;min-width:160px;transition:opacity 0.2s;" onmouseover="this.style.opacity='0.9'" onmouseout="this.style.opacity='1'">OK, Got It</button>
    </div>
</div>

<!-- ============ BOOKING MODAL ============ -->
<div id="bookingModal" class="modal-overlay" onclick="if(event.target===this)closeBookingModal()">
    <div class="booking-form-card" style="max-width:680px;width:95%;max-height:92vh;overflow-y:auto;position:relative;margin:20px;background:#1B2A4A;border-radius:var(--radius-lg);box-shadow:var(--shadow-lg);border:1px solid rgba(255,255,255,0.12);">
        <button onclick="closeBookingModal()" style="position:absolute;top:12px;right:16px;background:none;border:none;font-size:1.6rem;cursor:pointer;color:rgba(255,255,255,0.6);z-index:10;line-height:1;" aria-label="Close">&times;</button>
        <h2 style="padding-right:32px;">Book Now</h2>
        <p class="subtitle">Fill out the form below and our team will get back to you within 24 hours</p>

        <form method="POST" action="#book" id="bookingFormModal" onsubmit="submitBookingModal(event)">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <div class="form-row">
                <div class="form-group">
                    <label>Full Name *</label>
                    <input type="text" name="full_name" class="form-control" placeholder="Your full name" value="<?= sanitize($_POST['full_name'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                    <label>Phone Number *</label>
                    <input type="tel" name="phone" class="form-control" placeholder="024XXXXXXX" pattern="[0-9]{10}" maxlength="10" oninput="this.value=this.value.replace(/[^0-9]/g,'')" value="<?= sanitize($_POST['phone'] ?? '') ?>" required>
                </div>
            </div>
            <div class="form-group">
                <label>Email Address *</label>
                <input type="email" name="email" class="form-control" placeholder="e.g. name@example.com" pattern="[a-zA-Z0-9._%+-]+@[a-zA-Z0-9.-]+\.[a-zA-Z]{2,}" value="<?= sanitize($_POST['email'] ?? '') ?>" required>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label>Preferred Residence</label>
                    <select name="room_id" class="form-control">
                        <option value="">Any available room</option>
                        <?php foreach ($availableRooms as $r): ?>
                        <option value="<?= $r['id'] ?>" <?= (isset($_POST['room_id']) && $_POST['room_id'] == $r['id']) ? 'selected' : '' ?>>
                            <?= sanitize($r['room_number']) ?> — <?= sanitize(ucfirst($r['room_type'])) ?> — GH&#8373; <?= number_format($r['rental_price'], 0) ?>/<?= ($r['charge_period'] ?? 'monthly') === 'daily' ? 'day' : 'mo' ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label><i class='bx bx-calendar' style="vertical-align:-2px;"></i> Preferred View-in Date</label>
                    <input type="date" name="preferred_date" class="form-control" min="<?= date('Y-m-d') ?>" value="<?= sanitize($_POST['preferred_date'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group">
                <label>Message</label>
                <textarea name="message" class="form-control" rows="3" placeholder="Any special requests or questions..."><?= sanitize($_POST['message'] ?? '') ?></textarea>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label><i class='bx bx-credit-card' style="vertical-align:-2px;"></i> Deposit Option</label>
                    <select name="payment_type" id="paymentType" class="form-control" onchange="updateBookingPaymentInfo()">
                        <option value="none">Pay later</option>
                        <option value="down_payment">Pay 50% Deposit Now</option>
                        <option value="full_payment">Pay Full Amount Now</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Deposit Method</label>
                        <select name="payment_method" id="paymentMethod" class="form-control">
                            <option value="paystack">Mobile Money</option>
                            <option value="bank_transfer">Bank Transfer</option>
                        </select>
                </div>
            </div>
            <div id="bookingPaymentInfo" style="display:none;padding:12px 16px;background:var(--surface);border-radius:var(--radius);margin-bottom:16px;font-size:0.85rem;color:var(--text-secondary);border:1px dashed var(--border);">
            </div>
            <div style="padding:10px 16px;background:var(--warning-bg);border-radius:var(--radius);margin-bottom:16px;font-size:0.82rem;color:var(--warning);border:1px solid var(--warning);display:flex;align-items:center;gap:8px;">
                <i class='bx bx-info-circle' style="font-size:1.1rem;flex-shrink:0;"></i>
                <span><strong>Note:</strong> Balance must be paid within 1 week of move-in.</span>
            </div>
            <button type="submit" class="btn btn-primary btn-block btn-lg">Submit Booking Request</button>
        </form>
    </div>
</div>

<!-- ============ FOOTER ============ -->
<footer class="booking-footer">
    <div class="footer-grid">
        <div class="footer-brand">
            <h3><i class='bx bx-home'></i> <span class="brand-gold">PK's</span> <span style="color:#fff;">Luxury Apartments</span></h3>
            <p>Premium apartment living in Haatso, Accra. Modern residences, excellent amenities, and a commitment to tenant satisfaction.</p>
        </div>
        <div class="footer-col">
            <h4>Quick Links</h4>
            <a href="#about">About Us</a>
            <a href="#rooms">Our Residences</a>
            <a href="#amenities">Amenities</a>
            <a href="#location">Location</a>
            <a href="#report">Send a Report</a>
        </div>
        <div class="footer-col">
            <h4>Residence Types</h4>
            <a href="#rooms">Single Residence</a>
            <a href="#rooms">Double Residence</a>
            <a href="#rooms">Studio</a>
            <a href="#rooms">Penthouse</a>
        </div>
        <div class="footer-col">
            <h4>Contact</h4>
            <a href="tel:0554016037"><i class='bx bx-phone'></i> 055 401 6037</a>
            <a href="mailto:info@pkluxury.com"><i class='bx bx-envelope'></i> info@pkluxury.com</a>
            <a href="https://maps.app.goo.gl/yG677vTeCGbUZYUZ6" target="_blank" rel="noopener"><i class='bx bx-map-pin'></i> Haatso, Accra</a>
            <a href="login.php"><i class='bx bx-lock'></i> Tenant Sign In</a>
        </div>
    </div>
    <div class="footer-bottom">
        &copy; <?= date('Y') ?> PK's Luxury Apartments. All rights reserved.
    </div>
</footer>

<!-- ============ RESIDENCE SLIDESHOW MODAL ============ -->
<div class="room-slideshow" id="roomSlideshow" aria-hidden="true">
    <div class="room-slideshow-backdrop" onclick="closeRoomSlideshow()"></div>
    <div class="room-slideshow-panel">
        <button class="room-slideshow-close" onclick="closeRoomSlideshow()" aria-label="Close"><i class='bx bx-x'></i></button>
        <div class="room-slideshow-head">
            <h4 id="roomSlideshowTitle">Residence</h4>
            <span class="room-slideshow-count" id="roomSlideshowCount"></span>
        </div>
        <div class="room-slideshow-stage">
            <button class="room-slideshow-arrow prev" onclick="roomSlideshowMove(-1)" aria-label="Previous"><i class='bx bx-chevron-left'></i></button>
            <div class="room-slideshow-track" id="roomSlideshowTrack"></div>
            <button class="room-slideshow-arrow next" onclick="roomSlideshowMove(1)" aria-label="Next"><i class='bx bx-chevron-right'></i></button>
        </div>
        <div class="room-slideshow-thumbs" id="roomSlideshowThumbs"></div>
    </div>
</div>

<script>
// Theme toggle
function toggleTheme() {
    const current = document.documentElement.getAttribute('data-theme') === 'dark' ? 'dark' : 'light';
    const next = current === 'dark' ? 'light' : 'dark';
    document.documentElement.setAttribute('data-theme', next);
    localStorage.setItem('pk-theme', next);
    const btn = document.getElementById('themeToggle');
    if (btn) btn.innerHTML = next === 'dark' ? '<i class="bx bx-sun"></i>' : '<i class="bx bx-moon"></i>';
}

// Scroll nav background
window.addEventListener('scroll', function() {
    const nav = document.getElementById('bookingNav');
    if (window.scrollY > 50) {
        nav.classList.add('scrolled');
    } else {
        nav.classList.remove('scrolled');
    }
});

// Smooth scroll for anchor links
document.querySelectorAll('a[href^="#"]').forEach(function(a) {
    a.addEventListener('click', function(e) {
        e.preventDefault();
        const target = document.querySelector(this.getAttribute('href'));
        if (target) {
            target.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    });
});

// Scroll reveal
(function() {
    const targets = document.querySelectorAll('.reveal, .reveal-left, .reveal-right, .reveal-scale');
    if (!('IntersectionObserver' in window) || (window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches)) {
        targets.forEach(el => el.classList.add('visible'));
        return;
    }
    const obs = new IntersectionObserver((entries, o) => {
        entries.forEach(en => {
            if (en.isIntersecting) { en.target.classList.add('visible'); o.unobserve(en.target); }
        });
    }, { threshold: 0.08, rootMargin: '0px 0px -40px 0px' });
    targets.forEach(el => obs.observe(el));
})();

// ============ RESIDENCE SLIDESHOW POPUP ============
let roomSlideIndex = 0;
let roomSlideList = [];
const slideshowModal = document.getElementById('roomSlideshow');

function openRoomSlideshow(card) {
    let gallery = [];
    try { gallery = JSON.parse(card.getAttribute('data-gallery') || '[]'); }
    catch (e) { gallery = []; }
    const title = card.getAttribute('data-title') || 'Residence';
    const img = card.querySelector('img');
    if (gallery.length === 0 && img) gallery = [img.getAttribute('src')];

    document.getElementById('roomSlideshowTitle').textContent = title;
    const track = document.getElementById('roomSlideshowTrack');
    const thumbs = document.getElementById('roomSlideshowThumbs');
    track.innerHTML = '';
    thumbs.innerHTML = '';
    roomSlideList = gallery;

    if (gallery.length === 0) {
        track.innerHTML = '<div class="room-slideshow-empty"><i class="bx bx-home"></i><p>No photos available yet for this residence.</p></div>';
        document.getElementById('roomSlideshowCount').textContent = '';
    } else {
        gallery.forEach((src, i) => {
            const slide = document.createElement('div');
            slide.className = 'room-slideshow-slide';
            slide.innerHTML = '<img src="' + src + '" alt="' + title + ' - photo ' + (i + 1) + '">';
            track.appendChild(slide);

            const thumb = document.createElement('button');
            thumb.className = 'room-slideshow-thumb';
            thumb.innerHTML = '<img src="' + src + '" alt="">';
            thumb.onclick = function() { roomSlideshowGoto(i); };
            thumbs.appendChild(thumb);
        });
        document.getElementById('roomSlideshowCount').textContent = gallery.length + (gallery.length === 1 ? ' photo' : ' photos');
    }

    roomSlideIndex = 0;
    roomSlideshowRender();
    slideshowModal.classList.add('show');
    slideshowModal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
}

function roomSlideshowRender() {
    const track = document.getElementById('roomSlideshowTrack');
    const thumbs = document.querySelectorAll('#roomSlideshowThumbs .room-slideshow-thumb');
    if (roomSlideList.length === 0) return;
    track.style.transform = 'translateX(' + (-roomSlideIndex * 100) + '%)';
    thumbs.forEach((t, i) => t.classList.toggle('active', i === roomSlideIndex));
}

function roomSlideshowMove(dir) {
    if (roomSlideList.length === 0) return;
    roomSlideIndex = (roomSlideIndex + dir + roomSlideList.length) % roomSlideList.length;
    roomSlideshowRender();
}

function roomSlideshowGoto(i) {
    roomSlideIndex = i;
    roomSlideshowRender();
}

function closeRoomSlideshow() {
    slideshowModal.classList.remove('show');
    slideshowModal.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
}

document.addEventListener('keydown', function(e) {
    if (!slideshowModal.classList.contains('show')) return;
    if (e.key === 'Escape') closeRoomSlideshow();
    if (e.key === 'ArrowLeft') roomSlideshowMove(-1);
    if (e.key === 'ArrowRight') roomSlideshowMove(1);
});

// ============ PUBLIC REPORT FORM ============
async function submitPublicReport(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    const phone = (form.get('reporter_phone') || '').trim();
    if (phone && !/^[0-9]{10}$/.test(phone)) {
        alert('Phone number must be exactly 10 digits (numbers only).');
        return;
    }
    form.append('action', 'submit');
    const btn = e.target.querySelector('button[type="submit"]');
    const origText = btn.innerHTML;
    btn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Sending...';
    btn.disabled = true;
    const res = await fetch('api/feedback.php', { method: 'POST', body: form });
    const data = await res.json();
    btn.innerHTML = origText;
    btn.disabled = false;
    if (data.success) {
        document.getElementById('publicReportSuccess').querySelector('span').textContent = data.message || 'Your report has been submitted. We will review it shortly.';
        document.getElementById('publicReportSuccess').style.display = 'block';
        e.target.reset();
        showToast('Report submitted successfully!', 'success');
        document.getElementById('publicReportSuccess').scrollIntoView({ behavior: 'smooth', block: 'center' });
        setTimeout(() => { document.getElementById('publicReportSuccess').style.display = 'none'; }, 8000);
    } else {
        alert(data.error || 'Error submitting report. Please try again.');
    }
}

// ============ BOOKING MODAL ============
function openBookingModal() {
    const modal = document.getElementById('bookingModal');
    modal.classList.add('active');
    document.body.style.overflow = 'hidden';
}
function closeBookingModal() {
    const modal = document.getElementById('bookingModal');
    modal.classList.remove('active');
    document.body.style.overflow = 'auto';
}
function closeBookingSuccessModal() {
    document.getElementById('bookingSuccessModal').classList.remove('active');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeBookingModal(); });

// ============ BOOKING MODAL AJAX ============
async function submitBookingModal(e) {
    e.preventDefault();
    const form = new FormData(e.target);
    const phone = (form.get('phone') || '').trim();
    if (!/^[0-9]{10}$/.test(phone)) {
        alert('Phone number must be exactly 10 digits (numbers only).');
        return;
    }
    const fullName = (form.get('full_name') || '').trim();
    if (!fullName) {
        alert('Full name is required.');
        return;
    }
    const btn = e.target.querySelector('button[type="submit"]');
    const origText = btn.innerHTML;
    btn.innerHTML = '<i class="bx bx-loader-alt bx-spin"></i> Submitting...';
    btn.disabled = true;
    try {
        const res = await fetch('api/bookings.php', { method: 'POST', body: form });
        const data = await res.json();
        btn.innerHTML = origText;
        btn.disabled = false;
        if (data.success) {
            e.target.reset();
            closeBookingModal();
            document.getElementById('bookingSuccessModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        } else {
            alert(data.error || 'Error submitting booking. Please try again.');
        }
    } catch (err) {
        btn.innerHTML = origText;
        btn.disabled = false;
        alert('Network error. Please try again.');
    }
}

// ============ BOOKING PAYMENT INFO ============
function updateBookingPaymentInfo() {
    const type = document.getElementById('paymentType').value;
    const info = document.getElementById('bookingPaymentInfo');
    const roomSelect = document.querySelector('select[name="room_id"]');
    const roomId = roomSelect ? roomSelect.value : '';
    const method = document.getElementById('paymentMethod').value;

    if (type === 'none' || !roomId) {
        info.style.display = 'none';
        return;
    }

    let price = 0;
    const selected = roomSelect.options[roomSelect.selectedIndex];
    if (selected && selected.value) {
        const match = selected.text.match(/GH[^\d]*([\d,]+)/);
        if (match) price = parseFloat(match[1].replace(/,/g, ''));
    }

    if (!price) {
        info.style.display = 'none';
        return;
    }

    const amount = type === 'down_payment' ? price * 0.5 : price;
    const label = type === 'down_payment' ? '50% Deposit' : 'Full Amount';
    const methodLabel = method === 'paystack' ? 'Mobile Money' : 'Bank Transfer';

    info.innerHTML = '<strong>' + label + ':</strong> GH&#8373; ' + amount.toLocaleString(undefined, {minimumFractionDigits:2}) +
        ' via ' + methodLabel + (method === 'bank_transfer' ? ' — Bank details will be shown after submission.' : '') +
        (method === 'paystack' ? ' — You will be redirected to complete payment.' : '') +
        '<br><span style="font-size:0.78rem;color:var(--warning);font-weight:600;">Balance must be paid within 1 week of move-in.</span>';
    info.style.display = 'block';
}
</script>
<script src="js/email-validator.js"></script>

</body>
</html>