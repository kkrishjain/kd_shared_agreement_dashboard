<?php
require '../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Get entry type from query parameter
$type = isset($_GET['type']) ? ucfirst(strtolower($_GET['type'])) : 'Credit';
if (!in_array($type, ['Credit', 'Debit'])) {
    header('HTTP/1.1 400 Bad Request');
    echo "Invalid entry type specified.";
    exit;
}

$spreadsheet = new Spreadsheet();
$sheet = $spreadsheet->getActiveSheet();

// Set headers based on entry type
if ($type === 'Credit') {
    $headers = [
        'Entity',
        'Mode of Payment',
        'Date (DD/MM/YYYY)', // Changed format
        'Ref. No.',
        'Amount',
        'Broker ID',
        'Type of Transaction',
        'Invoice Mapping',
        'GST Mapping Remark',
        'Transaction Name'
    ];
} else {
    $headers = [
        'Entity',
        'Mode of Payment',
        'Date (DD/MM/YYYY)', // Changed format
        'Ref. No.',
        'Amount',
        'Partner ID',
        'Type of Transaction',
        'Invoice Mapping',
        'GST Mapping Remark'
    ];
}
$sheet->fromArray($headers, null, 'A1');

// Add data validation for Date
$dateValidation = $sheet->getDataValidation('C2:C1000');
$dateValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_DATE);
$dateValidation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
$dateValidation->setAllowBlank(false);
$dateValidation->setShowInputMessage(true);
$dateValidation->setPromptTitle('Date Format');
$dateValidation->setPrompt('Please use YYYY-MM-DD format');

// Mode of Payment validation
$modeOptions = ['Bank', 'Cash', 'Other'];
$modeValidation = $sheet->getCell('B2')->getDataValidation();
$modeValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
$modeValidation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
$modeValidation->setAllowBlank(false);
$modeValidation->setShowInputMessage(true);
$modeValidation->setPromptTitle('Mode of Payment');
$modeValidation->setPrompt('Select payment method: Bank, Cash, or Other');
$modeValidation->setFormula1('"' . implode(',', $modeOptions) . '"');

// Type of Transaction validation
$transactionOptions = ['Net', 'Gross', 'GST', 'Advance'];
$transactionValidation = $sheet->getCell('G2')->getDataValidation();
$transactionValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
$transactionValidation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
$transactionValidation->setAllowBlank(false);
$transactionValidation->setShowInputMessage(true);
$transactionValidation->setPromptTitle('Type of Transaction');
$transactionValidation->setPrompt('Select transaction type: Net, Gross, GST, or Advance');
$transactionValidation->setFormula1('"' . implode(',', $transactionOptions) . '"');

// Invoice Mapping validation
$yesNoOptions = ['Yes', 'No'];
$invoiceValidation = $sheet->getCell('H2')->getDataValidation();
$invoiceValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
$invoiceValidation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
$invoiceValidation->setAllowBlank(false);
$invoiceValidation->setShowInputMessage(true);
$invoiceValidation->setPromptTitle('Invoice Mapping');
$invoiceValidation->setPrompt('Select if invoice mapping is required: Yes or No');
$invoiceValidation->setFormula1('"' . implode(',', $yesNoOptions) . '"');

// GST Mapping Remark validation
$gstValidation = $sheet->getCell('I2')->getDataValidation();
$gstValidation->setType(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST);
$gstValidation->setErrorStyle(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::STYLE_STOP);
$gstValidation->setAllowBlank(false);
$gstValidation->setShowInputMessage(true);
$gstValidation->setPromptTitle('GST Mapping Remark');
$gstValidation->setPrompt('Select if GST mapping remark is required: Yes or No');
$gstValidation->setFormula1('"' . implode(',', $yesNoOptions) . '"');

// Add sample data row
$sampleData = [
    'Entity' => 'Example Entity',
    'Mode of Payment' => 'Bank',
    'Date (YYYY-MM-DD)' => date('d/m/y'),
    'Ref. No.' => 'REF-12345',
    'Amount' => '1000.00',
    'Type of Transaction' => 'Net',
    'Invoice Mapping' => 'Yes',
    'GST Mapping Remark' => 'Yes'
];
if ($type === 'Credit') {
    $sampleData = [
        'Entity' => 'Example Entity',
        'Mode of Payment' => 'Bank',
        'Date (DD/MM/YYYY)' => date('d/m/Y'), // Changed format
        'Ref. No.' => 'REF-12345',
        'Amount' => '1000.00',
        'Broker ID' => '123',
        'Type of Transaction' => 'Net',
        'Invoice Mapping' => 'Yes',
        'GST Mapping Remark' => 'Yes',
        'Transaction Name' => 'Name'
    ];
} else {
    $sampleData = array_merge(
        array_slice($sampleData, 0, 5),
        ['Partner ID' => 'Erevbay1'],
        array_slice($sampleData, 5)
    );
    $sampleData['Date (DD/MM/YYYY)'] = date('d/m/Y'); // Changed format
}

// Add sample data to row 2
$sheet->fromArray(array_values($sampleData), null, 'A2');

// Set filename and headers
$filename = "{$type}_Bank_Entry_Template.xlsx";
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header("Content-Disposition: attachment;filename=\"$filename\"");
header('Cache-Control: max-age=0');

// Write file
$writer = new Xlsx($spreadsheet);
$writer->save('php://output');
exit;