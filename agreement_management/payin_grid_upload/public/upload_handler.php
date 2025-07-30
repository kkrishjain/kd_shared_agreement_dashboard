<?php
session_start();
require '../vendor/autoload.php';
require '../../config/database.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $productId = $_POST['product_id'];
    $subproductId = $_POST['subproduct_id'];
    
    // Common file checks
    $file = $_FILES['excel_file']['tmp_name'];
    if (!$file) {
        $_SESSION['error'] = "File not uploaded.";
        header("Location: add_payingrid.php");
        exit();
    }
    $commissionFile = $_FILES['commission_statement']['tmp_name'];
    if (!$commissionFile) {
        $_SESSION['error'] = "Commission Statement not uploaded.";
        header("Location: add_payingrid.php");
        exit();
    }
    $commissionData = file_get_contents($commissionFile);
    $commissionMime = mime_content_type($commissionFile);
    $allowedMimeTypes = ['application/pdf', 'image/jpeg', 'image/png'];
    if (!in_array($commissionMime, $allowedMimeTypes)) {
        $_SESSION['error'] = "Invalid Commission Statement format. Allowed: PDF, JPG, PNG.";
        header("Location: add_payingrid.php");
        exit();
    }

    // Fetch names (common for both)
    $productStmt = $pdo->prepare("SELECT p_name FROM products WHERE p_id = ?");
    $productStmt->execute([$productId]);
    $product = $productStmt->fetch();
    if (!$product) {
        $_SESSION['error'] = "Invalid product ID.";
        header("Location: add_payingrid.php");
        exit();
    }
    $productName = $product['p_name'];

    $subproductStmt = $pdo->prepare("SELECT sp_name FROM sub_products WHERE sp_id = ?");
    $subproductStmt->execute([$subproductId]);
    $subproduct = $subproductStmt->fetch();
    if (!$subproduct) {
        $_SESSION['error'] = "Invalid subproduct ID.";
        header("Location: add_payingrid.php");
        exit();
    }
    $subproductName = $subproduct['sp_name'];

    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet()->toArray();
    unset($sheet[0]); // Remove header

    if (empty($sheet)) {
        $_SESSION['error'] = "The Excel file is empty. Please include data rows.";
        header("Location: add_payingrid.php");
        exit();
    }

    // FIX: Use case-insensitive comparison for subproduct name
    if (strtolower(trim($subproductName)) === 'health insurance') {
        // Health Insurance Processing
        handleHealthInsurance($pdo, $productId, $subproductId, $productName, $subproductName, $sheet, $commissionData);
    } else {
        // LCI Processing
        if (!isset($_POST['payin_category'])) {
            $_SESSION['error'] = "Category is required for LCI products.";
            header("Location: add_payingrid.php");
            exit();
        }
        $category = $_POST['payin_category'];
        handleLCI($pdo, $productId, $subproductId, $productName, $subproductName, $category, $sheet, $commissionData);
    }

    header("Location: add_payingrid.php");
    exit();
}

function handleHealthInsurance($pdo, $productId, $subproductId, $productName, $subproductName, $sheet, $commissionData) {
    // Add category parameter from form
    $category = $_POST['payin_category'] ?? 'Base'; // Default to Base if not provided
    $expectedColumns = [
        'Broker Code', 'Company Code', 'Plan Code', 'Policy Combination', 
        'Case Type', 'Brokerage Type', 'Applicable start', 'Applicable end',
        'Slab Start', 'Slab end', 'Age from', 'Age to', 'Premium From', 
        'Premium To', 'Location', 'Applicable Percentage', 'Policy Start', 'Policy End'
    ];

    // Fixed SQL query with proper column names and placeholders
    $stmt = $pdo->prepare("
        INSERT INTO ins_payin_grid_health (
            product_code, subproduct_code, broker_code, company_code, plan_code,
            brokerage_type, applicable_start, applicable_end, product_name,
            subproduct_name, policy_combination, case_type,
            slab_start, slab_end, age_from, age_to, premium_from, premium_to,
            location, applicable_percentage, commission_statement, category, policy_start, 
            policy_end, years_of_policy
        )
        VALUES (
            ?, ?, ?, ?, ?, 
            ?, 
            ?, 
            ?, 
            ?,
            ?,
            ?,
            ?,      
            ?,      
            ?,      
            ?,      
            ?,      
            ?,      
            ?,      
            ?,      
            ?,      
            ?,      
            ?, ?, ?, ?
        )
    ");

    $errors = [];
    foreach ($sheet as $rowIndex => $row) {
        // Skip empty rows
        if (count(array_filter($row)) === 0) continue;

        // Check required fields
        $requiredFields = [0, 1, 2, 5, 6, 7];
        foreach ($requiredFields as $colIndex) {
            if (!isset($row[$colIndex]) || trim($row[$colIndex]) === '') {
                $errors[] = "Required value missing in row " . ($rowIndex + 2) . ", column '" . $expectedColumns[$colIndex] . "'";
            }
        }

        // Validate Broker Code (numeric only)
        if (isset($row[0])) {
            $brokerCode = trim($row[0]);
            if (!is_numeric($brokerCode)) {
                $errors[] = "Invalid Broker Code in row " . ($rowIndex + 2) . ": Must be numeric only";
            }
        }

        // Validate Company Code (numeric only and exists in company_names_standardized with cns_product='hi')
        if (isset($row[1])) {
            $companyCode = trim($row[1]);
            if (!is_numeric($companyCode)) {
                $errors[] = "Invalid Company Code in row " . ($rowIndex + 2) . ": Must be numeric only";
            } else {
                // Check if company exists in company_names_standardized table for health insurance
                $companyCheck = $pdo->prepare("SELECT cns_id, cns_name FROM company_names_standardized WHERE cns_id = ? AND cns_product = 'hi' AND cns_status = '1'");
                $companyCheck->execute([$companyCode]);
                $company = $companyCheck->fetch();
                
                if (!$company) {
                    $errors[] = "Invalid Company Code in row " . ($rowIndex + 2) . ": Company not found or not valid for health insurance";
                }
            }
        }

        // Validate Plan Code (numeric only)
        if (isset($row[2])) {
            $planCode = trim($row[2]);
            if (!is_numeric($planCode)) {
                $errors[] = "Invalid Plan Code in row " . ($rowIndex + 2) . ": Must be numeric only";
            }
        }

        // Validate Policy Combination (alphabet and special characters only)
        if (isset($row[3])) {
            $policyCombination = trim($row[3]);
            if (!preg_match('/^[a-zA-Z\s\-\_\&\+\@\#\%\*\:\,\.]+$/', $policyCombination)) {
                $errors[] = "Invalid Policy Combination in row " . ($rowIndex + 2) . ": Only alphabets and special characters allowed";
            }
        }

        // Validate Case Type (alphabet only and specific values)
        if (isset($row[4])) {
            $caseType = trim($row[4]);
            $validCaseTypes = [
                'New Fresh', 
                'New Topup', 
                'New Port', 
                'Renewal Fresh', 
                'Renewal Topup', 
                'Renewal Port'
            ];
            
            // Check if it contains only alphabets and spaces
            if (!preg_match('/^[a-zA-Z\s]+$/', $caseType)) {
                $errors[] = "Invalid Case Type in row " . ($rowIndex + 2) . ": Only alphabets and spaces allowed";
            }
            // Check if it matches one of the valid case types
            elseif (!in_array($caseType, $validCaseTypes)) {
                $errors[] = "Invalid Case Type in row " . ($rowIndex + 2) . ": Must be one of: " . 
                        implode(', ', $validCaseTypes);
            }
        }

        // Validate Brokerage Type (specific values only)
        if (isset($row[5])) {
            $brokerageType = trim($row[5]);
            $validBrokerageTypes = ['Base', 'Reward'];
            if (!in_array($brokerageType, $validBrokerageTypes)) {
                $errors[] = "Invalid Brokerage Type in row " . ($rowIndex + 2) . ": Must be one of " . implode(', ', $validBrokerageTypes);
            }
            
            // Validate that brokerage_type matches selected category
            if ($brokerageType !== $category) {
                $errors[] = "Brokerage Type mismatch in row " . ($rowIndex + 2) . ": Expected '$category', found '$brokerageType'";
            }
        }

        // Validate dates
        $dateFrom = isset($row[6]) ? trim($row[6]) : '';
        $dateTo = isset($row[7]) ? trim($row[7]) : '';
        $policyStart = isset($row[16]) ? trim($row[16]) : '';
        $policyEnd = isset($row[17]) ? trim($row[17]) : '';

        if ($dateFrom && !strtotime($dateFrom)) {
            $errors[] = "Invalid date format in row " . ($rowIndex + 2) . " for Applicable start date: '$dateFrom'";
        }
        if ($dateTo && !strtotime($dateTo)) {
            $errors[] = "Invalid date format in row " . ($rowIndex + 2) . " for Applicable end date: '$dateTo'";
        }
        if ($policyStart && !strtotime($policyStart)) {
            $errors[] = "Invalid date format in row " . ($rowIndex + 2) . " for Policy start date: '$policyStart'";
        }
        if ($policyEnd && !strtotime($policyEnd)) {
            $errors[] = "Invalid date format in row " . ($rowIndex + 2) . " for Policy end date: '$policyEnd'";
        }

        // Validate Slab Start (numeric only)
        if (isset($row[8])) {
            $slabStart = trim($row[8]);
            if (!is_numeric($slabStart)) {
                $errors[] = "Invalid Slab Start in row " . ($rowIndex + 2) . ": Must be numeric only";
            }
        }

        // Validate Slab End (numeric only)
        if (isset($row[9])) {
            $slabEnd = trim($row[9]);
            if (!is_numeric($slabEnd)) {
                $errors[] = "Invalid Slab End in row " . ($rowIndex + 2) . ": Must be numeric only";
            }
        }

        // Validate Age From (numeric only)
        if (isset($row[10])) {
            $ageFrom = trim($row[10]);
            if (!is_numeric($ageFrom)) {
                $errors[] = "Invalid Age From in row " . ($rowIndex + 2) . ": Must be numeric only";
            }
        }

        // Validate Age To (numeric only)
        if (isset($row[11])) {
            $ageTo = trim($row[11]);
            if (!is_numeric($ageTo)) {
                $errors[] = "Invalid Age To in row " . ($rowIndex + 2) . ": Must be numeric only";
            }
        }

        // Validate Premium From (numeric only)
        if (isset($row[12])) {
            $premiumFrom = trim($row[12]);
            if (!is_numeric($premiumFrom)) {
                $errors[] = "Invalid Premium From in row " . ($rowIndex + 2) . ": Must be numeric only";
            }
        }

        // Validate Premium To (numeric only)
        if (isset($row[13])) {
            $premiumTo = trim($row[13]);
            if (!is_numeric($premiumTo)) {
                $errors[] = "Invalid Premium To in row " . ($rowIndex + 2) . ": Must be numeric only";
            }
        }

        // Validate Location (alphabet only)
        if (isset($row[14])) {
            $location = trim($row[14]);
            if (!preg_match('/^[a-zA-Z\s]+$/', $location)) {
                $errors[] = "Invalid Location in row " . ($rowIndex + 2) . ": Only alphabets allowed";
            }
        }

        // Validate Applicable Percentage (decimal with %)
        if (isset($row[15])) {
            $applicablePercentage = trim($row[15]);
            if (!preg_match('/^[\d\.]+%?$/', $applicablePercentage)) {
                $errors[] = "Invalid Applicable Percentage in row " . ($rowIndex + 2) . ": Must be a decimal value with optional % sign";
            }
        }
    }

    if (!empty($errors)) {
        $_SESSION['error'] = implode("<br>", $errors);
        header("Location: add_payingrid.php");
        exit();
    } 

    $pdo->beginTransaction();
    try {
       foreach ($sheet as $row) {
            // Skip empty rows
            if (count(array_filter($row)) === 0) continue;

            // Calculate years_of_policy if both dates are present
            $yearsOfPolicy = null;
            $policyStart = isset($row[16]) && $row[16] ? date('Y-m-d', strtotime($row[16])) : null;
            $policyEnd = isset($row[17]) && $row[17] ? date('Y-m-d', strtotime($row[17])) : null;
            
            if ($policyStart && $policyEnd) {
                $start = new DateTime($policyStart);
                $end = new DateTime($policyEnd);
                $interval = $start->diff($end);
                $yearsOfPolicy = $interval->y;
            }

            $stmt->execute([
                $productId, 
                $subproductId, 
                trim($row[0]), 
                trim($row[1]), 
                trim($row[2]), 
                trim($row[5]),  // brokerage_type from Excel
                date('Y-m-d', strtotime($row[6])), 
                date('Y-m-d', strtotime($row[7])), 
                $productName, 
                $subproductName,
                isset($row[3]) ? trim($row[3]) : null, 
                isset($row[4]) ? trim($row[4]) : null, 
                isset($row[8]) && is_numeric($row[8]) ? (int)$row[8] : null, 
                isset($row[9]) && is_numeric($row[9]) ? (int)$row[9] : null, 
                isset($row[10]) && is_numeric($row[10]) ? (int)$row[10] : null, 
                isset($row[11]) && is_numeric($row[11]) ? (int)$row[11] : null, 
                isset($row[12]) && is_numeric($row[12]) ? (int)$row[12] : null, 
                isset($row[13]) && is_numeric($row[13]) ? (int)$row[13] : null, 
                isset($row[14]) ? trim($row[14]) : null, 
                isset($row[15]) ? str_replace('%', '', trim($row[15])) : null,  // Remove % before storing
                $commissionData,
                $category,  // category from form
                $policyStart, // policy_start
                $policyEnd,  // policy_end
                $yearsOfPolicy  // years_of_policy (calculated value)
            ]);
        }
        $pdo->commit();
        $_SESSION['success'] = "Health insurance payin grid uploaded successfully!";
    } catch (PDOException $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Database error: " . $e->getMessage();
        header("Location: add_payingrid.php");
        exit();
    }
}

function handleLCI($pdo, $productId, $subproductId, $productName, $subproductName, $category, $sheet, $commissionData) {
    // Validate category
    $validCategories = ['Base', 'ORC', 'Incentive', 'Contest'];
    if (!in_array($category, $validCategories)) {
        $_SESSION['error'] = "Invalid category: '$category'. Must be one of: " . implode(', ', $validCategories);
        header("Location: add_payingrid.php");
        exit();
    }

    // Prepare query and validation based on category
    $expectedColumns = [];
    $brokerageTypeIndex = 0;

    switch ($category) {
        case 'Base':
        case 'ORC':
            $expectedColumns = [
                'Broker Code', 'Company Code', 'Plan Code', 'PPT', 'PT',
                'Brokerage Type', 'Case Type', 'Applicable Commission Type',
                'Brokerage Applicable From', 'Brokerage Applicable To',
                'Premium From', 'Premium To', 'Applicable Percentage'
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
                VALUES (?, ?, ?, ?, ?, ?, ?,?, ?, ?, ?, ?, ?, ?, ?, NULL, NULL, NULL, NULL, NULL, NULL, NULL, ?, ?, ?, ?)
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
    // Skip empty rows
    if (count(array_filter($row)) === 0) continue;

    // Check required fields for all columns
    for ($colIndex = 0; $colIndex < count($expectedColumns); $colIndex++) {
        $value = $row[$colIndex] ?? null;
        if (is_null($value) || trim($value) === '') {
            $errors[] = "Empty value in row " . ($rowIndex + 2) . ", column '" . $expectedColumns[$colIndex] . "'";
        }
    }

    // Validate Broker Code (numeric only)
    if (isset($row[0])) {
        $brokerCode = trim($row[0]);
        if (!is_numeric($brokerCode)) {
            $errors[] = "Invalid Broker Code in row " . ($rowIndex + 2) . ": Must be numeric only";
        }
    }

    // Validate Company Code (numeric only)
    if (isset($row[1])) {
        $companyCode = trim($row[1]);
        if (!is_numeric($companyCode)) {
            $errors[] = "Invalid Company Code in row " . ($rowIndex + 2) . ": Must be numeric only";
        }
    }

    // Validate Plan Code (numeric only - for Base/ORC)
    if (($category === 'Base' || $category === 'ORC') && isset($row[2])) {
        $planCode = trim($row[2]);
        if (!is_numeric($planCode)) {
            $errors[] = "Invalid Plan Code in row " . ($rowIndex + 2) . ": Must be numeric only";
        }
    }

    // Validate PPT (numeric only - for Base/ORC)
    if (($category === 'Base' || $category === 'ORC') && isset($row[3])) {
        $ppt = trim($row[3]);
        if (!is_numeric($ppt)) {
            $errors[] = "Invalid PPT in row " . ($rowIndex + 2) . ": Must be numeric only";
        }
    }

    // Validate PT (numeric only - for Base/ORC)
    if (($category === 'Base' || $category === 'ORC') && isset($row[4])) {
        $pt = trim($row[4]);
        if (!is_numeric($pt)) {
            $errors[] = "Invalid PT in row " . ($rowIndex + 2) . ": Must be numeric only";
        }
    }

    // Validate Brokerage Type matches category
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

    // Validate dates
    $dateFields = [];
    switch ($category) {
        case 'Base':
        case 'ORC':
            $dateFields = [
                ['index' => 8, 'name' => 'Brokerage Applicable From'],
                ['index' => 9, 'name' => 'Brokerage Applicable To']
            ];
            break;
        case 'Contest':
            $dateFields = [
                ['index' => 6, 'name' => 'Contest Applicable From'],
                ['index' => 7, 'name' => 'Contest Applicable To']
            ];
            break;
        case 'Incentive':
            $dateFields = [
                ['index' => 6, 'name' => 'Incentive Applicable From'],
                ['index' => 7, 'name' => 'Incentive Applicable To']
            ];
            break;
    }

    foreach ($dateFields as $field) {
        $dateValue = $row[$field['index']] ?? '';
        if (!strtotime($dateValue)) {
            $errors[] = "Invalid date format in row " . ($rowIndex + 2) . " for " . $field['name'] . ": '$dateValue'";
        }
    }

    // Validate numeric fields
    $numericFields = [];
    switch ($category) {
        case 'Base':
        case 'ORC':
            $numericFields = [
                ['index' => 10, 'name' => 'Premium From'],
                ['index' => 11, 'name' => 'Premium To']
            ];
            break;
        case 'Contest':
            $numericFields = [
                ['index' => 4, 'name' => 'Contest Target Amount'],
                ['index' => 5, 'name' => 'No. of Tickets']
            ];
            break;
        case 'Incentive':
            $numericFields = [
                ['index' => 4, 'name' => 'Incentive Target Amount'],
                ['index' => 5, 'name' => 'Incentive Applicable Percentage']
            ];
            break;
    }

    foreach ($numericFields as $field) {
        $numValue = $row[$field['index']] ?? '';
        if (!is_numeric($numValue)) {
            $errors[] = "Invalid value in row " . ($rowIndex + 2) . " for " . $field['name'] . ": Must be numeric";
        }
    }

    // Validate percentage format for applicable percentage (Base/ORC)
    if (($category === 'Base' || $category === 'ORC') && isset($row[12])) {
        $applicablePercentage = trim($row[12]);
        if (!preg_match('/^[\d\.]+%?$/', $applicablePercentage)) {
            $errors[] = "Invalid Applicable Percentage in row " . ($rowIndex + 2) . ": Must be a decimal value with optional % sign";
        }
    }
}

if (!empty($errors)) {
    $_SESSION['error'] = "Errors found:<br>" . implode("<br>", $errors);
    header("Location: add_payingrid.php");
    exit();
}

// Insert rows
$pdo->beginTransaction();
try {
    foreach ($sheet as $row) {
        // Skip empty rows
        if (count(array_filter($row)) === 0) continue;

        switch ($category) {
            case 'Base':
            case 'ORC':
                $stmt->execute([
                    $productId, $subproductId, trim($row[0]), trim($row[1]), trim($row[2]),
                    trim($row[3]), trim($row[4]), trim($row[5]), trim($row[6]), trim($row[7]),
                    date('Y-m-d', strtotime(trim($row[8]))), // brokerage_from
                    date('Y-m-d', strtotime(trim($row[9]))), // brokerage_to
                    $category, $productName, $subproductName,
                    is_numeric($row[10]) ? (int)$row[10] : null,  // premium_from
                    is_numeric($row[11]) ? (int)$row[11] : null,  // premium_to
                    isset($row[12]) ? str_replace('%', '', trim($row[12])) : null, // applicable_percentage
                    $commissionData
                ]);
                break;
            case 'Contest':
                $stmt->execute([
                    $productId, $subproductId, trim($row[0]), trim($row[1]),
                    trim($row[2]), trim($row[3]), $category, $productName, $subproductName,
                    is_numeric($row[4]) ? (int)$row[4] : null, // target_amount
                    is_numeric($row[5]) ? (int)$row[5] : null, // no_of_tickets
                    date('Y-m-d', strtotime(trim($row[6]))), // contest_from
                    date('Y-m-d', strtotime(trim($row[7]))), // contest_to
                    NULL, // applicable_percentage
                    $commissionData
                ]);
                break;
            case 'Incentive':
                $stmt->execute([
                    $productId, $subproductId, trim($row[0]), trim($row[1]),
                    trim($row[2]),   // Brokerage Type
                    trim($row[3]),   // Applicable Commission Type
                    $category, 
                    $productName, 
                    $subproductName,
                    is_numeric($row[4]) ? (int)$row[4] : null,   // Incentive Target Amount
                    date('Y-m-d', strtotime(trim($row[6]))), // incentive_from
                    date('Y-m-d', strtotime(trim($row[7]))), // incentive_to
                    isset($row[5]) ? str_replace('%', '', trim($row[5])) : null,   // Incentive Applicable Percentage
                    $commissionData
                ]);
                break;
        }
    }
    $pdo->commit();
    $_SESSION['success'] = "LCI payin grid ($category) uploaded successfully!";
} catch (PDOException $e) {
    $pdo->rollBack();
    $_SESSION['error'] = "Database error: " . $e->getMessage();
    header("Location: add_payingrid.php");
    exit();
}
}
?>