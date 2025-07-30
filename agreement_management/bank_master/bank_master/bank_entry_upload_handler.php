<?php
session_start();
require '../vendor/autoload.php';
require '../../config/database.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

// Check if coming from form submission
if (!isset($_SESSION['bank_entry_data'])) {
    $_SESSION['error'] = "Session data missing. Please submit the form again.";
    header("Location: add_bank_entry.php");
    exit;
}

// Retrieve data from session
$entryType = $_SESSION['bank_entry_data']['entry_type'];
$file = $_SESSION['bank_entry_data']['file_path'] ?? null;
unset($_SESSION['bank_entry_data']);

if (!$file || !file_exists($file)) {
    $_SESSION['error'] = "Temporary file not found. Please try again.";
    header("Location: add_bank_entry.php");
    exit;
}

try {
    $spreadsheet = IOFactory::load($file);
    $sheet = $spreadsheet->getActiveSheet()->toArray();
    
    // Clean up temp file
    @unlink($file);
    
    // Remove header row
    array_shift($sheet);
    
    if (empty($sheet)) {
        throw new Exception("The Excel file is empty. Please include data rows.");
    }
    
    $errors = [];
    $validModes = ['Bank', 'Cash'];
    $validTransactionTypes = ['Net', 'Gross', 'GST', 'Advance'];
    $validYesNo = ['Yes', 'No'];
    $transactionTypeMap = [
        'Net' => 1,
        'Gross' => 2,
        'GST' => 3,
        'Advance' => 4
    ];
    
    $pdo->beginTransaction();
    
    foreach ($sheet as $index => $row) {
        $line = $index + 2; // +2 because header was row 1 and we start at row 2
        
        // Map columns based on entry type
        if ($entryType === 'Credit') {
            $fieldMap = [
                'entity' => 0,
                'mode' => 1,
                'date' => 2,
                'ref_no' => 3,
                'amount' => 4,
                'broker_id' => 5,
                'transaction_type' => 6,
                'invoice_mapping' => 7,
                'gst_mapping_remark' => 8,
                'transaction_name' => 9
            ];
        } else { // Debit
            $fieldMap = [
                'entity' => 0,
                'mode' => 1,
                'date' => 2,
                'ref_no' => 3,
                'amount' => 4,
                'partner_id' => 5,
                'transaction_type' => 6,
                'invoice_mapping' => 7,
                'gst_mapping_remark' => 8
            ];
        }
        
        // Extract values
        $entity = trim($row[$fieldMap['entity']] ?? '');
        $mode = trim($row[$fieldMap['mode']] ?? '');
        $dateStr = trim($row[$fieldMap['date']] ?? '');
        $refNo = trim($row[$fieldMap['ref_no']] ?? '');
        $amount = trim($row[$fieldMap['amount']] ?? '');
        $transactionType = trim($row[$fieldMap['transaction_type']] ?? '');
        $invoiceMapping = trim($row[$fieldMap['invoice_mapping']] ?? '');
        $gstMappingRemark = trim($row[$fieldMap['gst_mapping_remark']] ?? '');
        $dateForDb = null;
        
        // Validate required fields
        $requiredFields = [
            'Entity' => $entity,
            'Mode of Payment' => $mode,
            'Date' => $dateStr,
            'Ref. No.' => $refNo,
            'Amount' => $amount,
            'Type of Transaction' => $transactionType,
            'Invoice Mapping' => $invoiceMapping,
            'GST Mapping Remark' => $gstMappingRemark
        ];
        
        foreach ($requiredFields as $field => $value) {
            if (empty($value)) {
                $errors[] = "Row $line: $field is required";
            }
        }
        
        // Validate Mode of Payment
        if (in_array($mode, $validModes, true)) {
            $otherModeDetail = null;
        } elseif (strtolower($mode) === 'bank' || strtolower($mode) === 'cash') {
            // Case-insensitive match but wrong casing
            $errors[] = "Row $line: '$mode' must be written exactly as 'Bank' or 'Cash' (case-sensitive).";
            continue;
        } else {
            // Anything else goes to Other
            $otherModeDetail = $mode;
            $mode = 'Other';
        }
        
        // Validate Date - Handle both string and Excel date formats
        if (!empty($dateStr)) {
            if (is_numeric($dateStr)) {
                // Handle Excel date serial number
                try {
                    $dateObj = Date::excelToDateTimeObject((float)$dateStr);
                    $dateForDb = $dateObj->format('Y-m-d');
                } catch (\Exception $e) {
                    $errors[] = "Row $line: Invalid Excel date value: $dateStr";
                }
            } else {
                // Handle string in DD/MM/YYYY format
                $parsed = date_parse_from_format('d/m/Y', $dateStr);
                if ($parsed['error_count'] > 0 || $parsed['warning_count'] > 0) {
                    $errors[] = "Row $line: Invalid Date format. Use DD/MM/YYYY. Given: '$dateStr'";
                } elseif (!checkdate($parsed['month'], $parsed['day'], $parsed['year'])) {
                    $errors[] = "Row $line: Invalid date: $dateStr";
                } else {
                    $dateForDb = sprintf('%04d-%02d-%02d', $parsed['year'], $parsed['month'], $parsed['day']);
                }
            }
        }
        
        // Validate Amount
        if (!empty($amount) && (!is_numeric($amount) || $amount <= 0)) {
            $errors[] = "Row $line: Amount must be a positive number";
        }
        
        // Validate Type of Transaction
        if (!empty($transactionType) && !in_array($transactionType, $validTransactionTypes)) {
            $errors[] = "Row $line: Invalid Type of Transaction. Must be Net, Gross, GST, or Advance";
        }
        
        // Validate Invoice Mapping
        if (!empty($invoiceMapping) && !in_array($invoiceMapping, $validYesNo)) {
            $errors[] = "Row $line: Invalid Invoice Mapping. Must be Yes or No";
        }
        
        // Validate GST Mapping Remark
        if (!empty($gstMappingRemark) && !in_array($gstMappingRemark, $validYesNo)) {
            $errors[] = "Row $line: Invalid GST Mapping Remark. Must be Yes or No";
        }
        
        // Validate type-specific fields
        if ($entryType === 'Credit') {
            $brokerId = trim($row[$fieldMap['broker_id']] ?? '');
            $transactionName = trim($row[$fieldMap['transaction_name']] ?? '');
            
            if (empty($brokerId)) {
                $errors[] = "Row $line: Broker ID is required for Credit entries";
            } elseif (!is_numeric($brokerId)) {
                $errors[] = "Row $line: Broker ID must be a number";
            }
            
            if (empty($transactionName)) {
                $errors[] = "Row $line: Transaction Name is required for Credit entries";
            }
        } else {
            $partnerId = trim($row[$fieldMap['partner_id']] ?? '');
            
            if (empty($partnerId)) {
                $errors[] = "Row $line: Partner ID is required for Debit entries";
            }
        }
        
        // Skip insertion if any errors
        if (!empty($errors)) continue;
        
        // Prepare data for insertion
        $transactionTypeId = $transactionTypeMap[$transactionType] ?? null;
        if (!$transactionTypeId) {
            $errors[] = "Row $line: Invalid Transaction Type ID";
            continue;
        }
        
        if ($entryType === 'Credit') {
            $brokerId = $row[$fieldMap['broker_id']];
            $transactionName = $row[$fieldMap['transaction_name']];
            $partnerId = null;
        } else {
            $brokerId = null;
            $transactionName = null;
            $partnerId = $row[$fieldMap['partner_id']];
        }
        
        // Insert into database
        $stmt = $pdo->prepare("
            INSERT INTO ins_bank_master (
                entity, mode_of_payment, other_mode_detail, transaction_date, 
                entry_type, ref_no, amount, broker_id, transaction_type_id, 
                partner_id, transaction_category, invoice_mapping, gst_mapping, 
                created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()
            )
        ");
        
        $stmt->execute([
            $entity,
            $mode,
            $otherModeDetail,
            $dateForDb,
            $entryType,
            $refNo,
            $amount,
            $brokerId,
            $transactionTypeId,
            $partnerId,
            $transactionType,
            $invoiceMapping,
            $gstMappingRemark
        ]);
    }
    
    if (!empty($errors)) {
        $pdo->rollBack();
        $_SESSION['error'] = implode("<br>", $errors);
        header("Location: add_bank_entry.php");
        exit;
    }
    
    $pdo->commit();
    $_SESSION['upload_success'] = true;
    header("Location: index.php");
    exit;
    
} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $_SESSION['error'] = $e->getMessage();
    error_log("Exception: " . $e->getMessage());
    header("Location: add_bank_entry.php");
    exit;
}