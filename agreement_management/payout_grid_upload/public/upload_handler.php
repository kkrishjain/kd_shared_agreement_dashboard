<?php
session_start();
require '../vendor/autoload.php';
require '../../config/database.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $productId = $_POST['product_id'];
    $subproductId = $_POST['subproduct_id'];

    // File check
    $file = $_FILES['excel_file']['tmp_name'];
    if (!$file) {
        die("File not uploaded.");
    }

    // Commission statement is optional
    $commissionData = null;
    if (isset($_FILES['commission_statement']) && $_FILES['commission_statement']['error'] === UPLOAD_ERR_OK) {
        $commissionFile = $_FILES['commission_statement']['tmp_name'];
        $commissionMime = mime_content_type($commissionFile);
        $allowedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png'];
        if (!in_array($commissionMime, $allowedMimeTypes)) {
            die("Invalid Commission Statement format. Allowed: PDF, JPG, PNG.");
        }
        $commissionData = file_get_contents($commissionFile);
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
        header("Location: add_payoutgrid.php");
        exit();
    }

    // Prepare query and validation
    $expectedColumns = [
        'Partner Finqy ID', 
        'Company Code', 
        'Plan Code', 
        'PPT', 
        'PT',
        'Case Type', 
        'Applicable From', 
        'Applicable To',
        'Premium From', 
        'Premium To', 
        'Applicable Percentage'
    ];

    $stmt = $pdo->prepare("
        INSERT INTO payout_grid (
            product_code, subproduct_code, partner_finqy_id, company_code, plan_code,
            ppt, pt, case_type, brokerage_from, brokerage_to, product_name, subproduct_name,
            premium_from, premium_to, applicable_percentage, commission_statement,
            created_at
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");

    // Validate rows
    $errors = [];
    foreach ($sheet as $rowIndex => $row) {
        for ($colIndex = 0; $colIndex < count($expectedColumns); $colIndex++) {
            $value = $row[$colIndex] ?? null;
            if (is_null($value) || trim($value) === '') {
                $errors[] = "Empty value in row " . ($rowIndex + 2) . ", column '" . $expectedColumns[$colIndex] . "'";
            }
        }

        // Validate Case Type
        $caseType = $row[5] ?? '';
        $validCaseTypes = ['New Fresh', 'New Port', 'Renewal'];
        if (!in_array(trim($caseType), $validCaseTypes)) {
            $errors[] = "Invalid Case Type in row " . ($rowIndex + 2) . ": '$caseType'. Must be one of: " . implode(', ', $validCaseTypes);
        }
    }

    if (!empty($errors)) {
        echo "Errors found:<br>" . implode("<br>", $errors);
        exit;
    }

    // Insert rows
    foreach ($sheet as $row) {
        $stmt->execute([
            $productId, $subproductId, $row[0], $row[1],
            $row[2], $row[3], $row[4], $row[5],
            date('Y-m-d', strtotime($row[6])), // brokerage_from
            date('Y-m-d', strtotime($row[7])), // brokerage_to
            $productName, $subproductName,
            (int)$row[8],  // premium_from
            (int)$row[9], // premium_to
            $row[10],      // applicable_percentage
            $commissionData
        ]);
    }
    
    $_SESSION['upload_success'] = true;
    header("Location: add_payoutgrid.php");
    exit();
}
?>