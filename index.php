<?php
require_once __DIR__ . '/config.php';
redirect(is_logged_in() ? 'dashboard.php' : 'login.php');
