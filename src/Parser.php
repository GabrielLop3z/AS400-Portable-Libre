<?php

namespace App;

class Parser {
    private $rawContent;
    private $lines = [];
    private $data = [];
    private $headers = [];

    public function __construct($content) {
        // Ensure UTF-8
        $encoding = \mb_detect_encoding($content, 'UTF-8, ISO-8859-1, Windows-1252', true);
        if (!$encoding) $encoding = 'ISO-8859-1';
        $this->rawContent = \mb_convert_encoding($content, 'UTF-8', $encoding);
        $this->preprocess();
    }

    private function preprocess() {
        // Remove print control characters (like page breaks '1' at start of line in AS/400)
        // AS/400 Spools often have a control character in the first column.
        $lines = explode("\n", str_replace("\r", "", $this->rawContent));
        
        foreach ($lines as $line) {
            if (empty(trim($line))) continue;
            
            // Typical AS/400 control chars in first char: 
            // ' ' (space) = skip 1 line
            // '0' = skip 2 lines
            // '-' = skip 3 lines
            // '+' = no skip (overprint)
            // '1' = skip to next page
            $controlChar = substr($line, 0, 1);
            $content = substr($line, 1);
            
            $this->lines[] = $content;
        }
    }

    public function parse() {
        if (empty($this->lines)) return [];

        // Identify column positions
        $colPositions = $this->identifyColumns();
        
        $useRegexSplit = count($colPositions) < 2;

        $results = [];
        foreach ($this->lines as $line) {
            $row = [];
            if ($useRegexSplit) {
                $row = preg_split('/\s{2,}/', trim($line));
            } else {
                for ($i = 0; $i < count($colPositions); $i++) {
                    $start = $colPositions[$i];
                    $end = isset($colPositions[$i+1]) ? $colPositions[$i+1] : strlen($line);
                    $length = $end - $start;
                    
                    $val = substr($line, $start, $length);
                    $row[] = trim($val);
                }
            }
            
            // Avoid empty rows
            if (!empty(array_filter($row))) {
                $results[] = $row;
            }
        }

        // Avoid assuming the first row is a perfect header because it often contains AS400 system text like '5722SS1'
        if (!empty($results)) {
            $this->data = $results;
            $this->headers = [];
            $colCount = count($results[0]);
            for ($i = 0; $i < $colCount; $i++) {
                $this->headers[] = "CAMPO_" . ($i + 1);
            }
        }

        return [
            'headers' => $this->headers,
            'data' => $this->data
        ];
    }

    private function identifyColumns() {
        $sampleCount = min(100, count($this->lines));
        $samples = array_slice($this->lines, 0, $sampleCount);
        
        $maxLength = 0;
        foreach ($samples as $line) {
            $maxLength = max($maxLength, strlen($line));
        }

        $whitespaceCount = array_fill(0, $maxLength, 0);
        $totalValidLines = 0;

        foreach ($samples as $line) {
            $trimmed = trim($line);
            if (strlen($trimmed) < 10 || strpos($trimmed, '***') !== false) continue;
            
            $totalValidLines++;
            for ($i = 0; $i < $maxLength; $i++) {
                $char = isset($line[$i]) ? $line[$i] : ' ';
                if ($char === ' ' || $char === "\t") {
                    $whitespaceCount[$i]++;
                }
            }
        }

        $colPositions = [0];
        $inWhitespace = false;
        
        // Col separator if it is whitespace in at least 75% of data lines
        $threshold = max(1, $totalValidLines * 0.75);

        for ($i = 1; $i < $maxLength; $i++) {
            $isSpace = $whitespaceCount[$i] >= $threshold;
            if ($isSpace && !$inWhitespace) {
                $inWhitespace = true;
            } elseif (!$isSpace && $inWhitespace) {
                $colPositions[] = $i;
                $inWhitespace = false;
            }
        }

        return $colPositions;
    }
}
