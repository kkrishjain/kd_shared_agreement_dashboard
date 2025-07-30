<?php
require '../config/database.php';

$product_id = $_GET['product_id'] ?? null;
if (!$product_id) {
    http_response_code(400);
    echo json_encode(['error' => 'Product ID required']);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT p_id, p_name FROM products WHERE p_id = ?");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    echo json_encode($product ?: ['error' => 'Product not found']);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
