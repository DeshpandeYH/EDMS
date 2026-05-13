-- ============================================================
-- EDMS - Engineering Drawing Management System
-- MS SQL Server Schema
-- ============================================================

-- Create Database
IF NOT EXISTS (SELECT * FROM sys.databases WHERE name = 'edms')
BEGIN
    CREATE DATABASE edms;
END
GO

USE edms;
GO

-- ============================================================
-- 1. USERS TABLE
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'users')
CREATE TABLE users (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    email VARCHAR(200) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL CHECK (role IN ('admin','sales','planning','engineering','mfg','qc')),
    department VARCHAR(50),
    is_active BIT DEFAULT 1,
    created_at DATETIME2 DEFAULT GETDATE(),
    updated_at DATETIME2 DEFAULT GETDATE()
);
GO

-- ============================================================
-- 2. PRODUCT CODES
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'product_codes')
CREATE TABLE product_codes (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    code VARCHAR(10) NOT NULL UNIQUE,
    name VARCHAR(200) NOT NULL,
    category VARCHAR(50),
    supports_combined_dwg BIT DEFAULT 0,
    combined_attribute_id UNIQUEIDENTIFIER NULL,
    description NVARCHAR(MAX),
    status VARCHAR(20) DEFAULT 'draft' CHECK (status IN ('active','draft','archived','incomplete')),
    created_at DATETIME2 DEFAULT GETDATE(),
    updated_at DATETIME2 DEFAULT GETDATE()
);
GO

-- ============================================================
-- 3. ATTRIBUTES
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'attributes')
CREATE TABLE attributes (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    product_code_id UNIQUEIDENTIFIER NOT NULL REFERENCES product_codes(id),
    code VARCHAR(10) NOT NULL,
    name VARCHAR(100) NOT NULL,
    position_in_model INT NOT NULL,
    is_combined_attribute BIT DEFAULT 0,
    affects_engg_dwg BIT DEFAULT 0,
    affects_internal_dwg BIT DEFAULT 0,
    affects_sap_item BIT DEFAULT 0,
    affects_test_cert BIT DEFAULT 0,
    sort_order INT DEFAULT 0,
    is_active BIT DEFAULT 1,
    created_at DATETIME2 DEFAULT GETDATE(),
    updated_at DATETIME2 DEFAULT GETDATE(),
    UNIQUE(product_code_id, code)
);
GO

-- Add FK for combined_attribute_id
ALTER TABLE product_codes ADD CONSTRAINT FK_product_combined_attr 
    FOREIGN KEY (combined_attribute_id) REFERENCES attributes(id);
GO

-- ============================================================
-- 4. ATTRIBUTE OPTIONS
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'attribute_options')
CREATE TABLE attribute_options (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    attribute_id UNIQUEIDENTIFIER NOT NULL REFERENCES attributes(id),
    code VARCHAR(10) NOT NULL,
    value VARCHAR(100) NOT NULL,
    display_label VARCHAR(200),
    dimension_modifiers NVARCHAR(MAX), -- JSON
    sort_order INT DEFAULT 0,
    is_active BIT DEFAULT 1,
    created_at DATETIME2 DEFAULT GETDATE(),
    UNIQUE(attribute_id, code)
);
GO

-- ============================================================
-- 5. COMBINATION MATRIX
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'combination_matrix')
CREATE TABLE combination_matrix (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    product_code_id UNIQUEIDENTIFIER NOT NULL REFERENCES product_codes(id),
    output_type VARCHAR(30) NOT NULL CHECK (output_type IN (
        'engg_individual','engg_combined','internal_individual',
        'internal_combined','sap_item','test_cert'
    )),
    combination_key VARCHAR(100) NOT NULL,
    combination_hash VARCHAR(64) NOT NULL,
    is_active BIT DEFAULT 1,
    created_at DATETIME2 DEFAULT GETDATE(),
    UNIQUE(combination_hash, output_type)
);
GO

-- ============================================================
-- 6. COMBINATION OPTIONS (junction)
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'combination_options')
CREATE TABLE combination_options (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    combination_id UNIQUEIDENTIFIER NOT NULL REFERENCES combination_matrix(id),
    attribute_id UNIQUEIDENTIFIER NOT NULL REFERENCES attributes(id),
    option_id UNIQUEIDENTIFIER NOT NULL REFERENCES attribute_options(id)
);
GO

-- ============================================================
-- 7. TEMPLATES
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'templates')
CREATE TABLE templates (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    product_code_id UNIQUEIDENTIFIER NOT NULL REFERENCES product_codes(id),
    template_type VARCHAR(30) NOT NULL CHECK (template_type IN (
        'engg_individual','engg_combined','internal_individual',
        'internal_combined','test_cert'
    )),
    template_code VARCHAR(50) NOT NULL,
    file_path VARCHAR(500),
    file_format VARCHAR(10) CHECK (file_format IN ('dwg','dxf','xlsx')),
    version INT DEFAULT 1,
    is_active BIT DEFAULT 1,
    uploaded_at DATETIME2 DEFAULT GETDATE(),
    uploaded_by UNIQUEIDENTIFIER NULL REFERENCES users(id)
);
GO

-- ============================================================
-- 8. TEMPLATE ANCHOR CONFIG
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'template_anchor_config')
CREATE TABLE template_anchor_config (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    product_code_id UNIQUEIDENTIFIER NOT NULL REFERENCES product_codes(id),
    anchor_type VARCHAR(30) NOT NULL CHECK (anchor_type IN ('model_code_table','dim_data_table')),
    x_coord DECIMAL(10,2) DEFAULT 0,
    y_coord DECIMAL(10,2) DEFAULT 0,
    table_width_mm DECIMAL(10,2) DEFAULT 120,
    row_height_mm DECIMAL(10,2) DEFAULT 6,
    max_data_rows INT DEFAULT 12,
    created_at DATETIME2 DEFAULT GETDATE()
);
GO

-- ============================================================
-- 9. DIM TABLE COLUMNS
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'dim_table_columns')
CREATE TABLE dim_table_columns (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    product_code_id UNIQUEIDENTIFIER NOT NULL REFERENCES product_codes(id),
    column_label VARCHAR(100) NOT NULL,
    ref_marker VARCHAR(5),
    dimension_key VARCHAR(50),
    source_type VARCHAR(30) CHECK (source_type IN ('dimension_modifier','sap_item_code','quantity','so_field')),
    width_pct INT DEFAULT 15,
    show_in_engg BIT DEFAULT 1,
    show_in_internal BIT DEFAULT 1,
    sort_order INT DEFAULT 0
);
GO

-- ============================================================
-- 10. COMBINATION TEMPLATE MAP
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'combination_template_map')
CREATE TABLE combination_template_map (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    combination_id UNIQUEIDENTIFIER NOT NULL REFERENCES combination_matrix(id),
    template_id UNIQUEIDENTIFIER NULL REFERENCES templates(id),
    sap_item_code VARCHAR(50),
    created_at DATETIME2 DEFAULT GETDATE()
);
GO

-- ============================================================
-- 11. TEMPLATE FIELD MAPPINGS
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'template_field_mappings')
CREATE TABLE template_field_mappings (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    template_id UNIQUEIDENTIFIER NOT NULL REFERENCES templates(id),
    dwg_field_name VARCHAR(100) NOT NULL,
    source_type VARCHAR(30) CHECK (source_type IN ('so_header','so_line','attribute_option','dimension_modifier','static')),
    source_key VARCHAR(100),
    is_editable BIT DEFAULT 0,
    default_value VARCHAR(200)
);
GO

-- ============================================================
-- 12. SALES ORDERS
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'sales_orders')
CREATE TABLE sales_orders (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    so_number VARCHAR(20) NOT NULL UNIQUE,
    customer_name VARCHAR(200) NOT NULL,
    project_name VARCHAR(200),
    po_reference VARCHAR(50),
    delivery_date DATE,
    status VARCHAR(20) DEFAULT 'draft' CHECK (status IN ('draft','in_progress','completed','approved','cancelled')),
    created_by UNIQUEIDENTIFIER NULL REFERENCES users(id),
    created_at DATETIME2 DEFAULT GETDATE(),
    updated_at DATETIME2 DEFAULT GETDATE()
);
GO

-- ============================================================
-- 13. COMBINED DRAWING GROUPS
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'combined_drawing_groups')
CREATE TABLE combined_drawing_groups (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    sales_order_id UNIQUEIDENTIFIER NOT NULL REFERENCES sales_orders(id),
    product_code_id UNIQUEIDENTIFIER NOT NULL REFERENCES product_codes(id),
    common_attrs_hash VARCHAR(64),
    common_attrs_display VARCHAR(200),
    pivot_values NVARCHAR(MAX), -- JSON
    created_at DATETIME2 DEFAULT GETDATE()
);
GO

-- ============================================================
-- 14. SO LINE ITEMS
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'so_line_items')
CREATE TABLE so_line_items (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    sales_order_id UNIQUEIDENTIFIER NOT NULL REFERENCES sales_orders(id),
    product_code_id UNIQUEIDENTIFIER NOT NULL REFERENCES product_codes(id),
    line_number INT NOT NULL,
    model_code_string VARCHAR(100),
    quantity INT DEFAULT 1,
    generate_individual BIT DEFAULT 1,
    generate_combined BIT DEFAULT 0,
    combined_group_id UNIQUEIDENTIFIER NULL REFERENCES combined_drawing_groups(id),
    status VARCHAR(20) DEFAULT 'incomplete' CHECK (status IN ('incomplete','resolved','generated','approved','blocked','needs_update','needs_revision')),
    created_at DATETIME2 DEFAULT GETDATE(),
    updated_at DATETIME2 DEFAULT GETDATE()
);
GO

-- ============================================================
-- 15. SO LINE SELECTIONS
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'so_line_selections')
CREATE TABLE so_line_selections (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    so_line_item_id UNIQUEIDENTIFIER NOT NULL REFERENCES so_line_items(id),
    attribute_id UNIQUEIDENTIFIER NOT NULL REFERENCES attributes(id),
    option_id UNIQUEIDENTIFIER NOT NULL REFERENCES attribute_options(id)
);
GO

-- ============================================================
-- 16. GENERATED DOCUMENTS
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'generated_documents')
CREATE TABLE generated_documents (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    so_line_item_id UNIQUEIDENTIFIER NULL REFERENCES so_line_items(id),
    combined_group_id UNIQUEIDENTIFIER NULL REFERENCES combined_drawing_groups(id),
    template_id UNIQUEIDENTIFIER NULL REFERENCES templates(id),
    document_type VARCHAR(30) CHECK (document_type IN (
        'engg_individual','engg_combined','internal_individual',
        'internal_combined','test_cert'
    )),
    sap_item_code VARCHAR(50),
    output_file_path VARCHAR(500),
    injected_values NVARCHAR(MAX), -- JSON
    status VARCHAR(20) DEFAULT 'generated' CHECK (status IN ('generated','reviewed','approved','rejected')),
    generated_at DATETIME2 DEFAULT GETDATE(),
    approved_by UNIQUEIDENTIFIER NULL REFERENCES users(id),
    approved_at DATETIME2 NULL
);
GO

-- ============================================================
-- 17. CERT TEMPLATES
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'cert_templates')
CREATE TABLE cert_templates (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    product_code_id UNIQUEIDENTIFIER NOT NULL REFERENCES product_codes(id),
    template_code VARCHAR(50) NOT NULL,
    file_path VARCHAR(500),
    version INT DEFAULT 1,
    is_active BIT DEFAULT 1,
    uploaded_at DATETIME2 DEFAULT GETDATE()
);
GO

-- ============================================================
-- 18. CERT FIELD MAPPINGS
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'cert_field_mappings')
CREATE TABLE cert_field_mappings (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    cert_template_id UNIQUEIDENTIFIER NOT NULL REFERENCES cert_templates(id),
    excel_cell VARCHAR(10) NOT NULL,
    field_label VARCHAR(100),
    source_layer VARCHAR(20) CHECK (source_layer IN ('attribute','sales_order','mfg_input')),
    source_key VARCHAR(100),
    data_type VARCHAR(20) CHECK (data_type IN ('text','number','date','pass_fail')),
    is_required BIT DEFAULT 0,
    is_repeating BIT DEFAULT 0,
    repeat_start_row INT NULL
);
GO

-- ============================================================
-- 19. CERT OPTION TEXT MAP
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'cert_option_text_map')
CREATE TABLE cert_option_text_map (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    cert_field_mapping_id UNIQUEIDENTIFIER NOT NULL REFERENCES cert_field_mappings(id),
    attribute_id UNIQUEIDENTIFIER NOT NULL REFERENCES attributes(id),
    option_id UNIQUEIDENTIFIER NOT NULL REFERENCES attribute_options(id),
    output_text VARCHAR(500) NOT NULL
);
GO

-- ============================================================
-- 20. CERT MFG DATA
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'cert_mfg_data')
CREATE TABLE cert_mfg_data (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    so_line_item_id UNIQUEIDENTIFIER NOT NULL REFERENCES so_line_items(id),
    serial_entries NVARCHAR(MAX), -- JSON
    heat_numbers NVARCHAR(MAX), -- JSON
    compliance_checks NVARCHAR(MAX), -- JSON
    inspector_name VARCHAR(100),
    inspection_date DATE,
    is_complete BIT DEFAULT 0,
    entered_by UNIQUEIDENTIFIER NULL REFERENCES users(id),
    entered_at DATETIME2 DEFAULT GETDATE()
);
GO

-- ============================================================
-- 21. GENERATED CERTIFICATES
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'generated_certificates')
CREATE TABLE generated_certificates (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    so_line_item_id UNIQUEIDENTIFIER NOT NULL REFERENCES so_line_items(id),
    cert_template_id UNIQUEIDENTIFIER NOT NULL REFERENCES cert_templates(id),
    certificate_number VARCHAR(50) NOT NULL UNIQUE,
    output_file_path VARCHAR(500),
    merged_values NVARCHAR(MAX), -- JSON
    inspector_name VARCHAR(100),
    inspection_date DATE,
    status VARCHAR(20) DEFAULT 'generated' CHECK (status IN ('generated','approved','sent')),
    generated_at DATETIME2 DEFAULT GETDATE()
);
GO

-- ============================================================
-- 22. CHANGE EVENTS
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'change_events')
CREATE TABLE change_events (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    product_code_id UNIQUEIDENTIFIER NOT NULL REFERENCES product_codes(id),
    change_type VARCHAR(30) NOT NULL CHECK (change_type IN (
        'attr_add','attr_delete','attr_flag_change',
        'opt_add','opt_deactivate','opt_reactivate'
    )),
    attribute_id UNIQUEIDENTIFIER NULL REFERENCES attributes(id),
    option_id UNIQUEIDENTIFIER NULL REFERENCES attribute_options(id),
    before_snapshot NVARCHAR(MAX), -- JSON
    after_snapshot NVARCHAR(MAX), -- JSON
    combos_added INT DEFAULT 0,
    combos_removed INT DEFAULT 0,
    affected_so_count INT DEFAULT 0,
    status VARCHAR(20) DEFAULT 'pending' CHECK (status IN ('pending','confirmed','rolled_back')),
    confirmed_by UNIQUEIDENTIFIER NULL REFERENCES users(id),
    created_at DATETIME2 DEFAULT GETDATE(),
    confirmed_at DATETIME2 NULL
);
GO

-- ============================================================
-- 23. CHANGE AFFECTED ORDERS
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'change_affected_orders')
CREATE TABLE change_affected_orders (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    change_event_id UNIQUEIDENTIFIER NOT NULL REFERENCES change_events(id),
    so_line_item_id UNIQUEIDENTIFIER NOT NULL REFERENCES so_line_items(id),
    impact_type VARCHAR(30) CHECK (impact_type IN ('needs_new_selection','auto_shortened','blocked','needs_regeneration')),
    previous_model_code VARCHAR(100),
    resolution_status VARCHAR(20) DEFAULT 'pending' CHECK (resolution_status IN ('pending','resolved','waived')),
    resolved_at DATETIME2 NULL,
    resolved_by UNIQUEIDENTIFIER NULL REFERENCES users(id)
);
GO

-- ============================================================
-- 24. ARCHIVED COMBINATIONS
-- ============================================================
IF NOT EXISTS (SELECT * FROM sys.tables WHERE name = 'archived_combinations')
CREATE TABLE archived_combinations (
    id UNIQUEIDENTIFIER PRIMARY KEY DEFAULT NEWID(),
    change_event_id UNIQUEIDENTIFIER NOT NULL REFERENCES change_events(id),
    original_combination_id UNIQUEIDENTIFIER NOT NULL,
    output_type VARCHAR(30),
    combination_key VARCHAR(100),
    template_id UNIQUEIDENTIFIER NULL,
    sap_item_code VARCHAR(50),
    full_snapshot NVARCHAR(MAX), -- JSON
    archived_at DATETIME2 DEFAULT GETDATE(),
    restorable BIT DEFAULT 1
);
GO

-- ============================================================
-- INDEXES
-- ============================================================
CREATE INDEX IX_attributes_product ON attributes(product_code_id);
CREATE INDEX IX_options_attribute ON attribute_options(attribute_id);
CREATE INDEX IX_combo_product_type ON combination_matrix(product_code_id, output_type);
CREATE INDEX IX_combo_options_combo ON combination_options(combination_id);
CREATE INDEX IX_so_lines_order ON so_line_items(sales_order_id);
CREATE INDEX IX_so_selections_line ON so_line_selections(so_line_item_id);
CREATE INDEX IX_gen_docs_line ON generated_documents(so_line_item_id);
CREATE INDEX IX_change_events_product ON change_events(product_code_id);
GO

-- ============================================================
-- DEFAULT ADMIN USER (password: admin123)
-- ============================================================
INSERT INTO users (id, email, name, password_hash, role, department, is_active)
VALUES (NEWID(), 'admin@edms.com', 'System Admin', 
    '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 
    'admin', 'IT', 1);
GO

PRINT 'EDMS Schema created successfully.';
GO
