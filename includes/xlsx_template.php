<?php
/**
 * Minimal dependency-free XLSX builder.
 *
 * Generates a valid .xlsx (OOXML) workbook using a store-only ZIP writer.
 * No external libraries (PhpSpreadsheet/ZipArchive) are required.
 */

if (!function_exists('xlsx_col_letter')) {
    /**
     * Convert a zero-based column index to a spreadsheet column letter.
     * 0 -> A, 25 -> Z, 26 -> AA, ...
     */
    function xlsx_col_letter($index) {
        $letter = '';
        $idx = (int) $index;
        while ($idx >= 0) {
            $letter = chr(($idx % 26) + 65) . $letter;
            $idx = intdiv($idx, 26) - 1;
        }
        return $letter;
    }
}

if (!function_exists('xlsx_xml_escape')) {
    function xlsx_xml_escape($value) {
        return str_replace(
            ['&', '<', '>', '"', "'"],
            ['&amp;', '&lt;', '&gt;', '&quot;', '&apos;'],
            (string) $value
        );
    }
}

if (!function_exists('xlsx_sheet_xml')) {
    /**
     * Build the XML for a single worksheet from a 2D array of strings.
     *
     * @param array $rows             Array of rows, each an array of cell strings.
     * @param int   $headerStyleIndex CellXfs index applied to the first row.
     * @param array $columnWidths     Optional map of column index => width.
     */
    function xlsx_sheet_xml(array $rows, $headerStyleIndex = 1, array $columnWidths = []) {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
              . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

        if (!empty($columnWidths)) {
            $xml .= '<cols>';
            foreach ($columnWidths as $colIdx => $width) {
                $xml .= '<col min="' . ($colIdx + 1) . '" max="' . ($colIdx + 1) . '" width="' . $width . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        $r = 1;
        foreach ($rows as $row) {
            $xml .= '<row r="' . $r . '">';
            $c = 0;
            foreach ($row as $val) {
                $ref = xlsx_col_letter($c) . $r;
                $style = ($r === 1) ? ' s="' . (int) $headerStyleIndex . '"' : '';
                $xml .= '<c r="' . $ref . '" t="inlineStr"' . $style . '>'
                      . '<is><t xml:space="preserve">' . xlsx_xml_escape($val) . '</t></is></c>';
                $c++;
            }
            $xml .= '</row>';
            $r++;
        }
        $xml .= '</sheetData>';
        $xml .= '</worksheet>';

        return $xml;
    }
}

if (!function_exists('xlsx_styles_xml')) {
    function xlsx_styles_xml() {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2">'
            .   '<font><sz val="11"/><name val="Calibri"/></font>'
            .   '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
            . '</fonts>'
            . '<fills count="3">'
            .   '<fill><patternFill patternType="none"/></fill>'
            .   '<fill><patternFill patternType="gray125"/></fill>'
            .   '<fill><patternFill patternType="solid"><fgColor rgb="FF4F46E5"/><bgColor indexed="64"/></patternFill></fill>'
            . '</fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            . '<cellXfs count="2">'
            .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .   '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center"/></xf>'
            . '</cellXfs>'
            . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            . '</styleSheet>';
    }
}

if (!function_exists('xlsx_build_package')) {
    /**
     * Assemble a complete .xlsx package (raw binary string).
     *
     * @param array $sheets Array of ['name' => string, 'xml' => string]
     */
    function xlsx_build_package(array $sheets) {
        $now = getdate();
        $dosTime = ($now['hours'] << 11) | ($now['minutes'] << 5) | (int)($now['seconds'] / 2);
        $dosDate = (($now['year'] - 1980) << 9) | ($now['mon'] << 5) | $now['mday'];

        $files = [];

        // Package relationships
        $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .   '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .   '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .   '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';

        // Content types
        $overrides = '';
        $overrides .= '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        $overrides .= '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        foreach ($sheets as $i => $sheet) {
            $overrides .= '<Override PartName="/xl/worksheets/sheet' . ($i + 1) . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $overrides .= '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>';
        $overrides .= '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        $files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .   '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .   '<Default Extension="xml" ContentType="application/xml"/>'
            .   $overrides
            . '</Types>';

        // Workbook
        $sheetTags = '';
        $wbRels = '';
        foreach ($sheets as $i => $sheet) {
            $rid = 'rId' . ($i + 1);
            $sheetTags .= '<sheet name="' . xlsx_xml_escape($sheet['name']) . '" sheetId="' . ($i + 1) . '" r:id="' . $rid . '"/>';
            $wbRels .= '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
        }
        $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .   '<sheets>' . $sheetTags . '</sheets>'
            . '</workbook>';

        $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .   $wbRels
            .   '<Relationship Id="rId' . (count($sheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>';

        $files['xl/styles.xml'] = xlsx_styles_xml();

        foreach ($sheets as $i => $sheet) {
            $files['xl/worksheets/sheet' . ($i + 1) . '.xml'] = $sheet['xml'];
        }

        // Doc properties
        $files['docProps/core.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            .   '<dc:title>Student Import Template</dc:title>'
            .   '<dc:creator>Liceo de Baleno FRAS</dc:creator>'
            .   '<cp:lastModifiedBy>Liceo de Baleno FRAS</cp:lastModifiedBy>'
            . '</cp:coreProperties>';

        $files['docProps/app.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
            .   '<Application>Liceo de Baleno FRAS</Application>'
            . '</Properties>';

        // Build store-only ZIP
        $zip = '';
        $central = '';
        $offset = 0;
        $count = count($files);

        foreach ($files as $name => $content) {
            $len = strlen($content);
            $crc = crc32($content);
            $nameLen = strlen($name);

            $local = "\x50\x4b\x03\x04"
                . pack('v', 20) . pack('v', 0) . pack('v', 0)
                . pack('v', $dosTime) . pack('v', $dosDate)
                . pack('V', $crc) . pack('V', $len) . pack('V', $len)
                . pack('v', $nameLen) . pack('v', 0)
                . $name . $content;

            $zip .= $local;

            $central .= "\x50\x4b\x01\x02"
                . pack('v', 20) . pack('v', 20) . pack('v', 0) . pack('v', 0)
                . pack('v', $dosTime) . pack('v', $dosDate)
                . pack('V', $crc) . pack('V', $len) . pack('V', $len)
                . pack('v', $nameLen) . pack('v', 0) . pack('v', 0) . pack('v', 0) . pack('v', 0)
                . pack('V', 0) . pack('V', $offset) . $name;

            $offset += strlen($local);
        }

        $cdSize = strlen($central);
        $end = "\x50\x4b\x05\x06"
            . pack('v', 0) . pack('v', 0)
            . pack('v', $count) . pack('v', $count)
            . pack('V', $cdSize) . pack('V', $offset)
            . pack('v', 0);

        return $zip . $central . $end;
    }
}
