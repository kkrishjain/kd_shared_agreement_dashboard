<?php
require '../config/database.php';

header('Content-Type: application/json');

try {
    if (!isset($_GET['repository_id'])) {
        throw new Exception('Repository ID not provided');
    }

    $repositoryId = (int)$_GET['repository_id'];
    
    // Updated query - removed 'type' column since it doesn't exist
    $stmt = $pdo->prepare("SELECT id, email, created_at FROM billing_repository_email WHERE repository_id = ?");
    $stmt->execute([$repositoryId]);
    $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format created_at dates for better display
    foreach ($emails as &$email) {
        if (!empty($email['created_at'])) {
            try {
                $date = new DateTime($email['created_at']);
                $email['created_at_formatted'] = $date->format('d-m-Y H:i:s');
            } catch (Exception $e) {
                $email['created_at_formatted'] = $email['created_at'];
            }
        } else {
            $email['created_at_formatted'] = 'N/A';
        }
    }

    echo json_encode([
        'success' => true,
        'data' => $emails,
        'count' => count($emails)
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'trace' => $e->getTrace() // For debugging
    ]);
}