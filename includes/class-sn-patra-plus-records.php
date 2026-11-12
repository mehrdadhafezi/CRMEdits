<?php
if (! defined('ABSPATH')) {
	exit;
}

final class SN_Patra_Plus_Records
{
	private $tables = [];

	public function page(string $type, int $after_id, int $limit, string $code): array
	{
		global $wpdb;
		$invoices = $wpdb->prefix . 'sn_invoices';
		$links = $wpdb->prefix . 'sn_dot_invoice_links';
		$where = 'i.id>%d';
		$args = [$after_id];

		if ($code !== '') {
			$match = $this->rows("SELECT id FROM {$invoices} WHERE invoice_code=%s LIMIT 1", [$code]);
			$invoice_id = (int) ($match[0]['id'] ?? 0);
			if (! $invoice_id) {
				return ['items' => [], 'has_more' => false, 'next_after_id' => null];
			}
			$where .= ' AND i.id=%d';
			$args[] = $invoice_id;
			if ($this->table_exists($links)) {
				$match = $this->rows("SELECT case_id FROM {$links} WHERE invoice_id=%d AND flow_kind='conversion_payment' LIMIT 1", [$invoice_id]);
				$case_id = (int) ($match[0]['case_id'] ?? 0);
				if ($case_id) {
					$where = "i.id>%d AND EXISTS (
						SELECT 1 FROM {$links} l WHERE l.invoice_id=i.id AND l.case_id=%d AND l.flow_kind='conversion_payment'
					)";
					$args = [$after_id, $case_id];
				}
			}
		}

		$rows = $this->rows("SELECT i.* FROM {$invoices} i WHERE {$where} ORDER BY i.id ASC LIMIT %d", array_merge($args, [$limit + 1]));
		$has_more = count($rows) > $limit;
		if ($has_more) {
			array_pop($rows);
		}
		if (! $rows) {
			return ['items' => [], 'has_more' => false, 'next_after_id' => null];
		}

		$ids = array_map('intval', array_column($rows, 'id'));
		$page = ['items' => [], 'has_more' => $has_more, 'next_after_id' => $has_more ? end($ids) : null];
		$shipping = $type !== 'invoice' ? $this->by_invoice($this->related_rows('sn_shipping_orders', $ids)) : [];
		$operations = $type !== 'invoice' ? $this->operations_orders($ids) : [];
		$woo_orders = $type !== 'invoice' ? $this->woo_orders($rows, $ids) : [];
		if ($type === 'order') {
			$order_invoice_ids = $shipping + $operations + $woo_orders;
			$rows = array_filter($rows, static fn(array $invoice): bool => isset($order_invoice_ids[(int) $invoice['id']]));
			if (! $rows) {
				return $page;
			}
			$ids = array_map('intval', array_column($rows, 'id'));
		}
		$dot_refs = SN_Dot_Flow::instance()->conversion_invoice_references($ids);
		$dot_data = $this->dot_data($links, $ids);
		$lead_ids = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'lead_id')))));
		$lead_supervisors = array_column($this->related_rows('sn_leads', $lead_ids, 'id', 'id, supervisor_id'), 'supervisor_id', 'id');
		$supervisors = [];
		foreach ($rows as $row) {
			$id = (int) $row['id'];
			$supervisors[$id] = (int) ($dot_data[$id]['supervisor_id'] ?? 0) ?: (int) ($lead_supervisors[(int) ($row['lead_id'] ?? 0)] ?? 0);
		}
		$staff = sn_bootstrap_plugin_instance(false)->patra_plus_staff_map($rows, $supervisors, $this->sale_roles($ids));
		$customer_ids = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'customer_wp_id')))));
		if ($customer_ids) {
			update_meta_cache('user', $customer_ids);
		}

		$items = $this->by_invoice($this->related_rows('sn_invoice_items', $ids, 'invoice_id', 'invoice_id, product_id, product_name, qty'));
		$stages = $this->by_invoice($this->related_rows('sn_invoice_payment_stages', $ids));
		$payments = $this->by_invoice($this->related_rows('sn_payments', $ids));
		foreach ($rows as $invoice) {
			$id = (int) $invoice['id'];
			$dot = $dot_refs[$id] ?? [];
			$context = $dot_data[$id] ?? [];
			$base = $this->invoice_record($invoice, $items[$id] ?? [], $staff[$id] ?? [], $dot, $context);
			$entities = $type !== 'order' ? [$base] : [];
			foreach ($woo_orders[$id] ?? [] as $woo_id) {
				$entities[] = $this->woo_record($base, $woo_id);
			}
			foreach ($shipping[$id] ?? [] as $order) {
				$snapshot = json_decode((string) ($order['items_snapshot_json'] ?? ''), true);
				$entities[] = $this->shipping_record($base, $order, 'shipping', is_array($snapshot) ? $snapshot : []);
			}
			foreach ($operations[$id] ?? [] as $order) {
				$entities[] = $this->shipping_record($base, $order, 'operations_shipping', []);
			}
			$invoice_stages = $this->payment_stages($invoice, $stages[$id] ?? [], $payments[$id] ?? [], $dot, $context);
			foreach ($entities as $entity) {
				$entity_id = $entity['record_type'] === 'invoice' ? 'invoice' : $entity['order_type'] . ':' . $entity['order_id'];
				foreach ($invoice_stages as $stage) {
					$page['items'][] = $entity + [
						'record_id' => $entity_id . ':' . $id . ':' . $stage['source_stage_no'],
						'payment_stage' => $stage,
					];
				}
			}
		}

		return $page;
	}

	private function rows(string $sql, array $args): array
	{
		global $wpdb;
		$rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
		if ($wpdb->last_error) {
			throw new RuntimeException('patra_plus_query_failed');
		}
		return $rows ?: [];
	}

	private function table_exists(string $table): bool
	{
		global $wpdb;
		if (! array_key_exists($table, $this->tables)) {
			$this->tables[$table] = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table))) === $table;
		}
		return $this->tables[$table];
	}

	private function marks(array $ids, string $placeholder = '%d'): string
	{
		return implode(',', array_fill(0, count($ids), $placeholder));
	}

	private function related_rows(string $suffix, array $ids, string $column = 'invoice_id', string $fields = '*'): array
	{
		global $wpdb;
		$table = $wpdb->prefix . $suffix;
		if (! $ids || ! $this->table_exists($table)) {
			return [];
		}
		$marks = $this->marks($ids);
		return $this->rows("SELECT {$fields} FROM {$table} WHERE {$column} IN ({$marks}) ORDER BY id ASC", $ids);
	}

	private function by_invoice(array $rows): array
	{
		$grouped = [];
		foreach ($rows as $row) {
			$grouped[(int) $row['invoice_id']][] = $row;
		}
		return $grouped;
	}

	private function dot_data(string $links, array $ids): array
	{
		global $wpdb;
		if (! $this->table_exists($links)) {
			return [];
		}
		$cases = $wpdb->prefix . 'sn_dot_cases';
		$payments = $wpdb->prefix . 'sn_dot_payments';
		$fields = 'l.invoice_id, l.supervisor_id';
		$joins = '';
		if ($this->table_exists($cases)) {
			$fields .= ', c.selected_option_price AS case_total';
			$joins .= " LEFT JOIN {$cases} c ON c.id=l.case_id AND l.flow_kind='conversion_payment'";
		}
		if ($this->table_exists($payments)) {
			$fields .= ', p.payment_type';
			$joins .= " LEFT JOIN {$payments} p ON p.invoice_id=l.invoice_id AND p.case_id=l.case_id AND l.flow_kind='conversion_payment'";
		}
		$marks = $this->marks($ids);
		return array_column($this->rows("SELECT {$fields} FROM {$links} l {$joins} WHERE l.invoice_id IN ({$marks})", $ids), null, 'invoice_id');
	}

	private function sale_roles(array $ids): array
	{
		global $wpdb;
		$table = $wpdb->prefix . 'sn_payment_review_events';
		if (! $this->table_exists($table)) {
			return [];
		}
		$marks = $this->marks($ids);
		$rows = $this->rows(
			"SELECT invoice_id, snapshot_json FROM {$table}
			WHERE invoice_id IN ({$marks}) AND payment_id=0 AND action='sale_policy'
			ORDER BY id ASC",
			$ids
		);
		$roles = [];
		foreach ($rows as $row) {
			$id = (int) $row['invoice_id'];
			$snapshot = json_decode((string) $row['snapshot_json'], true);
			if (! isset($roles[$id]) && is_array($snapshot['recipients'] ?? null)) {
				$roles[$id] = $snapshot['recipients'];
			}
		}
		return $roles;
	}

	private function operations_orders(array $ids): array
	{
		global $wpdb;
		$cases = $wpdb->prefix . 'sn_operations_execution_cases';
		$items = $wpdb->prefix . 'sn_project_membership_items';
		$members = $wpdb->prefix . 'sn_project_memberships';
		if (! $this->table_exists($cases) || ! $this->table_exists($items) || ! $this->table_exists($members)) {
			return [];
		}
		$marks = $this->marks($ids);
		return $this->by_invoice($this->rows(
			"SELECT m.source_invoice_id AS invoice_id, c.id, c.shipping_status AS fulfillment_status,
				c.shipping_province AS province, c.shipping_city AS city, c.shipping_address AS address,
				m.customer_name, m.customer_phone, mi.content_name_snapshot AS fulfillment_title
			FROM {$members} m
			INNER JOIN {$items} mi ON mi.membership_id=m.id
			INNER JOIN {$cases} c ON c.membership_item_id=mi.id
			WHERE m.source_invoice_id IN ({$marks}) AND c.fulfillment_type='physical_invoice' AND c.shipping_status IS NOT NULL
			ORDER BY c.id ASC",
			$ids
		));
	}

	private function woo_orders(array $invoices, array $ids): array
	{
		global $wpdb;
		$orders = [];
		foreach ($invoices as $invoice) {
			$id = (int) ($invoice['wc_order_id'] ?? 0);
			if ($id > 0) {
				$orders[(int) $invoice['id']][$id] = $id;
			}
		}

		// A saved Woo order can have CRM metadata even if its invoice pointer was never updated.
		$marks = $this->marks($ids, '%s');
		$hpos = $wpdb->prefix . 'wc_orders';
		$hpos_meta = $hpos . '_meta';
		if (get_option('woocommerce_custom_orders_table_enabled') === 'yes' && $this->table_exists($hpos) && $this->table_exists($hpos_meta)) {
			$sql = "SELECT m.meta_value AS invoice_id, o.id AS order_id FROM {$hpos_meta} m
				INNER JOIN {$hpos} o ON o.id=m.order_id AND o.type='shop_order'
				WHERE m.meta_key='_sn_invoice_id' AND m.meta_value IN ({$marks})";
		} else {
			$sql = "SELECT m.meta_value AS invoice_id, o.ID AS order_id FROM {$wpdb->postmeta} m
				INNER JOIN {$wpdb->posts} o ON o.ID=m.post_id AND o.post_type='shop_order'
				WHERE m.meta_key='_sn_invoice_id' AND m.meta_value IN ({$marks})";
		}
		foreach ($this->rows($sql, $ids) as $row) {
			$id = (int) $row['order_id'];
			$orders[(int) $row['invoice_id']][$id] = $id;
		}
		foreach ($orders as &$by_id) {
			ksort($by_id, SORT_NUMERIC);
		}
		unset($by_id);
		return $orders;
	}

	private function invoice_record(array $invoice, array $lines, array $staff, array $dot, array $dot_data): array
	{
		$products = $this->products($lines);
		if ($dot && count($products) === 1) {
			$products[0]['title'] = SN_Dot_Flow::instance()->conversion_product_title($products[0]['title'], (string) ($dot_data['payment_type'] ?? ''));
		}
		if (! $products && ! empty($invoice['product_id'])) {
			$product_id = (int) $invoice['product_id'];
			$title = get_the_title($product_id);
			if ($title !== '') {
				$products[] = ['product_id' => $product_id, 'title' => $title, 'quantity' => 1];
			}
		}
		$name = (string) ($invoice['customer_name'] ?? '');
		[$first, $last] = SN_Helpers::customer_name_parts($name, (int) ($invoice['customer_wp_id'] ?? 0));
		return [
			'record_type' => 'invoice',
			'order_type' => null,
			'order_id' => null,
			'order_status' => null,
			'fulfillment_title' => null,
			'invoice_code' => (string) ($dot['invoice_code'] ?? $invoice['invoice_code']),
			'invoice_id' => (int) ($dot['anchor_id'] ?? $invoice['id']),
			'source_invoice_id' => (int) $invoice['id'],
			'invoice_status' => (string) ($invoice['status'] ?? ''),
			'order_title' => $this->product_title($products),
			'products' => $products,
			'customer_name' => $name,
			'first_name' => $first,
			'last_name' => $last,
			'province' => (string) ($invoice['province'] ?? ''),
			'city' => (string) ($invoice['city'] ?? ''),
			'phone' => (string) ($invoice['customer_phone'] ?? ''),
			'phone_secondary' => $this->value($invoice['customer_phone_secondary'] ?? ''),
			'address' => SN_Helpers::customer_address($invoice['customer_address'] ?? '') ?: SN_Helpers::MISSING_CUSTOMER_ADDRESS,
			'call_center' => $staff['call_center'] ?? null,
			'seller' => $staff['seller'] ?? null,
			'sales_manager' => $staff['sales_manager'] ?? null,
			'senior_supervisor' => $staff['senior_supervisor'] ?? null,
			'invoice_total' => $this->money($dot_data['case_total'] ?? $invoice['payment_total_amount'] ?? $invoice['final_total'] ?? $invoice['product_price'] ?? null),
			'currency' => 'IRT',
		];
	}

	private function shipping_record(array $base, array $order, string $type, array $lines): array
	{
		$record = array_replace($base, [
			'record_type' => 'order',
			'order_type' => $type,
			'order_id' => (int) $order['id'],
			'order_status' => $this->value($order['fulfillment_status'] ?? ''),
			'fulfillment_title' => $this->value($order['fulfillment_title'] ?? ''),
			'province' => $this->value($order['province'] ?? '') ?? $base['province'],
			'city' => $this->value($order['city'] ?? '') ?? $base['city'],
			'phone' => $this->value($order['customer_phone'] ?? '') ?? $base['phone'],
			'address' => SN_Helpers::customer_address($order['address'] ?? '') ?: $base['address'],
		]);
		$name = $this->value($order['customer_name'] ?? '');
		if ($name !== null && $name !== $base['customer_name']) {
			$record['customer_name'] = $name;
			[$record['first_name'], $record['last_name']] = SN_Helpers::customer_name_parts($name);
		}
		$products = $this->products($lines);
		if ($products) {
			$record['products'] = $products;
			$record['order_title'] = $this->product_title($products);
		}
		return $record;
	}

	private function woo_record(array $base, int $id): array
	{
		$record = array_replace($base, ['record_type' => 'order', 'order_type' => 'woocommerce', 'order_id' => $id]);
		$order = function_exists('wc_get_order') ? wc_get_order($id) : false;
		if (! $order instanceof WC_Order) {
			return $record;
		}
		$record['order_status'] = $this->value($order->get_status());
		$first = trim((string) $order->get_billing_first_name());
		$last = trim((string) $order->get_billing_last_name());
		if ($first !== '' && $last !== '') {
			$record['customer_name'] = $first . ' ' . $last;
			$record['first_name'] = $first;
			$record['last_name'] = $last;
		}
		$record['phone'] = $this->value($order->get_billing_phone()) ?? $base['phone'];
		$record['phone_secondary'] = $this->value($order->get_meta('_sn_customer_phone_secondary', true)) ?? $base['phone_secondary'];
		$record['province'] = $this->value($order->get_shipping_state()) ?? $this->value($order->get_billing_state()) ?? $base['province'];
		$record['city'] = $this->value($order->get_shipping_city()) ?? $this->value($order->get_billing_city()) ?? $base['city'];
		$record['address'] = SN_Helpers::customer_address(trim($order->get_shipping_address_1() . "\n" . $order->get_shipping_address_2()))
			?: SN_Helpers::customer_address(trim($order->get_billing_address_1() . "\n" . $order->get_billing_address_2()))
			?: $base['address'];
		if (! $record['products']) {
			foreach ($order->get_items() as $item) {
				$record['products'][] = ['product_id' => (int) $item->get_product_id(), 'title' => $item->get_name(), 'quantity' => (int) $item->get_quantity()];
			}
			$record['order_title'] = $this->product_title($record['products']);
		}
		return $record;
	}

	private function products(array $lines): array
	{
		$products = [];
		foreach ($lines as $line) {
			if (! is_array($line) || empty($line['product_name'])) {
				continue;
			}
			$products[] = [
				'product_id' => (int) ($line['product_id'] ?? 0),
				'title' => (string) $line['product_name'],
				'quantity' => max(1, (int) ($line['qty'] ?? 1)),
			];
		}
		return $products;
	}

	private function product_title(array $products): string
	{
		return implode('، ', array_unique(array_column($products, 'title')));
	}

	private function payment_stages(array $invoice, array $stages, array $payments, array $dot, array $dot_data): array
	{
		$current = max(1, (int) ($invoice['current_payment_stage'] ?? 1));
		$by_stage = [];
		$transactions = [];
		foreach ($stages as $stage) {
			$by_stage[max(1, (int) $stage['stage_no'])] = $stage;
		}
		foreach ($payments as $payment) {
			$number = max(1, (int) ($payment['payment_stage_no'] ?? 1));
			$by_stage[$number] = $by_stage[$number] ?? [];
			$transactions[$number][] = $payment;
		}
		if (! $by_stage) {
			$by_stage[$current] = [];
		}
		ksort($by_stage, SORT_NUMERIC);

		$result = [];
		foreach ($by_stage as $number => $stage) {
			$projection = $number === $current ? $invoice : [];
			$payment_rows = $transactions[$number] ?? [];
			$latest = $payment_rows ? end($payment_rows) : [];
			$status = (string) ($stage['status'] ?? $projection['status'] ?? $latest['status'] ?? 'pending');
			$approved = $this->approved($status);
			if (! $stage && $projection && in_array($status, ['pending', 'pre_invoice', 'pending_payment'], true)) {
				$approved = $this->approved((string) ($projection['payment_status'] ?? ''))
					|| $this->approved((string) ($projection['invoice_status'] ?? ''));
			}

			$method = $this->value($stage['pay_method'] ?? $projection['pay_method'] ?? $latest['pay_method'] ?? '');
			$source = $this->value($stage['payment_source'] ?? $projection['payment_source'] ?? $latest['payment_source'] ?? '');
			$state = $stage ?: $projection;
			$confirmed_at = $this->date($state['approved_at'] ?? '');
			$reviewer_id = (int) ($state['approved_by_user_id'] ?? $state['approved_by'] ?? 0);
			$is_gateway = $this->is_gateway($method, $source);
			$gateway_paid_at = $approved && $reviewer_id === 0 && $is_gateway ? $this->date($state['paid_at'] ?? '') : null;
			$gateway_ref = (string) ($stage['payment_ref_id'] ?? '');
			$entries = [];
			foreach ($payment_rows as $payment) {
				$matching_ref = $gateway_ref === '' || (string) ($payment['ref_id'] ?? '') === $gateway_ref;
				$entries[] = $this->transaction($payment, $invoice, $matching_ref ? $gateway_paid_at : null);
			}

			$relevant = array_filter($entries, static function (array $entry) use ($approved, $status): bool {
				if ($approved) {
					return $entry['payment_approved'];
				}
				return in_array($entry['status'], ['pending', 'verified', $status], true);
			});
			$dates = array_values(array_unique(array_filter(array_column($relevant, 'payment_date'))));
			$jalali_dates = array_values(array_unique(array_filter(array_column($relevant, 'payment_date_jalali'))));
			$accounts = [];
			foreach (array_column($relevant, 'destination_account') as $account) {
				if ($account !== null && ! in_array($account, $accounts, true)) {
					$accounts[] = $account;
				}
			}
			$legacy = $this->transfer_details($is_gateway ? [] : $projection, $invoice);
			$payment_date = count($dates) === 1 ? $dates[0] : null;
			$payment_date_jalali = count($jalali_dates) === 1 ? $jalali_dates[0] : null;
			if (! $dates && ! $jalali_dates) {
				$payment_date = $legacy['payment_date'] ?? $gateway_paid_at;
				$payment_date_jalali = $legacy['payment_date_jalali'];
			}
			if (! $accounts && $legacy['destination_account'] !== null) {
				$accounts[] = $legacy['destination_account'];
			}

			$result[] = [
				'stage_no' => (int) ($dot['stage_no'] ?? $number),
				'source_stage_no' => $number,
				'stage_type' => $this->value($dot_data['payment_type'] ?? $stage['stage_type'] ?? $projection['payment_plan'] ?? ''),
				'amount' => $this->money($stage['requested_amount'] ?? $this->legacy_stage_amount($projection, $latest)),
				'status' => $status,
				'payment_method' => $method,
				'payment_source' => $source,
				'payment_date' => $payment_date,
				'payment_date_jalali' => $payment_date_jalali,
				'destination_account' => count($accounts) === 1 ? $accounts[0] : null,
				'destination_accounts' => $accounts,
				'payment_approved' => $approved,
				'payment_approval' => $approved ? 'تایید شده' : 'تایید نشده',
				'approved_at' => $approved ? $confirmed_at : null,
				'transactions' => $entries,
			];
		}
		return $result;
	}

	private function legacy_stage_amount(array $invoice, array $payment)
	{
		foreach (['current_due_amount', 'manual_amount', 'deposit_amount'] as $field) {
			if ((float) ($invoice[$field] ?? 0) > 0) {
				return $invoice[$field];
			}
		}
		if (($invoice['payment_plan'] ?? 'full') === 'full') {
			return $invoice['payment_total_amount'] ?? $invoice['final_total'] ?? $invoice['product_price'] ?? $payment['amount'] ?? null;
		}
		return $payment['amount'] ?? null;
	}

	private function transaction(array $row, array $invoice, ?string $gateway_paid_at): array
	{
		$proof = json_decode((string) ($row['proof_data'] ?? ''), true);
		$transfer = $this->transfer_details(is_array($proof) ? $proof : [], $invoice);
		$method = $this->value($row['pay_method'] ?? '');
		$source = $this->value($row['payment_source'] ?? '');
		$status = (string) ($row['status'] ?? 'pending');
		if ($transfer['payment_date'] === null && $this->is_gateway($method, $source, $row)) {
			if ($status === 'verified' && in_array($source, ['zarinpal', 'zibal', 'asanpardakht'], true)) {
				$transfer['payment_date'] = $this->date($row['updated_at'] ?? '');
			} elseif ($this->approved($status)) {
				$transfer['payment_date'] = $gateway_paid_at;
			}
		}
		return [
			'transaction_id' => (int) $row['id'],
			'amount' => $this->money($row['amount'] ?? null),
			'status' => $status,
			'payment_method' => $method,
			'payment_source' => $source,
			'payment_approved' => $this->approved($status),
		] + $transfer;
	}

	private function transfer_details(array $proof, array $invoice): array
	{
		$jalali = $this->value($proof['manual_paid_at_jalali'] ?? $proof['deposit_jalali_datetime'] ?? '');
		$payment_date = $this->date($proof['manual_paid_at'] ?? '');
		if ($payment_date === null && $jalali !== null && preg_match('~^([0-9]{4}[/.-][0-9]{1,2}[/.-][0-9]{1,2})\s+([0-9]{2}:[0-9]{2})$~D', SN_Helpers::to_english_nums($jalali), $match)) {
			$parsed = SN_Helpers::normalize_jalali_tehran_datetime($match[1], $match[2], false, true);
			if (! is_wp_error($parsed)) {
				$payment_date = SN_Helpers::tehran_format(DATE_ATOM, (int) $parsed['timestamp']);
			}
		}

		$card = $this->digits($proof['manual_card_to_number'] ?? '', 16);
		$last4 = $this->digits($proof['manual_card_to'] ?? $proof['deposit_card_to_last4'] ?? '', 4);
		$last6 = $this->digits($proof['manual_account_last6'] ?? '', 6);
		$owner = $this->value($proof['manual_account_owner'] ?? '');
		$transfer_type = $this->value($proof['manual_transfer_type'] ?? '');
		$account = null;
		if ($last6 !== null) {
			$account = ['type' => $transfer_type ?? 'bank_transfer', 'number' => null, 'last_digits' => $last6, 'owner' => $owner];
		} elseif ($card !== null || $last4 !== null) {
			$snapshot = $this->digits($invoice['payment_card_number'] ?? '', 16);
			if ($owner === null && $card !== null && $card === $snapshot) {
				$owner = $this->value($invoice['payment_card_owner'] ?? '');
			}
			$account = ['type' => 'card', 'number' => $card, 'last_digits' => $card !== null ? substr($card, -4) : $last4, 'owner' => $owner];
		}
		return ['payment_date' => $payment_date, 'payment_date_jalali' => $jalali, 'destination_account' => $account];
	}

	private function is_gateway(?string $method, ?string $source, array $payment = []): bool
	{
		return ! empty($payment['authority']) || $method === 'online'
			|| in_array($method, ['gateway', 'zarinpal', 'zibal', 'asanpardakht'], true)
			|| in_array($source, ['zarinpal', 'zibal', 'asanpardakht'], true);
	}

	private function approved(string $status): bool
	{
		return in_array($status, [
			'paid', 'approved', 'completed', 'confirmed', 'payment_approved', 'finance_approved', 'financially_approved',
			'payment_confirmed', 'paid_confirmed', 'receipt_approved', 'receipt_confirmed', 'manual_approved',
			'finance_confirmed', 'finance_verified', 'success', 'successful', 'settled',
			'تایید مالی', 'تأیید مالی', 'تایید شده', 'تأیید شده', 'پرداخت شده', 'موفق',
		], true);
	}

	private function date($value): ?string
	{
		if (! is_string($value) || ! preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value)) {
			return null;
		}
		$timestamp = SN_Helpers::site_mysql_timestamp($value);
		return $timestamp > 0 ? SN_Helpers::tehran_format(DATE_ATOM, $timestamp) : null;
	}

	private function value($value): ?string
	{
		$value = is_scalar($value) ? trim((string) $value) : '';
		return $value !== '' ? $value : null;
	}

	private function digits($value, int $length): ?string
	{
		$number = preg_replace('/[^0-9]/', '', SN_Helpers::to_english_nums($this->value($value) ?? ''));
		return strlen($number) === $length ? $number : null;
	}

	private function money($value): ?string
	{
		if (! preg_match('/^(\d+)(?:\.(\d{1,2}))?$/D', (string) $value, $matches)) {
			return null;
		}
		return $matches[1] . '.' . str_pad($matches[2] ?? '', 2, '0');
	}
}
