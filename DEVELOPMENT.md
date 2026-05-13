# EDMS — Engineering Drawing Management System
## Development Document

**Version:** 1.1.0  
**Stack:** PHP 8.2 (XAMPP) · MS SQL Server · Vanilla JS · CSS Grid · ODA File Converter  
**Deployment:** `C:\xampp\htdocs\edms` → `http://localhost/edms/`  
**Author / Maintainer:** SBEM Engineering — Drawing Automation Team

---

## 1. Purpose & Scope

EDMS is an internal web application that automates the entire lifecycle of engineering drawings and test certificates produced against customer sales orders for SBEM's instrumentation product line (Ultrasonic Level Transmitters, Pressure Transmitters, etc.).

It replaces the manual workflow where Sales/Planning/Engineering teams currently:

1. Read the customer PO and decode the model code by hand.
2. Open a DWG template in DraftSight, copy/paste customer details, edit the model-code table and dimension table.
3. Save under a manual filename and email it for review.
4. Repeat for the test certificate (Excel).

EDMS replaces all four steps with a deterministic, data-driven pipeline:

> **Product Master ➜ Attribute/Option Catalog ➜ Combination Matrix ➜ DWG Template (with `<<TAG>>` placeholders) ➜ Sales Order ➜ Auto-generated DWG Drawings + Excel Certificates**

The drawing engine **opens the engineering team's own DWG template**, substitutes placeholders like `<<CUSTOMER>>`, `<<SO_NO>>`, `<<MODEL_CODE>>` in the title block, draws the model-code table and dimension table at named anchor points, and saves a fresh DWG. Customer-supplied geometry, borders, blocks and styles are preserved verbatim. A from-scratch DXF generator remains as a fallback when no template is mapped.

---

## 2. High-Level Architecture

```
Browser (HTML/CSS/JS)
        │
        ▼
Apache (XAMPP)  ──►  PHP 8.2  ──►  PDO_SQLSRV  ──►  MS SQL Server (edms DB)
        │                                                   │
        ▼                                                   ▼
   /pages/*.php                                  24 normalized tables
   /api/*.php  (JSON / file streaming)           UNIQUEIDENTIFIER PKs
        │
        ├──► Drawing engine (chosen at runtime per document):
        │
        │     A) Template-based — preferred
        │        includes/DWGTemplateProcessor.php
        │        DWG ──(ODA)──► DXF ──► substitute <<TAGS>> + draw
        │        tables at <<ANCHOR>> ──► DXF ──(ODA)──► DWG output
        │
        │     B) From-scratch fallback (used when no template mapped)
        │        includes/DXFGenerator.php  →  R12 DXF, A3 landscape
        │
        ▼
   /outputs/{SO}/line{N}/*.dwg   (or *.dxf for fallback)
```

| Layer | Technology | Location |
|-------|-----------|----------|
| Presentation | HTML5 + CSS variables + Vanilla JS (no framework) | [assets/css/style.css](assets/css/style.css), [assets/js/app.js](assets/js/app.js) |
| Page Controller | PHP 8.2 procedural per-page | [pages/](pages/) |
| API | PHP returning JSON or binary streams | [api/](api/) |
| Drawing engine (primary) | DWG template processor with placeholder + table injection | [includes/DWGTemplateProcessor.php](includes/DWGTemplateProcessor.php) |
| Drawing engine (fallback) | R12 DXF generator | [includes/DXFGenerator.php](includes/DXFGenerator.php) |
| DWG ⇄ DXF round-trip | ODA File Converter (CLI) | `C:\Program Files\ODA\ODAFileConverter\ODAFileConverter.exe` |
| Persistence | MS SQL Server via `pdo_sqlsrv` | [sql/schema.sql](sql/schema.sql) |
| Storage | Local filesystem | `uploads/templates/{product_id}/`, `outputs/{so_number}/line{N}/` |

---

## 3. Folder Layout

```
edms/
├── index.php                     # Dashboard
├── setup.php                     # First-run health check
├── config/
│   └── database.php              # PDO connection, helpers, constants
├── includes/
│   ├── header.php                # Top-nav + flash + <head>
│   ├── footer.php                # Closing tags + JS include
│   ├── DWGTemplateProcessor.php  # ODA round-trip + <<TAG>> substitution + table injection (PRIMARY)
│   └── DXFGenerator.php          # R12 DXF renderer (FALLBACK when no template mapped)
├── pages/
│   ├── product_codes.php         # Product master CRUD
│   ├── attributes.php            # Attributes per product
│   ├── attribute_options.php     # Option codes per attribute
│   ├── combinations.php          # Generate / view combination matrix
│   ├── templates.php             # Upload DWG/DXF/XLSX templates
│   ├── template_fields.php       # Map DWG fields → data sources
│   ├── anchor_config.php         # X/Y anchors for tables on DWG
│   ├── sales_orders.php          # SO list + create
│   ├── sales_order_detail.php    # Line items, model code build, doc generation
│   ├── model_code_builder.php    # Standalone model-code wizard
│   ├── drawings.php              # All generated documents
│   ├── drawing_preview.php       # Per-document preview + DXF download
│   ├── certificates.php          # Test certificates
│   └── changes.php               # Change events / impact analysis
├── api/
│   ├── download.php              # Generates + streams DXF on demand
│   ├── get_product_attributes.php
│   └── resolve_combination.php   # Maps a model-code to a combination row
├── assets/
│   ├── css/style.css
│   └── js/app.js
├── uploads/
│   └── templates/                # User-uploaded DWG/DXF/XLSX
├── outputs/
│   └── {SO_NUMBER}/line{N}/*.dwg # Generated drawings (per SO + line item)
└── sql/
    ├── schema.sql                # Idempotent CREATE TABLE script
    ├── seed_data.php             # Loads 136ULT + 171PT from Excel
    ├── fix_flags.php             # Sets affects_*_dwg flags
    ├── fix_product_merge.php     # Dedupes "136 ULT" vs "136ULT"
    ├── fix_doc_template.php      # Backfills template_id on legacy docs
    ├── run_full_flow.php         # End-to-end smoke test (DXF fallback engine)
    ├── test_dxf.php              # Standalone DXF generator test
    ├── test_template_processor.php # End-to-end test of DWG template engine
    ├── prepare_template.php      # CLI: report which placeholders a template contains
    └── debug_files.php           # Inspect generated outputs
```

---

## 4. Database Design

### 4.1 Conventions

- Engine: **MS SQL Server** (any 2017+ edition).
- Database name: `edms`.
- All PKs are `UNIQUEIDENTIFIER DEFAULT NEWID()` — UUIDs are generated server-side.
- All time columns are `DATETIME2 DEFAULT GETDATE()`.
- Check constraints enforce all enum-like fields (status, role, output_type, etc.).
- All schema statements are idempotent (`IF NOT EXISTS` guards) — re-running [sql/schema.sql](sql/schema.sql) is safe.
- Connection: `sqlsrv:Server=localhost;Database=edms;TrustServerCertificate=yes` with SQL auth (`sa` / `LineAnalytics@2025`).

### 4.2 Table Catalog (24 tables)

#### Master Data

| # | Table | Purpose |
|---|-------|---------|
| 1 | `users` | Auth + RBAC (admin/sales/planning/engineering/mfg/qc) |
| 2 | `product_codes` | Top-level products (`136ULT`, `171PT`, …). Optional `combined_attribute_id` enables pivot-style combined drawings. |
| 3 | `attributes` | Position-ordered attributes per product. Flags: `affects_engg_dwg`, `affects_internal_dwg`, `affects_sap_item`, `affects_test_cert`. |
| 4 | `attribute_options` | Concrete option codes per attribute, with optional JSON `dimension_modifiers`. |

#### Combination Engine

| # | Table | Purpose |
|---|-------|---------|
| 5 | `combination_matrix` | One row per *valid* combination per `output_type` (`engg_individual`, `engg_combined`, `internal_individual`, `internal_combined`, `sap_item`, `test_cert`). Identified by `combination_hash`. |
| 6 | `combination_options` | Junction: which attribute/option each combination contains. |
| 23 | `change_affected_orders` | Links a `change_event` to every SO line impacted. |
| 24 | `archived_combinations` | Snapshot of combos removed by a change, restorable. |

#### Template & Mapping

| # | Table | Purpose |
|---|-------|---------|
| 7 | `templates` | Uploaded DWG/DXF/XLSX files per product + `template_type`. |
| 8 | `template_anchor_config` | X/Y anchor for `model_code_table` and `dim_data_table` on the sheet. |
| 9 | `dim_table_columns` | Column definitions for the dimension table on engineering drawings. |
| 10 | `combination_template_map` | Combination ➜ template (+ optional `sap_item_code`). |
| 11 | `template_field_mappings` | DWG/Excel field name ➜ data source (`so_header`, `so_line`, `attribute_option`, `dimension_modifier`, `static`). |

#### Sales Order Pipeline

| # | Table | Purpose |
|---|-------|---------|
| 12 | `sales_orders` | SO header (customer, project, PO ref, delivery date). |
| 13 | `combined_drawing_groups` | Groups multiple line items that share common attributes for a combined drawing. |
| 14 | `so_line_items` | One row per item, holds `model_code_string`, status, individual/combined flags. |
| 15 | `so_line_selections` | Junction: line item ➜ attribute ➜ chosen option. |
| 16 | `generated_documents` | One per produced DXF/PDF (engg or internal), with `injected_values` JSON snapshot. |

#### Certificates

| # | Table | Purpose |
|---|-------|---------|
| 17 | `cert_templates` | Excel test-certificate templates per product. |
| 18 | `cert_field_mappings` | Excel cell ➜ source layer (`attribute`, `sales_order`, `mfg_input`). |
| 19 | `cert_option_text_map` | Translate option codes into prose for the certificate. |
| 20 | `cert_mfg_data` | Manufacturing inputs (serials, heats, compliance, inspector). |
| 21 | `generated_certificates` | Final certificate file + merged values JSON. |

#### Change Tracking

| # | Table | Purpose |
|---|-------|---------|
| 22 | `change_events` | Every attribute/option mutation (add, delete, flag-flip, deactivate). Stores `before_snapshot`/`after_snapshot` JSON and impact counts. |

### 4.3 Indexes

```sql
IX_attributes_product           (product_code_id)
IX_options_attribute            (attribute_id)
IX_combo_product_type           (product_code_id, output_type)
IX_combo_options_combo          (combination_id)
IX_so_lines_order               (sales_order_id)
IX_so_selections_line           (so_line_item_id)
IX_gen_docs_line                (so_line_item_id)
IX_change_events_product        (product_code_id)
```

### 4.4 Default Seed

[sql/schema.sql](sql/schema.sql) inserts an admin user `admin@edms.com` / `admin123`.  
[sql/seed_data.php](sql/seed_data.php) ingests the *Drawing Template Details.xlsx* workbook and produces:

- **136ULT** – Ultrasonic Level Transmitter — 8 attributes, 21 options.
- **171PT** – Pressure Transmitter — 9 attributes, 25 options.

---

## 5. Domain Concepts

### 5.1 Model Code

A model code is a hyphen-joined string of option codes, ordered by `attributes.position_in_model`.  
Example: `136ULT-A-T-01-08-1-31-1-01` decodes to:

| Pos | Attribute | Option |
|----|-----------|--------|
| 1 | Material | A (SS304) |
| 2 | Type | T (Top-mount) |
| 3 | Range | 01 (0–1 m) |
| ⋯ | ⋯ | ⋯ |

The product prefix (`136ULT`) is fixed; the remainder is a deterministic concatenation of `attribute_options.code` separated by `-`.

### 5.2 Combination Matrix

For each product and each `output_type` we pre-compute every valid permutation of options across attributes whose corresponding `affects_*` flag is set.

- `engg_individual`: cartesian over attributes where `affects_engg_dwg = 1`.
- `engg_combined`: cartesian *excluding* the product's `combined_attribute_id` (that attribute becomes a pivot column).
- `internal_individual` / `internal_combined`: same logic, gated by `affects_internal_dwg`.
- `sap_item`: gated by `affects_sap_item`.
- `test_cert`: gated by `affects_test_cert`.

Each combination is hashed (`combination_hash`) so re-generation is idempotent. Templates and SAP item codes are attached via `combination_template_map`.

> Example: 136ULT with all 8 attributes flagged for engg → 960 unique engg_individual combinations (2 × 2 × 4 × 6 × 2 × 5 × 2 × 1 etc., per the seeded options).

### 5.3 Combined vs Individual Drawings

- **Individual**: one DXF per line item.
- **Combined**: when several line items in the same SO share all common attributes except the `combined_attribute_id`, they are grouped (`combined_drawing_groups`) and one drawing is produced with a pivot column listing each variant's value (e.g. range, length, tag).

### 5.4 Document Generation Status Machine

`so_line_items.status`:

```
incomplete ─► resolved ─► generated ─► approved
                  │            │
                  ▼            ▼
              blocked     needs_revision
                              │
                              ▼
                         needs_update (after a change_event)
```

`generated_documents.status`: `generated → reviewed → approved | rejected`.

### 5.5 Change Events

Any modification to attributes or options writes a `change_events` row, computes `combos_added` / `combos_removed`, scans `so_line_items` for impact, and inserts `change_affected_orders` rows so the engineering team can review and resolve before the changes "go live".

---

## 6. Page-by-Page Specification

### 6.1 Dashboard — [index.php](index.php)
KPI cards (products, combinations, templates, SOs, generated docs, certificates), recent SOs, pending change banner, quick-action tiles.

### 6.2 Product Codes — [pages/product_codes.php](pages/product_codes.php)
CRUD for `product_codes`. Validates code with `[A-Za-z0-9 \-]+`. Each row exposes drill-down buttons to *Attributes*, *Options*, *Combinations*, *Anchors*, *Templates*.

### 6.3 Attributes — [pages/attributes.php](pages/attributes.php)
CRUD for `attributes` of a product. Mark `affects_engg_dwg`, `affects_internal_dwg`, `affects_sap_item`, `affects_test_cert`. Set `position_in_model` (used to build the model code) and `is_combined_attribute`.

### 6.4 Attribute Options — [pages/attribute_options.php](pages/attribute_options.php)
CRUD for `attribute_options`. Optional JSON `dimension_modifiers` overrides numeric fields (e.g. `{"L": 250, "OD": 60}`) used by the dimension table on the drawing.

### 6.5 Combinations — [pages/combinations.php](pages/combinations.php)
"Generate Combinations" button performs the cartesian product per `output_type`, writes `combination_matrix` and `combination_options`. Shows the matrix with template assignment dropdown (writes `combination_template_map`).

### 6.6 Templates — [pages/templates.php](pages/templates.php)
Upload DWG/DXF/XLSX. Stored under `uploads/templates/{product_id}/`. Tied to product + `template_type`.

On upload of a `.dwg` file, the page automatically:
1. Calls ODA File Converter to produce a sibling `.dxf` (cached for fast subsequent generation).
2. Scans the DXF for the placeholder convention (`<<CUSTOMER>>`, `<<SO_NO>>`, `<<MODEL_CODE_TABLE>>`, …) and reports which tags were found in the flash message.

A help banner at the top of the page documents the placeholder convention engineering must follow when authoring templates.

### 6.7 Template Field Mappings — [pages/template_fields.php](pages/template_fields.php)
Per-template editor: list of `dwg_field_name` rows mapped to `source_type` + `source_key`. Optional — primary field injection now uses the in-file `<<TAG>>` placeholder convention handled by `DWGTemplateProcessor`. This page remains for templates that prefer explicit field-by-field mapping or for future cert/Excel mappings.

### 6.8 Anchor Config — [pages/anchor_config.php](pages/anchor_config.php)
Set X/Y/width/row-height/max-rows for `model_code_table` and `dim_data_table` per product.

### 6.9 Sales Orders — [pages/sales_orders.php](pages/sales_orders.php)
List + create SO. Generates next `so_number` (e.g. `SO-2026-NNN`).

### 6.10 Sales Order Detail — [pages/sales_order_detail.php](pages/sales_order_detail.php)
The workhorse:

1. Add line items (product + qty).
2. *Build Model Code* opens [model_code_builder.php](pages/model_code_builder.php) — pickers for each attribute (writes `so_line_selections`, sets `model_code_string`, status → `resolved`).
3. *Generate Documents* iterates resolved/generated lines, looks up the matching combination & template (with fallback), inserts `generated_documents`, sets line status → `generated`.
4. Re-generation deletes prior docs for that line so re-runs are clean.

### 6.11 Drawings — [pages/drawings.php](pages/drawings.php)
Master list of `generated_documents` across all SOs.

### 6.12 Drawing Preview — [pages/drawing_preview.php](pages/drawing_preview.php)
Shows metadata + injected JSON, plus a *Download Drawing* button → `api/download.php?doc_id=…&format=dwg`. Defaults to DWG when a template is mapped; falls back to DXF when the from-scratch generator is used.

### 6.13 Certificates — [pages/certificates.php](pages/certificates.php)
List of `generated_certificates`; manufacturing-data entry routed through `cert_mfg_data`.

### 6.14 Changes — [pages/changes.php](pages/changes.php)
Lists `change_events` with status, impact counters, and *Confirm / Roll-back* actions.

---

## 7. APIs

| Endpoint | Method | Purpose |
|----------|--------|---------|
| [api/get_product_attributes.php](api/get_product_attributes.php) | GET `?product_id=` | Returns JSON `[ {id, code, name, options:[…]} ]` for the model-code builder. |
| [api/resolve_combination.php](api/resolve_combination.php) | GET `?product_id=&model_code=` | Looks up the matching `combination_matrix` row for a parsed model code. |
| [api/download.php](api/download.php) | GET `?doc_id=&format=dwg\|dxf` | Re-renders the drawing on every request (so updates are always reflected). If a DWG/DXF template is mapped to the document, runs the `DWGTemplateProcessor` (returns `application/acad` DWG by default). Otherwise falls back to the from-scratch `DXFGenerator` and returns `application/dxf`. Result is cached under `outputs/{SO}/line{N}/`. |

---

## 8. DWG Template Processor — [includes/DWGTemplateProcessor.php](includes/DWGTemplateProcessor.php)

The **primary** drawing engine. Round-trips a customer-authored DWG template through ODA File Converter, performs in-place data injection on the intermediate DXF, then converts back to DWG.

### 8.1 Pipeline

```
 template.dwg ──(ODA: DWG→DXF)──► template.dxf (text)
                                       │
                                       ▼
                  injectPlaceholders($dxf, $orderData)   ← strtr <<TAG>> → value
                                       │
                                       ▼
                  injectTable($dxf, '<<MODEL_CODE_TABLE>>', rows, headers)
                                       │
                                       ▼
                  injectTable($dxf, '<<DIM_TABLE>>',        rows, headers)
                                       │
                                       ▼
                              produced.dxf
                                       │
                            (ODA: DXF→DWG)
                                       ▼
                              produced.dwg  ── streamed to browser
```

### 8.2 Placeholder Convention

Engineering authors each DWG template once with the following literal `TEXT` entities placed in the title block. They are replaced with live data at generation time:

| Tag | Replaced with |
|-----|---------------|
| `<<CUSTOMER>>` | `sales_orders.customer_name` |
| `<<PROJECT>>` | `sales_orders.project_name` |
| `<<SO_NO>>` | `sales_orders.so_number` |
| `<<PO_REF>>` | `sales_orders.po_reference` |
| `<<MODEL_CODE>>` / `<<DRG_NO>>` | `so_line_items.model_code_string` |
| `<<PRODUCT>>` | `product_codes.code` |
| `<<PRODUCT_NAME>>` | `product_codes.name` |
| `<<DATE>>` | today (`d-m-Y`) |
| `<<DELIVERY>>` | `sales_orders.delivery_date` |
| `<<QTY>>` | `so_line_items.quantity` |

Additionally, the engineer drops two **anchor** TEXT entities at the top-left corner of each table area:

| Anchor | Drawn as |
|--------|----------|
| `<<MODEL_CODE_TABLE>>` | Grid: `Pos · Attribute · Code · Value`, one row per attribute selection. |
| `<<DIM_TABLE>>` | Grid: `Ref · Attribute · Option · Value` (or columns from `dim_table_columns` if configured). |

The processor reads the X/Y/layer of the anchor TEXT entity, deletes it, then renders LINEs and TEXTs starting at that coordinate, inheriting the layer.

### 8.3 ODA File Converter Invocation

The converter is a GUI batch tool. The processor stages files in a per-call temp folder and shells out:

```
ODAFileConverter.exe <inFolder> <outFolder> ACAD2018 DWG|DXF 0 1
```

A brief progress dialog flashes; on a server desktop session this is harmless. The output version is configurable via `ODA_OUTPUT_VERSION` in [config/database.php](config/database.php).

### 8.4 DXF Format Sensitivity

The injection logic operates on raw DXF text, so it relies on these invariants:

- **Group codes** are right-justified into a 3-character field (`"  0"`, `"  1"`, `" 10"`).
- **Values** (e.g. layer name `"0"`) are NOT padded.
- This distinction is what lets the parser tell an entity boundary (`"  0"`) from a literal value of `"0"`. Discovered the hard way during development; both `findAnchorPosition()` and `removeAnchorEntity()` rely on the `isGroupCode()` helper for safety.
- Output uses **CRLF** line endings throughout to match what ODA emits.

### 8.5 Public API

```php
$proc = new DWGTemplateProcessor();
if (!$proc->isAvailable()) { /* ODA not installed */ }

$producedPath = $proc->process(
    $templatePath,   // .dwg or .dxf
    $orderData,      // see DXFGenerator key list (same shape)
    $selections,     // rows from so_line_selections + attribute_options
    $dimColumns,     // rows from dim_table_columns (may be empty)
    $outDir,         // outputs/{SO}/line{N}/
    $outBaseName,    // file name without extension
    'dwg'            // 'dwg' (default) or 'dxf'
);
```

---

## 9. DXF Generator (Fallback) — [includes/DXFGenerator.php](includes/DXFGenerator.php)

Used automatically when **no template is mapped** to the document, or when ODA is unavailable. Builds a self-contained A3 sheet from scratch.

### 9.1 Format

- AutoCAD **R12** (`$ACADVER = AC1009`) — the most universally accepted DXF dialect.
- ASCII, **CRLF** line endings.
- Group codes formatted as 3-character right-justified integers (`sprintf("%3d", $code)`).
- Sections: `HEADER`, `TABLES` (LTYPE, LAYER, STYLE), `BLOCKS`, `ENTITIES`, `EOF`.

### 9.2 Sheet Layout (A3 landscape, 420 × 297 mm)

```
┌──────────────────────────────────────────────────────────────────────┐
│  outer + inner border (BORDER layer)                                 │
│                                                                      │
│  ┌────────────────────────┐    ┌──────────────────────────────────┐ │
│  │   MODEL CODE TABLE     │    │   DIMENSION TABLE (per option)   │ │
│  │   pos | attr | option  │    │   ref | label | value | unit     │ │
│  └────────────────────────┘    └──────────────────────────────────┘ │
│                                                                      │
│  NOTES (NOTES layer)                                                 │
│                                                                      │
│  ┌──────────────────────────── TITLE BLOCK ──────────────────────┐  │
│  │ DRAWN/CHK/APPD/DATE/SCALE/DRG NO/REV/TITLE  |  Customer/SO/PO │  │
│  └────────────────────────────────────────────────────────────────┘  │
└──────────────────────────────────────────────────────────────────────┘
```

### 9.3 Layers

| Layer | Color | Used for |
|-------|-------|----------|
| `0` | 7 (white) | default |
| `BORDER` | 8 (grey) | sheet border |
| `TITLEBLOCK` | 3 (green) | title-block grid + text |
| `MODELCODE` | 4 (cyan) | model-code table |
| `DIMTABLE` | 5 (blue) | dimension table |
| `NOTES` | 2 (yellow) | textual notes |

### 9.4 Public API

```php
$dxf = new DXFGenerator();
$content = $dxf->generate($orderData, $selections, $dimColumns);
file_put_contents($path, $content);
```

`$orderData` keys: `model_code`, `product_code`, `product_name`, `customer_name`, `project_name`, `so_number`, `po_reference`, `delivery_date`, `quantity`.  
`$selections` rows: `attr_code`, `attr_name`, `position_in_model`, `opt_code`, `opt_value`, `display_label`, `dimension_modifiers` (JSON).  
`$dimColumns` rows from `dim_table_columns`.

Verified compatible with **DraftSight**, **AutoCAD**, **LibreCAD**.

---

## 9. End-to-End Workflow

```
1. Admin       → Define product, attributes, options       → product_codes.php / attributes.php / attribute_options.php
2. Admin       → Generate combination matrix                → combinations.php
3. Engineering → Upload DWG template, set anchors, map     → templates.php / anchor_config.php / template_fields.php
                 each combination to a template
4. Sales       → Create SO, add line items                  → sales_orders.php / sales_order_detail.php
5. Sales       → Build model code per line                  → model_code_builder.php
6. Engineering → Click "Generate Documents"                 → sales_order_detail.php
                 ┌──────────────────────────────────────────┐
                 │ • Loops resolved/generated lines         │
                 │ • Computes combination_hash from         │
                 │   so_line_selections                     │
                 │ • Finds template via                     │
                 │   combination_template_map (with         │
                 │   fallback to any product template)      │
                 │ • Calls DXFGenerator                     │
                 │ • Writes outputs/{SO}/...dxf             │
                 │ • Inserts generated_documents row        │
                 │ • Sets line.status = 'generated'         │
                 └──────────────────────────────────────────┘
7. Engineering → Preview + Download DXF                     → drawing_preview.php → api/download.php
8. QC / MFG    → Enter mfg data                             → cert_mfg_data
9. QC          → Generate certificate                       → certificates.php
10. Any change → change_events + change_affected_orders     → changes.php (resolve before pipeline runs)
```

---

## 11. Setup & Run

### 11.1 Prerequisites

- XAMPP with PHP 8.2+ (`C:\xampp\php\php.exe`).
- PHP extensions: `pdo_sqlsrv`, `sqlsrv` (verify with `php -m`).
- MS SQL Server 2017+ reachable on `localhost`, mixed-mode auth.
- **ODA File Converter** at `C:\Program Files\ODA\ODAFileConverter\ODAFileConverter.exe` (free download from openddesign.com). Required for the template-based engine; without it the system falls back to the from-scratch DXF generator.
- DraftSight / AutoCAD / LibreCAD to view generated `.dwg` / `.dxf` files.

### 11.2 First-time install

```powershell
# 1. Drop the project under XAMPP htdocs
#    C:\xampp\htdocs\edms\

# 2. Edit credentials if needed
notepad C:\xampp\htdocs\edms\config\database.php

# 3. Create the database + schema
sqlcmd -S localhost -U sa -P "LineAnalytics@2025" -i C:\xampp\htdocs\edms\sql\schema.sql

# 4. Seed the master data
C:\xampp\php\php.exe C:\xampp\htdocs\edms\sql\seed_data.php
C:\xampp\php\php.exe C:\xampp\htdocs\edms\sql\fix_flags.php

# 5. Start XAMPP Apache, then visit
start http://localhost/edms/
```

### 11.3 Smoke tests

```powershell
# Fallback DXF engine end-to-end (no template required)
C:\xampp\php\php.exe C:\xampp\htdocs\edms\sql\run_full_flow.php
# → Creates SO-2026-TEST-001 and emits 4 valid DXFs in outputs\SO_2026_TEST_001\

# DWG template engine end-to-end (uses one of the bundled SBEM DWG templates)
C:\xampp\php\php.exe C:\xampp\htdocs\edms\sql\test_template_processor.php
# → Reports DWG→DXF size, placeholder detection, and writes
#   outputs\_template_test\TEST_OUT.dwg (~495 KB)

# Inspect a candidate template before uploading via the UI
C:\xampp\php\php.exe C:\xampp\htdocs\edms\sql\prepare_template.php "C:\path\to\my_template.dwg"
# → Lists which <<TAG>> placeholders are present
```

### 11.4 Default credentials

| Role | Email | Password |
|------|-------|----------|
| Admin | admin@edms.com | admin123 |

---

## 12. Coding Conventions

- **PHP**: procedural per page, strict types where used; PDO prepared statements only; UUIDs from `generateUUID()` in [config/database.php](config/database.php) when not relying on `NEWID()`.
- **HTML output** is always passed through `sanitize()` (`htmlspecialchars` + trim).
- **Flash messages** via `setFlash()` / `getFlash()` in `$_SESSION`.
- **JSON APIs** always return through `jsonResponse()` for consistent headers.
- **No global state** beyond `$_SESSION` and the static `$pdo` in `getDB()`.
- **CSS** uses CSS custom properties under `:root` for the entire palette — see [assets/css/style.css](assets/css/style.css).
- **JS** is plain ES5/ES6 in [assets/js/app.js](assets/js/app.js); no bundler.

---

## 13. Security Notes

| Concern | Status |
|---------|--------|
| SQL injection | All queries use parameterized PDO statements. |
| XSS | All dynamic output passes through `sanitize()`. |
| Session fixation | `session.use_strict_mode = 1`; `session.cookie_httponly = 1`. |
| CSRF | **Not implemented yet** — internal LAN tool. Add `csrf_token` per form before any external exposure. |
| AuthN/AuthZ | Schema supports roles via `users.role`; login flow itself is **not yet wired** into pages — pending. |
| Upload validation | `templates.php` accepts only `dwg/dxf/xlsx` extensions; MIME re-check recommended before production. |
| Secrets | DB password is hard-coded in [config/database.php](config/database.php). Move to environment variable or `.ini` outside webroot before any deployment. |

---

## 14. Known Gaps / Backlog

1. **Login UI** — schema present, controllers absent. All pages currently open without auth.
2. **CSRF tokens** on all POST forms.
3. **Excel certificate generation** — schema (`cert_*`) ready, generator class not yet implemented (mirror the template processor using PhpSpreadsheet).
4. **Combined drawings** — schema supports `combined_drawing_groups`; the grouping logic is not yet wired into `sales_order_detail.php`.
5. **Change-event auto-impact** — `change_events` rows can be inserted manually, but the trigger logic on attribute/option mutations is not yet hooked into the CRUD pages.
6. **Audit log** for every user action.
7. **Background job runner** for bulk regeneration after change-event confirmation.
8. **Template authoring** — the existing SBEM DWG templates need to be opened once in DraftSight to add the `<<TAG>>` placeholder TEXT entities and the two table anchors. Until that is done, generation falls back to the synthetic A3 DXF.

---

## 15. Maintenance Scripts

| Script | When to run |
|--------|-------------|
| [sql/seed_data.php](sql/seed_data.php) | Fresh install — load 136ULT + 171PT from Excel. |
| [sql/fix_flags.php](sql/fix_flags.php) | After seeding — set `affects_*_dwg = 1` so combinations generate. |
| [sql/fix_product_merge.php](sql/fix_product_merge.php) | If the same product was created twice (e.g. with/without spaces). |
| [sql/fix_doc_template.php](sql/fix_doc_template.php) | Backfill `template_id` on legacy `generated_documents`. |
| [sql/run_full_flow.php](sql/run_full_flow.php) | Smoke test the entire pipeline (fallback DXF engine). |
| [sql/test_dxf.php](sql/test_dxf.php) | Test the from-scratch DXF generator standalone. |
| [sql/test_template_processor.php](sql/test_template_processor.php) | Test the DWG template processor end-to-end against a real DWG. |
| [sql/prepare_template.php](sql/prepare_template.php) | CLI utility — inspect a DWG/DXF and report which `<<TAG>>` placeholders are present. |
| [sql/debug_files.php](sql/debug_files.php) | List generated outputs on disk. |

These are safe to remove once the team is comfortable with the workflow.

---

## 16. Verified Demo Artefacts (April 2026)

**Fallback DXF engine** — [sql/run_full_flow.php](sql/run_full_flow.php):
- SO `SO-2026-TEST-001` — Tata Projects Ltd / Jamnagar Refinery.
- 2 line items: `136ULT-A-T-01-08-1-31-1-01` and `136ULT-C-M-02-15-2-XX-X-01`.
- 4 generated DXF files (~18 KB each) in `outputs\SO_2026_TEST_001\`.
- All open cleanly in DraftSight with title block, model code table, dimension table, and notes populated from live SO data.

**DWG template engine** — [sql/test_template_processor.php](sql/test_template_processor.php):
- Source: `JO-136-0939-R00_136 ULT.dwg` (3.5 MB customer template, AutoCAD 2018).
- Round-trip: DWG → DXF (3,514,235 B) → inject → DXF (3,519,158 B) → DWG (495,197 B).
- All 5 test placeholders (`<<CUSTOMER>>`, `<<SO_NO>>`, `<<MODEL_CODE>>`, `<<MODEL_CODE_TABLE>>`, `<<DIM_TABLE>>`) found pre-injection and replaced/removed post-injection.
- Output verified to contain `Tata Projects Ltd`, `SO-2026-TEST-101`, `136ULT-A-T-01-08-1-31-1-01`. ODA validation produced zero `.err` files — DWG is well-formed.

---

## 17. Change Log

| Version | Date | Notes |
|---------|------|-------|
| 1.0.0 | Apr 2026 | Initial release: from-scratch DXF generator only. |
| 1.1.0 | Apr 2026 | Added DWG template processor: ODA round-trip + `<<TAG>>` placeholder substitution + table injection at named anchors. From-scratch DXF generator retained as fallback. Output is now `.dwg` by default; falls back to `.dxf` when no template is mapped or ODA is unavailable. |

---

*End of document.*
