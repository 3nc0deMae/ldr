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

if (!defined('XLSX_ST_DEFAULT')) {
    define('XLSX_ST_DEFAULT', 0);      // default
    define('XLSX_ST_TITLE', 1);        // 13pt title
    define('XLSX_ST_SUBTITLE', 2);     // 5pt italic subtitle
    define('XLSX_ST_META_LABEL', 3);   // 6pt right-aligned label
    define('XLSX_ST_META_VALUE', 4);   // 8pt bordered value
    define('XLSX_ST_HDR', 5);          // 6pt header (No./NAME)
    define('XLSX_ST_DAY_HDR', 6);      // 6pt day header
    define('XLSX_ST_NO', 7);           // 7pt number
    define('XLSX_ST_NAME', 8);         // 7pt student name
    define('XLSX_ST_DAY', 9);          // 9pt day cell
    define('XLSX_ST_COUNT', 10);       // 7pt count
    define('XLSX_ST_REMARKS', 11);     // 5pt remarks
    define('XLSX_ST_SUBTOTAL', 12);    // double-top/medium-bottom total row
    define('XLSX_ST_COMBINED', 13);    // medium-bottom combined row
    define('XLSX_ST_BTITLE', 14);      // 6pt bold panel title
    define('XLSX_ST_TEXT', 15);        // 6pt panel text
    define('XLSX_ST_SUM_LABEL', 16);   // 6pt summary label
    define('XLSX_ST_SUM_VALUE', 17);   // 7pt summary value
    define('XLSX_ST_SUM_HEAD', 18);    // 5pt bold summary head
    define('XLSX_ST_CERTIFY', 19);     // 8pt italic certify
    define('XLSX_ST_SIG_NAME', 20);    // 6pt underlined signature name
    define('XLSX_ST_SIG_ROLE', 21);    // 6pt signature role
    define('XLSX_ST_GENERATED', 22);   // 7pt top-bordered "Generated thru LIS"
    define('XLSX_ST_HDR_TOTAL', 23);   // 8pt "Total for the Month"
    define('XLSX_ST_HDR_TINY', 24);    // 5pt ABSENT/PRESENT/REMARKS header
    define('XLSX_ST_TEXT_SMALL', 25);  // 5pt codes text
    define('XLSX_ST_TEXT_UNDER', 26);  // 6pt text with bottom rule (fraction bar)
}

if (!function_exists('xlsx_sf2_styles_xml')) {
    /**
     * Stylesheet for the SF2 report — mirrors the SF2_Template.xls look:
     * Arial small fonts (13/8/9/7/6/5pt), thin black borders, all-white cells.
     * Header text is regular weight (the template is not bold), day cells 9pt,
     * "I certify" 8pt italic, total rows use a double top + medium bottom rule.
     */
    function xlsx_sf2_styles_xml() {
        $fonts = array(
            '<font><sz val="7"/><name val="Arial"/></font>',           // 0 body 7pt
            '<font><sz val="13"/><name val="Arial"/></font>',          // 1 title 13pt
            '<font><sz val="5"/><i/><name val="Arial"/></font>',       // 2 subtitle 5pt italic
            '<font><sz val="6"/><name val="Arial"/></font>',           // 3 small 6pt
            '<font><sz val="8"/><name val="Arial"/></font>',           // 4 value 8pt
            '<font><sz val="8"/><i/><name val="Arial"/></font>',       // 5 certify 8pt italic
            '<font><sz val="5"/><name val="Arial"/></font>',           // 6 tiny 5pt
            '<font><sz val="9"/><name val="Arial"/></font>',           // 7 day cell 9pt
            '<font><b/><sz val="6"/><name val="Arial"/></font>',       // 8 small bold 6pt
            '<font><b/><sz val="5"/><name val="Arial"/></font>',       // 9 tiny bold 5pt
        );
        $fills = array(
            '<fill><patternFill patternType="none"/></fill>',
            '<fill><patternFill patternType="gray125"/></fill>',
        );
        $borders = array(
            '<border><left/><right/><top/><bottom/><diagonal/></border>',
            '<border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="thin"/><diagonal/></border>',
            '<border><left style="thin"/><right style="thin"/><top style="double"/><bottom style="medium"/><diagonal/></border>',
            '<border><left style="thin"/><right style="thin"/><top style="thin"/><bottom style="medium"/><diagonal/></border>',
            '<border><bottom style="medium"/><diagonal/></border>',
            '<border><left style="thin"/><right style="thin"/><top style="medium"/><diagonal/></border>',
            '<border><bottom style="thin"/><diagonal/></border>',
        );
        // xf: font, fill, border, horizontal alignment (null = none), wrap
        $xfs = array(
            array(0, 0, 0, null, false),     // 0 default
            array(1, 0, 0, 'center', false), // 1 title
            array(2, 0, 0, 'center', false), // 2 subtitle
            array(3, 0, 0, 'right', true),   // 3 meta label
            array(4, 0, 1, 'center', false), // 4 meta value
            array(3, 0, 1, 'center', true),  // 5 header (No/NAME)
            array(3, 0, 1, 'center', true),  // 6 day header
            array(0, 0, 1, 'center', false), // 7 no
            array(0, 0, 1, 'left', false),   // 8 name
            array(7, 0, 1, 'center', false), // 9 day
            array(0, 0, 1, 'center', false), // 10 count
            array(6, 0, 1, 'left', true),    // 11 remarks
            array(0, 0, 2, 'center', true),  // 12 subtotal
            array(0, 0, 3, 'center', true),  // 13 combined
            array(8, 0, 1, 'left', true),    // 14 bottom title
            array(3, 0, 1, 'left', true),    // 15 bottom text
            array(3, 0, 1, 'left', true),    // 16 summary label
            array(0, 0, 1, 'center', true),  // 17 summary value
            array(9, 0, 1, 'center', true),  // 18 summary head
            array(5, 0, 0, 'left', true),    // 19 certify
            array(3, 0, 4, 'center', false), // 20 signature name
            array(3, 0, 0, 'center', false), // 21 signature role
            array(0, 0, 5, 'center', false), // 22 generated
            array(4, 0, 1, 'center', true),  // 23 "Total for the Month"
            array(6, 0, 1, 'center', true),  // 24 ABSENT/PRESENT/REMARKS hdr
            array(6, 0, 1, 'left', true),    // 25 codes text (5pt)
            array(3, 0, 6, 'left', true),    // 26 text with bottom rule
        );

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
        $xml .= '<fonts count="' . count($fonts) . '">' . implode('', $fonts) . '</fonts>';
        $xml .= '<fills count="' . count($fills) . '">' . implode('', $fills) . '</fills>';
        $xml .= '<borders count="' . count($borders) . '">' . implode('', $borders) . '</borders>';
        $xml .= '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>';
        $xml .= '<cellXfs count="' . count($xfs) . '">';
        foreach ($xfs as $xf) {
            list($f, $fi, $b, $h, $wrap) = $xf;
            $xml .= '<xf numFmtId="0" fontId="' . $f . '" fillId="' . $fi . '" borderId="' . $b
                  . '" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center"';
            if ($h !== null) $xml .= ' horizontal="' . $h . '"';
            if ($wrap) $xml .= ' wrapText="1"';
            $xml .= '/></xf>';
        }
        $xml .= '</cellXfs>';
        $xml .= '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>';
        $xml .= '</styleSheet>';
        return $xml;
    }
}

if (!function_exists('xlsx_sheet_xml_styled')) {
    /**
     * Build worksheet XML with per-cell styles, merged ranges, column widths,
     * row heights, freeze panes and page setup.
     *
     * @param array $rows  Rows of cells; each cell is a string or
     *                     ['v' => value, 's' => style index].
     * @param array $opts  Keys: merges [[r1,c1,r2,c2] 1-based], widths,
     *                     heights (1-based key), freeze, page_setup.
     */
    function xlsx_sheet_xml_styled(array $rows, array $opts = array()) {
        $merges     = isset($opts['merges']) ? $opts['merges'] : array();
        $widths     = isset($opts['widths']) ? $opts['widths'] : array();
        $heights    = isset($opts['heights']) ? $opts['heights'] : array();
        $pageSetup  = isset($opts['page_setup']) ? $opts['page_setup'] : null;
        $freeze     = isset($opts['freeze']) ? $opts['freeze'] : null;

        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
        $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
              . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">';

        if ($pageSetup && !empty($pageSetup['fit_to_page'])) {
            $xml .= '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>';
        }

        $xml .= '<sheetViews><sheetView workbookViewId="0" showGridLines="1">';
        if ($freeze) {
            $xml .= '<pane xSplit="' . (int)($freeze['xSplit'] ?? 0) . '" ySplit="' . (int)($freeze['ySplit'] ?? 0)
                  . '" topLeftCell="' . ($freeze['topLeftCell'] ?? 'A1') . '" activePane="bottomRight" state="frozen"/>';
        }
        $xml .= '</sheetView></sheetViews>';

        if (!empty($widths)) {
            $xml .= '<cols>';
            foreach ($widths as $ci => $w) {
                $xml .= '<col min="' . ($ci + 1) . '" max="' . ($ci + 1) . '" width="' . $w . '" customWidth="1"/>';
            }
            $xml .= '</cols>';
        }

        $xml .= '<sheetData>';
        foreach ($rows as $ri => $rowCells) {
            $rn = $ri + 1;
            $rowAttr = ' r="' . $rn . '"';
            if (isset($heights[$ri])) {
                $rowAttr .= ' ht="' . $heights[$ri] . '" customHeight="1"';
            }
            $xml .= '<row' . $rowAttr . '>';
            $ci = 0;
            foreach ($rowCells as $cell) {
                if (is_array($cell)) {
                    $val = (string)(isset($cell['v']) ? $cell['v'] : '');
                    $style = (int)(isset($cell['s']) ? $cell['s'] : 0);
                } else {
                    $val = (string)$cell;
                    $style = 0;
                }
                $ref = xlsx_col_letter($ci) . $rn;
                $styleAttr = $style !== 0 ? ' s="' . $style . '"' : '';
                $xml .= '<c r="' . $ref . '" t="inlineStr"' . $styleAttr . '>'
                      . '<is><t xml:space="preserve">' . xlsx_xml_escape($val) . '</t></is></c>';
                $ci++;
            }
            $xml .= '</row>';
        }
        $xml .= '</sheetData>';

        if (!empty($merges)) {
            $xml .= '<mergeCells count="' . count($merges) . '">';
            foreach ($merges as $m) {
                list($r1, $c1, $r2, $c2) = $m;
                if ($r1 === $r2 && $c1 === $c2) continue;
                $ref = xlsx_col_letter($c1 - 1) . $r1 . ':' . xlsx_col_letter($c2 - 1) . $r2;
                $xml .= '<mergeCell ref="' . $ref . '"/>';
            }
            $xml .= '</mergeCells>';
        }

        if ($pageSetup) {
            $xml .= '<pageMargins left="' . $pageSetup['left'] . '" right="' . $pageSetup['right']
                  . '" top="' . $pageSetup['top'] . '" bottom="' . $pageSetup['bottom']
                  . '" header="0.2" footer="0.2"/>';
            $fitToHeight = !empty($pageSetup['fit_to_page']) ? 1 : 0;
            $xml .= '<pageSetup paperSize="' . (isset($pageSetup['size']) ? $pageSetup['size'] : 9)
                  . '" orientation="' . (!empty($pageSetup['landscape']) ? 'landscape' : 'portrait')
                  . '" fitToWidth="1" fitToHeight="' . $fitToHeight . '"/>';
        }

        $xml .= '</worksheet>';
        return $xml;
    }
}

if (!function_exists('xlsx_build_package')) {
    /**
     * Assemble a complete .xlsx package (raw binary string).
     *
     * @param array $sheets Array of ['name' => string, 'xml' => string]
     */
    function xlsx_build_package(array $sheets, $useSf2Styles = false) {
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

        $files['xl/styles.xml'] = $useSf2Styles ? xlsx_sf2_styles_xml() : xlsx_styles_xml();

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
