# Customer ID v2 (gated)

Source: latestest-sales-network-v4.zip (user-provided). Legacy registry unchanged until separately verified migration and explicit activation.

The new mapping table `wp_sn_customer_id_map_v2` must have `normalized_phone`, `sequence`, `source_type`, `first_invoice_id`, `first_invoice_at` and unique constraints. Test mapping is NOT automatically copied. This ZIP alone never remaps customers.

Activation options (DO NOT SET until production migration fully verified):
- sn_customer_id_map_v2_active = yes
- sn_customer_id_map_v2_historical_verified = 15475:26204
- sn_customer_id_map_v2_invoice_cursor = snapshot max invoice id as of completed map

Production cutover, concurrency, and rollback are NOT yet tested. Installation on production is not approved on syntax checks alone.
