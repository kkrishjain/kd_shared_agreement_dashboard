<?php
require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$headers = [
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

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();
$sheet->fromArray($headers, null, 'A1');

// Set filename
$filename = "Payout_Grid_Sample_Template.xlsx";

// Send headers
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment;filename=\"$filename\"");
header('Cache-Control: max-age=0');

// Write file
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;