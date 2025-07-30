<?php
session_start();
require '../vendor/autoload.php';
require '../../config/database.php';


use PhpOffice\PhpSpreadsheet\IOFactory;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $productId = $_POST['product_id'];
    $subproductId = $_POST['subproduct_id'];
    $category = $_POST['payin_category'];

    // Validate category
    $validCategories = ['Base', 'ORC', 'Incentive', 'Contest'];
    if (!in_array($category, $validCategories)) {
        die("Invalid category: '$category'. Must be one of: " . implode(', ', $validCategories));
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

    // Prepare query and validation
    $expectedColumns = [];
    $brokerageTypeIndex = 0;

    switch ($category) {
        case 'Base':
        case 'ORC':
            $expectedColumns = [
                'Broker Code', 'Company Code', 'Plan Code', 'PPT', 'PT',
                'Brokerage Type', 'Case Type', 'Applicable Commission Type',
                'Brokerage Applicable From', 'Brokerage Applicable To',
                'Premium From', 'Premium To', 'Applicable Percentage' // Changed from Payin %
            ];
            $brokerageTypeIndex = 5;
            $stmt = $pdo->prepare("
                INSERT INTO payin_grid (
                    product_code, subproduct_code, broker_code, company_code, plan_code,
                    ppt, pt, brokerage_type, case_type, commission_type,
                    brokerage_from, brokerage_to, category, product_name, subproduct_name,
                    target_amount, no_of_tickets, contest_from, contest_to,
                    incentive_applicable_percentage, incentive_from, incentive_to, 
                    premium_from, premium_to, applicable_percentage, commission_statement
                )
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, NULL, NULL, NULL, ?, ?, ?, ?)
            ");
            break;

        case 'Contest':
            $expectedColumns = [
                'Broker Code', 'Company Code', 'Brokerage Type',
                'Applicable Commission Type', 'Contest Target Amount', 'No. of Tickets',
                'Contest Applicable From', 'Contest Applicable To'
            ];
            $brokerageTypeIndex = 2;
            $stmt = $pdo->prepare("
                INSERT INTO payin_grid (
                    product_code, subproduct_code, broker_code, company_code, plan_code,
                    ppt, pt, brokerage_type, case_type, commission_type,
                    brokerage_from, brokerage_to, category, product_name, subproduct_name,
                    target_amount, no_of_tickets, contest_from, contest_to,
                    incentive_applicable_percentage, incentive_from, incentive_to, 
                    premium_from, premium_to, applicable_percentage, commission_statement
                )
                VALUES (?, ?, ?, ?, NULL, NULL, NULL, ?, NULL, ?, NULL, NULL, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, NULL, ?,?)
            ");
            break;

        case 'Incentive':
    $expectedColumns = [
        'Broker Code', 'Company Code', 'Brokerage Type',
        'Applicable Commission Type', 'Incentive Target Amount', 'Incentive Applicable Percentage',
        'Incentive Applicable From', 'Incentive Applicable To'
    ];
    $brokerageTypeIndex = 2;
     $stmt = $pdo->prepare("
        INSERT INTO payin_grid (
            product_code, subproduct_code, broker_code, company_code, plan_code,
            ppt, pt, brokerage_type, case_type, commission_type,
            brokerage_from, brokerage_to, category, product_name, subproduct_name,
            no_of_tickets, contest_from, contest_to,
            target_amount, incentive_from, incentive_to, 
            premium_from, premium_to, applicable_percentage, commission_statement
        )
        VALUES (?, ?, ?, ?, NULL, NULL, NULL, ?, NULL, ?, NULL, NULL, ?, ?, ?, NULL, NULL, NULL, ?, ?, ?, NULL, NULL, ?, ?)
    ");
    break;
    }

    // Validate rows
    $errors = [];
foreach ($sheet as $rowIndex => $row) {
    for ($colIndex = 0; $colIndex < count($expectedColumns); $colIndex++) {
        $value = $row[$colIndex] ?? null;
        if (is_null($value) || trim($value) === '') {
            $errors[] = "Empty value in row " . ($rowIndex + 2) . ", column '" . $expectedColumns[$colIndex] . "'";
        }
    }
    $brokerageType = $row[$brokerageTypeIndex] ?? '';
    if (trim($brokerageType) !== $category) {
        $errors[] = "Category mismatch in row " . ($rowIndex + 2) . ": Expected '$category', found '$brokerageType'";
    }

    // Validate Case Type for Base/ORC
    if ($category === 'Base' || $category === 'ORC') {
        $caseType = $row[6] ?? '';
        $validCaseTypes = ['New Fresh', 'New Port', 'Renewal'];
        if (!in_array(trim($caseType), $validCaseTypes)) {
            $errors[] = "Invalid Case Type in row " . ($rowIndex + 2) . ": '$caseType'. Must be one of: " . implode(', ', $validCaseTypes);
        }
    }

    // Validate Applicable Commission Type
    $commissionTypeIndex = ($category === 'Base' || $category === 'ORC') ? 7 : 3;
    $applicableCommissionType = $row[$commissionTypeIndex] ?? '';
    $validCommissionTypes = ['Net', 'OD', 'TP', 'OD & TP'];
    if (!in_array(trim($applicableCommissionType), $validCommissionTypes)) {
        $errors[] = "Invalid Applicable Commission Type in row " . ($rowIndex + 2) . ": '$applicableCommissionType'. Must be one of: " . implode(', ', $validCommissionTypes);
    }
}

    if (!empty($errors)) {
        echo "Errors found:<br>" . implode("<br>", $errors);
        exit;
    }

    // Insert rows
    foreach ($sheet as $row) {
        switch ($category) {
case 'Base':
case 'ORC':
    $stmt->execute([
        $productId, $subproductId, $row[0], $row[1], $row[2],
        $row[3], $row[4], $row[5], $row[6], $row[7],
        date('Y-m-d', strtotime($row[8])), // brokerage_from
        date('Y-m-d', strtotime($row[9])), // brokerage_to
        $category, $productName, $subproductName,
        (int)$row[10],  // premium_from (cast to integer)
        (int)$row[11],  // premium_to (cast to integer)
        $row[12],       // applicable_percentage
        $commissionData
    ]);
    break;
            case 'Contest':
                $stmt->execute([
                    $productId, $subproductId, $row[0], $row[1],
                    $row[2], $row[3], $category, $productName, $subproductName,
                    $row[4], $row[5], date('Y-m-d', strtotime($row[6])), date('Y-m-d', strtotime($row[7])),
                    NULL, // applicable_percentage
                    $commissionData
                ]);
                break;
case 'Incentive':
    $stmt->execute([
        $productId, $subproductId, $row[0], $row[1],
        $row[2],   // Brokerage Type
        $row[3],   // Applicable Commission Type
        $category, 
        $productName, 
        $subproductName,
        $row[4],   // Incentive Target Amount
        date('Y-m-d', strtotime($row[6])), // incentive_from
        date('Y-m-d', strtotime($row[7])), // incentive_to
        $row[5],   // Incentive Applicable Percentage
        $commissionData
    ]);
    break;
        }
    }
    $_SESSION['upload_success'] = true;
    header("Location: add_payingrid.php");
    exit();
}
?>