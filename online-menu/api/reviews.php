<?php
require_once dirname(__DIR__, 2) . '/config/config.php';
kk_start_session();
require_once dirname(__DIR__, 2) . '/config/db.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function review_json($data, $code = 200)
{
    http_response_code($code);
    echo json_encode($data);
    exit;
}

$_SESSION['review_csrf'] ??= bin2hex(random_bytes(32));

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $rows = $conn->query("
            SELECT r.rating, r.comment, r.created_at, u.full_name, u.profile_photo
            FROM customer_reviews r
            JOIN users u ON u.id = r.user_id
            WHERE u.deleted_at IS NULL AND u.is_archived = 0
            ORDER BY r.updated_at DESC
            LIMIT 12
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['rating'] = (int)$row['rating'];
            $row['name'] = kk_customer_first_name($row['full_name'] ?? '');
            $row['profile_photo_url'] = kk_customer_avatar_url($row['profile_photo'] ?? null);
            unset($row['full_name'], $row['profile_photo']);
        }
        unset($row);

        $summary = $conn->query('
            SELECT COUNT(*) AS total, ROUND(AVG(r.rating), 1) AS average
            FROM customer_reviews r
            JOIN users u ON u.id = r.user_id
            WHERE u.deleted_at IS NULL AND u.is_archived = 0
        ')->fetch(PDO::FETCH_ASSOC);

        $myReview = null;
        $sessionCustomerId = (int)($_SESSION['customer_user_id'] ?? 0);
        if ($sessionCustomerId > 0) {
            $myStmt = $conn->prepare('SELECT rating, comment, updated_at FROM customer_reviews WHERE user_id = ? LIMIT 1');
            $myStmt->execute([$sessionCustomerId]);
            $foundReview = $myStmt->fetch(PDO::FETCH_ASSOC);
            if ($foundReview) {
                $myReview = [
                    'rating' => (int)$foundReview['rating'],
                    'comment' => (string)$foundReview['comment'],
                    'updated_at' => (string)$foundReview['updated_at'],
                ];
            }
        }

        review_json([
            'status' => 'success',
            'reviews' => $rows,
            'summary' => $summary,
            'my_review' => $myReview,
            'csrf' => $_SESSION['review_csrf'],
        ]);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        review_json(['message' => 'Method not allowed.'], 405);
    }

    $data = json_decode(file_get_contents('php://input'), true);
    if (!is_array($data) || !hash_equals($_SESSION['review_csrf'], (string)($data['csrf'] ?? ''))) {
        review_json(['message' => 'Please refresh the page and try again.'], 403);
    }

    $id = (int)($_SESSION['customer_user_id'] ?? 0);
    $stmt = $conn->prepare("
        SELECT u.id
        FROM users u
        JOIN roles r ON r.id = u.role_id
        WHERE u.id = ?
          AND r.role_name = 'Customer (Online)'
          AND u.status = 'Active'
          AND u.customer_status = 'Active'
          AND u.email_verified_at IS NOT NULL
          AND u.deleted_at IS NULL
          AND u.is_archived = 0
    ");
    $stmt->execute([$id]);
    if (!$stmt->fetchColumn()) {
        review_json(['message' => 'Please sign in with your customer account to post a review.'], 401);
    }

    $rating = filter_var($data['rating'] ?? null, FILTER_VALIDATE_INT);
    $comment = trim((string)($data['comment'] ?? ''));
    if (!$rating || $rating < 1 || $rating > 5 || mb_strlen($comment) < 10 || mb_strlen($comment) > 1000) {
        review_json(['message' => 'Choose a rating and write 10–1,000 characters.'], 422);
    }

    $stmt = $conn->prepare('
        INSERT INTO customer_reviews (user_id, rating, comment)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            rating = VALUES(rating),
            comment = VALUES(comment),
            updated_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute([$id, $rating, $comment]);
    review_json(['status' => 'success', 'message' => 'Thank you! Your review is now published. Posting again updates your review.']);
} catch (Throwable $e) {
    error_log('Customer reviews: ' . $e->getMessage());
    review_json(['message' => 'Reviews are temporarily unavailable. Please try again later.'], 503);
}
