<?php

namespace App;

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\Shared\Converter;

class WordExporter {
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

    public function export($headers, $data, $filePath) {
        $phpWord = new PhpWord();
        
        $isRaw = (count($headers) === 1 && $headers[0] === 'Contenido');
        $orientation = 'portrait';
        if ($isRaw) {
            $maxLineLength = 0;
            foreach ($data as $row) {
                $line = (string)($row[0] ?? '');
                $len = strlen(rtrim($line));
                if ($len > $maxLineLength) $maxLineLength = $len;
            }
            if ($maxLineLength > 150) $orientation = 'landscape';
        } else {
            if (count($headers) > 8) $orientation = 'landscape';
        }
        
        $section = $phpWord->addSection([
            'orientation' => $orientation,
            'marginTop'    => Converter::cmToTwip(1.0),
            'marginBottom' => Converter::cmToTwip(1.0),
            'marginLeft'   => Converter::cmToTwip(1.0),
            'marginRight'  => Converter::cmToTwip(1.0),
        ]);

        if (!$isRaw) {
            $phpWord->addTitleStyle(1, ['name' => 'Calibri', 'size' => 18, 'color' => '13161b', 'bold' => true], ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
            $section->addTitle('INFORME CORPORATIVO AS400', 1);
            $section->addText('Generado oficialmente el: ' . date('d/m/Y H:i'), ['name' => 'Calibri', 'size' => 10, 'italic' => true], ['alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
            $section->addTextBreak(1);

            $styleTable = ['borderSize' => 6, 'borderColor' => '999999', 'cellMargin' => 80, 'alignment' => \PhpOffice\PhpWord\SimpleType\JcTable::CENTER];
            $phpWord->addTableStyle('ReportTable', $styleTable);
            $table = $section->addTable('ReportTable');

            $hasCustomHeaders = false;
            foreach ($headers as $header) {
                if ($header !== '' && !preg_match('/^(CAMPO|CABECERA|COLUMNA|COL|COLUMN)_\d+$/i', $header)) {
                    $hasCustomHeaders = true;
                    break;
                }
            }

            if ($hasCustomHeaders) {
                $table->addRow();
                foreach ($headers as $header) {
                    $displayHeader = preg_match('/^(CAMPO|CABECERA|COLUMNA|COL|COLUMN)_\d+$/i', $header) ? '' : $header;
                    $table->addCell()->addText($displayHeader, ['bold' => true, 'name' => 'Arial', 'size' => 9, 'color' => 'FFFFFF'], ['bgColor' => '13161b', 'alignment' => \PhpOffice\PhpWord\SimpleType\Jc::CENTER]);
                }
            }

            foreach ($data as $row) {
                $table->addRow();
                foreach ($row as $value) {
                    $table->addCell()->addText($value, ['name' => 'Courier New', 'size' => 8]);
                }
            }
        } else {
            // RAW AS400 REPORT
            $fontSize = 8;
            if ($maxLineLength > 90) $fontSize = 7.5;
            if ($maxLineLength > 110) $fontSize = 6.8;
            if ($maxLineLength > 130) $fontSize = 6.2;
            if ($maxLineLength > 140) $fontSize = 5.6;

            $fontStyle = ['name' => 'Courier New', 'size' => $fontSize];
            $boldFontStyle = ['name' => 'Courier New', 'size' => $fontSize, 'bold' => true];
            // En Word, spacing:0 garantiza que sea casi identico al interlineado de terminal
            $paraStyle = ['spaceAfter' => 0, 'spaceBefore' => 0, 'lineHeight' => 1.0];

            $processedLines = [];
            foreach ($data as $row) {
                $line = (string)($row[0] ?? '');
                if (strpos($line, '___PAGE_BREAK___') === 0) continue;
                
                $ctrl = substr($line, 0, 1);
                $actualLine = strlen($line) > 0 ? substr($line, 1) : '';
                
                if ($ctrl === '+') {
                    if (count($processedLines) > 0) {
                        $prevIdx = count($processedLines) - 1;
                        $baseText = $processedLines[$prevIdx]['text'];
                        $mergedSegments = $this->getMergedSegments($baseText, $actualLine);
                        $mergedText = '';
                        foreach ($mergedSegments as $seg) {
                            $mergedText .= $seg['text'];
                        }
                        $processedLines[$prevIdx]['text'] = $mergedText;
                        $processedLines[$prevIdx]['segments'] = $mergedSegments;
                    }
                } else {
                    $processedLines[] = [
                        'ctrl' => $ctrl,
                        'text' => $actualLine,
                        'segments' => [['text' => $actualLine, 'bold' => false]]
                    ];
                }
            }

            $isFirstPage = true;
            foreach ($processedLines as $pLine) {
                $ctrl = $pLine['ctrl'];
                $segments = $pLine['segments'];
                
                if ($ctrl === '1') {
                    if (!$isFirstPage) $section->addPageBreak();
                    $isFirstPage = false;
                    $textRun = $section->addTextRun($paraStyle);
                    foreach ($segments as $seg) {
                        $textRun->addText($seg['text'], $seg['bold'] ? $boldFontStyle : $fontStyle);
                    }
                } elseif ($ctrl === '0') {
                    $section->addTextBreak(1, $fontStyle, $paraStyle);
                    $textRun = $section->addTextRun($paraStyle);
                    foreach ($segments as $seg) {
                        $textRun->addText($seg['text'], $seg['bold'] ? $boldFontStyle : $fontStyle);
                    }
                } elseif ($ctrl === '-') {
                    $section->addTextBreak(2, $fontStyle, $paraStyle);
                    $textRun = $section->addTextRun($paraStyle);
                    foreach ($segments as $seg) {
                        $textRun->addText($seg['text'], $seg['bold'] ? $boldFontStyle : $fontStyle);
                    }
                } else {
                    $isFirstPage = false;
                    $textRun = $section->addTextRun($paraStyle);
                    foreach ($segments as $seg) {
                        $textRun->addText($seg['text'], $seg['bold'] ? $boldFontStyle : $fontStyle);
                    }
                }
            }
        }

        $objWriter = IOFactory::createWriter($phpWord, 'Word2007');
        $objWriter->save($filePath);
    }
}
