<?php
/**
 * EDMS - DXF Drawing Generator (R12 / AC1009 format)
 * Generates valid DXF files compatible with DraftSight, AutoCAD, LibreCAD
 * Uses strict R12 format with CRLF line endings for maximum compatibility
 */
class DXFGenerator {
    private array $entities = [];
    private float $sheetWidth = 420;  // A3 landscape
    private float $sheetHeight = 297;
    
    public function generate(array $orderData, array $selections, array $dimColumns = []): string {
        $this->entities = [];
        
        $this->drawBorder();
        $this->drawTitleBlock($orderData);
        $this->drawModelCodeTable($orderData, $selections);
        $this->drawDimensionTable($orderData, $selections);
        $this->drawNotes($orderData);
        
        return $this->buildDXF();
    }
    
    private function drawBorder(): void {
        $m = 5;
        $this->addLine($m, $m, $this->sheetWidth - $m, $m, 'BORDER');
        $this->addLine($this->sheetWidth - $m, $m, $this->sheetWidth - $m, $this->sheetHeight - $m, 'BORDER');
        $this->addLine($this->sheetWidth - $m, $this->sheetHeight - $m, $m, $this->sheetHeight - $m, 'BORDER');
        $this->addLine($m, $this->sheetHeight - $m, $m, $m, 'BORDER');
        
        $m2 = 10;
        $this->addLine($m2, $m2, $this->sheetWidth - $m2, $m2, 'BORDER');
        $this->addLine($this->sheetWidth - $m2, $m2, $this->sheetWidth - $m2, $this->sheetHeight - $m2, 'BORDER');
        $this->addLine($this->sheetWidth - $m2, $this->sheetHeight - $m2, $m2, $this->sheetHeight - $m2, 'BORDER');
        $this->addLine($m2, $this->sheetHeight - $m2, $m2, $m2, 'BORDER');
    }
    
    private function drawTitleBlock(array $data): void {
        $x = $this->sheetWidth - 200;
        $y = 12;
        $w = 188;
        $rh = 8;
        $layer = 'TITLEBLOCK';
        
        $this->addRect($x, $y, $w, $rh * 10, $layer);
        for ($i = 1; $i <= 9; $i++) {
            $this->addLine($x, $y + $rh * $i, $x + $w, $y + $rh * $i, $layer);
        }
        $this->addLine($x + 55, $y, $x + 55, $y + $rh * 10, $layer);
        $this->addLine($x + 120, $y, $x + 120, $y + $rh * 10, $layer);
        
        $labels = ['DRAWN BY', 'CHK BY', 'APPD BY', 'DATE', 'SCALE', 'SHEET', 'DRG NO.', 'REV', 'TITLE', 'COMPANY'];
        $values = [
            'EDMS', '', '', date('d-m-Y'), 'NTS', '1 OF 1',
            $data['model_code'] ?? '', 'R00',
            $data['product_name'] ?? '', 'SBEM ENGINEERING'
        ];
        
        for ($i = 0; $i < 10; $i++) {
            $ty = $y + $rh * $i + $rh * 0.3;
            $this->addText($x + 2, $ty, $labels[$i], 2.0, $layer);
            $this->addText($x + 57, $ty, $values[$i] ?? '', 2.2, $layer);
        }
        
        $rightData = [
            'CUSTOMER: ' . strtoupper($data['customer_name'] ?? ''),
            'PROJECT: ' . strtoupper($data['project_name'] ?? ''),
            'SO NO: ' . ($data['so_number'] ?? ''),
            'PO REF: ' . ($data['po_reference'] ?? ''),
            'MODEL: ' . ($data['model_code'] ?? ''),
            'QTY: ' . ($data['quantity'] ?? ''),
            'PRODUCT: ' . ($data['product_code'] ?? ''),
            'DELIVERY: ' . ($data['delivery_date'] ?? ''),
            'GENERATED: ' . date('d-m-Y H:i'),
            ''
        ];
        for ($i = 0; $i < 10; $i++) {
            $ty = $y + $rh * $i + $rh * 0.3;
            $this->addText($x + 122, $ty, $rightData[$i], 1.8, $layer);
        }
    }
    
    private function drawModelCodeTable(array $data, array $selections): void {
        $x = $this->sheetWidth - 200;
        $y = 105;
        $w = 188;
        $rh = 7;
        $layer = 'MODELCODE';
        $rows = count($selections) + 2;
        $tableH = $rh * $rows;
        
        $this->addRect($x, $y, $w, $tableH, $layer);
        
        $titleY = $y + $tableH - $rh;
        $this->addLine($x, $titleY, $x + $w, $titleY, $layer);
        $this->addText($x + 30, $titleY + $rh * 0.25, 'MODEL CODE BREAKDOWN', 2.8, $layer);
        
        $hdrY = $titleY - $rh;
        $this->addLine($x, $hdrY, $x + $w, $hdrY, $layer);
        $this->addText($x + 2, $hdrY + $rh * 0.25, 'ATTRIBUTE', 2.2, $layer);
        $this->addText($x + 60, $hdrY + $rh * 0.25, 'CODE', 2.2, $layer);
        $this->addText($x + 95, $hdrY + $rh * 0.25, 'DESCRIPTION', 2.2, $layer);
        $this->addLine($x + 58, $y, $x + 58, $titleY, $layer);
        $this->addLine($x + 93, $y, $x + 93, $titleY, $layer);
        
        $prodY = $hdrY - $rh;
        $this->addLine($x, $prodY, $x + $w, $prodY, $layer);
        $this->addText($x + 2, $prodY + $rh * 0.25, 'Product', 2.0, $layer);
        $this->addText($x + 60, $prodY + $rh * 0.25, $data['product_code'] ?? '', 2.0, $layer);
        $this->addText($x + 95, $prodY + $rh * 0.25, $data['product_name'] ?? '', 1.8, $layer);
        
        for ($i = 0; $i < count($selections); $i++) {
            $ry = $prodY - $rh * ($i + 1);
            if ($ry > $y) $this->addLine($x, $ry, $x + $w, $ry, $layer);
            $s = $selections[$i];
            $this->addText($x + 2, $ry + $rh * 0.25, $s['attr_name'] ?? '', 2.0, $layer);
            $this->addText($x + 60, $ry + $rh * 0.25, $s['opt_code'] ?? '', 2.0, $layer);
            $this->addText($x + 95, $ry + $rh * 0.25, $s['display_label'] ?: ($s['opt_value'] ?? ''), 1.8, $layer);
        }
        
        $this->addText($x + 10, $y + $tableH + 5, 'MODEL CODE: ' . ($data['model_code'] ?? ''), 4.0, $layer);
    }
    
    private function drawDimensionTable(array $data, array $selections): void {
        $x = 15;
        $y = 15;
        $w = 195;
        $rh = 7;
        $layer = 'DIMTABLE';
        
        $soFields = [
            ['Order No.', 'SO Header', $data['so_number'] ?? ''],
            ['Customer', 'SO Header', $data['customer_name'] ?? ''],
            ['Project', 'SO Header', $data['project_name'] ?? ''],
            ['PO Reference', 'SO Header', $data['po_reference'] ?? ''],
            ['Delivery Date', 'SO Header', $data['delivery_date'] ?? ''],
            ['Model Code', 'Model Code', $data['model_code'] ?? ''],
            ['Product', 'Product', ($data['product_code'] ?? '') . ' - ' . ($data['product_name'] ?? '')],
            ['Quantity', 'SO Line', (string)($data['quantity'] ?? '')],
        ];
        
        $dims = [];
        foreach ($selections as $sel) {
            if (!empty($sel['dimension_modifiers'])) {
                $mods = json_decode($sel['dimension_modifiers'], true);
                if ($mods) {
                    foreach ($mods as $key => $val) {
                        $dims[] = [$sel['attr_name'] . ' - ' . $key, 'Dim Modifier', (string)$val . ' mm'];
                    }
                }
            }
        }
        
        $allRows = array_merge($soFields, $dims);
        $totalRows = count($allRows) + 2;
        $tableH = $rh * $totalRows;
        
        $this->addRect($x, $y, $w, $tableH, $layer);
        
        $titleY = $y + $tableH - $rh;
        $this->addLine($x, $titleY, $x + $w, $titleY, $layer);
        $this->addText($x + 40, $titleY + $rh * 0.25, 'DIMENSION / DATA TABLE', 2.8, $layer);
        
        $hdrY = $titleY - $rh;
        $this->addLine($x, $hdrY, $x + $w, $hdrY, $layer);
        $this->addText($x + 2, $hdrY + $rh * 0.25, 'FIELD', 2.2, $layer);
        $this->addText($x + 55, $hdrY + $rh * 0.25, 'SOURCE', 2.2, $layer);
        $this->addText($x + 105, $hdrY + $rh * 0.25, 'VALUE', 2.2, $layer);
        $this->addLine($x + 53, $y, $x + 53, $titleY, $layer);
        $this->addLine($x + 103, $y, $x + 103, $titleY, $layer);
        
        for ($i = 0; $i < count($allRows); $i++) {
            $ry = $hdrY - $rh * ($i + 1);
            if ($ry > $y) $this->addLine($x, $ry, $x + $w, $ry, $layer);
            $this->addText($x + 2, $ry + $rh * 0.25, $allRows[$i][0], 2.0, $layer);
            $this->addText($x + 55, $ry + $rh * 0.25, $allRows[$i][1], 1.8, $layer);
            $this->addText($x + 105, $ry + $rh * 0.25, $allRows[$i][2], 2.2, $layer);
        }
    }
    
    private function drawNotes(array $data): void {
        $x = 15;
        $y = 200;
        $layer = 'NOTES';
        
        $this->addText($x, $y, 'NOTES:', 3.0, $layer);
        $this->addText($x, $y - 6, '1. ALL DIMENSIONS ARE IN MM UNLESS OTHERWISE SPECIFIED.', 2.2, $layer);
        $this->addText($x, $y - 12, '2. THIS DRAWING IS AUTO-GENERATED BY EDMS.', 2.2, $layer);
        $this->addText($x, $y - 18, '3. CUSTOMER: ' . strtoupper($data['customer_name'] ?? ''), 2.2, $layer);
        $this->addText($x, $y - 24, '4. ORDER REF: ' . ($data['so_number'] ?? '') . ' / ' . ($data['po_reference'] ?? ''), 2.2, $layer);
        $this->addText($x, $y - 30, '5. MODEL CODE: ' . ($data['model_code'] ?? ''), 2.2, $layer);
        $this->addText($x, $y - 36, '6. QUANTITY: ' . ($data['quantity'] ?? ''), 2.2, $layer);
        
        $cx = $this->sheetWidth / 2 - 60;
        $cy = $this->sheetHeight - 25;
        $this->addText($cx, $cy, $data['product_name'] ?? '', 5.0, $layer);
        $this->addText($cx, $cy - 10, 'MODEL: ' . ($data['model_code'] ?? ''), 4.5, $layer);
        $this->addText($cx, $cy - 19, 'SO: ' . ($data['so_number'] ?? '') . '   CUSTOMER: ' . ($data['customer_name'] ?? ''), 3.0, $layer);
    }
    
    // ═══════════════════════════════════════
    // DXF PRIMITIVE WRITERS
    // ═══════════════════════════════════════
    
    private function addLine(float $x1, float $y1, float $x2, float $y2, string $layer = '0'): void {
        $this->entities[] = $this->dxfLine($x1, $y1, $x2, $y2, $layer);
    }
    
    private function addRect(float $x, float $y, float $w, float $h, string $layer = '0'): void {
        $this->addLine($x, $y, $x + $w, $y, $layer);
        $this->addLine($x + $w, $y, $x + $w, $y + $h, $layer);
        $this->addLine($x + $w, $y + $h, $x, $y + $h, $layer);
        $this->addLine($x, $y + $h, $x, $y, $layer);
    }
    
    private function addText(float $x, float $y, string $text, float $height = 2.5, string $layer = '0'): void {
        $text = trim(str_replace(["\r", "\n"], ' ', $text));
        if ($text === '') return;
        $this->entities[] = $this->dxfText($x, $y, $text, $height, $layer);
    }
    
    // ═══════════════════════════════════════
    // DXF GROUP CODE FORMATTERS
    // ═══════════════════════════════════════
    
    private function gc(int $code, string $value): string {
        // DXF group code: right-justified code in 3 chars, then value
        return sprintf("%3d\r\n%s", $code, $value);
    }
    
    private function dxfLine(float $x1, float $y1, float $x2, float $y2, string $layer): string {
        return implode("\r\n", [
            $this->gc(0, 'LINE'),
            $this->gc(8, $layer),
            $this->gc(10, sprintf('%.6f', $x1)),
            $this->gc(20, sprintf('%.6f', $y1)),
            $this->gc(30, '0.000000'),
            $this->gc(11, sprintf('%.6f', $x2)),
            $this->gc(21, sprintf('%.6f', $y2)),
            $this->gc(31, '0.000000'),
        ]);
    }
    
    private function dxfText(float $x, float $y, string $text, float $h, string $layer): string {
        return implode("\r\n", [
            $this->gc(0, 'TEXT'),
            $this->gc(8, $layer),
            $this->gc(10, sprintf('%.6f', $x)),
            $this->gc(20, sprintf('%.6f', $y)),
            $this->gc(30, '0.000000'),
            $this->gc(40, sprintf('%.6f', $h)),
            $this->gc(1, $text),
        ]);
    }
    
    // ═══════════════════════════════════════
    // COMPLETE DXF FILE BUILDER (R12 format)
    // ═══════════════════════════════════════
    
    private function buildDXF(): string {
        $out = '';
        
        // ── HEADER ──
        $out .= $this->gc(0, 'SECTION') . "\r\n";
        $out .= $this->gc(2, 'HEADER') . "\r\n";
        $out .= $this->gc(9, '$ACADVER') . "\r\n" . $this->gc(1, 'AC1009') . "\r\n";
        $out .= $this->gc(9, '$INSBASE') . "\r\n";
        $out .= $this->gc(10, '0.0') . "\r\n" . $this->gc(20, '0.0') . "\r\n" . $this->gc(30, '0.0') . "\r\n";
        $out .= $this->gc(9, '$EXTMIN') . "\r\n";
        $out .= $this->gc(10, '0.0') . "\r\n" . $this->gc(20, '0.0') . "\r\n" . $this->gc(30, '0.0') . "\r\n";
        $out .= $this->gc(9, '$EXTMAX') . "\r\n";
        $out .= $this->gc(10, sprintf('%.6f', $this->sheetWidth)) . "\r\n";
        $out .= $this->gc(20, sprintf('%.6f', $this->sheetHeight)) . "\r\n";
        $out .= $this->gc(30, '0.0') . "\r\n";
        $out .= $this->gc(9, '$LIMMIN') . "\r\n";
        $out .= $this->gc(10, '0.0') . "\r\n" . $this->gc(20, '0.0') . "\r\n";
        $out .= $this->gc(9, '$LIMMAX') . "\r\n";
        $out .= $this->gc(10, sprintf('%.6f', $this->sheetWidth)) . "\r\n";
        $out .= $this->gc(20, sprintf('%.6f', $this->sheetHeight)) . "\r\n";
        $out .= $this->gc(0, 'ENDSEC') . "\r\n";
        
        // ── TABLES ──
        $out .= $this->gc(0, 'SECTION') . "\r\n";
        $out .= $this->gc(2, 'TABLES') . "\r\n";
        
        // LTYPE
        $out .= $this->gc(0, 'TABLE') . "\r\n";
        $out .= $this->gc(2, 'LTYPE') . "\r\n";
        $out .= $this->gc(70, '1') . "\r\n";
        $out .= $this->gc(0, 'LTYPE') . "\r\n";
        $out .= $this->gc(2, 'CONTINUOUS') . "\r\n";
        $out .= $this->gc(70, '0') . "\r\n";
        $out .= $this->gc(3, 'Solid line') . "\r\n";
        $out .= $this->gc(72, '65') . "\r\n";
        $out .= $this->gc(73, '0') . "\r\n";
        $out .= $this->gc(40, '0.0') . "\r\n";
        $out .= $this->gc(0, 'ENDTAB') . "\r\n";
        
        // LAYER
        $layers = [
            ['0', 7], ['BORDER', 8], ['TITLEBLOCK', 3],
            ['MODELCODE', 4], ['DIMTABLE', 5], ['NOTES', 2]
        ];
        $out .= $this->gc(0, 'TABLE') . "\r\n";
        $out .= $this->gc(2, 'LAYER') . "\r\n";
        $out .= $this->gc(70, (string)count($layers)) . "\r\n";
        foreach ($layers as [$name, $color]) {
            $out .= $this->gc(0, 'LAYER') . "\r\n";
            $out .= $this->gc(2, $name) . "\r\n";
            $out .= $this->gc(70, '0') . "\r\n";
            $out .= $this->gc(62, (string)$color) . "\r\n";
            $out .= $this->gc(6, 'CONTINUOUS') . "\r\n";
        }
        $out .= $this->gc(0, 'ENDTAB') . "\r\n";
        
        // STYLE
        $out .= $this->gc(0, 'TABLE') . "\r\n";
        $out .= $this->gc(2, 'STYLE') . "\r\n";
        $out .= $this->gc(70, '1') . "\r\n";
        $out .= $this->gc(0, 'STYLE') . "\r\n";
        $out .= $this->gc(2, 'STANDARD') . "\r\n";
        $out .= $this->gc(70, '0') . "\r\n";
        $out .= $this->gc(40, '0.0') . "\r\n";
        $out .= $this->gc(41, '1.0') . "\r\n";
        $out .= $this->gc(50, '0.0') . "\r\n";
        $out .= $this->gc(71, '0') . "\r\n";
        $out .= $this->gc(42, '2.5') . "\r\n";
        $out .= $this->gc(3, 'txt') . "\r\n";
        $out .= $this->gc(4, '') . "\r\n";
        $out .= $this->gc(0, 'ENDTAB') . "\r\n";
        
        $out .= $this->gc(0, 'ENDSEC') . "\r\n";
        
        // ── BLOCKS ──
        $out .= $this->gc(0, 'SECTION') . "\r\n";
        $out .= $this->gc(2, 'BLOCKS') . "\r\n";
        $out .= $this->gc(0, 'ENDSEC') . "\r\n";
        
        // ── ENTITIES ──
        $out .= $this->gc(0, 'SECTION') . "\r\n";
        $out .= $this->gc(2, 'ENTITIES') . "\r\n";
        foreach ($this->entities as $entity) {
            $out .= $entity . "\r\n";
        }
        $out .= $this->gc(0, 'ENDSEC') . "\r\n";
        
        // ── EOF ──
        $out .= $this->gc(0, 'EOF') . "\r\n";
        
        return $out;
    }
}
