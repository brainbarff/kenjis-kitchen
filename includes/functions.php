<?php
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function money($amount)
{
    return '₱' . number_format((float) $amount, 2);
}

function active_link($key)
{
    return ($GLOBALS['page'] ?? '') === $key ? 'active' : '';
}

function redirect($path)
{
    header('Location: ' . BASE_URL . $path);
    exit;
}

function upload_menu_image($file)
{
    if (empty($file['name'])) {
        return null;
    }

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($allowed[$file['type']]) || $file['size'] > 3000000) {
        return null;
    }

    $name = 'menu_' . time() . '_' . rand(100, 999) . '.' . $allowed[$file['type']];
    move_uploaded_file($file['tmp_name'], UPLOAD_PATH . $name);

    return $name;
}
?>
