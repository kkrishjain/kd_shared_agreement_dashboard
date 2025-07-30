<?php
require '../config/database.php';


try {
   // $pdo = new PDO("mysql:host=e2e-116-195.ssdcloudindia.net;dbname=finqy_dev", "dev_read", "finQY@22025#");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $agreementId = $_GET['agreement_id'] ?? 0;
    
    $stmt = $pdo->prepare("
        SELECT 
            business_start_day, 
            business_end_day,
            invoice_day,
            invoice_month,
            payment_day,
            gst_start_day,
            gst_month
        FROM agreement_cycles 
        WHERE agreement_id = ?
    ");
    $stmt->execute([$agreementId]);
    $cycles = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/json');
    echo json_encode($cycles);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error: ' . $e->getMessage(),
        'received_id' => $agreementId  // For debugging
    ]);
}
?>