<?php
require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$category = $_GET['category'] ?? 'Base';
$product_type = $_GET['product_type'] ?? ''; // Added product type parameter

// Define headers based on category and product type
$headers = [];
switch ($category) {
    case 'Base':
    case 'Reward':
        // Updated Health Insurance headers
        if ($product_type === 'Health Insurance') {
            $headers = [
                'Broker Code', 'Company Code', 'Plan Code', 'Policy Combination', 'Case Type',
                'Brokerage Type', 'Applicable start', 'Applicable end',
                'Slab Start', 'Slab end', 'Age from', 'Age to',
                'Premium From', 'Premium To', 'Location', 'Applicable Percentage','Policy Start', 'Policy End'
            ];
        } else { // Life Cum Investment
            $headers = [
                'Broker Code', 'Company Code', 'Plan Code', 'PPT', 'PT',
                'Brokerage Type', 'Case Type', 'Applicable Commission Type',
                'Brokerage Applicable From', 'Brokerage Applicable To',
                'Premium From', 'Premium To', 'Applicable Percentage'
            ];
        }
        break;
        
    case 'Contest':
        $headers = [
            'Broker Code', 'Company Code', 'Brokerage Type',
            'Applicable Commission Type', 'Contest Target Amount', 'No. of Tickets',
            'Contest Applicable From', 'Contest Applicable To'
        ];
        break;
        
    case 'Incentive':
        $headers = [
            'Broker Code', 'Company Code', 'Brokerage Type',
            'Applicable Commission Type', 'Incentive Target Amount', 'Applicable Percentage',
            'Incentive Applicable From', 'Incentive Applicable To'
        ];
        break;
        
    default:
        $headers = [
            'Broker Code', 'Company Code', 'Plan Code', 'PPT', 'PT',
            'Brokerage Type', 'Case Type', 'Applicable Commission Type',
            'Brokerage Applicable From', 'Brokerage Applicable To'
        ];
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->fromArray($headers, null, 'A1');

// Set filename
$filename = "{$category}_Payin_Template.xlsx";

// Send headers
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment;filename=\"$filename\"");
header('Cache-Control: max-age=0');

// Write file
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;