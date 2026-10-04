<?php
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_role('admin');
redirect('/modules/reports/index.php');
