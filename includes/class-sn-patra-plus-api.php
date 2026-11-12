<?php
if (! defined('ABSPATH')) {
	exit;
}

final class SN_Patra_Plus_API
{
	private const SETTINGS = 'sn_patra_plus_api_settings';
	private const KEY_HASH = 'sn_patra_plus_api_key_hash';
	private const REST_NAMESPACE = 'sn-crm/v1';
	private const ROUTE = '/patra-plus/records';
	private const TITLE = 'API پاترا پلاس';
	private const PAGE = 'sn-patra-plus-api';
	private const MAX_PAGE_SIZE = 200;

	private $new_key = '';
	private $notice = '';
	private $notice_type = 'success';

	public function register_hooks(): void
	{
		add_action('admin_menu', [$this, 'menu'], 20);
		add_action('rest_api_init', [$this, 'routes']);
		add_filter('rest_post_dispatch', [$this, 'no_cache'], 10, 3);
		add_filter('jwt_auth_do_jwt_check', [$this, 'skip_jwt'], 999);
	}

	public function menu(): void
	{
		$hook = add_submenu_page('sn-dashboard', self::TITLE, self::TITLE, 'manage_options', self::PAGE, [$this, 'render']);
		if ($hook) {
			add_action('load-' . $hook, [$this, 'handle_settings']);
			add_action('admin_enqueue_scripts', static function (string $screen) use ($hook): void {
				if ($screen === $hook) {
					wp_enqueue_style('sn-patra-console', SN_PLUGIN_URL . 'assets/css/patra-console.css', [], SN_VERSION);
					wp_enqueue_script('sn-patra-console', SN_PLUGIN_URL . 'assets/js/patra-console.js', [], SN_VERSION, true);
				}
			});
		}
	}

	private function settings(): array
	{
		$saved = (array) get_option(self::SETTINGS, []);
		return [
			'enabled' => ! empty($saved['enabled']),
			'page_size' => min(self::MAX_PAGE_SIZE, max(1, (int) ($saved['page_size'] ?? 50))),
		];
	}

	public function routes(): void
	{
		register_rest_route(self::REST_NAMESPACE, self::ROUTE, [
			'methods' => WP_REST_Server::READABLE,
			'permission_callback' => [$this, 'authorize'],
			'callback' => [$this, 'records'],
			'args' => [
				'type' => ['type' => 'string', 'enum' => ['all', 'invoice', 'order'], 'default' => 'all'],
				'after_id' => ['type' => 'integer', 'minimum' => 0, 'maximum' => PHP_INT_MAX, 'default' => 0],
				'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => self::MAX_PAGE_SIZE],
				'invoice_code' => [
					'type' => 'string',
					'maxLength' => 50,
					'validate_callback' => static function ($value): bool {
						return is_string($value) && strlen($value) <= 100
							&& preg_match('/^[A-Z0-9_-]{1,20}$/D', SN_Helpers::normalize_invoice_code($value)) === 1;
					},
				],
			],
		]);
	}

	public function authorize(WP_REST_Request $request)
	{
		if (! is_ssl()) {
			return new WP_Error('sn_patra_https_required', 'درخواست باید از طریق HTTPS باشد.', ['status' => 403]);
		}
		$hash = (string) get_option(self::KEY_HASH, '');
		if (! $this->settings()['enabled'] || $hash === '') {
			return new WP_Error('sn_patra_disabled', 'API پاترا پلاس غیرفعال است.', ['status' => 503]);
		}
		$key = trim((string) $request->get_header('x-patra-key'));
		$authorization = trim((string) $request->get_header('authorization'));
		if (preg_match('/^Bearer\s+(\S+)$/iD', $authorization, $match)) {
			$key = $match[1];
		}
		if (! preg_match('/^patra_[a-f0-9]{64}$/D', $key) || ! hash_equals($hash, hash('sha256', $key))) {
			return new WP_Error('sn_patra_unauthorized', 'کلید دسترسی API معتبر نیست.', ['status' => 401]);
		}
		$limit = SN_Helpers::rate_limit('patra_plus_records', $hash, 120, MINUTE_IN_SECONDS);
		if (! $limit['allowed']) {
			return new WP_Error('sn_patra_rate_limit', 'تعداد درخواست‌ها بیش از حد مجاز است.', ['status' => 429, 'retry_after' => $limit['retry_after']]);
		}
		return true;
	}

	public function records(WP_REST_Request $request)
	{
		try {
			$page = (new SN_Patra_Plus_Records())->page(
				(string) $request->get_param('type'),
				(int) $request->get_param('after_id'),
				(int) ($request->get_param('limit') ?? $this->settings()['page_size']),
				SN_Helpers::normalize_invoice_code((string) $request->get_param('invoice_code'))
			);
			return rest_ensure_response($page);
		} catch (RuntimeException $error) {
			return new WP_Error('sn_patra_records_unavailable', 'خواندن رکوردهای CRM در حال حاضر ممکن نیست.', ['status' => 503]);
		}
	}

	public function no_cache(WP_REST_Response $response, $server, WP_REST_Request $request): WP_REST_Response
	{
		if ($this->is_patra_route($request->get_route())) {
			$response->header('Cache-Control', 'private, no-store, max-age=0');
			$response->header('X-Robots-Tag', 'noindex');
		}
		return $response;
	}

	private function is_patra_route($route): bool
	{
		return is_string($route) && strcasecmp(rtrim($route, '/'), '/' . self::REST_NAMESPACE . self::ROUTE) === 0;
	}

	public function skip_jwt($check)
	{
		// WordPress resolves rest_route before dispatch and may override the pretty URL.
		return $this->is_patra_route($GLOBALS['wp']->query_vars['rest_route'] ?? null) ? false : $check;
	}

	public function handle_settings(): void
	{
		nocache_headers();
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || ! current_user_can('manage_options')) {
			return;
		}
		check_admin_referer('sn_patra_plus_settings');
		$action = sanitize_key(wp_unslash($_POST['patra_action'] ?? ''));
		if ($action === 'save') {
			update_option(self::SETTINGS, [
				'enabled' => ! empty($_POST['patra_enabled']),
				'page_size' => min(self::MAX_PAGE_SIZE, max(1, absint($_POST['patra_page_size'] ?? 50))),
			], false);
			wp_safe_redirect(add_query_arg('patra_saved', '1', admin_url('admin.php?page=' . self::PAGE)));
			exit;
		}
		if ($action === 'revoke') {
			delete_option(self::KEY_HASH);
			$this->notice = 'کلید دسترسی لغو شد.';
			if ((string) get_option(self::KEY_HASH, '') !== '') {
				$this->notice = 'لغو کلید انجام نشد.';
				$this->notice_type = 'error';
			}
		}
		if ($action === 'rotate') {
			try {
				$key = 'patra_' . bin2hex(random_bytes(32));
				if (! update_option(self::KEY_HASH, hash('sha256', $key), false)) {
					throw new RuntimeException('patra_key_update_failed');
				}
				$this->new_key = $key;
				$this->notice = 'کلید دسترسی جدید ساخته شد.';
			} catch (Throwable $error) {
				$this->notice = 'ساخت کلید دسترسی انجام نشد.';
				$this->notice_type = 'error';
			}
		}
	}

	public function render(): void
	{
		if (! current_user_can('manage_options')) {
			return;
		}
		$settings = $this->settings();
		$has_key = (string) get_option(self::KEY_HASH, '') !== '';
		$url = set_url_scheme(rest_url(self::REST_NAMESPACE . self::ROUTE), 'https');
		$examples = [
			'history' => [
				'label' => 'دریافت سوابق',
				'hint' => 'پیمایش سوابق را از صفر شروع کنید.',
				'query' => ['type' => 'all', 'limit' => $settings['page_size'], 'after_id' => 0],
			],
			'invoice' => [
				'label' => 'یک فاکتور',
				'hint' => 'کد نمونه را با کد فاکتور خود جایگزین کنید.',
				'query' => ['type' => 'all', 'invoice_code' => '28460125'],
			],
			'next' => [
				'label' => 'صفحهٔ بعد',
				'hint' => 'عدد ۱۲۰ نمونه است. مقدار next_after_id پاسخ خود را بفرستید.',
				'query' => ['type' => 'all', 'limit' => $settings['page_size'], 'after_id' => 120],
			],
		];
		$curl_prefix = 'curl --fail-with-body -H "Authorization: Bearer $PATRA_API_KEY"';
		$example_json = wp_json_encode($this->example(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		require SN_PLUGIN_DIR . 'templates/admin-patra-console.php';
	}

	private function example(): array
	{
		$invoice = [
			'record_type' => 'invoice',
			'order_type' => null,
			'order_id' => null,
			'order_status' => null,
			'fulfillment_title' => null,
			'invoice_code' => '28460125',
			'invoice_id' => 120,
			'source_invoice_id' => 120,
			'invoice_status' => 'partial_paid',
			'order_title' => 'اشتراک پاترا',
			'products' => [['product_id' => 20, 'title' => 'اشتراک پاترا', 'quantity' => 1]],
			'customer_name' => 'مریم نمونه',
			'first_name' => 'مریم',
			'last_name' => 'نمونه',
			'province' => 'تهران',
			'city' => 'تهران',
			'phone' => '09000000000',
			'phone_secondary' => null,
			'address' => 'خیابان نمونه، پلاک ۱۰',
			'call_center' => 'سرپرست فروش نمونه',
			'seller' => 'فروشنده نمونه',
			'sales_manager' => 'مدیر فروش نمونه',
			'senior_supervisor' => null,
			'invoice_total' => '5000000.00',
			'currency' => 'IRT',
		];
		$account = ['type' => 'card', 'number' => null, 'last_digits' => '1234', 'owner' => null];
		$stage = [
			'stage_no' => 1,
			'source_stage_no' => 1,
			'stage_type' => 'partial',
			'amount' => '2000000.00',
			'status' => 'approved',
			'payment_method' => 'card',
			'payment_source' => 'finance_approval',
			'payment_date' => '2026-11-01T10:30:00+03:30',
			'payment_date_jalali' => '1405/08/10 10:30',
			'destination_account' => $account,
			'destination_accounts' => [$account],
			'payment_approved' => true,
			'payment_approval' => 'تایید شده',
			'approved_at' => '2026-11-01T12:00:00+03:30',
			'transactions' => [],
		];
		return [
			'items' => [
				$invoice + ['record_id' => 'invoice:120:1', 'payment_stage' => $stage],
				$invoice + ['record_id' => 'invoice:120:2', 'payment_stage' => array_replace($stage, [
					'stage_no' => 2, 'source_stage_no' => 2, 'stage_type' => 'balance', 'amount' => '3000000.00',
					'status' => 'pending', 'payment_method' => null, 'payment_source' => null, 'payment_date' => null,
					'payment_date_jalali' => null, 'destination_account' => null, 'destination_accounts' => [],
					'payment_approved' => false, 'payment_approval' => 'تایید نشده', 'approved_at' => null,
				])],
			],
			'has_more' => false,
			'next_after_id' => null,
		];
	}
}
