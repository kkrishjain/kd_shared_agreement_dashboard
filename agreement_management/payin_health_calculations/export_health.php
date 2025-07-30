<?php
require '../config/database.php';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="payin_health_calculations_' . date('Y-m-d') . '.csv"');

try {
    $selectQuery = "SELECT * FROM payin_health_calculation ORDER BY lead_id";
    $stmt = $pdo->query($selectQuery);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Output CSV headers
    $headers = [
        'Lead ID', 'Product', 'Customer Name', 'Broker Name', 'Company Name', 'Plan Name',
        'PT/PPT', 'Net Premium', 'Base %', 'Base Amount', 'Reward %', 'Reward Amount',
        'Total Payin %', 'Payin Amount', 'TDS %', 'TDS Amount', 'GST %', 'GST Amount',
        'Gross Receipt', 'Net Receipt', 'RM Name', 'Team'
    ];
    echo implode(',', $headers) . "\r\n";

    // Output CSV data
    foreach ($results as $row) {
        $pt_ppt = explode('/', $row['pt_ppt']);
        $pt = isset($pt_ppt[0]) ? $pt_ppt[0] : '';
        $ppt = isset($pt_ppt[1]) ? $pt_ppt[1] : '';
        
        $csvRow = [
            'HCI' . str_pad($row['lead_id'], 6, '0', STR_PAD_LEFT),
            '"Health"',
            '"' . addslashes($row['customer_name']) . '"',
            '"' . addslashes($row['broker_name']) . '"',
            '"' . addslashes($row['company_name']) . '"',
            '"' . addslashes($row['plan_name']) . '"',
            '"' . $pt . '/' . $ppt . '"',
            number_format($row['net_premium'], 2),
            number_format($row['base_percent'], 2),
            number_format($row['base_amount'], 2),
            number_format($row['reward_percent'], 2),
            number_format($row['reward_amount'], 2),
            number_format($row['total_payin_percent'], 2),
            number_format($row['payin_amount'], 2),
            number_format($row['tds_percent'], 2),
            number_format($row['tds_amount'], 2),
            number_format($row['gst_percent'], 2),
            number_format($row['gst_amount'], 2),
            number_format($row['gross_receipt'], 2),
            number_format($row['net_receipt'], 2),
            '"' . addslashes($row['rm_name']) . '"',
            '"' . addslashes($row['team']) . '"'
        ];
        echo implode(',', $csvRow) . "\r\n";
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo "Error: " . $e->getMessage();
}