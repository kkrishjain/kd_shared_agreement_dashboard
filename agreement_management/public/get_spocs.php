<?php
require '../config/database.php';
header('Content-Type: application/json');

if (!isset($_GET['agreement_id'])) {
    echo json_encode(['error' => 'Agreement ID is required']);
    exit;
}

$agreement_id = $_GET['agreement_id']; // ✅ This was missing

try {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $sql = "SELECT * FROM spocs WHERE agreement_id = :agreement_id";
    $stmt = $pdo->prepare($sql);
    $stmt->bindParam(':agreement_id', $agreement_id, PDO::PARAM_INT);
    $stmt->execute();
    
    $spocs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($spocs);

} catch (PDOException $e) {
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
}
?>
