<?php
require '../config/database.php';

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->prepare("
        SELECT 
            c_name,
            c_b_start,
            c_b_end,
            c_pay AS payout_cycle,
            c_pay_month,
            c_gst_start,
            c_gst_month
        FROM partner_custom_cycle
        WHERE c_name = ?
        ORDER BY c_id
    ");
    
    $stmt->execute([$_GET['cycle_name']]);
    $cycles = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode($cycles);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>