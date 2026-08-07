<?php
$file = $_GET['file'] ?? null;
$name = $_GET['name'] ?? 'report.bin';

if (!$file) {
    die('No file specified.');
}

$filePath = __DIR__ . '/exports/' . basename($file);

if (file_exists($filePath)) {
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    header('Content-Length: ' . filesize($filePath));
    readfile($filePath);
    
    // Cleanup: delete file after download
    // unlink($filePath); 
    exit;
} else {
    die('File not found.');
}
