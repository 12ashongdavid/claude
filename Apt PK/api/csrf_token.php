<?php
// Hands back a CSRF token bound to the caller's current session. Public
// forms (booking, feedback) can sit open for a while as visitors browse
// rooms before submitting, and the session backing their original token
// may have expired server-side by then — this lets the page fetch a fresh
// one right before it actually submits, instead of failing with a stale
// "Invalid security token" error.
require_once __DIR__ . '/../config/database.php';
header('Content-Type: application/json');
echo json_encode(['csrf_token' => generateCSRFToken()]);
