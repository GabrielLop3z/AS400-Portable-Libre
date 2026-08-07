<?php

namespace App;

use Dompdf\Dompdf;
use Dompdf\Options;

class PdfExporter {
    private static $templatesCache = null;

    private static function loadPdfTemplates() {
        if (self::$templatesCache !== null) return self::$templatesCache;
        $file = __DIR__ . '/../config/pdf_templates.json';
        $templates = file_exists($file) ? (json_decode(file_get_contents($file), true) ?: []) : [];
        self::$templatesCache = is_array($templates) ? $templates : [];
        return self::$templatesCache;
    }

    private static function templateOption($template, $key, $fallback) {
        $cfg = self::loadPdfTemplates();
        $t = $cfg[$template] ?? [];
        return (isset($t[$key]) && $t[$key] !== '') ? $t[$key] : $fallback;
    }

    public function export($headers, $data, $filePath, $forceOrientation = null, $template = 'default', $stampText = 'OFICIAL', $stampStyle = 'classic') {

        $options = new Options();
        $options->set('isRemoteEnabled', false); 
        $options->set('defaultFont', 'Courier');
        $options->set('isHtml5ParserEnabled', true);
        $dompdf = new Dompdf($options);

        // Detectar ancho real efectivo
        $maxLineLength = 0;
        foreach ($data as $row) {
            $line = (string)($row[0] ?? '');
            if (strpos($line, '___PAGE_BREAK___') === 0) $line = substr($line, 16);
            $len = strlen(rtrim($line));
            if ($len > $maxLineLength) $maxLineLength = $len;
        }

        // Incrementar el umbral a 150 para que archivos como AP415D se mantengan en vertical (Portrait)
        $orientation = ($forceOrientation) ? $forceOrientation : (($maxLineLength > 150) ? 'landscape' : 'portrait');
        $paper = ($maxLineLength > 165) ? 'legal' : 'a4';

        $html = $this->generateHTML($headers, $data, $maxLineLength, $orientation, $template, $stampText, $stampStyle);

        if ($template === 'html_only') return $html;


        $dompdf->setPaper($paper, $orientation);
        $dompdf->loadHtml($html);
        $dompdf->render();
        
        file_put_contents($filePath, $dompdf->output());
    }

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

    public function getHtmlForPrint($headers, $data, $template = 'default', $stampText = 'OFICIAL', $stampStyle = 'classic') {
        $maxLineLength = 0;
        foreach ($data as $row) {
            $line = (string)($row[0] ?? '');
            if (strpos($line, '___PAGE_BREAK___') === 0) $line = substr($line, 16);
            $len = strlen(rtrim($line));
            if ($len > $maxLineLength) $maxLineLength = $len;
        }
        $orientation = ($maxLineLength > 150) ? 'landscape' : 'portrait';
        return $this->generateHTML($headers, $data, $maxLineLength, $orientation, $template, $stampText, $stampStyle);
    }

    private function generateHTML($headers, $data, $maxLineLength, $orientation, $template, $stampText, $stampStyle = 'classic') {



        $fontSize = '10.5pt';
        if ($maxLineLength > 80) $fontSize = '9.5pt';
        if ($maxLineLength > 100) $fontSize = '8.5pt';
        if ($maxLineLength > 120) $fontSize = '7.4pt'; 
        if ($maxLineLength > 140) $fontSize = '6.5pt'; 
        if ($maxLineLength > 160) $fontSize = '5.8pt'; 

        // Define template styles (configuracion centralizada en /config/pdf_templates.json)
        $bgColor = self::templateOption($template, 'bgColor', '#fff');
        $textColor = self::templateOption($template, 'textColor', '#000');
        $borderColor = self::templateOption($template, 'borderColor', '#ccc');
        $fontFamily = self::templateOption($template, 'fontFamily', '"Courier", monospace');
        $headerColor = self::templateOption($template, 'headerColor', '#888');

        $html = '<html><head><style>
                @page { 
                    size: auto; 
                    margin: 0.15cm !important; 
                    margin-bottom: 0.1cm !important; 
                }
                body { 
                    font-family: ' . $fontFamily . '; 
                    font-size: ' . $fontSize . '; 
                    color: ' . $textColor . '; 
                    background: ' . $bgColor . '; 
                    margin: 0; padding: 0; 
                    width: 100%; 
                    height: auto;
                    -webkit-print-color-adjust: exact;
                    print-color-adjust: exact;
                    letter-spacing: -0.1pt;
                }
                .page-container { 
                    width: 100%; 
                    page-break-after: always; 
                    position: relative;
                    margin: 0;
                    padding: 0;
                }
                .page-container:last-child { page-break-after: auto; }
                .raw-container { 
                    white-space: pre; 
                    font-family: ' . $fontFamily . '; 
                    line-height: 1.0; 
                    padding-right: 0px; 
                    overflow: hidden;
                }
                .raw-line { 
                    display: block; 
                    min-height: 1.0em; 
                    white-space: pre;
                    margin: 0; padding: 0;
                }
                .fw-bold {
                    font-weight: 900;
                    color: ' . $textColor . ';
                }
                .sello-wrapper {
                    position: fixed;
                    top: 0.8cm;
                    right: 0.8cm;
                    z-index: 9999;
                    opacity: 0.8;
                }
                .sello-circle {
                    width: 130px; height: 130px;
                    border: 6px double ' . $borderColor . ';
                    border-radius: 50%;
                    display: flex; flex-direction: column; align-items: center; justify-content: center;
                    transform: rotate(-15deg);
                    text-align: center; color: ' . $borderColor . ';
                }
                .sello-circle .titulo { font-family: Arial, sans-serif; font-weight: 900; font-size: 16pt; text-transform: uppercase; }
                .sello-circle .detalle { font-family: Arial, sans-serif; font-size: 8pt; font-weight: bold; margin-top: 5px; }

                .sello-square {
                    width: 160px;
                    border: 5px solid ' . $borderColor . ';
                    padding: 15px; border-radius: 4px;
                    transform: rotate(-10deg);
                    text-align: center; color: ' . $borderColor . ';
                }
                .sello-square .titulo { font-family: "Impact", sans-serif; font-weight: bold; font-size: 20pt; text-transform: uppercase; }
                
                .sello-ribbon {
                    position: fixed;
                    top: 2.5cm; right: -2cm;
                    width: 350px;
                    background: ' . $borderColor . ';
                    color: white !important;
                    text-align: center;
                    padding: 8px 0;
                    transform: rotate(45deg);
                    font-family: sans-serif; font-weight: 800; font-size: 16pt;
                    box-shadow: 0 5px 15px rgba(0,0,0,0.4);
                    text-transform: uppercase;
                }
                .sello-recibido {
                    position: absolute;
                    top: 15px;
                    right: 35%;
                    border: 2px solid '.$borderColor.';
                    padding: 4px 10px;
                    color: '.$borderColor.';
                    transform: rotate(-3deg);
                    text-align: center;
                    opacity: 0.6;
                    z-index: 100;
                    margin-top: -10px;
                }
                .sello-recibido .titulo { font-weight: 900; font-size: 10pt; font-family: sans-serif; text-transform: uppercase; }
                .sello-recibido .fecha { font-size: 7pt; font-family: sans-serif; border-top: 1px solid '.$borderColor.'; margin-top: 2px; }
                .sello-recibido .ref { font-size: 5pt; font-family: sans-serif; font-weight: bold; }
            </style></head><body>
            <script type="text/php">
                if ( isset($pdf) ) {
                    $font = $fontMetrics->get_font("helvetica", "bold");
                    $pdf->page_text(520, 830, "Página: {PAGE_NUM} de {PAGE_COUNT}", $font, 6, array(0,0,0));
                }
            </script>';

        if ($template !== 'default' && $stampStyle !== 'none') {
            if ($stampStyle === 'ribbon') {
                $html .= '<div class="sello-ribbon">' . htmlspecialchars($stampText) . '</div>';
            } else {
                $class = ($stampStyle === 'square') ? 'sello-square' : 'sello-circle';
                $html .= '<div class="sello-wrapper">
                            <div class="' . $class . '">
                                <div class="titulo">' . htmlspecialchars($stampText) . '</div>
                                ' . ($stampStyle === 'classic' || $stampStyle === 'circle' ? '<div class="detalle">' . date('d/m/Y') . '</div>' : '') . '
                            </div>
                          </div>';
            }
        }


        $rawLines = [];
        $hasOverprint = false;
        foreach ($data as $row) {
            $line = (string)($row[0] ?? '');
            if (strpos($line, '___PAGE_BREAK___') === 0) continue;
            if (!$hasOverprint && substr($line, 0, 1) === '+') $hasOverprint = true;
            $rawLines[] = $line;
        }

        // Pre-process lines to merge overprint (+)
        $processedLines = [];
        if (!$hasOverprint) {
            foreach ($rawLines as $line) {
                $processedLines[] = [
                    'ctrl' => substr($line, 0, 1),
                    'text' => substr($line, 1),
                    'html' => htmlspecialchars(substr($line, 1))
                ];
            }
        } else {
            foreach ($rawLines as $line) {
                $ctrl = substr($line, 0, 1);
                $actualLine = strlen($line) > 0 ? substr($line, 1) : '';
                
                if ($ctrl === '+') {
                    if (count($processedLines) > 0) {
                        $prevIdx = count($processedLines) - 1;
                        $baseText = $processedLines[$prevIdx]['text'];
                        $mergedSegments = $this->getMergedSegments($baseText, $actualLine);
                        $htmlLine = '';
                        $mergedText = '';
                        foreach ($mergedSegments as $seg) {
                            $mergedText .= $seg['text'];
                            if ($seg['bold']) {
                                $htmlLine .= '<span class="fw-bold">' . htmlspecialchars($seg['text']) . '</span>';
                            } else {
                                $htmlLine .= htmlspecialchars($seg['text']);
                            }
                        }
                        $processedLines[$prevIdx]['text'] = $mergedText;
                        $processedLines[$prevIdx]['html'] = $htmlLine;
                    }
                } else {
                    $processedLines[] = [
                        'ctrl' => $ctrl,
                        'text' => $actualLine,
                        'html' => htmlspecialchars($actualLine)
                    ];
                }
            }
        }

        $pagesHtml = '';
        $currentPageContent = '';
        $isFirstLine = true;

        foreach ($processedLines as $pLine) {
            $ctrl = $pLine['ctrl'];
            $htmlContent = $pLine['html'];

            if ($isFirstLine) {
                $pagesHtml .= '<div class="page-container" style="' . ($template !== 'default' ? 'border: 1px solid '.$borderColor.'; padding: 8px; border-radius: 4px;' : '') . '">';
                if ($template !== 'default') {
                    $pagesHtml .= '<div style="text-align: right; font-size: 0.8em; color: ' . $headerColor . '; font-weight: bold; margin-bottom: 8px; border-bottom: 2px solid ' . $borderColor . '; padding-bottom: 4px; font-family: sans-serif; padding-right: 15px;">Reporte AS400 - ' . strtoupper(uniqid()) . '</div>';
                }
                $pagesHtml .= '<div class="raw-container">';
                $isFirstLine = false;
            }

            if ($ctrl === '1') {
                if ($currentPageContent !== '') {
                    $pagesHtml .= $currentPageContent . '</div></div><div class="page-container" style="' . ($template !== 'default' ? 'border: 1px solid '.$borderColor.'; padding: 8px; border-radius: 4px;' : '') . '">';
                    if ($template !== 'default') {
                        $pagesHtml .= '<div style="text-align: right; font-size: 0.8em; color: ' . $headerColor . '; font-weight: bold; margin-bottom: 8px; border-bottom: 2px solid ' . $borderColor . '; padding-bottom: 4px; font-family: sans-serif; padding-right: 15px;">Reporte Continúa</div>';
                    }
                    $pagesHtml .= '<div class="raw-container">';
                }
                $currentPageContent = '<div class="raw-line">' . $htmlContent . '</div>';
            } elseif ($ctrl === '0') {
                $currentPageContent .= '<div class="raw-line">&nbsp;</div><div class="raw-line">' . $htmlContent . '</div>';
            } elseif ($ctrl === '-') {
                $currentPageContent .= '<div class="raw-line">&nbsp;</div><div class="raw-line">&nbsp;</div><div class="raw-line">' . $htmlContent . '</div>';
            } else {
                $currentPageContent .= '<div class="raw-line">' . $htmlContent . '</div>';
            }
        }

        if (!$isFirstLine) {
            $pagesHtml .= $currentPageContent . '</div></div>';
        }

        $html .= $pagesHtml . '</body></html>';
        return $html;
    }
}
