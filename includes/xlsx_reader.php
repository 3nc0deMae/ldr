<?php
/**
 * Minimal dependency-free XLSX reader.
 *
 * Parses .xlsx files using PHP ZipArchive (preferred) or the system `unzip` command,
 * combined with PHP SimpleXML. No external libraries (PhpSpreadsheet) are required.
 */

if (!function_exists('xlsx_read_file')) {
    /**
     * Read an XLSX file and return its data as a 2D array.
     *
     * @param string $filePath Path to the .xlsx file
     * @return array Array of rows, each row is an array of cell values
     * @throws RuntimeException If the file cannot be read or parsed
     */
    function xlsx_read_file($filePath) {
        if (!file_exists($filePath)) {
            throw new RuntimeException('XLSX file not found: ' . $filePath);
        }

        $tempDir = sys_get_temp_dir() . '/xlsx_import_' . uniqid();
        if (!@mkdir($tempDir, 0700, true) && !is_dir($tempDir)) {
            throw new RuntimeException('Unable to create temporary directory for XLSX extraction: ' . $tempDir);
        }

        try {
            if (class_exists('ZipArchive')) {
                xlsx_extract_with_ziparchive($filePath, $tempDir);
            } elseif (function_exists('shell_exec')) {
                xlsx_extract_with_shell($filePath, $tempDir);
            } else {
                throw new RuntimeException(
                    'Cannot process .xlsx file. The PHP Zip extension (php_zip) is not enabled ' .
                    'and shell_exec() is unavailable. Please enable "extension=zip" in php.ini and restart Apache.'
                );
            }

            $sharedStrings = [];
            $sharedStringsFile = $tempDir . '/xl/sharedStrings.xml';
            if (file_exists($sharedStringsFile)) {
                $sharedStrings = xlsx_parse_shared_strings($sharedStringsFile);
            }

            $sheetFile = null;
            $xlDir = $tempDir . '/xl';
            if (is_dir($xlDir)) {
                $worksheetsDir = $xlDir . '/worksheets';
                if (is_dir($worksheetsDir)) {
                    $sheetFiles = glob($worksheetsDir . '/sheet*.xml');
                    if (!empty($sheetFiles)) {
                        sort($sheetFiles);
                        $sheetFile = $sheetFiles[0];
                    }
                }
            }

            if ($sheetFile === null) {
                throw new RuntimeException('No worksheet found in XLSX file.');
            }

            $rows = xlsx_parse_sheet($sheetFile, $sharedStrings);

            return $rows;
        } finally {
            xlsx_remove_dir($tempDir);
        }
    }
}

if (!function_exists('xlsx_extract_with_ziparchive')) {
    function xlsx_extract_with_ziparchive($filePath, $tempDir) {
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== TRUE) {
            throw new RuntimeException('Unable to open XLSX file as ZIP archive.');
        }
        if (!$zip->extractTo($tempDir)) {
            $zip->close();
            throw new RuntimeException('Failed to extract XLSX file to temporary directory.');
        }
        $zip->close();
    }
}

if (!function_exists('xlsx_extract_with_shell')) {
    function xlsx_extract_with_shell($filePath, $tempDir) {
        $result = shell_exec('unzip -o -q ' . escapeshellarg($filePath) . ' -d ' . escapeshellarg($tempDir) . ' 2>&1');
        if ($result === null) {
            throw new RuntimeException(
                'Cannot process .xlsx file. The PHP Zip extension is not enabled and shell_exec() is disabled. ' .
                'Please enable "extension=zip" in php.ini and restart Apache.'
            );
        }
        if ($result !== '') {
            throw new RuntimeException('Failed to extract XLSX file via unzip: ' . $result);
        }
    }
}

if (!function_exists('xlsx_parse_shared_strings')) {
    /**
     * Parse sharedStrings.xml and return an array of string values indexed by their position.
     */
    function xlsx_parse_shared_strings($filePath) {
        $strings = [];
        $xml = @simplexml_load_file($filePath);
        if ($xml === false) {
            return $strings;
        }

        $ns = $xml->getNamespaces(true);
        $sst = $xml->children($ns[''] ?? '');

        foreach ($sst->si as $si) {
            $text = '';
            $tNodes = $si->xpath('.//*[local-name()="t"]');
            if ($tNodes) {
                foreach ($tNodes as $t) {
                    $text .= (string) $t;
                }
            }
            $strings[] = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        }

        return $strings;
    }
}

if (!function_exists('xlsx_parse_sheet')) {
    /**
     * Parse a worksheet XML file and return a 2D array of cell values.
     *
     * @param string $sheetFilePath Path to the sheet XML file
     * @param array  $sharedStrings Array of shared string values
     * @return array 2D array of cell values
     */
    function xlsx_parse_sheet($sheetFilePath, array $sharedStrings) {
        $rows = [];
        $xml = @simplexml_load_file($sheetFilePath);
        if ($xml === false) {
            return $rows;
        }

        $ns = $xml->getNamespaces(true);
        $mainNs = $ns[''] ?? '';

        $sheetData = $xml->children($mainNs)->sheetData;
        if ($sheetData === null) {
            return $rows;
        }

        foreach ($sheetData->row as $row) {
            $rowAttrs = $row->attributes();
            $rowIndex = (int) ($rowAttrs['r'] ?? 0);
            $rowData = [];

            foreach ($row->c as $cell) {
                $cellAttrs = $cell->attributes();
                $cellRef = (string) ($cellAttrs['r'] ?? '');
                $columnIndex = xlsx_column_index($cellRef);
                $dataType = (string) ($cellAttrs['t'] ?? '');

                $value = '';
                $is = $cell->children($mainNs)->is;
                if ($is !== null) {
                    $textNodes = $is->xpath('.//*[local-name()="t"]');
                    if ($textNodes) {
                        $value = implode('', array_map('strval', $textNodes));
                    }
                }

                if ($dataType === 's') {
                    $siIndex = isset($cell->v) ? (int) $cell->v : 0;
                    $value = isset($sharedStrings[$siIndex]) ? $sharedStrings[$siIndex] : '';
                } elseif ($dataType === 'b') {
                    $value = (isset($cell->v) && (int) $cell->v) ? 'TRUE' : 'FALSE';
                } elseif ($dataType === '' || $dataType === 'n') {
                    if (isset($cell->v)) {
                        $value = (string) $cell->v;
                    }
                }

                $rowData[$columnIndex] = trim($value);
            }

            ksort($rowData);
            $maxIndex = $rowData ? max(array_keys($rowData)) : -1;
            for ($i = 0; $i <= $maxIndex; $i++) {
                if (!array_key_exists($i, $rowData)) {
                    $rowData[$i] = '';
                }
            }
            ksort($rowData);
            $rows[] = array_values($rowData);
        }

        return $rows;
    }
}

if (!function_exists('xlsx_column_index')) {
    /**
     * Convert a spreadsheet column reference (e.g. "A", "AA", "AB1") to a zero-based index.
     *
     * @param string $ref Cell reference like "A1", "BC23"
     * @return int Zero-based column index
     */
    function xlsx_column_index($ref) {
        $letters = preg_replace('/[0-9]+$/', '', $ref);
        $index = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
        }
        return $index - 1;
    }
}

if (!function_exists('xlsx_remove_dir')) {
    /**
     * Recursively remove a directory and its contents.
     */
    function xlsx_remove_dir($dir) {
        if (!is_dir($dir)) {
            return;
        }
        $items = glob($dir . '/*');
        foreach ($items as $item) {
            if (is_dir($item)) {
                xlsx_remove_dir($item);
            } else {
                @unlink($item);
            }
        }
        @rmdir($dir);
    }
}