<?php
session_start();
require '../vendor/autoload.php';
require '../../config/database.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $productId = $_POST['product_id'];
    $subproductId = $_POST['subproduct_id'];
    
    // Validate subproduct is for health insurance (sp_id 4)
    if ($subproductId != 4) {
        die("This upload handler is only for Health Insurance (subproduct_id 4)");
    }

    // File check
    $file = $_FILES['excel_file']['tmp_name'];
    if (!$file) {
        die("File not uploaded.");
    }
    $commissionFile = $_FILES['commission_statement']['tmp_name'];
    if (!$commissionFile) {
        die("Commission Statement not uploaded.");
    }
    $commissionData = file_get_contents($commissionFile);
    $commissionMime = mime_content_type($commissionFile);
    $allowedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png'];
    if (!in_array($commissionMime, $allowedMimeTypes)) {
        die("Invalid Commission Statement format. Allowed: PDF, JPG, PNG.");
    }

    // Fetch names
    $productStmt = $pdo->prepare("SELECT p_name FROM products WHERE p_id = ?");
    $productStmt->execute([$productId]);
    $product = $productStmt->fetch();
    if (!$product) die("Invalid product ID.");
    $productName = $product['p_name'];

    $subproductStmt = $pdo->prepare("SELECT sp_name FROM sub_products WHERE sp_id = ?");
    $subproductStmt->execute([$subproductId]);
    $subproduct = $subproductStmt->fetch();
    if (!$subproduct) die("Invalid subproduct ID.");
    $subproductName = $subproduct['sp_name'];

    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet()->toArray();
    unset($sheet[0]); // Remove header

    if (empty($sheet)) {
        $_SESSION['error'] = "The Excel file is empty. Please include data rows.";
        header("Location: add_payingrid.php");
        exit();
    }

    // Expected columns for health insurance
    $expectedColumns = [
        'Broker Code', 'Company Code', 'Plan Code', 'Policy Combination', 
        'Policy Type', 'Brokerage Type', 'Applicable start', 'Applicable end',
        'Slab Start', 'Slab end', 'Age from', 'Age to', 'Premium From', 
        'Premium To', 'Location', 'Applicable Percentage'
    ];

    // Prepare the insert statement for health insurance
    $stmt = $pdo->prepare("
        INSERT INTO payin_grid (
            product_code, subproduct_code, broker_code, company_code, plan_code,
            brokerage_type, brokerage_from, brokerage_to, category, 
            product_name, subproduct_name, policy_combination, policy_type,
            slab_start, slab_end, age_from, age_to, premium_from, premium_to, 
            location, applicable_percentage, commission_statement
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Health', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    // Validate rows
    $errors = [];
    foreach ($sheet as $rowIndex => $row) {
        // Check if row has enough columns
        if (count($row) < count($expectedColumns)) {
            $errors[] = "Row " . ($rowIndex + 2) . " has missing columns. Expected " . count($expectedColumns) . " columns.";
            continue;
        }

        // Validate required fields
        for ($colIndex = 0; $colIndex < count($expectedColumns); $colIndex++) {
            $value = $row[$colIndex] ?? null;
            if (is_null($value) || trim($value) === '') {
                $errors[] = "Empty value in row " . ($rowIndex + 2) . ", column '" . $expectedColumns[$colIndex] . "'";
            }
        }

        // Validate date formats
        try {
            $brokerageFrom = date('Y-m-d', strtotime($row[6]));
            $brokerageTo = date('Y-m-d', strtotime($row[7]));
        } catch (Exception $e) {
            $errors[] = "Invalid date format in row " . ($rowIndex + 2) . " for Applicable start/end dates";
        }

        // Validate numeric fields
        if (!is_numeric($row[8]) || !is_numeric($row[9]) || 
            !is_numeric($row[10]) || !is_numeric($row[11]) ||
            !is_numeric($row[12]) || !is_numeric($row[13]) ||
            !is_numeric($row[15])) {
            $errors[] = "Numeric values expected in row " . ($rowIndex + 2) . " for slab/age/premium/percentage fields";
        }
    }

    if (!empty($errors)) {
        echo "Errors found:<br>" . implode("<br>", $errors);
        exit;
    }

    // Insert rows
    foreach ($sheet as $row) {
        $stmt->execute([
            $productId, $subproductId, $row[0], $row[1], $row[2], // broker, company, plan codes
            $row[5], // brokerage_type
            date('Y-m-d', strtotime($row[6])), // brokerage_from
            date('Y-m-d', strtotime($row[7])), // brokerage_to
            $productName, $subproductName,
            $row[3], // policy_combination
            $row[4], // policy_type
            $row[8], // slab_start
            $row[9], // slab_end
            $row[10], // age_from
            $row[11], // age_to
            $row[12], // premium_from
            $row[13], // premium_to
            $row[14], // location
            $row[15], // applicable_percentage
            $commissionData
        ]);
    }

    $_SESSION['upload_success'] = true;
    header("Location: add_payingrid.php");
    exit();
}
?>