<?php
require '../config/database.php';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="payin_grid_calculations_' . date('Y-m-d') . '.csv"');

try {
    $selectQuery = "SELECT * FROM payin_calculation ORDER BY lead_id";
    $stmt = $pdo->query($selectQuery);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Output CSV headers
    $headers = [
        'Lead ID', 'Product', 'Case Type', 'Application no', 'Policy No',
        'Customer Name', 'Status', 'Broker Name', 'Company Name', 'Plan Name',
        'Frequency', 'PT', 'PPT', 'Net Premium', 'Base %', 'Base Amount',
        'ORC %', 'ORC Amount', 'Incentive %', 'Incentive Amount', 'Contest %', 'Contest Amount',
        'Total Payin %', 'Payin Amount', 'TDS %', 'TDS Amount', 'GST Amount',
        'Gross Receipt', 'Net Receipt', 'Team', 'RM Name'
    ];
    echo implode(',', $headers) . "\r\n";

    // Output CSV data
    foreach ($results as $row) {
        $pt_ppt = explode('/', $row['pt_ppt']);
        $pt = isset($pt_ppt[0]) ? $pt_ppt[0] : '';
        $ppt = isset($pt_ppt[1]) ? $pt_ppt[1] : '';
        
        $csvRow = [
            'LCI' . str_pad($row['lead_id'], 6, '0', STR_PAD_LEFT),
            '"' . addslashes($row['product']) . '"',
            '"' . addslashes($row['case_type']) . '"',
            '"' . addslashes($row['login_app_no']) . '"',
            '"' . addslashes($row['policy_no']) . '"',
            '"' . addslashes($row['customer_name']) . '"',
            '"Policy Issued"',
            '"' . addslashes($row['broker_name']) . '"',
            '"' . addslashes($row['company_name']) . '"',
            '"' . addslashes($row['plan_name']) . '"',
            '"' . addslashes($row['premium_frequencyy']) . '"',
            $pt,
            $ppt,
            number_format($row['net_premium'], 2),
            number_format($row['base_percent'], 2),
            number_format($row['base_amount'], 2),
            number_format($row['orc_percent'], 2),
            number_format($row['orc_amount'], 2),
            number_format($row['incentive_percent'], 2),
            number_format($row['incentive_amount'], 2),
            number_format($row['contest_percent'], 2),
            number_format($row['contest_amount'], 2),
            number_format($row['total_payin_percent'], 2),
            number_format($row['payin_amount'], 2),
            number_format($row['tds_percent'], 2),
            number_format($row['tds_amount'], 2),
            number_format($row['gst_amount'], 2),
            number_format($row['gross_receipt'], 2),
            number_format($row['net_receipt'], 2),
            '"' . addslashes($row['team']) . '"',
            '"' . addslashes($row['rm_name']) . '"'
        ];
        echo implode(',', $csvRow) . "\r\n";
    }

} catch (PDOException $e) {
    http_response_code(500);
    echo "Error: " . $e->getMessage();
}
?>