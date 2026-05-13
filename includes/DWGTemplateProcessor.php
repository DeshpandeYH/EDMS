<?php
/**
 * EDMS - DWG Template Processor
 *
 * Round-trips a customer DWG/DXF template through ODA File Converter,
 * substitutes placeholder tokens like <<CUSTOMER>>, and injects the
 * model-code table and dimension table at named anchor points.
 *
 * Convention (set up once per template by engineering):
 *   Title-block placeholders (literal TEXT entities):
 *     <<CUSTOMER>>  <<PROJECT>>   <<SO_NO>>     <<PO_REF>>
 *     <<MODEL_CODE>> <<DATE>>     <<DELIVERY>>  <<QTY>>
 *     <<PRODUCT>>   <<PRODUCT_NAME>>            <<DRG_NO>>
 *
 *   Table anchors (single TEXT entities placed at top-left of each table):
 *     <<MODEL_CODE_TABLE>>
 *     <<DIM_TABLE>>
 */
class DWGTemplateProcessor {

    private string $oda;
    private string $oda_ver;
    private string $tmp_root;

    public function __construct() {
        $this->oda     = ODA_FILE_CONVERTER;
        $this->oda_ver = ODA_OUTPUT_VERSION;
        $this->tmp_root = sys_get_temp_dir();
    }

    public function isAvailable(): bool {
        return is_file($this->oda);
    }

    /**
     * Main entry. Takes a template file (DWG or DXF), injects data, returns
     * a path to the produced file in the chosen output format.
     *
     * @param string $templatePath  Absolute path to the source template.
     * @param array  $orderData     Title-block fields (see keys below).
     * @param array  $selections    Rows from so_line_selections join.
     * @param array  $dimColumns    Rows from dim_table_columns.
     * @param string $outDir        Folder to drop the produced file into.
     * @param string $outBaseName   File name (without extension).
     * @param string $outFormat     'dwg' or 'dxf'.
     * @return string Absolute path of the produced file.
     */
    public function process(
        string $templatePath,
        array $orderData,
        array $selections,
        array $dimColumns,
        string $outDir,
        string $outBaseName,
        string $outFormat = 'dwg',
        array $anchorConfig = []
    ): string {
        if (!is_file($templatePath)) {
            throw new RuntimeException("Template not found: $templatePath");
        }
        $ext = strtolower(pathinfo($templatePath, PATHINFO_EXTENSION));
        if (!in_array($ext, ['dwg','dxf'], true)) {
            throw new RuntimeException("Unsupported template extension: $ext");
        }

        // 1. Load DXF (convert from DWG if necessary).
        $dxfContent = ($ext === 'dxf')
            ? file_get_contents($templatePath)
            : $this->dwgToDxf($templatePath);

        // 2. Inject data.
        $dxfContent = $this->injectPlaceholders($dxfContent, $orderData);

        $modelHeaders = ['Pos','Attribute','Code','Value'];
        $modelRows    = $this->buildModelCodeRows($orderData, $selections);
        $dimHeaders   = $this->buildDimHeader($dimColumns);
        $dimRows      = $this->buildDimRows($selections, $dimColumns);

        $modelInjected = false;
        $dxfContent = $this->injectTable(
            $dxfContent, '<<MODEL_CODE_TABLE>>', $modelRows, $modelHeaders, $modelInjected
        );
        $dimInjected = false;
        $dxfContent = $this->injectTable(
            $dxfContent, '<<DIM_TABLE>>', $dimRows, $dimHeaders, $dimInjected
        );

        // 2b. Fallback placement when the template has no anchor placeholders.
        // Use product-level anchor config when present, otherwise sensible
        // defaults (top-left of an A3 sheet area). This guarantees the
        // generated drawing actually shows the order data even if engineering
        // hasn't yet annotated the template with <<TAGS>>.
        if (!$modelInjected) {
            $a = $anchorConfig['model_code_table'] ?? ['x' => 10.0, 'y' => 280.0];
            $dxfContent = $this->appendEntities(
                $dxfContent,
                $this->renderTable((float)$a['x'], (float)$a['y'], $modelHeaders, $modelRows, '0')
            );
        }
        if (!$dimInjected) {
            $a = $anchorConfig['dim_data_table'] ?? ['x' => 150.0, 'y' => 280.0];
            $dxfContent = $this->appendEntities(
                $dxfContent,
                $this->renderTable((float)$a['x'], (float)$a['y'], $dimHeaders, $dimRows, '0')
            );
        }

        // 2c. Always stamp a compact order-info overlay block. This is
        // visible proof that the file was processed by EDMS and carries the
        // SO/customer/model code regardless of template annotation state.
        $dxfContent = $this->appendEntities(
            $dxfContent,
            $this->renderInfoStamp($orderData)
        );

        if (!is_dir($outDir)) {
            @mkdir($outDir, 0755, true);
        }

        // 3. Write produced DXF.
        $producedDxf = rtrim($outDir, '/\\') . DIRECTORY_SEPARATOR . $outBaseName . '.dxf';
        file_put_contents($producedDxf, $dxfContent);

        // 4. Optionally round-trip back to DWG.
        if (strtolower($outFormat) === 'dwg') {
            $producedDwg = $this->dxfToDwg($producedDxf, $outDir, $outBaseName);
            // Keep the DXF alongside as a debugging aid.
            return $producedDwg;
        }
        return $producedDxf;
    }

    // ---------------------------------------------------------------------
    // ODA round-trip
    // ---------------------------------------------------------------------

    private function runOda(string $inDir, string $outDir, string $outFmt): void {
        if (!$this->isAvailable()) {
            throw new RuntimeException("ODA File Converter not found at: {$this->oda}");
        }
        // Args: <in> <out> <outVer> <outFormat> <recurse> <audit>
        $cmd = sprintf(
            '"%s" "%s" "%s" %s %s 0 1',
            $this->oda, $inDir, $outDir, $this->oda_ver, $outFmt
        );
        $output = [];
        $rc = 0;
        exec($cmd . ' 2>&1', $output, $rc);
        // ODA returns 0 on success; any popups close themselves quickly.
    }

    /** Convert a single DWG file to DXF text. */
    public function dwgToDxf(string $dwgPath): string {
        $work = $this->makeWorkDir();
        $in   = $work . DIRECTORY_SEPARATOR . 'in';
        $out  = $work . DIRECTORY_SEPARATOR . 'out';
        @mkdir($in, 0755, true);
        @mkdir($out, 0755, true);
        $copied = $in . DIRECTORY_SEPARATOR . basename($dwgPath);
        copy($dwgPath, $copied);

        $this->runOda($in, $out, 'DXF');

        $produced = $out . DIRECTORY_SEPARATOR
            . pathinfo($dwgPath, PATHINFO_FILENAME) . '.dxf';
        if (!is_file($produced)) {
            $this->cleanWorkDir($work);
            throw new RuntimeException("ODA failed to convert DWG to DXF: $dwgPath");
        }
        $content = file_get_contents($produced);
        $this->cleanWorkDir($work);
        return $content;
    }

    /** Convert a DXF file to a DWG saved next to it. Returns the DWG path. */
    public function dxfToDwg(string $dxfPath, string $outDir, string $outBaseName): string {
        $work = $this->makeWorkDir();
        $in   = $work . DIRECTORY_SEPARATOR . 'in';
        $out  = $work . DIRECTORY_SEPARATOR . 'out';
        @mkdir($in, 0755, true);
        @mkdir($out, 0755, true);
        $stagedDxf = $in . DIRECTORY_SEPARATOR . $outBaseName . '.dxf';
        copy($dxfPath, $stagedDxf);

        $this->runOda($in, $out, 'DWG');

        $producedDwg = $out . DIRECTORY_SEPARATOR . $outBaseName . '.dwg';
        if (!is_file($producedDwg)) {
            $this->cleanWorkDir($work);
            throw new RuntimeException("ODA failed to convert DXF to DWG: $dxfPath");
        }
        $finalDwg = rtrim($outDir, '/\\') . DIRECTORY_SEPARATOR . $outBaseName . '.dwg';
        copy($producedDwg, $finalDwg);
        $this->cleanWorkDir($work);
        return $finalDwg;
    }

    private function makeWorkDir(): string {
        $dir = $this->tmp_root . DIRECTORY_SEPARATOR
             . 'edms_oda_' . bin2hex(random_bytes(6));
        @mkdir($dir, 0755, true);
        return $dir;
    }

    private function cleanWorkDir(string $dir): void {
        if (!is_dir($dir)) return;
        $items = scandir($dir);
        foreach ($items as $it) {
            if ($it === '.' || $it === '..') continue;
            $p = $dir . DIRECTORY_SEPARATOR . $it;
            if (is_dir($p)) $this->cleanWorkDir($p);
            else @unlink($p);
        }
        @rmdir($dir);
    }

    // ---------------------------------------------------------------------
    // Placeholder substitution
    // ---------------------------------------------------------------------

    /**
     * Replace every <<TAG>> placeholder anywhere in the DXF text.
     *
     * Because tag names are unusual strings (e.g. "<<CUSTOMER>>"), a
     * straightforward string replace is safe and robust across TEXT,
     * MTEXT, and ATTDEF default-value entities.
     */
    public function injectPlaceholders(string $dxf, array $orderData): string {
        $map = [
            '<<CUSTOMER>>'      => $orderData['customer_name']  ?? '',
            '<<PROJECT>>'       => $orderData['project_name']   ?? '',
            '<<SO_NO>>'         => $orderData['so_number']      ?? '',
            '<<PO_REF>>'        => $orderData['po_reference']   ?? '',
            '<<MODEL_CODE>>'    => $orderData['model_code']     ?? '',
            '<<PRODUCT>>'       => $orderData['product_code']   ?? '',
            '<<PRODUCT_NAME>>'  => $orderData['product_name']   ?? '',
            '<<DATE>>'          => date('d-m-Y'),
            '<<DELIVERY>>'      => $orderData['delivery_date']  ?? '',
            '<<QTY>>'           => (string)($orderData['quantity'] ?? ''),
            '<<DRG_NO>>'        => $orderData['model_code']     ?? '',
        ];
        return strtr($dxf, array_map([$this, 'sanitizeForDxf'], $map));
    }

    /** Strip characters that confuse DXF text fields. */
    private function sanitizeForDxf(string $s): string {
        // DXF group code 1 cannot contain CR/LF; keep ASCII-printable.
        $s = preg_replace('/[\r\n]+/', ' ', $s);
        return $s ?? '';
    }

    // ---------------------------------------------------------------------
    // Table injection
    // ---------------------------------------------------------------------

    /**
     * Find a <<ANCHOR>> placeholder TEXT entity in the DXF, read its X/Y
     * insert point, delete the entire TEXT entity, and emit a grid of
     * lines + TEXT entities representing the table starting from there.
     */
    public function injectTable(string $dxf, string $anchor, array $rows, array $headers, ?bool &$injected = null): string {
        $pos = $this->findAnchorPosition($dxf, $anchor);
        if ($pos === null) {
            // Anchor not present in this template — silently skip.
            $injected = false;
            return $dxf;
        }
        $injected = true;
        // Remove the anchor TEXT entity from the ENTITIES section.
        $dxf = $this->removeAnchorEntity($dxf, $anchor);

        // Compose new entities (lines + text).
        $entities = $this->renderTable(
            (float)$pos['x'],
            (float)$pos['y'],
            $headers,
            $rows,
            $pos['layer'] ?? '0'
        );

        // Insert the new entities just before "ENDSEC" of the ENTITIES section.
        return $this->appendEntities($dxf, $entities);
    }

    /**
     * Locate the first TEXT/MTEXT entity whose group-code-1 value equals
     * the anchor token. Returns ['x'=>..,'y'=>..,'layer'=>..] or null.
     *
     * DXF group codes are right-justified into a 3-char field (e.g. "  0",
     * "  1", " 10"). Values are NOT padded. We rely on this convention to
     * distinguish a group code "0" (entity boundary) from a value "0"
     * (e.g. a layer name).
     */
    private function findAnchorPosition(string $dxf, string $anchor): ?array {
        $lines = preg_split("/\r\n|\n|\r/", $dxf);
        $n = count($lines);
        for ($i = 0; $i < $n - 1; $i++) {
            if ($this->isGroupCode($lines[$i], 1) && isset($lines[$i+1]) && $lines[$i+1] === $anchor) {
                // Walk backwards to find the start of this entity (group code 0)
                // and capture x (10), y (20), layer (8).
                $x = null; $y = null; $layer = null;
                for ($j = $i; $j >= 0 && $j > $i - 60; $j -= 2) {
                    $code = $lines[$j] ?? '';
                    $val  = $lines[$j+1] ?? '';
                    if ($this->isGroupCode($code, 10) && $x === null) $x = trim($val);
                    if ($this->isGroupCode($code, 20) && $y === null) $y = trim($val);
                    if ($this->isGroupCode($code, 8)  && $layer === null) $layer = trim($val);
                    if ($this->isGroupCode($code, 0)  && (trim($val) === 'TEXT' || trim($val) === 'MTEXT')) break;
                }
                if ($x !== null && $y !== null) {
                    return ['x' => $x, 'y' => $y, 'layer' => $layer ?? '0'];
                }
            }
        }
        return null;
    }

    /** True if $line is the literal DXF group-code marker for $code. */
    private function isGroupCode(string $line, int $code): bool {
        // DXF group codes are right-justified into a 3-character field.
        return $line === sprintf('%3d', $code);
    }

    /** Remove the entity (TEXT/MTEXT) whose text payload equals $anchor. */
    private function removeAnchorEntity(string $dxf, string $anchor): string {
        $lines = preg_split("/\r\n|\n|\r/", $dxf);
        $n = count($lines);
        for ($i = 0; $i < $n - 1; $i++) {
            if ($this->isGroupCode($lines[$i], 1) && $lines[$i+1] === $anchor) {
                // Walk back in steps of 2 (code/value pairs) to the entity's
                // own "  0" boundary.
                $start = null;
                for ($j = $i - 1; $j >= 1; $j -= 2) {
                    if ($this->isGroupCode($lines[$j-1], 0) &&
                        ($lines[$j] === 'TEXT' || $lines[$j] === 'MTEXT')) {
                        $start = $j - 1;
                        break;
                    }
                }
                if ($start === null) return $dxf;
                // Walk forward to the next "  0" boundary.
                $end = $n - 1;
                for ($k = $i + 2; $k < $n; $k++) {
                    if ($this->isGroupCode($lines[$k], 0)) { $end = $k - 1; break; }
                }
                array_splice($lines, $start, $end - $start + 1);
                break;
            }
        }
        return implode("\r\n", $lines);
    }

    /** Append raw entity lines just before the ENTITIES section's ENDSEC. */
    private function appendEntities(string $dxf, string $entities): string {
        $lines = preg_split("/\r\n|\n|\r/", $dxf);
        $n = count($lines);
        // Find the ENTITIES section start: "  0\nSECTION\n  2\nENTITIES".
        $entitiesStart = null;
        for ($i = 0; $i < $n - 5; $i++) {
            if ($this->isGroupCode($lines[$i], 0) && ($lines[$i+1] ?? '') === 'SECTION'
                && $this->isGroupCode($lines[$i+2] ?? '', 2) && ($lines[$i+3] ?? '') === 'ENTITIES') {
                $entitiesStart = $i + 4;
                break;
            }
        }
        if ($entitiesStart === null) return $dxf;
        // Find ENDSEC after that point.
        $endsecAt = null;
        for ($i = $entitiesStart; $i < $n - 1; $i++) {
            if ($this->isGroupCode($lines[$i], 0) && ($lines[$i+1] ?? '') === 'ENDSEC') {
                $endsecAt = $i;
                break;
            }
        }
        if ($endsecAt === null) return $dxf;
        // Insert the new entities right before the ENDSEC group-code line.
        $before = implode("\r\n", array_slice($lines, 0, $endsecAt));
        $after  = implode("\r\n", array_slice($lines, $endsecAt));
        return $before . "\r\n" . $entities . $after;
    }

    /**
     * Build raw DXF entity text representing a header + data rows table.
     * Renders TEXT entities (R12/AC1009 compatible group codes) + LINE
     * entities for the cell grid.
     */
    private function renderTable(float $x0, float $y0, array $headers, array $rows, string $layer): string {
        $rowH = 6.0;
        $colCount = max(1, count($headers));
        $colW = 30.0; // mm per column
        $totalW = $colW * $colCount;
        $totalRows = count($rows) + 1; // + header
        $totalH = $rowH * $totalRows;

        // Top-left of the table is at (x0, y0). Grow downward (decreasing Y).
        $top    = $y0;
        $bottom = $y0 - $totalH;
        $left   = $x0;
        $right  = $x0 + $totalW;

        $out = '';

        // Horizontal lines
        for ($r = 0; $r <= $totalRows; $r++) {
            $y = $top - ($r * $rowH);
            $out .= $this->dxfLine($left, $y, $right, $y, $layer);
        }
        // Vertical lines
        for ($c = 0; $c <= $colCount; $c++) {
            $x = $left + ($c * $colW);
            $out .= $this->dxfLine($x, $top, $x, $bottom, $layer);
        }

        // Header row text
        foreach ($headers as $ci => $h) {
            $tx = $left + ($ci * $colW) + 1.0;
            $ty = $top - $rowH + 1.5;
            $out .= $this->dxfText($tx, $ty, 2.2, (string)$h, $layer);
        }
        // Data rows
        foreach ($rows as $ri => $row) {
            foreach ($headers as $ci => $h) {
                $val = $row[$ci] ?? '';
                $tx = $left + ($ci * $colW) + 1.0;
                $ty = $top - ($rowH * ($ri + 2)) + 1.5;
                $out .= $this->dxfText($tx, $ty, 2.0, (string)$val, $layer);
            }
        }
        return $out;
    }

    /**
     * Compact "EDMS-stamp" overlay: a small framed block containing
     * SO No / Customer / Model Code / Date / Qty. Drawn at a known
     * top-right corner of the sheet so the user can immediately see
     * that the file carries this order's data, even when the template
     * had no <<TAGS>> for the title block.
     */
    private function renderInfoStamp(array $o): string {
        $lines = [
            'SO NO     : ' . ($o['so_number']     ?? ''),
            'CUSTOMER  : ' . ($o['customer_name'] ?? ''),
            'PROJECT   : ' . ($o['project_name']  ?? ''),
            'PO REF    : ' . ($o['po_reference']  ?? ''),
            'MODEL CODE: ' . ($o['model_code']    ?? ''),
            'PRODUCT   : ' . ($o['product_name']  ?? ''),
            'QTY       : ' . ($o['quantity']      ?? ''),
            'DATE      : ' . date('d-m-Y'),
        ];
        $x0 = 280.0; $y0 = 290.0;     // top-right area on a typical A3 sheet
        $w  = 130.0; $rowH = 5.0;
        $h  = $rowH * (count($lines) + 1);
        $out  = $this->dxfLine($x0,       $y0,       $x0 + $w, $y0,       '0');
        $out .= $this->dxfLine($x0 + $w,  $y0,       $x0 + $w, $y0 - $h,  '0');
        $out .= $this->dxfLine($x0 + $w,  $y0 - $h,  $x0,      $y0 - $h,  '0');
        $out .= $this->dxfLine($x0,       $y0 - $h,  $x0,      $y0,       '0');
        // Title bar
        $out .= $this->dxfLine($x0, $y0 - $rowH, $x0 + $w, $y0 - $rowH, '0');
        $out .= $this->dxfText($x0 + 2.0, $y0 - $rowH + 1.5, 2.5, 'EDMS GENERATED', '0');
        foreach ($lines as $i => $t) {
            $ty = $y0 - ($rowH * ($i + 2)) + 1.2;
            $out .= $this->dxfText($x0 + 2.0, $ty, 2.0, $t, '0');
        }
        return $out;
    }

    private function dxfLine(float $x1, float $y1, float $x2, float $y2, string $layer): string {
        // 67/1 = paper-space flag so the entity is visible on Layout1
        // (the sheet) and not buried in model space.
        return "  0\r\nLINE\r\n  8\r\n{$layer}\r\n 67\r\n     1\r\n 10\r\n"
             . sprintf('%.4f', $x1) . "\r\n 20\r\n" . sprintf('%.4f', $y1) . "\r\n 30\r\n0.0\r\n"
             . " 11\r\n" . sprintf('%.4f', $x2) . "\r\n 21\r\n" . sprintf('%.4f', $y2) . "\r\n 31\r\n0.0\r\n";
    }

    private function dxfText(float $x, float $y, float $h, string $text, string $layer): string {
        $text = $this->sanitizeForDxf($text);
        return "  0\r\nTEXT\r\n  8\r\n{$layer}\r\n 67\r\n     1\r\n 10\r\n"
             . sprintf('%.4f', $x) . "\r\n 20\r\n" . sprintf('%.4f', $y) . "\r\n 30\r\n0.0\r\n"
             . " 40\r\n" . sprintf('%.4f', $h) . "\r\n  1\r\n{$text}\r\n";
    }

    // ---------------------------------------------------------------------
    // Row builders (data → table cells)
    // ---------------------------------------------------------------------

    private function buildModelCodeRows(array $order, array $selections): array {
        $rows = [];
        foreach ($selections as $s) {
            $rows[] = [
                (string)($s['position_in_model'] ?? ''),
                (string)($s['attr_name'] ?? $s['attr_code'] ?? ''),
                (string)($s['opt_code'] ?? ''),
                (string)($s['display_label'] ?? $s['opt_value'] ?? ''),
            ];
        }
        return $rows;
    }

    private function buildDimHeader(array $dimColumns): array {
        if (empty($dimColumns)) {
            return ['Ref','Attribute','Option','Value'];
        }
        $h = [];
        foreach ($dimColumns as $c) {
            $h[] = (string)($c['column_label'] ?? $c['ref_marker'] ?? '');
        }
        return $h;
    }

    private function buildDimRows(array $selections, array $dimColumns): array {
        $rows = [];
        foreach ($selections as $s) {
            $mods = [];
            if (!empty($s['dimension_modifiers'])) {
                $decoded = json_decode($s['dimension_modifiers'], true);
                if (is_array($decoded)) $mods = $decoded;
            }
            if (empty($dimColumns)) {
                $rows[] = [
                    (string)($s['attr_code'] ?? ''),
                    (string)($s['attr_name'] ?? ''),
                    (string)($s['opt_code'] ?? ''),
                    (string)($s['display_label'] ?? $s['opt_value'] ?? ''),
                ];
            } else {
                $row = [];
                foreach ($dimColumns as $col) {
                    $key = $col['dimension_key'] ?? '';
                    $row[] = isset($mods[$key]) ? (string)$mods[$key] : '';
                }
                $rows[] = $row;
            }
        }
        return $rows;
    }
}
