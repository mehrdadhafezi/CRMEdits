# Declared database schema audit

مشاهده‌شده: DDL ثابت از PHP نسخه 2.0.235 استخراج شده؛ هیچ اتصال دیتابیس برقرار نشد. این سند schema اجراشده Production نیست. `UNRESOLVED` یعنی نام با expression/متغیر ساخته می‌شود و باید در فایل مرجع دنبال شود. `ALTER TABLE`های شرطی نیز در کد وجود دارند و DDL اولیه به‌تنهایی schema نهایی را اثبات نمی‌کند.

## Data ownership and logical relations

| Family | Technical owner | Logical relation | Sensitive fields | Read/write evidence |
|---|---|---|---|---|
| leads / distribution / MIS | MIS + Seller + Core | source row → pool → distribution item → live lead | phone, names, address, history | SN_MIS_Service, SN_Plugin, SN_Seller_Flow |
| invoices / invoice_items / payments | Invoice + Finance | invoice_id, lead_id, seller_id, stage | token, phone, amount, receipt URL | SN_Invoice, SN_Plugin, SN_Payment_Batch, SN_Receipt_Review |
| hr / assignments | HR | profile.user_id → WordPress users; parent/child and effective history | national_id, salary, phone | SN_HR_Service, SN_Hierarchy_Service, SN_HR_Transfer |
| wallets / commission | Finance + Commission | user/invoice/run/transaction identifiers | balances, compensation | SN_Plugin, SN_Purpose_Commission |
| projects / operations / subscription | Corresponding module | membership/project/source invoice identifiers | chat, files, credit, customer snapshot | Module classes listed in contracts |
| campaign / integrations / reports | Campaign + Integrations + Reporting | partner allowlist, typed subject and invoice state | token hash, identity hash, payload_json | SN_Campaign_Tracking, SN_Plus_API, SN_Patra_Plus_Records |

این پیوندها منطقی‌اند؛ Foreign Key اجراشده اثبات نشده است. در هر DDL زیر PRIMARY KEY، UNIQUE و KEY عین declaration آمده‌اند؛ نبودن در declaration به معنی نبودن index در سرور نیست. مالک حقوقی داده شرکت است و مسئول فنی از ماژول تعیین می‌شود.

## DDL declarations

### 1. `sn_report_invoice_state_events`

Source: [includes/class-sn-activator.php:93](../../includes/class-sn-activator.php#L93) — module `CORE_AUTH`. Identifier expression: `{$table}`.

```sql
CREATE TABLE {$table} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			seller_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			state VARCHAR(60) NOT NULL,
			actor_user_id BIGINT UNSIGNED DEFAULT NULL,
			source_activity_id BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY source_activity_id (source_activity_id),
			KEY invoice_time (invoice_id,created_at),
			KEY state_time (state,created_at),
			KEY seller_time (seller_id,created_at)
)
```

### 2. `sn_leads`

Source: [includes/class-sn-activator.php:237](../../includes/class-sn-activator.php#L237) — module `CORE_AUTH`. Identifier expression: `{$wpdb->prefix}sn_leads`.

```sql
CREATE TABLE {$wpdb->prefix}sn_leads (
id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			phone        VARCHAR(20)     NOT NULL,
			customer_name VARCHAR(120)    DEFAULT NULL,
			national_id VARCHAR(20)       DEFAULT NULL,
			marketing_form_id VARCHAR(80) DEFAULT NULL,
			import_code  VARCHAR(80)     DEFAULT NULL,
			province     VARCHAR(60)     DEFAULT NULL,
			city         VARCHAR(60)     DEFAULT NULL,
			status       VARCHAR(20)     NOT NULL DEFAULT 'unassigned',
			lead_status  VARCHAR(60)     DEFAULT NULL,
			sales_prediction VARCHAR(120) DEFAULT NULL,
			destination_panel VARCHAR(50) DEFAULT NULL,
			destination_routed_at DATETIME DEFAULT NULL,
			note         TEXT            DEFAULT NULL,
			seller_id    BIGINT UNSIGNED DEFAULT NULL,
			supervisor_id BIGINT UNSIGNED DEFAULT NULL,
			imported_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			assigned_at  DATETIME        DEFAULT NULL,
			updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY phone (phone),
			KEY seller_id (seller_id),
			KEY supervisor_id (supervisor_id),
			KEY import_code (import_code),
			KEY status (status),
			KEY lead_status (lead_status)
)
```

### 3. `sn_lead_statuses`

Source: [includes/class-sn-activator.php:266](../../includes/class-sn-activator.php#L266) — module `CORE_AUTH`. Identifier expression: `{$wpdb->prefix}sn_lead_statuses`.

```sql
CREATE TABLE {$wpdb->prefix}sn_lead_statuses (
id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
			label      VARCHAR(100) NOT NULL,
			color      VARCHAR(20)  NOT NULL DEFAULT '#6b7280',
			sort_order INT          NOT NULL DEFAULT 0,
			is_active  TINYINT(1)   NOT NULL DEFAULT 1,
			destination_panel VARCHAR(50) DEFAULT NULL,
			move_to_destination TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY (id)
)
```

### 4. `sn_invoices`

Source: [includes/class-sn-activator.php:277](../../includes/class-sn-activator.php#L277) — module `CORE_AUTH`. Identifier expression: `{$wpdb->prefix}sn_invoices`.

```sql
CREATE TABLE {$wpdb->prefix}sn_invoices (
id              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_code    VARCHAR(20)     NOT NULL,
			access_token    VARCHAR(96)     DEFAULT NULL,
			short_code      VARCHAR(32)     DEFAULT NULL,
			seller_id       BIGINT UNSIGNED NOT NULL,
			issued_by_user_id BIGINT UNSIGNED DEFAULT NULL,
			lead_id         BIGINT UNSIGNED DEFAULT NULL,
			customer_wp_id  BIGINT UNSIGNED DEFAULT NULL,
			customer_name   VARCHAR(120)    NOT NULL,
			customer_phone  VARCHAR(20)     NOT NULL,
			customer_phone_secondary VARCHAR(20) DEFAULT NULL,
			province        VARCHAR(60)     DEFAULT NULL,
			city            VARCHAR(60)     DEFAULT NULL,
			customer_address TEXT           DEFAULT NULL,
			customer_postal_code VARCHAR(20) DEFAULT NULL,
			product_id      BIGINT UNSIGNED NOT NULL,
			product_price   DECIMAL(18,2)   NOT NULL DEFAULT 0,
			catalog_discounts_snapshot LONGTEXT DEFAULT NULL,
			catalog_manager_user_id BIGINT UNSIGNED DEFAULT NULL,
			pay_method      VARCHAR(20)     DEFAULT NULL,
			payment_card_number VARCHAR(32) DEFAULT NULL,
			payment_card_owner VARCHAR(191) DEFAULT NULL,
			payment_card_sales_manager_user_id BIGINT UNSIGNED DEFAULT NULL,
			payment_card_deputy_user_id BIGINT UNSIGNED DEFAULT NULL,
			status          VARCHAR(60)     NOT NULL DEFAULT 'pending',
			invoice_status  VARCHAR(60)     DEFAULT NULL,
			payment_status  VARCHAR(60)     DEFAULT NULL,
			receipt_url     VARCHAR(500)    DEFAULT NULL,
			receipt_file    VARCHAR(500)    DEFAULT NULL,
			receipt_urls LONGTEXT DEFAULT NULL,
			receipt_source  VARCHAR(50)     DEFAULT NULL,
			payment_source  VARCHAR(50)     DEFAULT NULL,
			manual_card_from VARCHAR(4)     DEFAULT NULL,
			manual_card_to  VARCHAR(4)      DEFAULT NULL,
			manual_card_to_number VARCHAR(32) DEFAULT NULL,
			manual_amount   DECIMAL(18,2)   DEFAULT NULL,
			manual_paid_at  DATETIME        DEFAULT NULL,
			manual_paid_at_jalali VARCHAR(30) DEFAULT NULL,
			deposit_card_from_last4 VARCHAR(4) DEFAULT NULL,
			deposit_card_to_last4 VARCHAR(4) DEFAULT NULL,
			deposit_amount  DECIMAL(18,2)   DEFAULT NULL,
			deposit_jalali_datetime VARCHAR(30) DEFAULT NULL,
			paid_at         DATETIME        DEFAULT NULL,
			approved_by     BIGINT UNSIGNED DEFAULT NULL,
			approved_at     DATETIME        DEFAULT NULL,
			rejected_by     BIGINT UNSIGNED DEFAULT NULL,
			rejected_at     DATETIME        DEFAULT NULL,
			rejected_reason TEXT            DEFAULT NULL,
			financial_reviewed_by BIGINT UNSIGNED DEFAULT NULL,
			financial_reviewed_at DATETIME  DEFAULT NULL,
			financial_reject_reason TEXT    DEFAULT NULL,
			financial_rejected_at DATETIME DEFAULT NULL,
			financial_rejected_by BIGINT UNSIGNED DEFAULT NULL,
			resend_to_financial_at DATETIME DEFAULT NULL,
			recontact_requested_at DATETIME DEFAULT NULL,
			recontact_note TEXT DEFAULT NULL,
			discount_amount DECIMAL(18,2) DEFAULT NULL,
			wheel_reward_summary TEXT DEFAULT NULL,
			coupon_code VARCHAR(100) DEFAULT NULL,
			coupon_discount_amount DECIMAL(18,2) DEFAULT NULL,
			original_total DECIMAL(18,2) DEFAULT NULL,
			discount_total DECIMAL(18,2) DEFAULT NULL,
			final_total DECIMAL(18,2) DEFAULT NULL,
			payment_plan VARCHAR(20) NOT NULL DEFAULT 'full',
			payment_total_amount DECIMAL(18,2) DEFAULT NULL,
			current_due_amount DECIMAL(18,2) DEFAULT NULL,
			paid_total_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			remaining_amount DECIMAL(18,2) DEFAULT NULL,
			current_payment_stage INT UNSIGNED NOT NULL DEFAULT 1,
			payment_workflow_status VARCHAR(60) NOT NULL DEFAULT 'awaiting_payment',
			current_stage_issued_by_user_id BIGINT UNSIGNED DEFAULT NULL,
			payment_completed_at DATETIME DEFAULT NULL,
			payment_archived_at DATETIME DEFAULT NULL,
			payment_archived_by BIGINT UNSIGNED DEFAULT NULL,
			payment_archive_reason TEXT DEFAULT NULL,
			referral_item_id BIGINT UNSIGNED DEFAULT NULL,
			wc_order_id BIGINT UNSIGNED DEFAULT NULL,
			financial_return_state VARCHAR(40) DEFAULT NULL,
			returned_to_seller_at DATETIME DEFAULT NULL,
			resent_after_return_at DATETIME DEFAULT NULL,
			created_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at      DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY invoice_code (invoice_code),
			KEY seller_id (seller_id),
			KEY customer_wp_id (customer_wp_id),
			KEY customer_phone (customer_phone),
			KEY referral_item_id (referral_item_id),
			KEY status (status)
)
```

### 5. `sn_invoice_payment_stages`

Source: [includes/class-sn-activator.php:369](../../includes/class-sn-activator.php#L369) — module `CORE_AUTH`. Identifier expression: `{$wpdb->prefix}sn_invoice_payment_stages`.

```sql
CREATE TABLE {$wpdb->prefix}sn_invoice_payment_stages (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			stage_no INT UNSIGNED NOT NULL DEFAULT 1,
			stage_type VARCHAR(30) NOT NULL DEFAULT 'full',
			requested_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			status VARCHAR(60) NOT NULL DEFAULT 'pending',
			pay_method VARCHAR(30) DEFAULT NULL,
			payment_source VARCHAR(60) DEFAULT NULL,
			payment_ref_id VARCHAR(191) DEFAULT NULL,
			receipt_urls LONGTEXT DEFAULT NULL,
			issued_by_user_id BIGINT UNSIGNED DEFAULT NULL,
			approved_by_user_id BIGINT UNSIGNED DEFAULT NULL,
			approved_at DATETIME DEFAULT NULL,
			paid_at DATETIME DEFAULT NULL,
			note TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY invoice_stage (invoice_id, stage_no),
			KEY invoice_id (invoice_id),
			KEY status (status)
)
```

### 6. `sn_payments`

Source: [includes/class-sn-activator.php:393](../../includes/class-sn-activator.php#L393) — module `CORE_AUTH`. Identifier expression: `{$wpdb->prefix}sn_payments`.

```sql
CREATE TABLE {$wpdb->prefix}sn_payments (
id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id   BIGINT UNSIGNED NOT NULL,
			payment_stage_no INT UNSIGNED NOT NULL DEFAULT 1,
			authority    VARCHAR(100)    DEFAULT NULL,
			ref_id       VARCHAR(191)    DEFAULT NULL,
			amount       DECIMAL(18,2)   NOT NULL DEFAULT 0,
			status       VARCHAR(20)     NOT NULL DEFAULT 'pending',
			pay_method   VARCHAR(30)     DEFAULT NULL,
			payment_source VARCHAR(60)   DEFAULT NULL,
			proof_data LONGTEXT DEFAULT NULL,
			submission_key VARCHAR(64) DEFAULT NULL,
			uploaded_by_type VARCHAR(20) DEFAULT NULL,
			uploaded_by_user_id BIGINT UNSIGNED DEFAULT NULL,
			created_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY invoice_id (invoice_id),
			KEY invoice_stage_status (invoice_id, payment_stage_no, status)
)
```

### 7. `sn_invoice_items`

Source: [includes/class-sn-activator.php:414](../../includes/class-sn-activator.php#L414) — module `CORE_AUTH`. Identifier expression: `{$wpdb->prefix}sn_invoice_items`.

```sql
CREATE TABLE {$wpdb->prefix}sn_invoice_items (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			product_name VARCHAR(255) NOT NULL,
			product_type VARCHAR(30) DEFAULT NULL,
			qty INT UNSIGNED NOT NULL DEFAULT 1,
			unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
			total_price DECIMAL(18,2) NOT NULL DEFAULT 0,
			is_free TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY invoice_id (invoice_id),
			KEY product_id (product_id)
)
```

### 8. `sn_invoice_wheel`

Source: [includes/class-sn-activator.php:430](../../includes/class-sn-activator.php#L430) — module `CORE_AUTH`. Identifier expression: `{$wpdb->prefix}sn_invoice_wheel`.

```sql
CREATE TABLE {$wpdb->prefix}sn_invoice_wheel (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			customer_id BIGINT UNSIGNED DEFAULT NULL,
			reward_type VARCHAR(40) DEFAULT NULL,
			reward_value VARCHAR(120) DEFAULT NULL,
			reward_payload LONGTEXT DEFAULT NULL,
			used_discount TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY invoice_id (invoice_id),
			KEY customer_id (customer_id)
)
```

### 9. `sn_payment_reward_claims`

Source: [includes/class-sn-activator.php:444](../../includes/class-sn-activator.php#L444) — module `CORE_AUTH`. Identifier expression: `{$wpdb->prefix}sn_payment_reward_claims`.

```sql
CREATE TABLE {$wpdb->prefix}sn_payment_reward_claims (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			invoice_code VARCHAR(40) DEFAULT NULL,
			product_id BIGINT UNSIGNED DEFAULT NULL,
			product_name VARCHAR(255) DEFAULT NULL,
			customer_name VARCHAR(120) DEFAULT NULL,
			customer_phone VARCHAR(20) DEFAULT NULL,
			reward_phone VARCHAR(20) NOT NULL,
			sms_sent TINYINT(1) NOT NULL DEFAULT 0,
			sms_status VARCHAR(40) DEFAULT NULL,
			sms_pattern VARCHAR(120) DEFAULT NULL,
			sms_template TEXT DEFAULT NULL,
			sms_error TEXT DEFAULT NULL,
			ip_address VARCHAR(64) DEFAULT NULL,
			user_agent TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY invoice_id (invoice_id),
			KEY invoice_code (invoice_code),
			KEY reward_phone (reward_phone),
			KEY customer_phone (customer_phone),
			KEY created_at (created_at)
)
```

### 10. `sn_activity_logs`

Source: [includes/class-sn-activator.php:471](../../includes/class-sn-activator.php#L471) — module `CORE_AUTH`. Identifier expression: `{$wpdb->prefix}sn_activity_logs`.

```sql
CREATE TABLE {$wpdb->prefix}sn_activity_logs (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			lead_id BIGINT UNSIGNED DEFAULT NULL,
			invoice_id BIGINT UNSIGNED DEFAULT NULL,
			user_id BIGINT UNSIGNED DEFAULT NULL,
			action VARCHAR(120) NOT NULL,
			old_value LONGTEXT DEFAULT NULL,
			new_value LONGTEXT DEFAULT NULL,
			description TEXT DEFAULT NULL,
			context LONGTEXT DEFAULT NULL,
			ip_address VARCHAR(64) DEFAULT NULL,
			user_agent TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY lead_id (lead_id),
			KEY invoice_id (invoice_id),
			KEY user_id (user_id),
			KEY action (action),
			KEY created_at (created_at)
)
```

### 11. `sn_lead_status_history`

Source: [includes/class-sn-activator.php:492](../../includes/class-sn-activator.php#L492) — module `CORE_AUTH`. Identifier expression: `{$wpdb->prefix}sn_lead_status_history`.

```sql
CREATE TABLE {$wpdb->prefix}sn_lead_status_history (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			lead_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED DEFAULT NULL,
			old_status VARCHAR(100) DEFAULT NULL,
			new_status VARCHAR(100) DEFAULT NULL,
			note TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY lead_id (lead_id),
			KEY user_id (user_id),
			KEY created_at (created_at)
)
```

### 12. `sn_campaigns`

Source: [includes/class-sn-campaign-tracking.php:99](../../includes/class-sn-campaign-tracking.php#L99) — module `CAMPAIGNS`. Identifier expression: `{$t['campaigns']}`.

Table-name resolution: [includes/class-sn-campaign-tracking.php:76](../../includes/class-sn-campaign-tracking.php#L76), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['campaigns']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_key CHAR(64) NOT NULL,
			title VARCHAR(191) NOT NULL,
			utm_source VARCHAR(191) DEFAULT NULL,
			utm_medium VARCHAR(191) DEFAULT NULL,
			utm_campaign VARCHAR(191) DEFAULT NULL,
			utm_content VARCHAR(191) DEFAULT NULL,
			utm_term VARCHAR(191) DEFAULT NULL,
			budget_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
			cost_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
			currency VARCHAR(12) NOT NULL DEFAULT 'IRR',
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY campaign_key (campaign_key),
			KEY utm_source (utm_source),
			KEY utm_medium (utm_medium),
			KEY utm_campaign (utm_campaign),
			KEY status (status)
)
```

### 13. `sn_campaign_sessions`

Source: [includes/class-sn-campaign-tracking.php:123](../../includes/class-sn-campaign-tracking.php#L123) — module `CAMPAIGNS`. Identifier expression: `{$t['sessions']}`.

Table-name resolution: [includes/class-sn-campaign-tracking.php:77](../../includes/class-sn-campaign-tracking.php#L77), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['sessions']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			visitor_id CHAR(36) NOT NULL,
			session_id CHAR(36) NOT NULL,
			first_campaign_id BIGINT UNSIGNED DEFAULT NULL,
			last_campaign_id BIGINT UNSIGNED DEFAULT NULL,
			landing_page_url TEXT DEFAULT NULL,
			first_utm_source VARCHAR(191) DEFAULT NULL,
			first_utm_medium VARCHAR(191) DEFAULT NULL,
			first_utm_campaign VARCHAR(191) DEFAULT NULL,
			first_utm_content VARCHAR(191) DEFAULT NULL,
			first_utm_term VARCHAR(191) DEFAULT NULL,
			last_utm_source VARCHAR(191) DEFAULT NULL,
			last_utm_medium VARCHAR(191) DEFAULT NULL,
			last_utm_campaign VARCHAR(191) DEFAULT NULL,
			last_utm_content VARCHAR(191) DEFAULT NULL,
			last_utm_term VARCHAR(191) DEFAULT NULL,
			ip_address VARCHAR(45) DEFAULT NULL,
			user_agent VARCHAR(500) DEFAULT NULL,
			started_at DATETIME NOT NULL,
			last_seen_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY session_id (session_id),
			KEY visitor_started (visitor_id,started_at),
			KEY first_campaign_started (first_campaign_id,started_at),
			KEY last_campaign_started (last_campaign_id,started_at),
			KEY last_seen_at (last_seen_at)
)
```

### 14. `sn_campaign_events`

Source: [includes/class-sn-campaign-tracking.php:154](../../includes/class-sn-campaign-tracking.php#L154) — module `CAMPAIGNS`. Identifier expression: `{$t['events']}`.

Table-name resolution: [includes/class-sn-campaign-tracking.php:78](../../includes/class-sn-campaign-tracking.php#L78), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['events']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			event_key CHAR(64) NOT NULL,
			visitor_id CHAR(36) NOT NULL,
			session_id CHAR(36) NOT NULL,
			campaign_id BIGINT UNSIGNED DEFAULT NULL,
			event_type VARCHAR(40) NOT NULL DEFAULT 'landing_view',
			landing_page_url TEXT DEFAULT NULL,
			utm_source VARCHAR(191) DEFAULT NULL,
			utm_medium VARCHAR(191) DEFAULT NULL,
			utm_campaign VARCHAR(191) DEFAULT NULL,
			utm_content VARCHAR(191) DEFAULT NULL,
			utm_term VARCHAR(191) DEFAULT NULL,
			ip_address VARCHAR(45) DEFAULT NULL,
			user_agent VARCHAR(500) DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY event_key (event_key),
			KEY session_created (session_id,created_at),
			KEY visitor_created (visitor_id,created_at),
			KEY campaign_created (campaign_id,created_at),
			KEY event_type (event_type)
)
```

### 15. `sn_campaign_attributions`

Source: [includes/class-sn-campaign-tracking.php:178](../../includes/class-sn-campaign-tracking.php#L178) — module `CAMPAIGNS`. Identifier expression: `{$t['attributions']}`.

Table-name resolution: [includes/class-sn-campaign-tracking.php:79](../../includes/class-sn-campaign-tracking.php#L79), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['attributions']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			subject_type VARCHAR(40) NOT NULL,
			subject_id BIGINT UNSIGNED NOT NULL,
			identity_hash CHAR(64) DEFAULT NULL,
			visitor_id CHAR(36) DEFAULT NULL,
			session_id CHAR(36) DEFAULT NULL,
			first_campaign_id BIGINT UNSIGNED DEFAULT NULL,
			last_campaign_id BIGINT UNSIGNED DEFAULT NULL,
			first_touch_source VARCHAR(191) DEFAULT NULL,
			first_touch_medium VARCHAR(191) DEFAULT NULL,
			first_touch_campaign VARCHAR(191) DEFAULT NULL,
			first_touch_content VARCHAR(191) DEFAULT NULL,
			first_touch_term VARCHAR(191) DEFAULT NULL,
			last_touch_source VARCHAR(191) DEFAULT NULL,
			last_touch_medium VARCHAR(191) DEFAULT NULL,
			last_touch_campaign VARCHAR(191) DEFAULT NULL,
			last_touch_content VARCHAR(191) DEFAULT NULL,
			last_touch_term VARCHAR(191) DEFAULT NULL,
			attributed_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY subject (subject_type,subject_id),
			KEY identity_attributed (identity_hash,attributed_at),
			KEY first_campaign_attributed (first_campaign_id,attributed_at),
			KEY last_campaign_attributed (last_campaign_id,attributed_at)
)
```

### 16. `sn_campaign_conversions`

Source: [includes/class-sn-campaign-tracking.php:207](../../includes/class-sn-campaign-tracking.php#L207) — module `CAMPAIGNS`. Identifier expression: `{$t['conversions']}`.

Table-name resolution: [includes/class-sn-campaign-tracking.php:80](../../includes/class-sn-campaign-tracking.php#L80), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['conversions']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			attribution_id BIGINT UNSIGNED NOT NULL,
			conversion_type VARCHAR(40) NOT NULL,
			subject_type VARCHAR(40) NOT NULL,
			subject_id BIGINT UNSIGNED NOT NULL,
			identity_hash CHAR(64) DEFAULT NULL,
			first_campaign_id BIGINT UNSIGNED DEFAULT NULL,
			last_campaign_id BIGINT UNSIGNED DEFAULT NULL,
			revenue_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
			occurred_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY subject_conversion (conversion_type,subject_type,subject_id),
			KEY first_campaign_type_date (first_campaign_id,conversion_type,occurred_at),
			KEY last_campaign_type_date (last_campaign_id,conversion_type,occurred_at),
			KEY identity_type (identity_hash,conversion_type)
)
```

### 17. `sn_campaign_partner_campaigns`

Source: [includes/class-sn-campaign-tracking.php:227](../../includes/class-sn-campaign-tracking.php#L227) — module `CAMPAIGNS`. Identifier expression: `{$t['assignments']}`.

Table-name resolution: [includes/class-sn-campaign-tracking.php:81](../../includes/class-sn-campaign-tracking.php#L81), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['assignments']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			partner_user_id BIGINT UNSIGNED NOT NULL,
			campaign_id BIGINT UNSIGNED NOT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY partner_campaign (partner_user_id,campaign_id),
			KEY campaign_partner (campaign_id,partner_user_id)
)
```

### 18. `sn_campaign_partner_tokens`

Source: [includes/class-sn-campaign-tracking.php:238](../../includes/class-sn-campaign-tracking.php#L238) — module `CAMPAIGNS`. Identifier expression: `{$t['tokens']}`.

Table-name resolution: [includes/class-sn-campaign-tracking.php:82](../../includes/class-sn-campaign-tracking.php#L82), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['tokens']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			partner_user_id BIGINT UNSIGNED NOT NULL,
			token_hash CHAR(64) NOT NULL,
			token_prefix VARCHAR(20) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			last_used_at DATETIME DEFAULT NULL,
			last_ip_hash CHAR(64) DEFAULT NULL,
			expires_at DATETIME DEFAULT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY token_hash (token_hash),
			KEY partner_status (partner_user_id,status),
			KEY status_expires (status,expires_at)
)
```

### 19. `sn_dot_cases`

Source: [includes/class-sn-dot-flow.php:238](../../includes/class-sn-dot-flow.php#L238) — module `DOT_CONVERSION`. Identifier expression: `{$t['cases']}`.

Table-name resolution: [includes/class-sn-dot-flow.php:222](../../includes/class-sn-dot-flow.php#L222), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['cases']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			referral_item_id BIGINT UNSIGNED DEFAULT NULL,
			source_invoice_id BIGINT UNSIGNED NOT NULL,
			source_product_id BIGINT UNSIGNED NOT NULL,
			seller_id BIGINT UNSIGNED NOT NULL,
			supervisor_id BIGINT UNSIGNED NOT NULL,
			converter_id BIGINT UNSIGNED DEFAULT NULL,
			conversion_route VARCHAR(30) DEFAULT NULL,
			access_sms_enabled TINYINT(1) NOT NULL DEFAULT 1,
			customer_wp_id BIGINT UNSIGNED DEFAULT NULL,
			customer_name VARCHAR(120) NOT NULL,
			customer_phone VARCHAR(20) NOT NULL,
			province VARCHAR(60) DEFAULT NULL,
			city VARCHAR(60) DEFAULT NULL,
			national_id VARCHAR(20) DEFAULT NULL,
			nominal_credit_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
			nominal_credit_key VARCHAR(80) DEFAULT NULL,
			options_snapshot_json LONGTEXT DEFAULT NULL,
			catalog_discount_snapshot LONGTEXT DEFAULT NULL,
			status VARCHAR(60) NOT NULL DEFAULT 'awaiting_access_sms',
			selected_option_key VARCHAR(80) DEFAULT NULL,
			selected_option_product_id BIGINT UNSIGNED DEFAULT NULL,
			selected_option_title VARCHAR(191) DEFAULT NULL,
			selected_option_price DECIMAL(20,2) DEFAULT NULL,
			selected_option_html LONGTEXT DEFAULT NULL,
			selected_by BIGINT UNSIGNED DEFAULT NULL,
			selected_at DATETIME DEFAULT NULL,
			access_token_hash CHAR(64) DEFAULT NULL,
			access_code_hash VARCHAR(255) DEFAULT NULL,
			access_expires_at DATETIME DEFAULT NULL,
			access_failed_attempts INT UNSIGNED NOT NULL DEFAULT 0,
			access_locked_until DATETIME DEFAULT NULL,
			session_token_hash CHAR(64) DEFAULT NULL,
			session_expires_at DATETIME DEFAULT NULL,
			sms_due_at DATETIME DEFAULT NULL,
			sms_sent_at DATETIME DEFAULT NULL,
			sms_previewed_at DATETIME DEFAULT NULL,
			sms_delivery_mode VARCHAR(20) DEFAULT NULL,
			sms_attempts INT UNSIGNED NOT NULL DEFAULT 0,
			sms_last_error VARCHAR(191) DEFAULT NULL,
			customer_viewed_at DATETIME DEFAULT NULL,
			assigned_by BIGINT UNSIGNED DEFAULT NULL,
			assigned_at DATETIME DEFAULT NULL,
			converter_contact_status VARCHAR(40) DEFAULT NULL,
			converter_no_answer_attempts INT UNSIGNED NOT NULL DEFAULT 0,
			converter_note TEXT DEFAULT NULL,
			converter_decline_reason TEXT DEFAULT NULL,
			converter_declined_at DATETIME DEFAULT NULL,
			converter_followup_at DATETIME DEFAULT NULL,
			converter_followup_reminded_at DATETIME DEFAULT NULL,
			converter_followup_reminder_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			converter_followup_reminder_error VARCHAR(191) DEFAULT NULL,
			payment_mode VARCHAR(30) DEFAULT NULL,
			deposit_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
			paid_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
			remaining_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
			completed_at DATETIME DEFAULT NULL,
			validation_fee_gifted TINYINT(1) NOT NULL DEFAULT 0,
			validation_gifted_by BIGINT UNSIGNED DEFAULT NULL,
			validation_gifted_at DATETIME DEFAULT NULL,
			validation_gift_sms_sent_at DATETIME DEFAULT NULL,
			validation_gift_sms_error VARCHAR(191) DEFAULT NULL,
			operational_archived_at DATETIME DEFAULT NULL,
			operational_archive_reason VARCHAR(60) DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY referral_item_id (referral_item_id),
			UNIQUE KEY source_invoice_id (source_invoice_id),
			KEY supervisor_status (supervisor_id,status),
			KEY converter_status (converter_id,status),
			KEY converter_followup (converter_contact_status,converter_followup_at,converter_followup_reminded_at),
			KEY seller_route (seller_id,conversion_route,converter_id),
			KEY supervisor_converter_route (supervisor_id,converter_id,conversion_route),
			KEY customer_phone (customer_phone),
			KEY selected_option_product_id (selected_option_product_id),
			KEY sms_due_at (sms_due_at),
			KEY validation_fee_gifted (validation_fee_gifted),
			KEY operational_archive (operational_archived_at,operational_archive_reason)
)
```

### 20. `sn_dot_invoice_links`

Source: [includes/class-sn-dot-flow.php:320](../../includes/class-sn-dot-flow.php#L320) — module `DOT_CONVERSION`. Identifier expression: `{$t['links']}`.

Table-name resolution: [includes/class-sn-dot-flow.php:223](../../includes/class-sn-dot-flow.php#L223), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['links']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			case_id BIGINT UNSIGNED DEFAULT NULL,
			flow_kind VARCHAR(40) NOT NULL,
			payment_kind VARCHAR(30) NOT NULL DEFAULT 'source',
			marketing_form_id VARCHAR(80) DEFAULT NULL,
			supervisor_id BIGINT UNSIGNED NOT NULL,
			conversion_route VARCHAR(30) DEFAULT NULL,
			send_assessment_sms TINYINT(1) NOT NULL DEFAULT 1,
			requires_finance TINYINT(1) NOT NULL DEFAULT 0,
			sms_delay_minutes INT UNSIGNED NOT NULL DEFAULT 30,
			payment_verified_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY invoice_id (invoice_id),
			KEY case_id (case_id),
			KEY flow_kind (flow_kind),
			KEY marketing_form_id (marketing_form_id)
)
```

### 21. `sn_dot_payments`

Source: [includes/class-sn-dot-flow.php:342](../../includes/class-sn-dot-flow.php#L342) — module `DOT_CONVERSION`. Identifier expression: `{$t['payments']}`.

Table-name resolution: [includes/class-sn-dot-flow.php:224](../../includes/class-sn-dot-flow.php#L224), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['payments']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			case_id BIGINT UNSIGNED NOT NULL,
			invoice_id BIGINT UNSIGNED NOT NULL,
			payment_type VARCHAR(30) NOT NULL,
			requested_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
			status VARCHAR(40) NOT NULL DEFAULT 'pending',
			sms_sent_at DATETIME DEFAULT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			paid_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY invoice_id (invoice_id),
			KEY case_status (case_id,status),
			KEY payment_type (payment_type)
)
```

### 22. `sn_dot_case_logs`

Source: [includes/class-sn-dot-flow.php:360](../../includes/class-sn-dot-flow.php#L360) — module `DOT_CONVERSION`. Identifier expression: `{$t['logs']}`.

Table-name resolution: [includes/class-sn-dot-flow.php:225](../../includes/class-sn-dot-flow.php#L225), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['logs']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			case_id BIGINT UNSIGNED NOT NULL,
			actor_id BIGINT UNSIGNED DEFAULT NULL,
			event_key VARCHAR(100) NOT NULL,
			old_status VARCHAR(60) DEFAULT NULL,
			new_status VARCHAR(60) DEFAULT NULL,
			details LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY case_id (case_id),
			KEY event_key (event_key),
			KEY created_at (created_at)
)
```

### 23. `sn_dot_operational_archives`

Source: [includes/class-sn-dot-flow.php:375](../../includes/class-sn-dot-flow.php#L375) — module `DOT_CONVERSION`. Identifier expression: `{$t['archives']}`.

Table-name resolution: [includes/class-sn-dot-flow.php:226](../../includes/class-sn-dot-flow.php#L226), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['archives']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			archive_type VARCHAR(40) NOT NULL,
			entity_kind VARCHAR(30) NOT NULL,
			entity_id BIGINT UNSIGNED NOT NULL,
			invoice_id BIGINT UNSIGNED DEFAULT NULL,
			case_id BIGINT UNSIGNED DEFAULT NULL,
			seller_id BIGINT UNSIGNED DEFAULT NULL,
			supervisor_id BIGINT UNSIGNED DEFAULT NULL,
			converter_id BIGINT UNSIGNED DEFAULT NULL,
			sales_manager_user_id BIGINT UNSIGNED NOT NULL,
			customer_name VARCHAR(120) DEFAULT NULL,
			customer_phone VARCHAR(20) DEFAULT NULL,
			product_title VARCHAR(191) DEFAULT NULL,
			total_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
			paid_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
			remaining_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
			due_at DATETIME NOT NULL,
			archived_at DATETIME NOT NULL,
			resolved_at DATETIME DEFAULT NULL,
			resolved_reason VARCHAR(80) DEFAULT NULL,
			details_json LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY archive_entity (archive_type,entity_kind,entity_id),
			KEY manager_active (sales_manager_user_id,archive_type,resolved_at,archived_at),
			KEY invoice_id (invoice_id),
			KEY case_id (case_id)
)
```

### 24. `sn_dot_marketing_leads`

Source: [includes/class-sn-dot-flow.php:406](../../includes/class-sn-dot-flow.php#L406) — module `DOT_CONVERSION`. Identifier expression: `{$t['marketing_leads']}`.

Table-name resolution: [includes/class-sn-dot-flow.php:227](../../includes/class-sn-dot-flow.php#L227), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['marketing_leads']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			crm_lead_id BIGINT UNSIGNED DEFAULT NULL,
			invoice_id BIGINT UNSIGNED DEFAULT NULL,
			form_id VARCHAR(80) NOT NULL DEFAULT 'default',
			form_title VARCHAR(191) DEFAULT NULL,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			supervisor_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			seller_id BIGINT UNSIGNED DEFAULT NULL,
			customer_name VARCHAR(120) NOT NULL,
			customer_phone VARCHAR(20) NOT NULL,
			national_id VARCHAR(20) DEFAULT NULL,
			province VARCHAR(60) DEFAULT NULL,
			city VARCHAR(60) DEFAULT NULL,
			campaign_attribution_id BIGINT UNSIGNED DEFAULT NULL,
			campaign_attribution_status VARCHAR(20) DEFAULT NULL,
			campaign_attributed_at DATETIME DEFAULT NULL,
			campaign_landing_url TEXT DEFAULT NULL,
			utm_source VARCHAR(191) DEFAULT NULL,
			utm_medium VARCHAR(191) DEFAULT NULL,
			utm_campaign VARCHAR(191) DEFAULT NULL,
			utm_content VARCHAR(191) DEFAULT NULL,
			utm_term VARCHAR(191) DEFAULT NULL,
			status VARCHAR(40) NOT NULL DEFAULT 'payment_not_done',
			funnel_stage SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			last_event VARCHAR(40) DEFAULT NULL,
			last_event_reason VARCHAR(191) DEFAULT NULL,
			last_event_at DATETIME DEFAULT NULL,
			application_started_at DATETIME DEFAULT NULL,
			lead_saved_at DATETIME DEFAULT NULL,
			invoice_created_at DATETIME DEFAULT NULL,
			confirmation_opened_at DATETIME DEFAULT NULL,
			confirmation_accepted_at DATETIME DEFAULT NULL,
			otp_requested_at DATETIME DEFAULT NULL,
			otp_verified_at DATETIME DEFAULT NULL,
			gateway_requested_at DATETIME DEFAULT NULL,
			gateway_entered_at DATETIME DEFAULT NULL,
			gateway_returned_at DATETIME DEFAULT NULL,
			paid_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY invoice_id (invoice_id),
			UNIQUE KEY crm_lead_id (crm_lead_id),
			KEY form_status (form_id,status),
			KEY funnel_stage (funnel_stage),
			KEY last_event (last_event),
			KEY supervisor_id (supervisor_id),
			KEY campaign_attribution_id (campaign_attribution_id),
			KEY campaign_attribution_status (campaign_attribution_status),
			KEY utm_source (utm_source),
			KEY customer_phone (customer_phone),
			KEY created_at (created_at)
)
```

### 25. `sn_dot_marketing_events`

Source: [includes/class-sn-dot-flow.php:461](../../includes/class-sn-dot-flow.php#L461) — module `DOT_CONVERSION`. Identifier expression: `{$t['marketing_events']}`.

Table-name resolution: [includes/class-sn-dot-flow.php:228](../../includes/class-sn-dot-flow.php#L228), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['marketing_events']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			marketing_lead_id BIGINT UNSIGNED NOT NULL,
			event_key CHAR(64) NOT NULL,
			event_type VARCHAR(40) NOT NULL,
			event_status VARCHAR(20) NOT NULL DEFAULT 'success',
			reason VARCHAR(191) DEFAULT NULL,
			details LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY event_key (event_key),
			KEY lead_created (marketing_lead_id,created_at),
			KEY event_created (event_type,created_at)
)
```

### 26. `sn_hr_positions`

Source: [includes/class-sn-migration-service.php:388](../../includes/class-sn-migration-service.php#L388) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$p}sn_hr_positions`.

```sql
CREATE TABLE {$p}sn_hr_positions (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(80) NOT NULL,
			label VARCHAR(120) NOT NULL,
			description TEXT DEFAULT NULL,
			panel_key VARCHAR(80) DEFAULT NULL,
			sort_order INT NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			is_system TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY is_active (is_active),
			KEY sort_order (sort_order)
)
```

### 27. `sn_hr_levels`

Source: [includes/class-sn-migration-service.php:405](../../includes/class-sn-migration-service.php#L405) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$p}sn_hr_levels`.

```sql
CREATE TABLE {$p}sn_hr_levels (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			slug VARCHAR(80) NOT NULL,
			label VARCHAR(120) NOT NULL,
			description TEXT DEFAULT NULL,
			sort_order INT NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			is_system TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY is_active (is_active),
			KEY sort_order (sort_order)
)
```

### 28. `sn_hr_profiles`

Source: [includes/class-sn-migration-service.php:423](../../includes/class-sn-migration-service.php#L423) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$p}sn_hr_profiles`.

```sql
CREATE TABLE {$p}sn_hr_profiles (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			position_id BIGINT UNSIGNED DEFAULT NULL,
			level_id BIGINT UNSIGNED DEFAULT NULL,
			employment_status VARCHAR(50) NOT NULL DEFAULT 'active',
			contract_status VARCHAR(50) DEFAULT NULL,
			training_status VARCHAR(50) DEFAULT NULL,
			legacy_role VARCHAR(120) DEFAULT NULL,
			legacy_source VARCHAR(120) DEFAULT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY user_id (user_id),
			KEY position_id (position_id),
			KEY level_id (level_id),
			KEY legacy_role (legacy_role),
			KEY is_active (is_active)
)
```

### 29. `sn_hr_position_role_mappings`

Source: [includes/class-sn-migration-service.php:444](../../includes/class-sn-migration-service.php#L444) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$p}sn_hr_position_role_mappings`.

```sql
CREATE TABLE {$p}sn_hr_position_role_mappings (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			legacy_role VARCHAR(120) NOT NULL,
			position_id BIGINT UNSIGNED NOT NULL,
			default_level_id BIGINT UNSIGNED DEFAULT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY legacy_role (legacy_role),
			KEY position_id (position_id),
			KEY default_level_id (default_level_id),
			KEY is_active (is_active)
)
```

### 30. `sn_hr_profile_logs`

Source: [includes/class-sn-migration-service.php:459](../../includes/class-sn-migration-service.php#L459) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$p}sn_hr_profile_logs`.

```sql
CREATE TABLE {$p}sn_hr_profile_logs (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			profile_id BIGINT UNSIGNED DEFAULT NULL,
			user_id BIGINT UNSIGNED DEFAULT NULL,
			actor_user_id BIGINT UNSIGNED DEFAULT NULL,
			action VARCHAR(120) NOT NULL,
			old_value LONGTEXT DEFAULT NULL,
			new_value LONGTEXT DEFAULT NULL,
			context LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY profile_id (profile_id),
			KEY user_id (user_id),
			KEY actor_user_id (actor_user_id),
			KEY action (action),
			KEY created_at (created_at)
)
```

### 31. `sn_hr_departments / sn_hr_units / sn_hr_teams`

Source: [includes/class-sn-migration-service.php:534](../../includes/class-sn-migration-service.php#L534) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$table}`.

Table-name resolution: [create_hr_structure_tables:519](../../includes/class-sn-migration-service.php#L519) iterates three literal names. `parent_column` differs for units and teams; the SQL below is their shared template.

```sql
CREATE TABLE {$table} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				{$parent_column}
				slug VARCHAR(100) NOT NULL,
				label VARCHAR(191) NOT NULL,
				description TEXT DEFAULT NULL,
				is_active TINYINT(1) NOT NULL DEFAULT 1,
				sort_order INT NOT NULL DEFAULT 0,
				created_at DATETIME DEFAULT NULL,
				updated_at DATETIME DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY slug (slug),
				KEY is_active (is_active),
				KEY sort_order (sort_order)
)
```

### 32. `sn_hr_assignments`

Source: [includes/class-sn-migration-service.php:581](../../includes/class-sn-migration-service.php#L581) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$p}sn_hr_assignments`.

```sql
CREATE TABLE {$p}sn_hr_assignments (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			child_profile_id BIGINT UNSIGNED NOT NULL,
			parent_profile_id BIGINT UNSIGNED DEFAULT NULL,
			relationship_type VARCHAR(80) NOT NULL DEFAULT 'reports_to',
			effective_from DATETIME DEFAULT NULL,
			effective_to DATETIME DEFAULT NULL,
			is_current TINYINT(1) NOT NULL DEFAULT 1,
			source VARCHAR(80) DEFAULT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY child_profile_id (child_profile_id),
			KEY parent_profile_id (parent_profile_id),
			KEY relationship_type (relationship_type),
			KEY is_current (is_current),
			KEY effective_from (effective_from),
			KEY effective_to (effective_to)
)
```

### 33. `sn_hr_assignment_history`

Source: [includes/class-sn-migration-service.php:602](../../includes/class-sn-migration-service.php#L602) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$p}sn_hr_assignment_history`.

```sql
CREATE TABLE {$p}sn_hr_assignment_history (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			assignment_id BIGINT UNSIGNED DEFAULT NULL,
			child_profile_id BIGINT UNSIGNED DEFAULT NULL,
			old_parent_profile_id BIGINT UNSIGNED DEFAULT NULL,
			new_parent_profile_id BIGINT UNSIGNED DEFAULT NULL,
			actor_user_id BIGINT UNSIGNED DEFAULT NULL,
			action VARCHAR(120) NOT NULL,
			reason TEXT DEFAULT NULL,
			context LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY assignment_id (assignment_id),
			KEY child_profile_id (child_profile_id),
			KEY old_parent_profile_id (old_parent_profile_id),
			KEY new_parent_profile_id (new_parent_profile_id),
			KEY actor_user_id (actor_user_id),
			KEY action (action),
			KEY created_at (created_at)
)
```

### 34. `sn_mis_import_batches`

Source: [includes/class-sn-migration-service.php:630](../../includes/class-sn-migration-service.php#L630) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$p}sn_mis_import_batches`.

```sql
CREATE TABLE {$p}sn_mis_import_batches (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_code VARCHAR(80) NOT NULL,
			title VARCHAR(190) NOT NULL,
			source_type VARCHAR(80) DEFAULT NULL,
			source_label VARCHAR(190) DEFAULT NULL,
			campaign_code VARCHAR(120) DEFAULT NULL,
			data_category VARCHAR(120) DEFAULT NULL,
			import_file_name VARCHAR(255) DEFAULT NULL,
			import_status VARCHAR(40) NOT NULL DEFAULT 'draft',
			assigned_manager_user_id BIGINT UNSIGNED DEFAULT NULL,
			total_rows INT NOT NULL DEFAULT 0,
			valid_rows INT NOT NULL DEFAULT 0,
			duplicate_rows INT NOT NULL DEFAULT 0,
			invalid_rows INT NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			notes TEXT DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY batch_code (batch_code),
			KEY import_status (import_status),
			KEY assigned_manager_user_id (assigned_manager_user_id),
			KEY campaign_code (campaign_code),
			KEY data_category (data_category),
			KEY created_at (created_at)
)
```

### 35. `sn_mis_lead_pool`

Source: [includes/class-sn-migration-service.php:695](../../includes/class-sn-migration-service.php#L695) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$pool_table}`.

Table-name resolution: [includes/class-sn-migration-service.php:694](../../includes/class-sn-migration-service.php#L694), literal definition/assignment in the same source.

```sql
CREATE TABLE {$pool_table} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_row_id BIGINT UNSIGNED NOT NULL,
			batch_id BIGINT UNSIGNED NOT NULL,
			assigned_manager_user_id BIGINT UNSIGNED NOT NULL,
			assigned_seller_user_id BIGINT UNSIGNED DEFAULT NULL,
			pool_status VARCHAR(40) NOT NULL DEFAULT 'ready',
			normalized_phone VARCHAR(50) NOT NULL DEFAULT '',
			customer_phone VARCHAR(50) NOT NULL DEFAULT '',
			customer_name VARCHAR(191) DEFAULT NULL,
			city VARCHAR(100) DEFAULT NULL,
			province VARCHAR(100) DEFAULT NULL,
			source_meta LONGTEXT DEFAULT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME DEFAULT NULL,
			updated_at DATETIME DEFAULT NULL,
			notes TEXT DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_row_id (source_row_id),
			KEY batch_id (batch_id),
			KEY assigned_manager_user_id (assigned_manager_user_id),
			KEY assigned_seller_user_id (assigned_seller_user_id),
			KEY pool_status (pool_status),
			KEY normalized_phone (normalized_phone)
)
```

### 36. `sn_mis_distribution_rules`

Source: [includes/class-sn-migration-service.php:745](../../includes/class-sn-migration-service.php#L745) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$rules_table}`.

Table-name resolution: [includes/class-sn-migration-service.php:744](../../includes/class-sn-migration-service.php#L744), literal definition/assignment in the same source.

```sql
CREATE TABLE {$rules_table} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			rule_name VARCHAR(191) NOT NULL,
			rule_type VARCHAR(50) NOT NULL DEFAULT 'equal_split',
			manager_user_id BIGINT UNSIGNED DEFAULT NULL,
			status VARCHAR(40) NOT NULL DEFAULT 'draft',
			config LONGTEXT DEFAULT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME DEFAULT NULL,
			updated_at DATETIME DEFAULT NULL,
			notes TEXT DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY rule_type (rule_type),
			KEY manager_user_id (manager_user_id),
			KEY status (status)
)
```

### 37. `sn_mis_distribution_plans`

Source: [includes/class-sn-migration-service.php:777](../../includes/class-sn-migration-service.php#L777) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$plans_table}`.

Table-name resolution: [includes/class-sn-migration-service.php:776](../../includes/class-sn-migration-service.php#L776), literal definition/assignment in the same source.

```sql
CREATE TABLE {$plans_table} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			plan_code VARCHAR(100) NOT NULL,
			rule_id BIGINT UNSIGNED DEFAULT NULL,
			batch_id BIGINT UNSIGNED DEFAULT NULL,
			manager_user_id BIGINT UNSIGNED NOT NULL,
			plan_status VARCHAR(40) NOT NULL DEFAULT 'draft',
			total_pool_rows INT UNSIGNED NOT NULL DEFAULT 0,
			planned_rows INT UNSIGNED NOT NULL DEFAULT 0,
			skipped_rows INT UNSIGNED NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME DEFAULT NULL,
			updated_at DATETIME DEFAULT NULL,
			notes TEXT DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY plan_code (plan_code),
			KEY rule_id (rule_id),
			KEY batch_id (batch_id),
			KEY manager_user_id (manager_user_id),
			KEY plan_status (plan_status)
)
```

### 38. `sn_mis_distribution_plan_items`

Source: [includes/class-sn-migration-service.php:819](../../includes/class-sn-migration-service.php#L819) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$items_table}`.

Table-name resolution: [includes/class-sn-migration-service.php:818](../../includes/class-sn-migration-service.php#L818), literal definition/assignment in the same source.

```sql
CREATE TABLE {$items_table} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			plan_id BIGINT UNSIGNED NOT NULL,
			pool_id BIGINT UNSIGNED NOT NULL,
			source_row_id BIGINT UNSIGNED NOT NULL,
			manager_user_id BIGINT UNSIGNED NOT NULL,
			planned_seller_user_id BIGINT UNSIGNED DEFAULT NULL,
			item_status VARCHAR(40) NOT NULL DEFAULT 'planned',
			skip_reason VARCHAR(191) DEFAULT NULL,
			created_at DATETIME DEFAULT NULL,
			updated_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY plan_id (plan_id),
			KEY pool_id (pool_id),
			KEY manager_user_id (manager_user_id),
			KEY planned_seller_user_id (planned_seller_user_id),
			KEY item_status (item_status)
)
```

### 39. `sn_mis_distribution_logs`

Source: [includes/class-sn-migration-service.php:861](../../includes/class-sn-migration-service.php#L861) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$p}sn_mis_distribution_logs`.

```sql
CREATE TABLE {$p}sn_mis_distribution_logs (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_id BIGINT UNSIGNED DEFAULT NULL,
			actor_user_id BIGINT UNSIGNED DEFAULT NULL,
			action VARCHAR(120) NOT NULL,
			old_value LONGTEXT DEFAULT NULL,
			new_value LONGTEXT DEFAULT NULL,
			reason TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY batch_id (batch_id),
			KEY actor_user_id (actor_user_id),
			KEY action (action),
			KEY created_at (created_at)
)
```

### 40. `sn_distribution_chains`

Source: [includes/class-sn-migration-service.php:885](../../includes/class-sn-migration-service.php#L885) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$chains}`.

Table-name resolution: [includes/class-sn-migration-service.php:884](../../includes/class-sn-migration-service.php#L884), literal definition/assignment in the same source.

```sql
CREATE TABLE {$chains} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			chain_code VARCHAR(100) NOT NULL,
			title VARCHAR(191) NOT NULL,
			description TEXT DEFAULT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			is_default TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME DEFAULT NULL,
			updated_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY chain_code (chain_code),
			KEY is_active (is_active),
			KEY is_default (is_default)
)
```

### 41. `sn_distribution_chain_steps`

Source: [includes/class-sn-migration-service.php:917](../../includes/class-sn-migration-service.php#L917) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$steps}`.

Table-name resolution: [includes/class-sn-migration-service.php:916](../../includes/class-sn-migration-service.php#L916), literal definition/assignment in the same source.

```sql
CREATE TABLE {$steps} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			chain_id BIGINT UNSIGNED NOT NULL,
			step_order INT UNSIGNED NOT NULL DEFAULT 0,
			from_position_slug VARCHAR(100) NOT NULL,
			to_position_slug VARCHAR(100) NOT NULL,
			allow_skip_if_missing TINYINT(1) NOT NULL DEFAULT 1,
			is_final_delivery_step TINYINT(1) NOT NULL DEFAULT 0,
			creates_live_lead TINYINT(1) NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME DEFAULT NULL,
			updated_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY chain_id (chain_id),
			KEY step_order (step_order),
			KEY from_position_slug (from_position_slug),
			KEY to_position_slug (to_position_slug),
			KEY is_active (is_active)
)
```

### 42. `sn_distribution_items`

Source: [includes/class-sn-migration-service.php:963](../../includes/class-sn-migration-service.php#L963) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$items}`.

Table-name resolution: [includes/class-sn-migration-service.php:962](../../includes/class-sn-migration-service.php#L962), literal definition/assignment in the same source.

```sql
CREATE TABLE {$items} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_type VARCHAR(60) NOT NULL DEFAULT 'mis_pool',
			source_id BIGINT UNSIGNED NOT NULL,
			batch_id BIGINT UNSIGNED DEFAULT NULL,
			pool_item_id BIGINT UNSIGNED DEFAULT NULL,
			current_owner_user_id BIGINT UNSIGNED DEFAULT NULL,
			current_owner_position VARCHAR(100) DEFAULT NULL,
			current_step_order INT UNSIGNED NOT NULL DEFAULT 0,
			final_seller_user_id BIGINT UNSIGNED DEFAULT NULL,
			live_lead_id BIGINT UNSIGNED DEFAULT NULL,
			converted_by BIGINT UNSIGNED DEFAULT NULL,
			converted_at DATETIME DEFAULT NULL,
			conversion_status VARCHAR(60) DEFAULT NULL,
			conversion_error TEXT DEFAULT NULL,
			conversion_attempts INT UNSIGNED NOT NULL DEFAULT 0,
			seller_customer_name VARCHAR(255) DEFAULT NULL,
			seller_province VARCHAR(100) DEFAULT NULL,
			seller_city VARCHAR(100) DEFAULT NULL,
			seller_contact_status VARCHAR(60) DEFAULT NULL,
			seller_sale_probability TINYINT UNSIGNED NOT NULL DEFAULT 0,
			seller_next_followup_at DATETIME DEFAULT NULL,
			seller_notes TEXT DEFAULT NULL,
			seller_updated_by BIGINT UNSIGNED DEFAULT NULL,
			seller_updated_at DATETIME DEFAULT NULL,
			status VARCHAR(60) NOT NULL DEFAULT 'ready_for_distribution',
			created_at DATETIME DEFAULT NULL,
			updated_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_unique (source_type, source_id),
			KEY batch_id (batch_id),
			KEY pool_item_id (pool_item_id),
			KEY current_owner_user_id (current_owner_user_id),
			KEY current_owner_position (current_owner_position),
			KEY status (status),
			KEY final_seller_user_id (final_seller_user_id),
			KEY live_lead_id (live_lead_id),
			KEY conversion_status (conversion_status),
			KEY converted_at (converted_at),
			KEY seller_next_followup_at (seller_next_followup_at),
			KEY seller_contact_status (seller_contact_status)
)
```

### 43. `sn_distribution_item_logs`

Source: [includes/class-sn-migration-service.php:1055](../../includes/class-sn-migration-service.php#L1055) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$logs}`.

Table-name resolution: [includes/class-sn-migration-service.php:1054](../../includes/class-sn-migration-service.php#L1054), literal definition/assignment in the same source.

```sql
CREATE TABLE {$logs} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			item_id BIGINT UNSIGNED NOT NULL,
			action VARCHAR(100) NOT NULL,
			actor_user_id BIGINT UNSIGNED DEFAULT NULL,
			from_user_id BIGINT UNSIGNED DEFAULT NULL,
			to_user_id BIGINT UNSIGNED DEFAULT NULL,
			from_position VARCHAR(100) DEFAULT NULL,
			to_position VARCHAR(100) DEFAULT NULL,
			step_order INT UNSIGNED DEFAULT NULL,
			note TEXT DEFAULT NULL,
			payload_json LONGTEXT DEFAULT NULL,
			created_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY item_id (item_id),
			KEY action (action),
			KEY actor_user_id (actor_user_id),
			KEY to_user_id (to_user_id),
			KEY created_at (created_at)
)
```

### 44. `sn_after_sales_cases`

Source: [includes/class-sn-migration-service.php:1113](../../includes/class-sn-migration-service.php#L1113) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$cases}`.

Table-name resolution: [includes/class-sn-migration-service.php:1110](../../includes/class-sn-migration-service.php#L1110), literal definition/assignment in the same source.

```sql
CREATE TABLE {$cases} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			case_code VARCHAR(100) NOT NULL DEFAULT '',
			invoice_id BIGINT UNSIGNED DEFAULT NULL,
			invoice_code VARCHAR(100) DEFAULT NULL,
			lead_id BIGINT UNSIGNED DEFAULT NULL,
			customer_name VARCHAR(191) DEFAULT NULL,
			customer_phone VARCHAR(50) DEFAULT NULL,
			normalized_phone VARCHAR(50) DEFAULT NULL,
			seller_user_id BIGINT UNSIGNED DEFAULT NULL,
			assigned_after_sales_user_id BIGINT UNSIGNED DEFAULT NULL,
			case_type VARCHAR(50) DEFAULT NULL,
			case_status VARCHAR(50) NOT NULL DEFAULT 'open',
			priority VARCHAR(30) NOT NULL DEFAULT 'normal',
			source VARCHAR(50) NOT NULL DEFAULT 'manual',
			opened_at DATETIME DEFAULT NULL,
			due_at DATETIME DEFAULT NULL,
			resolved_at DATETIME DEFAULT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME DEFAULT NULL,
			updated_at DATETIME DEFAULT NULL,
			notes TEXT DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY case_code (case_code),
			KEY invoice_id (invoice_id),
			KEY invoice_code (invoice_code),
			KEY lead_id (lead_id),
			KEY normalized_phone (normalized_phone),
			KEY seller_user_id (seller_user_id),
			KEY assigned_after_sales_user_id (assigned_after_sales_user_id),
			KEY case_status (case_status),
			KEY priority (priority),
			KEY due_at (due_at)
)
```

### 45. `sn_after_sales_case_logs`

Source: [includes/class-sn-migration-service.php:1189](../../includes/class-sn-migration-service.php#L1189) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$logs}`.

Table-name resolution: [includes/class-sn-migration-service.php:1111](../../includes/class-sn-migration-service.php#L1111), literal definition/assignment in the same source.

```sql
CREATE TABLE {$logs} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			case_id BIGINT UNSIGNED NOT NULL,
			actor_user_id BIGINT UNSIGNED DEFAULT NULL,
			action VARCHAR(80) NOT NULL,
			old_value LONGTEXT DEFAULT NULL,
			new_value LONGTEXT DEFAULT NULL,
			note TEXT DEFAULT NULL,
			created_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY case_id (case_id),
			KEY actor_user_id (actor_user_id),
			KEY action (action),
			KEY created_at (created_at)
)
```

### 46. `sn_commission_rules`

Source: [includes/class-sn-migration-service.php:1240](../../includes/class-sn-migration-service.php#L1240) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$rules}`.

Table-name resolution: [includes/class-sn-migration-service.php:1233](../../includes/class-sn-migration-service.php#L1233), literal definition/assignment in the same source.

```sql
CREATE TABLE {$rules} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			rule_code VARCHAR(100) NOT NULL DEFAULT '',
			title VARCHAR(191) NOT NULL DEFAULT '',
			description TEXT DEFAULT NULL,
			applies_to_position VARCHAR(80) NOT NULL DEFAULT '',
			applies_to_level VARCHAR(80) NOT NULL DEFAULT '',
			payment_method VARCHAR(80) NOT NULL DEFAULT '',
			invoice_status VARCHAR(120) NOT NULL DEFAULT '',
			employment_status VARCHAR(80) NOT NULL DEFAULT '',
			employment_type VARCHAR(80) NOT NULL DEFAULT '',
			calculation_type VARCHAR(60) NOT NULL DEFAULT 'invoice_percent_fixed',
			rate_percent DECIMAL(10,4) NOT NULL DEFAULT 0,
			fixed_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			daily_base_amount DECIMAL(18,6) DEFAULT NULL,
			attendance_first_before TIME DEFAULT NULL,
			attendance_last_after TIME DEFAULT NULL,
			min_invoice_amount DECIMAL(18,2) DEFAULT NULL,
			max_invoice_amount DECIMAL(18,2) DEFAULT NULL,
			effective_from DATE DEFAULT NULL,
			effective_to DATE DEFAULT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			priority INT NOT NULL DEFAULT 0,
			created_at DATETIME DEFAULT NULL,
			updated_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY rule_code (rule_code),
			KEY applies_to_position (applies_to_position),
			KEY calculation_type (calculation_type),
			KEY is_active (is_active),
			KEY priority (priority)
)
```

### 47. `sn_commission_dry_runs`

Source: [includes/class-sn-migration-service.php:1312](../../includes/class-sn-migration-service.php#L1312) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$runs}`.

Table-name resolution: [includes/class-sn-migration-service.php:1234](../../includes/class-sn-migration-service.php#L1234), literal definition/assignment in the same source.

```sql
CREATE TABLE {$runs} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_code VARCHAR(100) NOT NULL DEFAULT '',
			title VARCHAR(191) NOT NULL DEFAULT '',
			date_from DATE DEFAULT NULL,
			date_to DATE DEFAULT NULL,
			status VARCHAR(40) NOT NULL DEFAULT 'completed',
			created_by BIGINT UNSIGNED DEFAULT NULL,
			summary_json LONGTEXT DEFAULT NULL,
			approval_status VARCHAR(40) NOT NULL DEFAULT 'generated',
			approved_by BIGINT UNSIGNED DEFAULT NULL,
			approved_at DATETIME DEFAULT NULL,
			rejected_by BIGINT UNSIGNED DEFAULT NULL,
			rejected_at DATETIME DEFAULT NULL,
			approval_note TEXT DEFAULT NULL,
			locked_at DATETIME DEFAULT NULL,
			created_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY run_code (run_code),
			KEY status (status),
			KEY approval_status (approval_status),
			KEY created_by (created_by),
			KEY created_at (created_at)
)
```

### 48. `sn_commission_dry_run_items`

Source: [includes/class-sn-migration-service.php:1368](../../includes/class-sn-migration-service.php#L1368) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$items}`.

Table-name resolution: [includes/class-sn-migration-service.php:1235](../../includes/class-sn-migration-service.php#L1235), literal definition/assignment in the same source.

```sql
CREATE TABLE {$items} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			invoice_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			invoice_code VARCHAR(100) NOT NULL DEFAULT '',
			seller_user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			seller_position VARCHAR(80) NOT NULL DEFAULT '',
			seller_level VARCHAR(80) NOT NULL DEFAULT '',
			invoice_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			payment_method VARCHAR(80) NOT NULL DEFAULT '',
			invoice_status VARCHAR(80) NOT NULL DEFAULT '',
			matched_rule_id BIGINT UNSIGNED DEFAULT NULL,
			calculated_commission DECIMAL(18,2) NOT NULL DEFAULT 0,
			legacy_commission DECIMAL(18,2) DEFAULT NULL,
			difference_amount DECIMAL(18,2) DEFAULT NULL,
			item_status VARCHAR(40) NOT NULL DEFAULT 'no_rule',
			notes TEXT DEFAULT NULL,
			wallet_transaction_id BIGINT UNSIGNED DEFAULT NULL,
			posted_at DATETIME DEFAULT NULL,
			posting_status VARCHAR(40) NOT NULL DEFAULT 'pending',
			posting_error VARCHAR(191) DEFAULT NULL,
			compensation_history_id BIGINT UNSIGNED DEFAULT NULL,
			compensation_effective_from DATE DEFAULT NULL,
			compensation_effective_to DATE DEFAULT NULL,
			rule_source VARCHAR(60) DEFAULT NULL,
			warning_code VARCHAR(120) DEFAULT NULL,
			calculation_date DATE DEFAULT NULL,
			created_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY run_id (run_id),
			KEY invoice_id (invoice_id),
			KEY seller_user_id (seller_user_id),
			KEY matched_rule_id (matched_rule_id),
			KEY item_status (item_status),
			KEY wallet_transaction_id (wallet_transaction_id),
			KEY posting_status (posting_status),
			KEY compensation_history_id (compensation_history_id),
			KEY rule_source (rule_source),
			KEY calculation_date (calculation_date)
)
```

### 49. `sn_commission_wallet_posting_logs`

Source: [includes/class-sn-migration-service.php:1456](../../includes/class-sn-migration-service.php#L1456) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$logs}`.

Table-name resolution: [includes/class-sn-migration-service.php:1236](../../includes/class-sn-migration-service.php#L1236), literal definition/assignment in the same source.

```sql
CREATE TABLE {$logs} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			run_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			item_id BIGINT UNSIGNED DEFAULT NULL,
			wallet_transaction_id BIGINT UNSIGNED DEFAULT NULL,
			actor_user_id BIGINT UNSIGNED DEFAULT NULL,
			action VARCHAR(80) NOT NULL DEFAULT '',
			status VARCHAR(40) NOT NULL DEFAULT '',
			old_value LONGTEXT DEFAULT NULL,
			new_value LONGTEXT DEFAULT NULL,
			message TEXT DEFAULT NULL,
			created_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY run_id (run_id),
			KEY item_id (item_id),
			KEY wallet_transaction_id (wallet_transaction_id),
			KEY action (action),
			KEY status (status)
)
```

### 50. `sn_wallet_settlement_batches`

Source: [includes/class-sn-migration-service.php:1501](../../includes/class-sn-migration-service.php#L1501) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$settlement_batches}`.

Table-name resolution: [includes/class-sn-migration-service.php:1237](../../includes/class-sn-migration-service.php#L1237), literal definition/assignment in the same source.

```sql
CREATE TABLE {$settlement_batches} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_code VARCHAR(100) NOT NULL DEFAULT '',
			status VARCHAR(40) NOT NULL DEFAULT 'draft',
			created_by BIGINT UNSIGNED DEFAULT NULL,
			total_items INT UNSIGNED NOT NULL DEFAULT 0,
			total_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			notes TEXT DEFAULT NULL,
			created_at DATETIME DEFAULT NULL,
			updated_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY batch_code (batch_code),
			KEY status (status),
			KEY created_by (created_by)
)
```

### 51. `sn_wallet_settlement_items`

Source: [includes/class-sn-migration-service.php:1538](../../includes/class-sn-migration-service.php#L1538) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$settlement_items}`.

Table-name resolution: [includes/class-sn-migration-service.php:1238](../../includes/class-sn-migration-service.php#L1238), literal definition/assignment in the same source.

```sql
CREATE TABLE {$settlement_items} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			wallet_id BIGINT UNSIGNED DEFAULT NULL,
			wallet_type VARCHAR(30) NOT NULL DEFAULT 'seller',
			suggested_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			item_status VARCHAR(40) NOT NULL DEFAULT 'ready',
			created_at DATETIME DEFAULT NULL,
			updated_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY batch_id (batch_id),
			KEY user_id (user_id),
			KEY wallet_id (wallet_id),
			KEY item_status (item_status)
)
```

### 52. `sn_hr_compensation_profiles`

Source: [includes/class-sn-migration-service.php:1586](../../includes/class-sn-migration-service.php#L1586) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$table}`.

Table-name resolution: [includes/class-sn-migration-service.php:1583](../../includes/class-sn-migration-service.php#L1583), literal definition/assignment in the same source.

```sql
CREATE TABLE {$table} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			profile_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			base_salary BIGINT UNSIGNED DEFAULT NULL,
			salary_currency VARCHAR(20) NOT NULL DEFAULT 'IRT',
			commission_enabled TINYINT(1) NOT NULL DEFAULT 0,
			default_commission_rule_id BIGINT UNSIGNED DEFAULT NULL,
			commission_override_mode VARCHAR(30) NOT NULL DEFAULT 'inherit',
			effective_from DATE DEFAULT NULL,
			effective_to DATE DEFAULT NULL,
			notes TEXT DEFAULT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME DEFAULT NULL,
			updated_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY profile_id (profile_id),
			KEY user_id (user_id),
			KEY commission_enabled (commission_enabled),
			KEY default_commission_rule_id (default_commission_rule_id),
			KEY is_active (is_active)
)
```

### 53. `sn_hr_compensation_history`

Source: [includes/class-sn-migration-service.php:1639](../../includes/class-sn-migration-service.php#L1639) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$history}`.

Table-name resolution: [includes/class-sn-migration-service.php:1584](../../includes/class-sn-migration-service.php#L1584), literal definition/assignment in the same source.

```sql
CREATE TABLE {$history} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			profile_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			user_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			base_salary BIGINT UNSIGNED DEFAULT NULL,
			salary_currency VARCHAR(20) NOT NULL DEFAULT 'IRT',
			commission_enabled TINYINT(1) NOT NULL DEFAULT 0,
			commission_rule_id BIGINT UNSIGNED DEFAULT NULL,
			commission_override_mode VARCHAR(30) NOT NULL DEFAULT 'inherit',
			effective_from DATE NOT NULL,
			effective_to DATE DEFAULT NULL,
			employment_type VARCHAR(50) DEFAULT NULL,
			employment_status VARCHAR(50) DEFAULT NULL,
			position_slug_snapshot VARCHAR(80) DEFAULT NULL,
			level_slug_snapshot VARCHAR(80) DEFAULT NULL,
			notes TEXT DEFAULT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			locked_after_payroll TINYINT(1) NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			updated_by BIGINT UNSIGNED DEFAULT NULL,
			change_reason TEXT DEFAULT NULL,
			created_at DATETIME DEFAULT NULL,
			updated_at DATETIME DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY profile_id (profile_id),
			KEY user_id (user_id),
			KEY commission_rule_id (commission_rule_id),
			KEY commission_override_mode (commission_override_mode),
			KEY effective_from (effective_from),
			KEY effective_to (effective_to),
			KEY is_active (is_active)
)
```

### 54. `sn_mis_data_rows`

Source: [includes/class-sn-migration-service.php:1727](../../includes/class-sn-migration-service.php#L1727) — module `MIGRATIONS_IMPORT`. Identifier expression: `{$table}`.

Table-name resolution: [caller assignment:658](../../includes/class-sn-migration-service.php#L658), passed to repair_mis_data_rows_table at line 659; the helper normalizes that table name.

```sql
CREATE TABLE {$table} (
id bigint unsigned NOT NULL AUTO_INCREMENT,
			batch_id bigint unsigned NOT NULL DEFAULT 0,
			`row_number` int unsigned NOT NULL DEFAULT 0,
			customer_name varchar(191) NULL,
			customer_phone varchar(50) NOT NULL DEFAULT '',
			normalized_phone varchar(50) NOT NULL DEFAULT '',
			city varchar(100) NULL,
			province varchar(100) NULL,
			source_meta longtext NULL,
			row_status varchar(30) NOT NULL DEFAULT 'valid',
			duplicate_reason varchar(191) NULL,
			assigned_manager_user_id bigint unsigned NULL,
			assigned_at datetime NULL,
			created_at datetime NULL,
			updated_at datetime NULL,
			PRIMARY KEY  (id),
			KEY batch_id (batch_id),
			KEY normalized_phone (normalized_phone),
			KEY row_status (row_status),
			KEY assigned_manager_user_id (assigned_manager_user_id)
)
```

### 55. `sn_mis_import_runs`

Source: [includes/class-sn-mis-import-log.php:12](../../includes/class-sn-mis-import-log.php#L12) — module `MIS`. Identifier expression: `{$p}sn_mis_import_runs`.

```sql
CREATE TABLE {$p}sn_mis_import_runs (
id bigint unsigned NOT NULL AUTO_INCREMENT,batch_id bigint unsigned NOT NULL,
            batch_code varchar(120) NOT NULL,actor_user_id bigint unsigned NOT NULL,
            filename varchar(191) NOT NULL,file_sha256 char(64) NOT NULL DEFAULT '',file_bytes bigint unsigned NOT NULL DEFAULT 0,
            status varchar(24) NOT NULL DEFAULT 'received',total_rows int unsigned NOT NULL DEFAULT 0,
            valid_rows int unsigned NOT NULL DEFAULT 0,invalid_rows int unsigned NOT NULL DEFAULT 0,duplicate_rows int unsigned NOT NULL DEFAULT 0,
            imported_rows int unsigned NOT NULL DEFAULT 0,unchanged_rows int unsigned NOT NULL DEFAULT 0,
            report_json longtext,error_message text,created_at datetime NOT NULL,updated_at datetime NOT NULL,
            PRIMARY KEY(id),KEY batch_time(batch_id,created_at,id),KEY status_time(status,created_at),KEY actor_time(actor_user_id,created_at)
)
```

### 56. `sn_mis_jobs`

Source: [includes/class-sn-mis-jobs.php:13](../../includes/class-sn-mis-jobs.php#L13) — module `MIS`. Identifier expression: `{$p}sn_mis_jobs`.

```sql
CREATE TABLE {$p}sn_mis_jobs (
id bigint unsigned NOT NULL AUTO_INCREMENT, owner_id bigint unsigned NOT NULL, kind varchar(20) NOT NULL, status varchar(20) NOT NULL DEFAULT 'queued', payload longtext, cursor_id bigint unsigned NOT NULL DEFAULT 0, total bigint unsigned NOT NULL DEFAULT 0, processed bigint unsigned NOT NULL DEFAULT 0, result_json longtext, created_at datetime NOT NULL, updated_at datetime NOT NULL, expires_at datetime NOT NULL, PRIMARY KEY(id), KEY owner_status(owner_id,status), KEY expires(expires_at)
)
```

### 57. `sn_mis_job_rows`

Source: [includes/class-sn-mis-jobs.php:14](../../includes/class-sn-mis-jobs.php#L14) — module `MIS`. Identifier expression: `{$p}sn_mis_job_rows`.

```sql
CREATE TABLE {$p}sn_mis_job_rows (
job_id bigint unsigned NOT NULL, cursor_id bigint unsigned NOT NULL, row_id bigint unsigned NOT NULL, event_id bigint unsigned NOT NULL DEFAULT 0, PRIMARY KEY(job_id,cursor_id)
)
```

### 58. `sn_mis_row_index`

Source: [includes/class-sn-mis-read-model.php:22](../../includes/class-sn-mis-read-model.php#L22) — module `MIS`. Identifier expression: `{$p}sn_mis_row_index`.

```sql
CREATE TABLE {$p}sn_mis_row_index (
row_id bigint unsigned NOT NULL, logical_state varchar(20) NOT NULL DEFAULT 'PENDING',
                block_reason varchar(191) NOT NULL DEFAULT '', manager_id bigint unsigned NOT NULL DEFAULT 0,
                is_consumed tinyint(1) NOT NULL DEFAULT 0, assigned_event_id bigint unsigned DEFAULT NULL,
                returned_event_id bigint unsigned DEFAULT NULL, flags_json text, checked_at datetime NOT NULL,
                PRIMARY KEY(row_id), KEY state_row(logical_state,row_id), KEY manager_state(manager_id,logical_state,row_id),
                KEY consumed_row(is_consumed,row_id), KEY checked_row(checked_at,row_id)
)
```

### 59. `sn_mis_index_dirty`

Source: [includes/class-sn-mis-read-model.php:30](../../includes/class-sn-mis-read-model.php#L30) — module `MIS`. Identifier expression: `{$p}sn_mis_index_dirty`.

```sql
CREATE TABLE {$p}sn_mis_index_dirty (
row_id bigint unsigned NOT NULL, revision bigint unsigned NOT NULL DEFAULT 1,
                touched_at datetime NOT NULL, PRIMARY KEY(row_id)
)
```

### 60. `sn_mis_record_meta`

Source: [includes/class-sn-mis-service.php:29](../../includes/class-sn-mis-service.php#L29) — module `MIS`. Identifier expression: `{$p}sn_mis_record_meta`.

```sql
CREATE TABLE {$p}sn_mis_record_meta (
row_id bigint unsigned NOT NULL, source_code varchar(191) NOT NULL DEFAULT '',
                first_name varchar(191) NOT NULL DEFAULT '', last_name varchar(191) NOT NULL DEFAULT '',
                source_datetime_raw varchar(191) NOT NULL DEFAULT '',
                PRIMARY KEY (row_id), KEY source_code (source_code)
)
```

### 61. `sn_mis_events`

Source: [includes/class-sn-mis-service.php:35](../../includes/class-sn-mis-service.php#L35) — module `MIS`. Identifier expression: `{$p}sn_mis_events`.

```sql
CREATE TABLE {$p}sn_mis_events (
id bigint unsigned NOT NULL AUTO_INCREMENT, row_id bigint unsigned NOT NULL,
                batch_id bigint unsigned NOT NULL, operation_id varchar(64) NOT NULL,
                event_type varchar(40) NOT NULL, old_manager_id bigint unsigned DEFAULT NULL,
                new_manager_id bigint unsigned DEFAULT NULL, branch_key varchar(80) NOT NULL DEFAULT 'unknown',
                branch_label varchar(191) NOT NULL DEFAULT 'نامشخص', campaign_code varchar(120) NOT NULL DEFAULT '',
                actor_user_id bigint unsigned NOT NULL, before_json longtext, after_json longtext,
                reason text, created_at datetime NOT NULL,
                PRIMARY KEY (id), KEY row_event (row_id,event_type,id),
                KEY event_time (event_type,created_at), KEY batch_id (batch_id),
                KEY manager_branch (new_manager_id,branch_key), KEY operation_id (operation_id)
)
```

### 62. `sn_mis_claims`

Source: [includes/class-sn-mis-service.php:47](../../includes/class-sn-mis-service.php#L47) — module `MIS`. Identifier expression: `{$p}sn_mis_claims`.

```sql
CREATE TABLE {$p}sn_mis_claims (
row_id bigint unsigned NOT NULL, normalized_phone varchar(50) NOT NULL,
                owner_user_id bigint unsigned DEFAULT NULL, updated_at datetime NOT NULL,
                PRIMARY KEY (row_id), UNIQUE KEY phone_unique (normalized_phone)
)
```

### 63. `sn_operations_wallet_codes`

Source: [includes/class-sn-operations-execution.php:77](../../includes/class-sn-operations-execution.php#L77) — module `OPERATIONS`. Identifier expression: `{$t['codes']}`.

Table-name resolution: [includes/class-sn-operations-execution.php:49](../../includes/class-sn-operations-execution.php#L49), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['codes']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			product_id BIGINT UNSIGNED NOT NULL,
			code_hash CHAR(64) NOT NULL,
			activation_code VARCHAR(191) NOT NULL,
			destination_url TEXT DEFAULT NULL,
			membership_item_id BIGINT UNSIGNED DEFAULT NULL,
			assigned_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id), UNIQUE KEY code_hash (code_hash), UNIQUE KEY membership_item_id (membership_item_id), KEY product_available (product_id,membership_item_id)
)
```

### 64. `sn_operations_form_definitions`

Source: [includes/class-sn-operations-execution.php:88](../../includes/class-sn-operations-execution.php#L88) — module `OPERATIONS`. Identifier expression: `{$t['forms']}`.

Table-name resolution: [includes/class-sn-operations-execution.php:51](../../includes/class-sn-operations-execution.php#L51), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['forms']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			title VARCHAR(191) NOT NULL,
			slug VARCHAR(191) NOT NULL,
			description TEXT DEFAULT NULL,
			fields_json LONGTEXT NOT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			updated_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id), UNIQUE KEY slug (slug), KEY is_active (is_active)
)
```

### 65. `sn_operations_execution_cases`

Source: [includes/class-sn-operations-execution.php:101](../../includes/class-sn-operations-execution.php#L101) — module `OPERATIONS`. Identifier expression: `{$t['cases']}`.

Table-name resolution: [includes/class-sn-operations-execution.php:50](../../includes/class-sn-operations-execution.php#L50), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['cases']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			operation_id BIGINT UNSIGNED NOT NULL,
			membership_item_id BIGINT UNSIGNED NOT NULL,
			content_product_id BIGINT UNSIGNED NOT NULL,
			fulfillment_type VARCHAR(30) NOT NULL DEFAULT 'unconfigured',
			form_definition_id BIGINT UNSIGNED DEFAULT NULL,
			config_snapshot_json LONGTEXT DEFAULT NULL,
			form_schema_snapshot_json LONGTEXT DEFAULT NULL,
			manager_user_id BIGINT UNSIGNED DEFAULT NULL,
			expert_user_id BIGINT UNSIGNED DEFAULT NULL,
			assigned_by_user_id BIGINT UNSIGNED DEFAULT NULL,
			assigned_at DATETIME DEFAULT NULL,
			status VARCHAR(40) NOT NULL DEFAULT 'manager_queue',
			no_answer_count INT UNSIGNED NOT NULL DEFAULT 0,
			follow_up_at DATETIME DEFAULT NULL,
			last_contact_at DATETIME DEFAULT NULL,
			last_note TEXT DEFAULT NULL,
			cancel_reason TEXT DEFAULT NULL,
			cancelled_at DATETIME DEFAULT NULL,
			wallet_external_id VARCHAR(191) DEFAULT NULL,
			wallet_activation_code VARCHAR(191) DEFAULT NULL,
			wallet_status VARCHAR(40) DEFAULT NULL,
			wallet_usage_json LONGTEXT DEFAULT NULL,
			wallet_customer_url TEXT DEFAULT NULL,
			wallet_sms_sent_at DATETIME DEFAULT NULL,
			shipping_status VARCHAR(40) DEFAULT NULL,
			shipping_province VARCHAR(100) DEFAULT NULL,
			shipping_city VARCHAR(100) DEFAULT NULL,
			shipping_address TEXT DEFAULT NULL,
			shipping_postal_code VARCHAR(30) DEFAULT NULL,
			shipping_carrier VARCHAR(120) DEFAULT NULL,
			shipping_tracking_code VARCHAR(191) DEFAULT NULL,
			form_submission_id BIGINT UNSIGNED DEFAULT NULL,
			final_decision VARCHAR(30) DEFAULT NULL,
			completed_at DATETIME DEFAULT NULL,
			completed_by_user_id BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id), UNIQUE KEY operation_id (operation_id), KEY expert_status (expert_user_id,status),
			KEY fulfillment_type (fulfillment_type,status), KEY membership_item_id (membership_item_id), KEY follow_up_at (follow_up_at)
)
```

### 66. `sn_operations_form_submissions`

Source: [includes/class-sn-operations-execution.php:143](../../includes/class-sn-operations-execution.php#L143) — module `OPERATIONS`. Identifier expression: `{$t['submissions']}`.

Table-name resolution: [includes/class-sn-operations-execution.php:52](../../includes/class-sn-operations-execution.php#L52), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['submissions']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			execution_case_id BIGINT UNSIGNED NOT NULL,
			form_definition_id BIGINT UNSIGNED DEFAULT NULL,
			schema_snapshot_json LONGTEXT NOT NULL,
			values_json LONGTEXT NOT NULL,
			submitted_by_user_id BIGINT UNSIGNED NOT NULL,
			submitted_at DATETIME NOT NULL,
			final_decision VARCHAR(30) DEFAULT NULL,
			final_note TEXT DEFAULT NULL,
			decided_by_user_id BIGINT UNSIGNED DEFAULT NULL,
			decided_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id), UNIQUE KEY execution_case_id (execution_case_id), KEY form_definition_id (form_definition_id)
)
```

### 67. `sn_operations_execution_events`

Source: [includes/class-sn-operations-execution.php:159](../../includes/class-sn-operations-execution.php#L159) — module `OPERATIONS`. Identifier expression: `{$t['events']}`.

Table-name resolution: [includes/class-sn-operations-execution.php:53](../../includes/class-sn-operations-execution.php#L53), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['events']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			execution_case_id BIGINT UNSIGNED NOT NULL,
			operation_id BIGINT UNSIGNED NOT NULL,
			membership_item_id BIGINT UNSIGNED NOT NULL,
			actor_user_id BIGINT UNSIGNED DEFAULT NULL,
			event_key VARCHAR(90) NOT NULL,
			details_json LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id), KEY case_events (execution_case_id,id), KEY operation_id (operation_id), KEY event_key (event_key)
)
```

### 68. `sn_project_operations`

Source: [includes/class-sn-operations-flow.php:93](../../includes/class-sn-operations-flow.php#L93) — module `OPERATIONS`. Identifier expression: `{$table}`.

Table-name resolution: [includes/class-sn-operations-flow.php:65](../../includes/class-sn-operations-flow.php#L65), literal definition/assignment in the same source.

```sql
CREATE TABLE {$table} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			membership_item_id BIGINT UNSIGNED NOT NULL,
			customer_choice VARCHAR(30) DEFAULT NULL,
			customer_choice_at DATETIME DEFAULT NULL,
			decision_due_at DATETIME DEFAULT NULL,
			auto_route_eligible TINYINT(1) NOT NULL DEFAULT 0,
			customer_self_activation_allowed TINYINT(1) NOT NULL DEFAULT 1,
			source_sales_manager_user_id BIGINT UNSIGNED DEFAULT NULL,
			operations_sales_manager_user_id BIGINT UNSIGNED DEFAULT NULL,
			sales_supervisor_user_id BIGINT UNSIGNED DEFAULT NULL,
			routing_source VARCHAR(30) NOT NULL DEFAULT 'legacy',
			activation_mode VARCHAR(20) DEFAULT NULL,
			base_credit DECIMAL(18,2) NOT NULL DEFAULT 0,
			current_credit DECIMAL(18,2) NOT NULL DEFAULT 0,
			upgrade_target_product_id BIGINT UNSIGNED DEFAULT NULL,
			upgrade_target_name VARCHAR(255) DEFAULT NULL,
			upgrade_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			upgrade_invoice_id BIGINT UNSIGNED DEFAULT NULL,
			upgrade_action_id BIGINT UNSIGNED DEFAULT NULL,
			payment_state VARCHAR(30) DEFAULT NULL,
			stage VARCHAR(40) NOT NULL DEFAULT 'awaiting_customer',
			sales_expert_user_id BIGINT UNSIGNED DEFAULT NULL,
			assigned_by_user_id BIGINT UNSIGNED DEFAULT NULL,
			assigned_at DATETIME DEFAULT NULL,
			supervisor_assigned_at DATETIME DEFAULT NULL,
			no_answer_count INT UNSIGNED NOT NULL DEFAULT 0,
			contact_status VARCHAR(30) DEFAULT NULL,
			last_contact_at DATETIME DEFAULT NULL,
			follow_up_at DATETIME DEFAULT NULL,
			follow_up_reminded_at DATETIME DEFAULT NULL,
			follow_up_reminder_attempts INT UNSIGNED NOT NULL DEFAULT 0,
			last_note TEXT DEFAULT NULL,
			cancel_reason TEXT DEFAULT NULL,
			cancelled_at DATETIME DEFAULT NULL,
			cancelled_by_user_id BIGINT UNSIGNED DEFAULT NULL,
			routed_at DATETIME DEFAULT NULL,
			completed_at DATETIME DEFAULT NULL,
			completed_by_user_id BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY membership_item_id (membership_item_id),
			KEY stage (stage),
			KEY operations_sales_manager_user_id (operations_sales_manager_user_id),
			KEY sales_supervisor_user_id (sales_supervisor_user_id),
			KEY source_sales_manager_user_id (source_sales_manager_user_id),
			KEY sales_expert_user_id (sales_expert_user_id),
			KEY contact_status (contact_status),
			KEY decision_due_at (decision_due_at),
			KEY follow_up_at (follow_up_at),
			KEY upgrade_invoice_id (upgrade_invoice_id)
)
```

### 69. `sn_lead_statuses`

Source: [includes/class-sn-plugin.php:671](../../includes/class-sn-plugin.php#L671) — module `CORE_AUTH`. Identifier expression: `{$st_table}`.

```sql
CREATE TABLE {$st_table} (
id INT UNSIGNED NOT NULL AUTO_INCREMENT,
				label VARCHAR(100) NOT NULL,
				color VARCHAR(20) NOT NULL DEFAULT '#6b7280',
				sort_order INT NOT NULL DEFAULT 0,
				is_active TINYINT(1) NOT NULL DEFAULT 1,
				destination_panel VARCHAR(50) DEFAULT NULL,
				move_to_destination TINYINT(1) NOT NULL DEFAULT 0,
				PRIMARY KEY (id)
)
```

### 70. `sn_activity_logs`

Source: [includes/class-sn-plugin.php:699](../../includes/class-sn-plugin.php#L699) — module `CORE_AUTH`. Identifier expression: `{$wpdb->prefix}sn_activity_logs`.

```sql
CREATE TABLE {$wpdb->prefix}sn_activity_logs (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			lead_id BIGINT UNSIGNED DEFAULT NULL,
			invoice_id BIGINT UNSIGNED DEFAULT NULL,
			user_id BIGINT UNSIGNED DEFAULT NULL,
			action VARCHAR(120) NOT NULL,
			old_value LONGTEXT DEFAULT NULL,
			new_value LONGTEXT DEFAULT NULL,
			description TEXT DEFAULT NULL,
			context LONGTEXT DEFAULT NULL,
			ip_address VARCHAR(64) DEFAULT NULL,
			user_agent TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY lead_id (lead_id),
			KEY invoice_id (invoice_id),
			KEY user_id (user_id),
			KEY action (action),
			KEY created_at (created_at)
)
```

### 71. `sn_invoice_payment_stages`

Source: [includes/class-sn-plugin.php:720](../../includes/class-sn-plugin.php#L720) — module `CORE_AUTH`. Identifier expression: `{$wpdb->prefix}sn_invoice_payment_stages`.

```sql
CREATE TABLE {$wpdb->prefix}sn_invoice_payment_stages (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			stage_no INT UNSIGNED NOT NULL DEFAULT 1,
			stage_type VARCHAR(30) NOT NULL DEFAULT 'full',
			requested_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			status VARCHAR(60) NOT NULL DEFAULT 'pending',
			pay_method VARCHAR(30) DEFAULT NULL,
			payment_source VARCHAR(60) DEFAULT NULL,
			payment_ref_id VARCHAR(191) DEFAULT NULL,
			receipt_urls LONGTEXT DEFAULT NULL,
			issued_by_user_id BIGINT UNSIGNED DEFAULT NULL,
			approved_by_user_id BIGINT UNSIGNED DEFAULT NULL,
			approved_at DATETIME DEFAULT NULL,
			paid_at DATETIME DEFAULT NULL,
			note TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY invoice_stage (invoice_id, stage_no),
			KEY invoice_id (invoice_id),
			KEY status (status)
)
```

### 72. `sn_hr_change_requests`

Source: [includes/class-sn-plugin.php:744](../../includes/class-sn-plugin.php#L744) — module `CORE_AUTH`. Identifier expression: `{$wpdb->prefix}sn_hr_change_requests`.

```sql
CREATE TABLE {$wpdb->prefix}sn_hr_change_requests (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			request_type VARCHAR(60) NOT NULL,
			subject_user_id BIGINT UNSIGNED NOT NULL,
			subject_profile_id BIGINT UNSIGNED DEFAULT NULL,
			current_parent_profile_id BIGINT UNSIGNED DEFAULT NULL,
			target_parent_profile_id BIGINT UNSIGNED DEFAULT NULL,
			target_parent_user_id BIGINT UNSIGNED DEFAULT NULL,
			requested_by_user_id BIGINT UNSIGNED NOT NULL,
			current_reviewer_user_id BIGINT UNSIGNED DEFAULT NULL,
			status VARCHAR(60) NOT NULL DEFAULT 'pending_review',
			reason TEXT DEFAULT NULL,
			reviewer_notes TEXT DEFAULT NULL,
			approval_path LONGTEXT DEFAULT NULL,
			applied_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY request_type (request_type),
			KEY subject_user_id (subject_user_id),
			KEY requested_by_user_id (requested_by_user_id),
			KEY current_reviewer_user_id (current_reviewer_user_id),
			KEY status (status),
			KEY created_at (created_at)
)
```

### 73. `sn_lead_status_history`

Source: [includes/class-sn-plugin.php:770](../../includes/class-sn-plugin.php#L770) — module `CORE_AUTH`. Identifier expression: `{$wpdb->prefix}sn_lead_status_history`.

```sql
CREATE TABLE {$wpdb->prefix}sn_lead_status_history (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			lead_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED DEFAULT NULL,
			old_status VARCHAR(100) DEFAULT NULL,
			new_status VARCHAR(100) DEFAULT NULL,
			note TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY lead_id (lead_id),
			KEY user_id (user_id),
			KEY created_at (created_at)
)
```

### 74. `sn_invoice_items`

Source: [includes/class-sn-plugin.php:924](../../includes/class-sn-plugin.php#L924) — module `CORE_AUTH`. Identifier expression: `{$wpdb->prefix}sn_invoice_items`.

```sql
CREATE TABLE {$wpdb->prefix}sn_invoice_items (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			product_name VARCHAR(255) NOT NULL,
			product_type VARCHAR(30) DEFAULT NULL,
			qty INT UNSIGNED NOT NULL DEFAULT 1,
			unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
			total_price DECIMAL(18,2) NOT NULL DEFAULT 0,
			is_free TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY invoice_id (invoice_id),
			KEY product_id (product_id)
)
```

### 75. `sn_invoice_wheel`

Source: [includes/class-sn-plugin.php:940](../../includes/class-sn-plugin.php#L940) — module `CORE_AUTH`. Identifier expression: `{$wpdb->prefix}sn_invoice_wheel`.

```sql
CREATE TABLE {$wpdb->prefix}sn_invoice_wheel (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			customer_id BIGINT UNSIGNED DEFAULT NULL,
			reward_type VARCHAR(40) DEFAULT NULL,
			reward_value VARCHAR(120) DEFAULT NULL,
			reward_payload LONGTEXT DEFAULT NULL,
			used_discount TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY invoice_id (invoice_id),
			KEY customer_id (customer_id)
)
```

### 76. `sn_payment_reward_claims`

Source: [includes/class-sn-plugin.php:954](../../includes/class-sn-plugin.php#L954) — module `CORE_AUTH`. Identifier expression: `{$wpdb->prefix}sn_payment_reward_claims`.

```sql
CREATE TABLE {$wpdb->prefix}sn_payment_reward_claims (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			invoice_code VARCHAR(40) DEFAULT NULL,
			product_id BIGINT UNSIGNED DEFAULT NULL,
			product_name VARCHAR(255) DEFAULT NULL,
			customer_name VARCHAR(120) DEFAULT NULL,
			customer_phone VARCHAR(20) DEFAULT NULL,
			reward_phone VARCHAR(20) NOT NULL,
			sms_sent TINYINT(1) NOT NULL DEFAULT 0,
			sms_status VARCHAR(40) DEFAULT NULL,
			sms_pattern VARCHAR(120) DEFAULT NULL,
			sms_template TEXT DEFAULT NULL,
			sms_error TEXT DEFAULT NULL,
			ip_address VARCHAR(64) DEFAULT NULL,
			user_agent TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY invoice_id (invoice_id),
			KEY invoice_code (invoice_code),
			KEY reward_phone (reward_phone),
			KEY customer_phone (customer_phone),
			KEY created_at (created_at)
)
```

### 77. `sn_extra_number_requests`

Source: [includes/class-sn-plugin.php:1027](../../includes/class-sn-plugin.php#L1027) — module `CORE_AUTH`. Identifier expression: `{$wpdb->prefix}sn_extra_number_requests`.

```sql
CREATE TABLE {$wpdb->prefix}sn_extra_number_requests (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			seller_id BIGINT UNSIGNED NOT NULL,
			manager_id BIGINT UNSIGNED DEFAULT NULL,
			phone VARCHAR(20) NOT NULL,
			customer_name VARCHAR(160) DEFAULT NULL,
			note TEXT DEFAULT NULL,
			status VARCHAR(40) NOT NULL DEFAULT 'pending',
			approved_by BIGINT UNSIGNED DEFAULT NULL,
			approved_at DATETIME DEFAULT NULL,
			rejected_by BIGINT UNSIGNED DEFAULT NULL,
			rejected_at DATETIME DEFAULT NULL,
			reject_reason TEXT DEFAULT NULL,
			lead_id BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY seller_id (seller_id),
			KEY manager_id (manager_id),
			KEY phone (phone),
			KEY status (status),
			KEY created_at (created_at)
)
```

### 78. `sn_aggregate_customer_ids`

Source: [includes/class-sn-plugin.php:4012](../../includes/class-sn-plugin.php#L4012) — module `CORE_AUTH`. Identifier expression: ``{$table}``.

```sql
CREATE TABLE `{$table}` (
sequence BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, identity_key CHAR(64) NOT NULL, first_invoice_id BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL, PRIMARY KEY (sequence), UNIQUE KEY identity_key (identity_key)
)
```

### 79. `sn_invoice_items`

Source: [includes/class-sn-plugin.php:4795](../../includes/class-sn-plugin.php#L4795) — module `CORE_AUTH`. Identifier expression: `{$item_table}`.

Table-name resolution: [includes/class-sn-plugin.php:4738](../../includes/class-sn-plugin.php#L4738), literal definition/assignment in the same source.

```sql
CREATE TABLE {$item_table} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			product_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			product_name VARCHAR(255) NOT NULL,
			qty INT UNSIGNED NOT NULL DEFAULT 1,
			unit_price DECIMAL(18,2) NOT NULL DEFAULT 0,
			total_price DECIMAL(18,2) NOT NULL DEFAULT 0,
			is_free TINYINT(1) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY invoice_id (invoice_id),
			KEY product_id (product_id)
)
```

### 80. `sn_invoice_payment_stages`

Source: [includes/class-sn-plugin.php:4811](../../includes/class-sn-plugin.php#L4811) — module `CORE_AUTH`. Identifier expression: `{$stage_table}`.

Table-name resolution: [includes/class-sn-plugin.php:4739](../../includes/class-sn-plugin.php#L4739), literal definition/assignment in the same source.

```sql
CREATE TABLE {$stage_table} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			stage_no INT UNSIGNED NOT NULL DEFAULT 1,
			stage_type VARCHAR(30) NOT NULL DEFAULT 'full',
			requested_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			status VARCHAR(60) NOT NULL DEFAULT 'pending',
			pay_method VARCHAR(30) DEFAULT NULL,
			payment_source VARCHAR(60) DEFAULT NULL,
			payment_ref_id VARCHAR(191) DEFAULT NULL,
			receipt_urls LONGTEXT DEFAULT NULL,
			issued_by_user_id BIGINT UNSIGNED DEFAULT NULL,
			approved_by_user_id BIGINT UNSIGNED DEFAULT NULL,
			approved_at DATETIME DEFAULT NULL,
			paid_at DATETIME DEFAULT NULL,
			note TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY invoice_stage (invoice_id, stage_no),
			KEY invoice_id (invoice_id),
			KEY status (status)
)
```

### 81. `sn_hr_change_requests`

Source: [includes/class-sn-plugin.php:11372](../../includes/class-sn-plugin.php#L11372) — module `CORE_AUTH`. Identifier expression: `{$table}`.

```sql
CREATE TABLE {$table} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			request_type VARCHAR(60) NOT NULL,
			subject_user_id BIGINT UNSIGNED NOT NULL,
			subject_profile_id BIGINT UNSIGNED DEFAULT NULL,
			current_parent_profile_id BIGINT UNSIGNED DEFAULT NULL,
			target_parent_profile_id BIGINT UNSIGNED DEFAULT NULL,
			target_parent_user_id BIGINT UNSIGNED DEFAULT NULL,
			requested_by_user_id BIGINT UNSIGNED NOT NULL,
			current_reviewer_user_id BIGINT UNSIGNED DEFAULT NULL,
			status VARCHAR(60) NOT NULL DEFAULT 'pending_review',
			reason TEXT DEFAULT NULL,
			reviewer_notes TEXT DEFAULT NULL,
			approval_path LONGTEXT DEFAULT NULL,
			applied_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY request_type (request_type),
			KEY subject_user_id (subject_user_id),
			KEY requested_by_user_id (requested_by_user_id),
			KEY current_reviewer_user_id (current_reviewer_user_id),
			KEY status (status),
			KEY created_at (created_at)
)
```

### 82. `sn_lead_statuses`

Source: [includes/class-sn-plugin.php:28190](../../includes/class-sn-plugin.php#L28190) — module `CORE_AUTH`. Identifier expression: `{$table}`.

```sql
CREATE TABLE {$table} (
id INT UNSIGNED NOT NULL AUTO_INCREMENT,
						label VARCHAR(100) NOT NULL,
						color VARCHAR(20) NOT NULL DEFAULT '#6b7280',
						sort_order INT NOT NULL DEFAULT 0,
						is_active TINYINT(1) NOT NULL DEFAULT 1,
						destination_panel VARCHAR(50) DEFAULT NULL,
						move_to_destination TINYINT(1) NOT NULL DEFAULT 0,
						PRIMARY KEY (id)
)
```

### 83. `sn_wallets`

Source: [includes/class-sn-plugin.php:35899](../../includes/class-sn-plugin.php#L35899) — module `CORE_AUTH`. Identifier expression: `{$wpdb->prefix}sn_wallets`.

```sql
CREATE TABLE {$wpdb->prefix}sn_wallets (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			wallet_type VARCHAR(30) NOT NULL DEFAULT 'seller',
			balance DECIMAL(18,2) NOT NULL DEFAULT 0,
			total_credit DECIMAL(18,2) NOT NULL DEFAULT 0,
			total_debit DECIMAL(18,2) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY user_wallet (user_id, wallet_type),
			KEY wallet_type (wallet_type)
)
```

### 84. `sn_wallet_transactions`

Source: [includes/class-sn-plugin.php:35912](../../includes/class-sn-plugin.php#L35912) — module `CORE_AUTH`. Identifier expression: `{$wpdb->prefix}sn_wallet_transactions`.

```sql
CREATE TABLE {$wpdb->prefix}sn_wallet_transactions (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wallet_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			wallet_type VARCHAR(30) NOT NULL DEFAULT 'seller',
			invoice_id BIGINT UNSIGNED DEFAULT NULL,
			lead_id BIGINT UNSIGNED DEFAULT NULL,
			amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			direction VARCHAR(10) NOT NULL DEFAULT 'credit',
			type VARCHAR(60) NOT NULL DEFAULT 'commission',
			status VARCHAR(30) NOT NULL DEFAULT 'approved',
			description TEXT DEFAULT NULL,
			meta LONGTEXT DEFAULT NULL,
			idempotency_key VARCHAR(191) DEFAULT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY wallet_id (wallet_id),
			KEY user_id (user_id),
			KEY invoice_id (invoice_id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY type (type),
			KEY created_at (created_at)
)
```

### 85. `sn_extra_number_requests`

Source: [includes/class-sn-plugin.php:38221](../../includes/class-sn-plugin.php#L38221) — module `CORE_AUTH`. Identifier expression: `{$table}`.

```sql
CREATE TABLE {$table} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
					seller_id BIGINT UNSIGNED NOT NULL,
					manager_id BIGINT UNSIGNED DEFAULT NULL,
					phone VARCHAR(20) NOT NULL,
					customer_name VARCHAR(160) DEFAULT NULL,
					note TEXT DEFAULT NULL,
					status VARCHAR(40) NOT NULL DEFAULT 'pending',
					approved_by BIGINT UNSIGNED DEFAULT NULL,
					approved_at DATETIME DEFAULT NULL,
					rejected_by BIGINT UNSIGNED DEFAULT NULL,
					rejected_at DATETIME DEFAULT NULL,
					reject_reason TEXT DEFAULT NULL,
					lead_id BIGINT UNSIGNED DEFAULT NULL,
					created_at DATETIME NOT NULL,
					updated_at DATETIME NOT NULL,
					PRIMARY KEY (id),
					KEY seller_id (seller_id),
					KEY manager_id (manager_id),
					KEY phone (phone),
					KEY status (status),
					KEY created_at (created_at)
)
```

### 86. `sn_plus_api_jobs`

Source: [includes/class-sn-plus-api.php:32](../../includes/class-sn-plus-api.php#L32) — module `INTEGRATIONS`. Identifier expression: `{$table}`.

Table-name resolution: [includes/class-sn-plus-api.php:8](../../includes/class-sn-plus-api.php#L8), literal definition/assignment in the same source.

```sql
CREATE TABLE {$table} (
sale_id BIGINT UNSIGNED NOT NULL,
                revision INT UNSIGNED NOT NULL,
                state_hash CHAR(64) NOT NULL,
                destination_hash CHAR(64) NOT NULL,
                event_id VARCHAR(36) NOT NULL,
                payload_json LONGTEXT NOT NULL,
                delivery_status VARCHAR(20) NOT NULL DEFAULT 'pending',
                attempts INT UNSIGNED NOT NULL DEFAULT 0,
                next_attempt_at DATETIME DEFAULT NULL,
                lease_token VARCHAR(36) DEFAULT NULL,
                lease_expires_at DATETIME DEFAULT NULL,
                last_http_code INT UNSIGNED NOT NULL DEFAULT 0,
                last_error_code VARCHAR(60) NOT NULL DEFAULT '',
                ack_at DATETIME DEFAULT NULL,
                updated_at DATETIME NOT NULL,
                PRIMARY KEY  (sale_id),
                UNIQUE KEY event_id (event_id),
                KEY delivery_due (delivery_status,next_attempt_at)
)
```

### 87. `sn_shipping_orders`

Source: [includes/class-sn-product-flow.php:69](../../includes/class-sn-product-flow.php#L69) — module `PRODUCTS_CATALOG`. Identifier expression: `{$t['orders']}`.

Table-name resolution: [includes/class-sn-product-flow.php:42](../../includes/class-sn-product-flow.php#L42), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['orders']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			seller_id BIGINT UNSIGNED NOT NULL,
			customer_wp_id BIGINT UNSIGNED DEFAULT NULL,
			customer_name VARCHAR(120) NOT NULL,
			customer_phone VARCHAR(20) NOT NULL,
			province VARCHAR(80) DEFAULT NULL,
			city VARCHAR(80) DEFAULT NULL,
			address TEXT DEFAULT NULL,
			postal_code VARCHAR(20) DEFAULT NULL,
			items_snapshot_json LONGTEXT DEFAULT NULL,
			total_amount DECIMAL(20,2) NOT NULL DEFAULT 0,
			contact_status VARCHAR(30) NOT NULL DEFAULT 'new',
			fulfillment_status VARCHAR(40) NOT NULL DEFAULT 'awaiting_review',
			shipping_method VARCHAR(80) DEFAULT NULL,
			carrier VARCHAR(120) DEFAULT NULL,
			tracking_code VARCHAR(120) DEFAULT NULL,
			expert_note TEXT DEFAULT NULL,
			failure_reason TEXT DEFAULT NULL,
			handled_by BIGINT UNSIGNED DEFAULT NULL,
			last_contact_at DATETIME DEFAULT NULL,
			shipped_at DATETIME DEFAULT NULL,
			delivered_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY invoice_id (invoice_id),
			KEY fulfillment_status (fulfillment_status,updated_at),
			KEY handled_by (handled_by,fulfillment_status),
			KEY customer_phone (customer_phone)
)
```

### 88. `sn_shipping_order_logs`

Source: [includes/class-sn-product-flow.php:101](../../includes/class-sn-product-flow.php#L101) — module `PRODUCTS_CATALOG`. Identifier expression: `{$t['logs']}`.

Table-name resolution: [includes/class-sn-product-flow.php:43](../../includes/class-sn-product-flow.php#L43), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['logs']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			shipping_order_id BIGINT UNSIGNED NOT NULL,
			invoice_id BIGINT UNSIGNED NOT NULL,
			actor_user_id BIGINT UNSIGNED DEFAULT NULL,
			event_key VARCHAR(80) NOT NULL,
			old_status VARCHAR(40) DEFAULT NULL,
			new_status VARCHAR(40) DEFAULT NULL,
			details_json LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY shipping_order_id (shipping_order_id,id),
			KEY invoice_id (invoice_id),
			KEY event_key (event_key)
)
```

### 89. `sn_product_flow_events`

Source: [includes/class-sn-product-flow.php:116](../../includes/class-sn-product-flow.php#L116) — module `PRODUCTS_CATALOG`. Identifier expression: `{$t['events']}`.

Table-name resolution: [includes/class-sn-product-flow.php:44](../../includes/class-sn-product-flow.php#L44), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['events']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			invoice_id BIGINT UNSIGNED NOT NULL,
			actor_user_id BIGINT UNSIGNED DEFAULT NULL,
			event_key VARCHAR(80) NOT NULL,
			details_json LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY invoice_id (invoice_id,id),
			KEY event_key (event_key)
)
```

### 90. `sn_project_subscriptions`

Source: [includes/class-sn-projects.php:125](../../includes/class-sn-projects.php#L125) — module `PROJECTS`. Identifier expression: `{$t['subscriptions']}`.

Table-name resolution: [includes/class-sn-projects.php:89](../../includes/class-sn-projects.php#L89), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['subscriptions']} (
subscription_product_id BIGINT UNSIGNED NOT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (subscription_product_id),
			KEY is_active (is_active)
)
```

### 91. `sn_project_subscription_contents`

Source: [includes/class-sn-projects.php:135](../../includes/class-sn-projects.php#L135) — module `PROJECTS`. Identifier expression: `{$t['contents']}`.

Table-name resolution: [includes/class-sn-projects.php:90](../../includes/class-sn-projects.php#L90), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['contents']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			subscription_product_id BIGINT UNSIGNED NOT NULL,
			content_product_id BIGINT UNSIGNED NOT NULL,
			sort_order INT NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY subscription_content (subscription_product_id,content_product_id),
			KEY content_product_id (content_product_id),
			KEY is_active (is_active)
)
```

### 92. `sn_project_manager_products`

Source: [includes/class-sn-projects.php:149](../../includes/class-sn-projects.php#L149) — module `PROJECTS`. Identifier expression: `{$t['managers']}`.

Table-name resolution: [includes/class-sn-projects.php:91](../../includes/class-sn-projects.php#L91), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['managers']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			content_product_id BIGINT UNSIGNED NOT NULL,
			manager_user_id BIGINT UNSIGNED NOT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY content_product_id (content_product_id),
			KEY manager_user_id (manager_user_id)
)
```

### 93. `sn_project_manager_sales_routes`

Source: [includes/class-sn-projects.php:163](../../includes/class-sn-projects.php#L163) — module `PROJECTS`. Identifier expression: `{$t['manager_routes']}`.

Table-name resolution: [includes/class-sn-projects.php:92](../../includes/class-sn-projects.php#L92), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['manager_routes']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			content_product_id BIGINT UNSIGNED NOT NULL,
			sales_manager_user_id BIGINT UNSIGNED NOT NULL,
			manager_user_id BIGINT UNSIGNED NOT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY content_sales_manager (content_product_id,sales_manager_user_id),
			KEY sales_manager_user_id (sales_manager_user_id),
			KEY manager_user_id (manager_user_id)
)
```

### 94. `sn_project_operations_manager_products`

Source: [includes/class-sn-projects.php:180](../../includes/class-sn-projects.php#L180) — module `PROJECTS`. Identifier expression: `{$t['operations_managers']}`.

Table-name resolution: [includes/class-sn-projects.php:93](../../includes/class-sn-projects.php#L93), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['operations_managers']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			content_product_id BIGINT UNSIGNED NOT NULL,
			operations_manager_user_id BIGINT UNSIGNED NOT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY content_product_id (content_product_id),
			KEY operations_manager_user_id (operations_manager_user_id)
)
```

### 95. `sn_project_operations_sales_routes`

Source: [includes/class-sn-projects.php:192](../../includes/class-sn-projects.php#L192) — module `PROJECTS`. Identifier expression: `{$t['operations_manager_routes']}`.

Table-name resolution: [includes/class-sn-projects.php:94](../../includes/class-sn-projects.php#L94), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['operations_manager_routes']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			content_product_id BIGINT UNSIGNED NOT NULL,
			sales_manager_user_id BIGINT UNSIGNED NOT NULL,
			operations_manager_user_id BIGINT UNSIGNED NOT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY content_sales_manager (content_product_id,sales_manager_user_id),
			KEY sales_manager_user_id (sales_manager_user_id),
			KEY operations_manager_user_id (operations_manager_user_id)
)
```

### 96. `sn_project_operations_supervisor_sales_routes`

Source: [includes/class-sn-projects.php:209](../../includes/class-sn-projects.php#L209) — module `PROJECTS`. Identifier expression: `{$t['operations_supervisor_routes']}`.

Table-name resolution: [includes/class-sn-projects.php:95](../../includes/class-sn-projects.php#L95), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['operations_supervisor_routes']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			content_product_id BIGINT UNSIGNED NOT NULL,
			sales_manager_user_id BIGINT UNSIGNED NOT NULL,
			operations_manager_user_id BIGINT UNSIGNED NOT NULL,
			operations_supervisor_user_id BIGINT UNSIGNED NOT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY content_sales_manager (content_product_id,sales_manager_user_id),
			KEY operations_manager_user_id (operations_manager_user_id),
			KEY operations_supervisor_user_id (operations_supervisor_user_id)
)
```

### 97. `sn_project_card_upgrade_rules`

Source: [includes/class-sn-projects.php:226](../../includes/class-sn-projects.php#L226) — module `PROJECTS`. Identifier expression: `{$t['upgrade_rules']}`.

Table-name resolution: [includes/class-sn-projects.php:96](../../includes/class-sn-projects.php#L96), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['upgrade_rules']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_product_id BIGINT UNSIGNED NOT NULL,
			target_product_id BIGINT UNSIGNED NOT NULL,
			upgrade_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY source_target (source_product_id,target_product_id),
			KEY target_product_id (target_product_id),
			KEY is_active (is_active)
)
```

### 98. `sn_project_memberships`

Source: [includes/class-sn-projects.php:241](../../includes/class-sn-projects.php#L241) — module `PROJECTS`. Identifier expression: `{$t['memberships']}`.

Table-name resolution: [includes/class-sn-projects.php:97](../../includes/class-sn-projects.php#L97), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['memberships']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_key VARCHAR(191) NOT NULL,
			source_type VARCHAR(30) NOT NULL,
			source_invoice_id BIGINT UNSIGNED NOT NULL,
			dot_case_id BIGINT UNSIGNED DEFAULT NULL,
			subscription_product_id BIGINT UNSIGNED NOT NULL,
			subscription_name_snapshot VARCHAR(255) NOT NULL,
			customer_wp_id BIGINT UNSIGNED DEFAULT NULL,
			customer_name VARCHAR(120) NOT NULL,
			customer_phone VARCHAR(20) NOT NULL,
			origin_user_id BIGINT UNSIGNED NOT NULL,
			origin_role VARCHAR(30) NOT NULL,
			source_sales_manager_user_id BIGINT UNSIGNED DEFAULT NULL,
			sales_chat_user_id BIGINT UNSIGNED DEFAULT NULL,
			sales_chat_role VARCHAR(30) DEFAULT NULL,
			first_payment_invoice_id BIGINT UNSIGNED NOT NULL,
			first_payment_stage_no INT UNSIGNED NOT NULL DEFAULT 1,
			first_payment_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			contents_snapshot_json LONGTEXT DEFAULT NULL,
			status VARCHAR(30) NOT NULL DEFAULT 'active',
			portal_invite_status VARCHAR(20) DEFAULT NULL,
			portal_invite_attempts INT UNSIGNED NOT NULL DEFAULT 0,
			portal_invite_attempted_at DATETIME DEFAULT NULL,
			portal_invite_sent_at DATETIME DEFAULT NULL,
			activated_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY source_key (source_key),
			KEY source_invoice_id (source_invoice_id),
			KEY dot_case_id (dot_case_id),
			KEY subscription_product_id (subscription_product_id),
			KEY customer_phone (customer_phone),
			KEY origin_user_id (origin_user_id),
			KEY source_sales_manager_user_id (source_sales_manager_user_id),
			KEY sales_chat_user_id (sales_chat_user_id),
			KEY status (status)
)
```

### 99. `sn_project_membership_items`

Source: [includes/class-sn-projects.php:282](../../includes/class-sn-projects.php#L282) — module `PROJECTS`. Identifier expression: `{$t['items']}`.

Table-name resolution: [includes/class-sn-projects.php:98](../../includes/class-sn-projects.php#L98), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['items']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			membership_id BIGINT UNSIGNED NOT NULL,
			content_product_id BIGINT UNSIGNED NOT NULL,
			content_name_snapshot VARCHAR(255) NOT NULL,
			base_credit_snapshot DECIMAL(18,2) DEFAULT NULL,
			upgrade_options_snapshot_json LONGTEXT DEFAULT NULL,
			manager_user_id BIGINT UNSIGNED DEFAULT NULL,
			expert_user_id BIGINT UNSIGNED DEFAULT NULL,
			workflow_status VARCHAR(30) NOT NULL DEFAULT 'waiting',
			cancel_reason TEXT DEFAULT NULL,
			last_contact_at DATETIME DEFAULT NULL,
			contact_attempts INT UNSIGNED NOT NULL DEFAULT 0,
			assigned_at DATETIME DEFAULT NULL,
			status_updated_at DATETIME DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY membership_content (membership_id,content_product_id),
			KEY content_product_cursor (content_product_id,id),
			KEY manager_user_id (manager_user_id),
			KEY expert_user_id (expert_user_id),
			KEY workflow_status (workflow_status)
)
```

### 100. `sn_project_notes`

Source: [includes/class-sn-projects.php:308](../../includes/class-sn-projects.php#L308) — module `PROJECTS`. Identifier expression: `{$t['notes']}`.

Table-name resolution: [includes/class-sn-projects.php:99](../../includes/class-sn-projects.php#L99), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['notes']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			membership_item_id BIGINT UNSIGNED NOT NULL,
			author_user_id BIGINT UNSIGNED NOT NULL,
			note TEXT NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY membership_item_id (membership_item_id),
			KEY author_user_id (author_user_id),
			KEY created_at (created_at)
)
```

### 101. `sn_project_messages`

Source: [includes/class-sn-projects.php:320](../../includes/class-sn-projects.php#L320) — module `PROJECTS`. Identifier expression: `{$t['messages']}`.

Table-name resolution: [includes/class-sn-projects.php:100](../../includes/class-sn-projects.php#L100), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['messages']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			membership_item_id BIGINT UNSIGNED NOT NULL,
			sender_user_id BIGINT UNSIGNED NOT NULL,
			message_text TEXT DEFAULT NULL,
			attachment_token CHAR(64) DEFAULT NULL,
			attachment_original_name VARCHAR(255) DEFAULT NULL,
			attachment_storage_name VARCHAR(100) DEFAULT NULL,
			attachment_mime VARCHAR(100) DEFAULT NULL,
			attachment_size BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY attachment_token (attachment_token),
			KEY membership_item_id (membership_item_id),
			KEY sender_user_id (sender_user_id),
			KEY created_at (created_at)
)
```

### 102. `sn_project_message_reads`

Source: [includes/class-sn-projects.php:338](../../includes/class-sn-projects.php#L338) — module `PROJECTS`. Identifier expression: `{$t['reads']}`.

Table-name resolution: [includes/class-sn-projects.php:101](../../includes/class-sn-projects.php#L101), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['reads']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			membership_item_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			last_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY item_user (membership_item_id,user_id),
			KEY user_id (user_id)
)
```

### 103. `sn_project_invoice_links`

Source: [includes/class-sn-projects.php:349](../../includes/class-sn-projects.php#L349) — module `PROJECTS`. Identifier expression: `{$t['invoice_links']}`.

Table-name resolution: [includes/class-sn-projects.php:102](../../includes/class-sn-projects.php#L102), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['invoice_links']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			membership_item_id BIGINT UNSIGNED NOT NULL,
			invoice_id BIGINT UNSIGNED NOT NULL,
			content_product_id BIGINT UNSIGNED NOT NULL,
			line_total DECIMAL(18,2) NOT NULL DEFAULT 0,
			status VARCHAR(30) NOT NULL DEFAULT 'verified',
			linked_by_user_id BIGINT UNSIGNED DEFAULT NULL,
			verified_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY item_invoice_content (membership_item_id,invoice_id,content_product_id),
			UNIQUE KEY invoice_id (invoice_id),
			KEY content_product_id (content_product_id),
			KEY status (status)
)
```

### 104. `sn_project_commission_rules`

Source: [includes/class-sn-projects.php:367](../../includes/class-sn-projects.php#L367) — module `PROJECTS`. Identifier expression: `{$t['rules']}`.

Table-name resolution: [includes/class-sn-projects.php:103](../../includes/class-sn-projects.php#L103), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['rules']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			content_product_id BIGINT UNSIGNED NOT NULL,
			commission_type VARCHAR(20) NOT NULL DEFAULT 'percent',
			commission_value DECIMAL(18,4) NOT NULL DEFAULT 0,
			is_active TINYINT(1) NOT NULL DEFAULT 1,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY content_product_id (content_product_id),
			KEY is_active (is_active)
)
```

### 105. `sn_project_commissions`

Source: [includes/class-sn-projects.php:381](../../includes/class-sn-projects.php#L381) — module `PROJECTS`. Identifier expression: `{$t['commissions']}`.

Table-name resolution: [includes/class-sn-projects.php:104](../../includes/class-sn-projects.php#L104), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['commissions']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			membership_item_id BIGINT UNSIGNED NOT NULL,
			invoice_id BIGINT UNSIGNED NOT NULL,
			recipient_user_id BIGINT UNSIGNED NOT NULL,
			base_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			commission_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			status VARCHAR(30) NOT NULL DEFAULT 'pending',
			wallet_transaction_id BIGINT UNSIGNED DEFAULT NULL,
			reversal_transaction_id BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY item_invoice_recipient (membership_item_id,invoice_id,recipient_user_id),
			KEY invoice_id (invoice_id),
			KEY recipient_user_id (recipient_user_id),
			KEY status (status)
)
```

### 106. `sn_project_events`

Source: [includes/class-sn-projects.php:400](../../includes/class-sn-projects.php#L400) — module `PROJECTS`. Identifier expression: `{$t['events']}`.

Table-name resolution: [includes/class-sn-projects.php:105](../../includes/class-sn-projects.php#L105), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['events']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			membership_id BIGINT UNSIGNED DEFAULT NULL,
			membership_item_id BIGINT UNSIGNED DEFAULT NULL,
			actor_user_id BIGINT UNSIGNED DEFAULT NULL,
			event_key VARCHAR(100) NOT NULL,
			details LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY membership_id (membership_id),
			KEY membership_item_id (membership_item_id),
			KEY event_key (event_key),
			KEY created_at (created_at)
)
```

### 107. `sn_project_actions`

Source: [includes/class-sn-projects.php:415](../../includes/class-sn-projects.php#L415) — module `PROJECTS`. Identifier expression: `{$t['actions']}`.

Table-name resolution: [includes/class-sn-projects.php:106](../../includes/class-sn-projects.php#L106), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['actions']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			membership_item_id BIGINT UNSIGNED NOT NULL,
			actor_user_id BIGINT UNSIGNED NOT NULL,
			action_type VARCHAR(20) NOT NULL,
			fulfillment_mode VARCHAR(20) NOT NULL,
			target_product_id BIGINT UNSIGNED DEFAULT NULL,
			target_product_name VARCHAR(255) DEFAULT NULL,
			base_credit DECIMAL(18,2) NOT NULL DEFAULT 0,
			target_credit DECIMAL(18,2) NOT NULL DEFAULT 0,
			amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			status VARCHAR(30) NOT NULL DEFAULT 'awaiting_payment',
			invoice_id BIGINT UNSIGNED DEFAULT NULL,
			payment_attempts INT UNSIGNED NOT NULL DEFAULT 0,
			last_payment_at DATETIME DEFAULT NULL,
			note TEXT DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY membership_item_id (membership_item_id),
			KEY invoice_id (invoice_id),
			KEY status (status),
			KEY action_type (action_type)
)
```

### 108. `sn_project_api_configs`

Source: [includes/class-sn-projects.php:441](../../includes/class-sn-projects.php#L441) — module `PROJECTS`. Identifier expression: `{$t['api_configs']}`.

Table-name resolution: [includes/class-sn-projects.php:107](../../includes/class-sn-projects.php#L107), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['api_configs']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			content_product_id BIGINT UNSIGNED NOT NULL,
			endpoint_url TEXT NOT NULL,
			api_key TEXT NOT NULL,
			project_code VARCHAR(100) NOT NULL,
			is_active TINYINT(1) NOT NULL DEFAULT 0,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY content_product_id (content_product_id),
			KEY project_code (project_code),
			KEY is_active (is_active)
)
```

### 109. `sn_wallets`

Source: [includes/class-sn-projects.php:588](../../includes/class-sn-projects.php#L588) — module `PROJECTS`. Identifier expression: `{$wpdb->prefix}sn_wallets`.

```sql
CREATE TABLE {$wpdb->prefix}sn_wallets (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			wallet_type VARCHAR(30) NOT NULL DEFAULT 'seller',
			balance DECIMAL(18,2) NOT NULL DEFAULT 0,
			total_credit DECIMAL(18,2) NOT NULL DEFAULT 0,
			total_debit DECIMAL(18,2) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY user_wallet (user_id,wallet_type),
			KEY wallet_type (wallet_type)
)
```

### 110. `sn_wallet_transactions`

Source: [includes/class-sn-projects.php:601](../../includes/class-sn-projects.php#L601) — module `PROJECTS`. Identifier expression: `{$wpdb->prefix}sn_wallet_transactions`.

```sql
CREATE TABLE {$wpdb->prefix}sn_wallet_transactions (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wallet_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			wallet_type VARCHAR(30) NOT NULL DEFAULT 'seller',
			invoice_id BIGINT UNSIGNED DEFAULT NULL,
			lead_id BIGINT UNSIGNED DEFAULT NULL,
			amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			direction VARCHAR(10) NOT NULL DEFAULT 'credit',
			type VARCHAR(60) NOT NULL DEFAULT 'commission',
			status VARCHAR(30) NOT NULL DEFAULT 'approved',
			description TEXT DEFAULT NULL,
			meta LONGTEXT DEFAULT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY wallet_id (wallet_id),
			KEY user_id (user_id),
			KEY invoice_id (invoice_id),
			KEY type (type),
			KEY created_at (created_at)
)
```

### 111. `sn_payment_commission_matrix`

Source: [includes/class-sn-purpose-commission.php:75](../../includes/class-sn-purpose-commission.php#L75) — module `COMMISSIONS_WALLETS`. Identifier expression: `{$table}`.

Table-name resolution: [includes/class-sn-purpose-commission.php:49](../../includes/class-sn-purpose-commission.php#L49), literal definition/assignment in the same source.

```sql
CREATE TABLE {$table} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			payment_purpose VARCHAR(60) NOT NULL,
			role_key VARCHAR(80) NOT NULL,
			rate_percent DECIMAL(7,4) NOT NULL DEFAULT 0,
			updated_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			UNIQUE KEY purpose_role (payment_purpose, role_key),
			KEY payment_purpose (payment_purpose),
			KEY role_key (role_key)
)
```

### 112. `sn_wallets`

Source: [includes/class-sn-purpose-commission.php:114](../../includes/class-sn-purpose-commission.php#L114) — module `COMMISSIONS_WALLETS`. Identifier expression: `{$wpdb->prefix}sn_wallets`.

```sql
CREATE TABLE {$wpdb->prefix}sn_wallets (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			user_id BIGINT UNSIGNED NOT NULL,
			wallet_type VARCHAR(30) NOT NULL DEFAULT 'seller',
			balance DECIMAL(18,2) NOT NULL DEFAULT 0,
			total_credit DECIMAL(18,2) NOT NULL DEFAULT 0,
			total_debit DECIMAL(18,2) NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY user_wallet (user_id,wallet_type),
			KEY wallet_type (wallet_type)
)
```

### 113. `sn_wallet_transactions`

Source: [includes/class-sn-purpose-commission.php:127](../../includes/class-sn-purpose-commission.php#L127) — module `COMMISSIONS_WALLETS`. Identifier expression: `{$wpdb->prefix}sn_wallet_transactions`.

```sql
CREATE TABLE {$wpdb->prefix}sn_wallet_transactions (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			wallet_id BIGINT UNSIGNED NOT NULL,
			user_id BIGINT UNSIGNED NOT NULL,
			wallet_type VARCHAR(30) NOT NULL DEFAULT 'seller',
			invoice_id BIGINT UNSIGNED DEFAULT NULL,
			lead_id BIGINT UNSIGNED DEFAULT NULL,
			amount DECIMAL(18,2) NOT NULL DEFAULT 0,
			direction VARCHAR(10) NOT NULL DEFAULT 'credit',
			type VARCHAR(60) NOT NULL DEFAULT 'commission',
			status VARCHAR(30) NOT NULL DEFAULT 'approved',
			description TEXT DEFAULT NULL,
			meta LONGTEXT DEFAULT NULL,
			idempotency_key VARCHAR(191) DEFAULT NULL,
			created_by BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY wallet_id (wallet_id),
			KEY user_id (user_id),
			KEY invoice_id (invoice_id),
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY type (type),
			KEY created_at (created_at)
)
```

### 114. `sn_payment_review_events`

Source: [includes/class-sn-receipt-review.php:17](../../includes/class-sn-receipt-review.php#L17) — module `PAYMENTS_FINANCE`. Identifier expression: `{$table}`.

```sql
CREATE TABLE {$table} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            invoice_id BIGINT UNSIGNED NOT NULL,
            payment_id BIGINT UNSIGNED NOT NULL,
            revision INT UNSIGNED NOT NULL,
            actor_user_id BIGINT UNSIGNED NOT NULL,
            action VARCHAR(40) NOT NULL,
            reason TEXT DEFAULT NULL,
            snapshot_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY invoice_payment (invoice_id,payment_id,id)
)
```

### 115. `sn_seller_flow_states`

Source: [includes/class-sn-seller-flow.php:68](../../includes/class-sn-seller-flow.php#L68) — module `SELLER_DISTRIBUTION`. Identifier expression: `{$t['states']}`.

Table-name resolution: [includes/class-sn-seller-flow.php:57](../../includes/class-sn-seller-flow.php#L57), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['states']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			source_kind VARCHAR(30) NOT NULL,
			source_id BIGINT UNSIGNED NOT NULL,
			seller_user_id BIGINT UNSIGNED NOT NULL,
			sales_manager_user_id BIGINT UNSIGNED DEFAULT NULL,
			flow_status VARCHAR(30) NOT NULL DEFAULT '',
			no_answer_attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			third_no_answer_at DATETIME DEFAULT NULL,
			callback_at DATETIME DEFAULT NULL,
			not_purchase_reason TEXT DEFAULT NULL,
			last_activity_at DATETIME NOT NULL,
			archived_at DATETIME DEFAULT NULL,
			archive_reason VARCHAR(60) DEFAULT NULL,
			archive_error VARCHAR(100) DEFAULT NULL,
			last_archive_attempt_at DATETIME DEFAULT NULL,
			last_invoice_id BIGINT UNSIGNED DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY source_ref (source_kind,source_id),
			KEY seller_active (seller_user_id,archived_at),
			KEY manager_archive (sales_manager_user_id,archived_at),
			KEY archive_due (flow_status,no_answer_attempts,archived_at,last_activity_at)
)
```

### 116. `sn_seller_flow_events`

Source: [includes/class-sn-seller-flow.php:98](../../includes/class-sn-seller-flow.php#L98) — module `SELLER_DISTRIBUTION`. Identifier expression: `{$t['events']}`.

Table-name resolution: [includes/class-sn-seller-flow.php:58](../../includes/class-sn-seller-flow.php#L58), literal definition/assignment in the same source.

```sql
CREATE TABLE {$t['events']} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			state_id BIGINT UNSIGNED NOT NULL,
			source_kind VARCHAR(30) NOT NULL,
			source_id BIGINT UNSIGNED NOT NULL,
			seller_user_id BIGINT UNSIGNED NOT NULL,
			actor_user_id BIGINT UNSIGNED DEFAULT NULL,
			event_key VARCHAR(60) NOT NULL,
			request_token VARCHAR(64) DEFAULT NULL,
			previous_status VARCHAR(30) DEFAULT NULL,
			new_status VARCHAR(30) DEFAULT NULL,
			attempt_number TINYINT UNSIGNED DEFAULT NULL,
			reason TEXT DEFAULT NULL,
			invoice_id BIGINT UNSIGNED DEFAULT NULL,
			payload_json LONGTEXT DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			KEY state_created (state_id,created_at),
			KEY source_created (source_kind,source_id,created_at),
			KEY seller_created (seller_user_id,created_at),
			KEY event_key (event_key),
			UNIQUE KEY request_token (request_token)
)
```

### 117. `sn_subscription_plus_sales`

Source: [includes/class-sn-subscription-plus.php:81](../../includes/class-sn-subscription-plus.php#L81) — module `SUBSCRIPTIONS_PLUS`. Identifier expression: `{$table}`.

Table-name resolution: [includes/class-sn-subscription-plus.php:24](../../includes/class-sn-subscription-plus.php#L24), literal definition/assignment in the same source.

```sql
CREATE TABLE {$table} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            source_invoice_id BIGINT UNSIGNED NOT NULL,
            product_id BIGINT UNSIGNED NOT NULL,
            snapshot_json LONGTEXT NOT NULL,
            base_paid_price DECIMAL(18,2) NOT NULL DEFAULT 0,
            cash_price DECIMAL(18,2) NOT NULL DEFAULT 0,
            requires_conversion TINYINT(1) NOT NULL DEFAULT 0,
            membership_id BIGINT UNSIGNED DEFAULT NULL,
            cash_conversion_case_id BIGINT UNSIGNED DEFAULT NULL,
            cash_profile_sms_sent_at DATETIME DEFAULT NULL,
            cash_profile_sms_state VARCHAR(20) NOT NULL DEFAULT '',
            status VARCHAR(40) NOT NULL DEFAULT 'awaiting_payment',
            api_payload_json LONGTEXT DEFAULT NULL,
            api_sync_status VARCHAR(30) NOT NULL DEFAULT 'not_ready',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY source_invoice_id (source_invoice_id),
            UNIQUE KEY membership_id (membership_id),
            UNIQUE KEY cash_conversion_case_id (cash_conversion_case_id),
            KEY status (status)
)
```

### 118. `sn_leads`

Source: [tests/mis/bootstrap.php:22](../../tests/mis/bootstrap.php#L22) — module `UNVERIFIED`. Identifier expression: `{$wpdb->prefix}sn_leads`.

```sql
CREATE TABLE {$wpdb->prefix}sn_leads (
id bigint unsigned NOT NULL AUTO_INCREMENT,phone varchar(50),seller_id bigint unsigned,status varchar(60),lead_status varchar(60),PRIMARY KEY(id),KEY phone(phone)
)
```

### 119. `sn_invoices`

Source: [tests/mis/bootstrap.php:23](../../tests/mis/bootstrap.php#L23) — module `UNVERIFIED`. Identifier expression: `{$wpdb->prefix}sn_invoices`.

```sql
CREATE TABLE {$wpdb->prefix}sn_invoices (
id bigint unsigned NOT NULL AUTO_INCREMENT,lead_id bigint unsigned,status varchar(60),PRIMARY KEY(id),KEY lead_id(lead_id)
)
```

## Runtime and historical limits

Schema versions: SN_Activator::DB_VERSION در activator، نسخه‌بندی SN_Migration_Service، SN_LEGACY_RUNTIME_VERSION در bootstrap و نسخه‌های مستقل ماژول‌ها با نسخه Plugin یکسان نیستند. اجرای نصب/upgrade برای اثبات schema در این مأموریت ممنوع است. برای بررسی نهایی، schema-only export بدون داده و با مجوز مالک و بررسی SHOW INDEX/SHOW CREATE TABLE لازم است. [DB_SCHEMA.md](../DB_SCHEMA.md) شامل قراردادهای تاریخی مکمل است.
