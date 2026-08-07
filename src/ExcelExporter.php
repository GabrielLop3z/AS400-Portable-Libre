<?php

namespace App;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Worksheet\PageSetup;

class ExcelExporter {
    private function getMergedSegments($baseText, $overText) {
        $len = max(strlen($baseText), strlen($overText));
        $baseText = str_pad($baseText, $len, ' ');
        $overText = str_pad($overText, $len, ' ');
        $segments = [];
        $currentSegment = '';
        $currentIsBold = false;
        
        for ($i = 0; $i < $len; $i++) {
            $b = $baseText[$i];
            $o = $overText[$i];
            $isBold = ($o !== ' ');
            $char = ($o !== ' ') ? $o : $b;
            
            if ($i === 0) {
                $currentIsBold = $isBold;
                $currentSegment .= $char;
            } else {
                if ($isBold === $currentIsBold) {
                    $currentSegment .= $char;
                } else {
                    $segments[] = ['text' => $currentSegment, 'bold' => $currentIsBold];
                    $currentIsBold = $isBold;
                    $currentSegment = $char;
                }
            }
        }
        if ($currentSegment !== '') {
            $segments[] = ['text' => $currentSegment, 'bold' => $currentIsBold];
        }
        return $segments;
    }

    // Evalúa reglas de estilo {pattern, type} contra un texto
    private function evaluateStyle($text, $styleRules) {
        $flags = ['bold' => false, 'italic' => false, 'underline' => false];
        if (!is_array($styleRules)) return $flags;
        foreach ($styleRules as $rule) {
            if (!is_array($rule) || empty($rule['pattern'])) continue;
            $escaped = preg_quote((string)$rule['pattern'], '/');
            $type = in_array($rule['type'] ?? '', ['bold', 'italic', 'underline'], true) ? $rule['type'] : 'bold';
            if (preg_match('/' . $escaped . '/i', (string)$text)) {
                $flags[$type] = true;
            }
        }
        return $flags;
    }

    // Filas clave del reporte (resaltado inteligente)
    private function isHighlightRow($text) {
        return (bool)preg_match('/TOTAL|SUBTOTAL|CIFRAS:|TOTALES|GRAN TOTAL|REPORTE DE|ORDEN DE COMPRA/i', (string)$text);
    }

    private function applyRowStyle($sheet, $range, $text, $boldDefault, $styleRules, $smartHighlight) {
        $flags = $this->evaluateStyle($text, $styleRules);
        $highlight = $smartHighlight && $this->isHighlightRow($text);
        $isBold = $boldDefault || $flags['bold'] || $highlight;
        $style = $sheet->getStyle($range);
        if ($isBold) $style->getFont()->setBold(true);
        if ($flags['italic']) $style->getFont()->setItalic(true);
        if ($flags['underline']) $style->getFont()->setUnderline(\PhpOffice\PhpSpreadsheet\Style\Font::UNDERLINE_SINGLE);
        if ($highlight) {
            $style->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFEF3C7');
        }
    }

    public function export($headers, $data, $filePath, $styleRules = [], $smartHighlight = true, $boldRows = []) {        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Check if raw AS400 lines (only 1 column)
        $isRaw = (count($headers) === 1 && $headers[0] === 'Contenido');
        
        // --- PRE-PROCESAMIENTO DE LÍNEAS (Para detectar negritas AS400 y saltos) ---
        $processed = [];
        $maxLineLen = 0;
        if ($isRaw) {
            foreach ($data as $row) {
                $line = (string)($row[0] ?? '');
                if (strpos($line, '___PAGE_BREAK___') === 0) $line = substr($line, 16);
                
                $ctrl = substr($line, 0, 1);
                $actualLine = strlen($line) > 0 ? substr($line, 1) : '';
                if (strlen($actualLine) > $maxLineLen) $maxLineLen = strlen($actualLine);

                if ($ctrl === '+') {
                    if (count($processed) > 0) {
                        $prevIdx = count($processed) - 1;
                        $prevObj = $processed[$prevIdx];
                        $merged = $this->getMergedSegments($prevObj['text'], $actualLine);
                        $fullText = '';
                        foreach ($merged as $seg) $fullText .= $seg['text'];
                        $processed[$prevIdx]['text'] = $fullText;
                        $processed[$prevIdx]['bold'] = true; // Marcamos toda la fila como bold para excel si hay overprint
                    }
                } else {
                    $processed[] = [
                        'ctrl' => $ctrl,
                        'text' => $actualLine,
                        'bold' => false
                    ];
                }
            }
        }

        // ESTILO GLOBAL: Courier New para look AS400 uniforme
        $spreadsheet->getDefaultStyle()->getFont()->setName('Courier New');
        $spreadsheet->getDefaultStyle()->getFont()->setSize(9);

        // CONFIGURACIÓN DE PÁGINA: Horizontal (Landscape)
        $sheet->getPageSetup()->setOrientation(PageSetup::ORIENTATION_LANDSCAPE);
        $sheet->getPageSetup()->setPaperSize(PageSetup::PAPERSIZE_A4);
        
        $totalCols = count($headers);
        $lastColLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($totalCols);

        // CABECERA PREMIUM
        $sheet->setCellValue('A1', 'SISTEMA DE GESTIÓN AS400 - REPORTE OFICIAL');
        $sheet->mergeCells("A1:{$lastColLetter}1");
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14)->getColor()->setARGB('FF1F2937');
        $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

        $sheet->setCellValue('A2', 'FECHA DE GENERACIÓN: ' . date('d/m/Y H:i') . ' | USER: EXPORT_ENGINE');
        $sheet->mergeCells("A2:{$lastColLetter}2");
        $sheet->getStyle('A2')->getFont()->setItalic(true)->setSize(9)->getColor()->setARGB('FF6B7280');
        $sheet->getStyle('A2')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
        
        // Bordes decorativos para el encabezado
        $sheet->getStyle("A1:{$lastColLetter}2")->getBorders()->getBottom()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN)->getColor()->setARGB('FFD1D5DB');

        $rowNum = 4;
        
        if ($isRaw) {
            // MODO RAW: Imprimir línea por línea conservando verticalidad
            foreach ($processed as $pLine) {
                $ctrl = $pLine['ctrl'];
                $text = $pLine['text'];
                $bold = $pLine['bold'];

                if ($ctrl === '1') {
                    // Marcador de página en Excel: Borde grueso arriba
                    $sheet->getStyle("A$rowNum:{$lastColLetter}$rowNum")->getBorders()->getTop()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_MEDIUM);
                } elseif ($ctrl === '0') {
                    $rowNum++; // Espacio simple adicional
                } elseif ($ctrl === '-') {
                    $rowNum += 2; // Espacio doble adicional
                }

                $sheet->setCellValue('A' . $rowNum, $text);
                $this->applyRowStyle($sheet, 'A' . $rowNum, $text, $bold || preg_match('/CIFRAS:|TOTAL|REPORTE DE|ORDEN DE COMPRA/i', $text), $styleRules, $smartHighlight);
                $rowNum++;
            }
            $sheet->getColumnDimension('A')->setAutoSize(true);
        } else {
            // MODO GRID: Cabeceras y Filas Estructuradas
            $colString = 'A';
            $hasCustomHeaders = false;
            foreach ($headers as $header) {
                if ($header !== '' && !preg_match('/^(CAMPO|CABECERA|COLUMNA|COL|COLUMN)_\d+$/i', $header)) {
                    $hasCustomHeaders = true;
                    break;
                }
            }

            if ($hasCustomHeaders) {
                foreach ($headers as $header) {
                    // Solo mostramos el texto si no es genérico
                    $displayHeader = preg_match('/^(CAMPO|CABECERA|COLUMNA|COL|COLUMN)_\d+$/i', $header) ? '' : $header;
                    
                    $sheet->setCellValue($colString . $rowNum, $displayHeader);
                    $sheet->getStyle($colString . $rowNum)->getFont()->setBold(true)->getColor()->setARGB('FFFFFFFF');
                    $sheet->getStyle($colString . $rowNum)->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setARGB('FF1F2937'); // Dark gray / Black
                    $sheet->getStyle($colString . $rowNum)->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);
                    $colString++;
                }
                $rowNum++;
            }

            $dataRowIdx = 0;
            foreach ($data as $row) {
                $dataRowIdx++;
                $colString = 'A';
                $isBoldRow = in_array($dataRowIdx, $boldRows);

                foreach ($row as $value) {
                    $strVal = (string)$value;
                    if (strpos($strVal, '___PAGE_BREAK___') === 0) {
                        $strVal = substr($strVal, 16);
                        $sheet->getStyle("A$rowNum:{$lastColLetter}$rowNum")->getBorders()->getTop()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);
                    }
                    
                    // Prevenir inyección de fórmulas pero mantener legibilidad
                    if (isset($strVal[0]) && in_array($strVal[0], ['=', '-', '+', '@'])) {
                        $sheet->setCellValueExplicit($colString . $rowNum, $strVal, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
                    } else {
                        $sheet->setCellValue($colString . $rowNum, $strVal);
                    }

                    $this->applyRowStyle($sheet, $colString . $rowNum, $strVal, $isBoldRow || preg_match('/CIFRAS:|TOTAL|REPORTE DE|ORDEN DE COMPRA/i', $strVal), $styleRules, $smartHighlight);
                    $colString++;
                }
                $rowNum++;
            }
            
            // Auto-ajustar columnas
            for ($i = 1; $i <= $totalCols; $i++) {
                $colLetter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($i);
                $sheet->getColumnDimension($colLetter)->setAutoSize(true);
            }
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($filePath);
    }

}
