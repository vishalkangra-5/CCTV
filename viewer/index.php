<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Viewer now requires the same admin login as the admin panel. If you'd
// rather have a separate, lower-privilege "viewer" account so you can hand
// it out without giving admin/camera-edit access, that's a small follow-up
// (a second role column on admin_users) — say the word if you want that.
require_login();

readfile(__DIR__ . '/app.html');
