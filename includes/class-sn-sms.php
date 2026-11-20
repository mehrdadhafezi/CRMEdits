<?php
if ( ! defined( 'ABSPATH' ) ) { exit; }
require_once __DIR__ . '/class-sn-invoice-sms-policy.php';

/**
 * سرویس ارسال پیامک
 * پشتیبانی از: کاوه‌نگار | فراز اس‌ام‌اس | ملی پیامک
 */
class SN_SMS {

	private string $last_error = '';
	private bool $delivery_uncertain = false;
	private string $invoice_dispatch_state = '';
	private static bool $transport_hook_registered = false;

	/** Scope the IPv4 workaround to our Faraz requests, never other HTTP traffic. */
	public static function configure_faraz_curl( $handle, array $args, string $url ): void {
		if ( empty( $args['sn_faraz_request'] ) || wp_parse_url( $url, PHP_URL_HOST ) !== 'api.iranpayamak.com' || wp_parse_url( $url, PHP_URL_SCHEME ) !== 'https' ) { return; }
		if ( function_exists( 'curl_setopt' ) && defined( 'CURLOPT_IPRESOLVE' ) && defined( 'CURL_IPRESOLVE_V4' ) && apply_filters( 'sn_faraz_use_ipv4', true ) ) {
			curl_setopt( $handle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4 );
		}
	}

	/** Keep useful cURL diagnostics without leaking credentials or customer data. */
	private function transport_error_message( $error, string $provider ): string {
		$detail = sanitize_text_field( (string) $error->get_error_message() );
		foreach ( [ $this->api_key, rawurlencode( $this->api_key ), $this->meli_password ] as $secret ) {
			if ( $secret !== '' ) { $detail = str_replace( $secret, '***', $detail ); }
		}
		$detail = preg_replace( '~https?://[^\s<>]+~i', '[آدرس سرویس]', $detail );
		$detail = preg_replace( '/(?<!\d)(?:\+?98|0)?9\d{9}(?!\d)/', '[شماره]', $detail );
		$detail = function_exists( 'mb_substr' ) ? mb_substr( $detail, 0, 350 ) : substr( $detail, 0, 350 );
		return 'ارتباط با ' . $provider . ' برقرار نشد (' . sanitize_key( $error->get_error_code() ) . '). ' . $detail . ' نتیجه ارسال نامشخص است؛ پیش از ارسال مجدد، پنل پیامک را بررسی کنید.';
	}

	public function get_last_error(): string {
		return $this->last_error;
	}

	public function delivery_uncertain(): bool {
		return $this->delivery_uncertain;
	}

	/** Resolve one provider's credentials without borrowing another gateway's key. */
	public static function provider_configuration( string $provider ): array {
		$active = (string) get_option( 'sn_sms_provider', 'kavenegar' );
		$owner = (string) get_option( 'sn_sms_credentials_provider', '' );
		if ( ! in_array( $provider, [ 'faraz', 'kavenegar', 'melipayamak' ], true ) ) { return [ 'api_key'=>'', 'sender'=>'' ]; }
		$key = get_option( 'sn_sms_api_key_' . $provider, null );
		$sender = get_option( 'sn_sms_sender_' . $provider, null );
		// Old releases always sent using the shared credentials of the active
		// provider. Preserve that installation until the first controlled switch;
		// the ownership marker then prevents cross-provider credential fallback.
		$legacy = $active === $provider && ( $owner === $provider || $owner === '' );
		if ( $owner === '' && $key === '' ) { $key = null; }
		if ( $owner === '' && $sender === '' ) { $sender = null; }
		return [
			'api_key'=>trim( (string) ( $key ?? ( $legacy ? get_option( 'sn_sms_api_key', '' ) : '' ) ) ),
			'sender'=>trim( (string) ( $sender ?? ( $legacy ? get_option( 'sn_sms_sender', '' ) : '' ) ) ),
		];
	}

	/** Called only by the authenticated settings handler; saves both providers. */
	public static function save_provider_settings_from_request( array $request ): ?WP_Error {
		if ( ! isset( $request['sn_sms_provider'] ) ) { return null; }
		if ( ! is_scalar( $request['sn_sms_provider'] ) ) { return new WP_Error( 'sms_provider', 'سرویس‌دهنده پیامک نامعتبر است.' ); }
		$provider = sanitize_key( wp_unslash( (string) $request['sn_sms_provider'] ) );
		if ( ! in_array( $provider, [ 'faraz','kavenegar','melipayamak' ], true ) ) { return new WP_Error( 'sms_provider', 'سرویس‌دهنده پیامک نامعتبر است.' ); }
		$fields = [ 'sn_sms_api_key_faraz','sn_sms_sender_faraz','sn_sms_api_key_kavenegar','sn_sms_sender_kavenegar','sn_sms_sender' ];
		foreach ( $fields as $field ) {
			if ( isset( $request[ $field ] ) && ! is_scalar( $request[ $field ] ) ) { return new WP_Error( 'sms_credentials', 'اطلاعات اتصال پیامک نامعتبر است.' ); }
		}
		$old_provider = (string) get_option( 'sn_sms_provider', 'kavenegar' );
		$old = self::provider_configuration( $old_provider );
		if ( in_array( $old_provider, [ 'faraz','kavenegar','melipayamak' ], true ) ) {
			foreach ( [ 'api_key','sender' ] as $part ) {
				$field = 'sn_sms_' . $part . '_' . $old_provider;
				$stored = get_option( $field, null );
				if ( $stored === null || ( $stored === '' && $old[ $part ] !== '' ) ) { update_option( $field, $old[ $part ], false ); }
			}
		}
		foreach ( $fields as $field ) {
			if ( ! isset( $request[ $field ] ) ) { continue; }
			// This legacy field belongs to the Meli row, even while that row is hidden.
			$target = $field === 'sn_sms_sender' ? 'sn_sms_sender_melipayamak' : $field;
			update_option( $target, trim( sanitize_text_field( wp_unslash( (string) $request[ $field ] ) ) ), false );
		}
		update_option( 'sn_sms_provider', $provider, false );
		$active = self::provider_configuration( $provider );
		update_option( 'sn_sms_api_key', $active['api_key'], false );
		update_option( 'sn_sms_sender', $active['sender'], false );
		update_option( 'sn_sms_credentials_provider', $provider, false );
		return null;
	}

	public function get_provider(): string { return $this->provider; }
	public function get_sender(): string { return $this->sender; }
	public function event_pattern( string $event ): string {
		if ( ! in_array( $event, [ 'customer_otp','invoice','online_payment','card_payment','payment_reward' ], true ) ) { return ''; }
		$prefix = $this->provider === 'kavenegar' ? 'sn_kavenegar_template_' : ( $this->provider === 'faraz' ? 'sn_faraz_pattern_' : '' );
		return $prefix !== '' ? trim( (string) get_option( $prefix . $event, '' ) ) : '';
	}

	/** Parse the documented Faraz response without treating HTTP success alone as acceptance. */
	private function faraz_response_ok( $response ): bool {
		$this->last_error = '';
		if ( is_wp_error( $response ) ) {
			$this->delivery_uncertain = true;
			$this->last_error = $this->transport_error_message( $response, 'فراز / ایران‌پیامک' );
			return false;
		}
		$http_code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$status = is_array( $body ) ? ( $body['status'] ?? null ) : null;
		$accepted = $status === true || ( is_string( $status ) && in_array( strtolower( trim( $status ) ), [ 'success', 'ok' ], true ) );
		if ( in_array( $http_code, [ 200, 201 ], true ) && $accepted ) { return true; }
		$this->delivery_uncertain = $http_code < 100 || $http_code === 408 || $http_code >= 500
			|| ( $http_code >= 200 && $http_code < 300 && $status === null );
		$messages = is_array( $body ) ? ( $body['messages'] ?? $body['message'] ?? [] ) : [];
		$parts = [];
		if ( is_string( $messages ) ) { $parts[] = $messages; }
		elseif ( is_array( $messages ) ) {
			array_walk_recursive( $messages, static function ( $value ) use ( &$parts ) {
				if ( is_string( $value ) && count( $parts ) < 5 ) { $parts[] = $value; }
			} );
		}
		$detail = sanitize_text_field( implode( '؛ ', $parts ) );
		foreach ( [ $this->api_key, $this->meli_password ] as $secret ) {
			if ( $secret !== '' ) { $detail = str_replace( $secret, '***', $detail ); }
		}
		$detail = function_exists( 'mb_substr' ) ? mb_substr( $detail, 0, 400 ) : substr( $detail, 0, 400 );
		$this->last_error = ! is_array( $body )
			? 'پاسخ سرویس پیامک معتبر نبود؛ نتیجه ارسال را در پنل پیامک بررسی کنید (HTTP ' . $http_code . ').'
			: 'سرویس پیامک ارسال را تأیید نکرد (HTTP ' . $http_code . ').' . ( $detail !== '' ? ' ' . $detail : ' تنظیمات خط ارسال، الگو و اعتبار پنل را بررسی کنید.' );
		return false;
	}

	private string $provider;
	private string $api_key;
	private string $sender;
	private string $invoice_pattern;
	private string $customer_otp_pattern;
	private string $customer_otp_variable;

	// --- ملی پیامک ---
	private string $meli_username;
	private string $meli_password;
	private string $meli_body_id_invoice;

	public function __construct() {
		$this->provider        = trim( (string) get_option( 'sn_sms_provider', 'kavenegar' ) );
		$config = self::provider_configuration( $this->provider );
		$this->api_key         = $config['api_key'];
		$this->sender          = $config['sender'];
		$this->invoice_pattern = trim( (string) get_option( 'sn_faraz_pattern_invoice', '' ) );
		$this->customer_otp_pattern = trim( (string) get_option( 'sn_faraz_pattern_customer_otp', '' ) );
		$this->customer_otp_variable = sanitize_key( (string) get_option( 'sn_faraz_pattern_customer_otp_variable', 'code' ) ) ?: 'code';

		// تنظیمات ملی پیامک (الگو)
		$this->meli_username         = trim( (string) get_option( 'sn_meli_username', '' ) );
		$this->meli_password         = trim( (string) get_option( 'sn_meli_password', '' ) );
		$this->meli_body_id_invoice  = trim( (string) get_option( 'sn_meli_body_id_invoice', '' ) );

		// مجاز کردن دامنه‌های فراز اس‌ام‌اس در صورت block بودن external requests
		add_filter( 'http_request_host_is_external', [ $this, 'allow_sms_hosts' ], 10, 2 );
		if ( ! self::$transport_hook_registered ) {
			add_filter( 'http_api_curl', [ __CLASS__, 'configure_faraz_curl' ], 10, 3 );
			self::$transport_hook_registered = true;
		}
	}

	public function allow_sms_hosts( bool $allow, string $host ): bool {
		$allowed_hosts = [
			'api.kavenegar.com',
			'api.iranpayamak.com',
			'rest.ippanel.com',
			'ippanel.com',
			'app.farazsms.com',
		];
		if ( in_array( strtolower( $host ), $allowed_hosts, true ) ) {
			return true;
		}
		return $allow;
	}

	/** ارسال پیامک ساده */
	public function send( string $to, string $message ): bool {
		$this->last_error = '';
		$this->delivery_uncertain = false;
		$to = SN_Helpers::normalize_mobile( $to );

		if ( empty( $to ) ) {
			$this->last_error = 'شماره مقصد پیامک خالی است.';
			return false;
		}
		if ( empty( $this->api_key ) && ! ( $this->provider === 'melipayamak' && $this->meli_username !== '' && $this->meli_password !== '' ) ) {
			$this->last_error = 'اطلاعات اتصال سرویس پیامک تنظیم نشده است.';
			return false;
		}

		switch ( $this->provider ) {
			case 'kavenegar':
				return $this->send_kavenegar( $to, $message );

			case 'faraz':
				return $this->send_faraz_simple( $to, $message );

			case 'melipayamak':
				return $this->send_melipayamak( $to, $message );
		}

		$this->last_error = 'سرویس‌دهنده پیامک انتخاب‌شده پشتیبانی نمی‌شود.';
		return false;
	}

	/**
	 * Send the customer-portal login code.
	 *
	 * Faraz must use a dedicated pattern here. The simple-message endpoint was
	 * returning false on the production account and the portal intentionally
	 * surfaced that provider failure as HTTP 502.
	 */
	public function send_customer_portal_otp( string $to, string $code ): bool {
		$this->last_error = '';
		$to = SN_Helpers::normalize_mobile( $to );
		$code = preg_replace( '/\D+/', '', SN_Helpers::to_english_nums( $code ) );
		if ( ! SN_Helpers::is_valid_mobile( $to ) || ! preg_match( '/^\d{6}$/', $code ) ) { $this->last_error = 'شماره مقصد یا کد ورود معتبر نیست.'; return false; }
		if ( $this->provider === 'kavenegar' ) {
			return $this->send_kavenegar_pattern( $to, $this->event_pattern( 'customer_otp' ), [ 'token'=>$code ] );
		}
		if ( $this->provider === 'faraz' ) {
			if ( $this->customer_otp_pattern === '' ) {
				$this->last_error = 'کد پترن ورود مشتری فراز تنظیم نشده است.';
				error_log( 'SN Customer OTP Pattern: کد پترن فراز تنظیم نشده است.' );
				return false;
			}
			return $this->send_faraz_pattern_once(
				$to,
				$this->customer_otp_pattern,
				[ $this->customer_otp_variable => $code ]
			);
		}
		return $this->send( $to, "کد ورود به پروفایل بیاوین: {$code}\nاعتبار کد: ۵ دقیقه" );
	}

	/** One bounded Faraz pattern request; no second call to the same endpoint. */
	private function send_faraz_pattern_once( string $recipient, string $pattern_code, array $attributes ): bool {
		if ( $this->api_key === '' || $this->sender === '' || $pattern_code === '' ) {
			$this->last_error = 'کلید سرویس، خط ارسال یا کد الگوی پیامک تنظیم نشده است.';
			return false;
		}
		$payload = [
			'code'          => $pattern_code,
			'recipient'     => $recipient,
			'line_number'   => $this->sender,
			'attributes'    => $attributes,
			'number_format' => 'english',
		];
		$res = wp_remote_post( 'https://api.iranpayamak.com/ws/v1/sms/pattern', [
			'timeout'   => 30,
			'redirection' => 0,
			'sslverify' => true,
			'sn_faraz_request' => true,
			'headers'   => [
				'Accept'       => 'application/json',
				'Content-Type' => 'application/json',
				'Api-Key'      => $this->api_key,
			],
			'body' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
		] );
		return $this->faraz_response_ok( $res );
	}

	private function is_short_invoice_url( string $invoice_url ): bool {
		$invoice_url = trim( (string) $invoice_url );
		$path = (string) wp_parse_url( $invoice_url, PHP_URL_PATH );
		if ( $path === '' && preg_match( '~(?:^|/)(i/[A-Za-z0-9_-]+/?)(?:$|[?#])~', $invoice_url, $m ) ) {
			$path = '/' . trim( (string) $m[1], '/' ) . '/';
		}
		return (bool) preg_match( '~^/i/[A-Za-z0-9_-]+/?$~', $path );
	}

	private function normalize_invoice_sms_amount( $amount ): string {
		$amount = trim( SN_Helpers::to_english_nums( (string) $amount ) );
		if ( $amount === '' ) {
			return '';
		}
		$amount = str_replace( [ ',', '٬', '،', ' ' ], '', $amount );
		if ( preg_match( '/^-?\d+(?:\.\d+)?$/', $amount ) ) {
			return (string) (int) floor( (float) $amount );
		}
		if ( strpos( $amount, '.' ) !== false ) {
			$amount = strtok( $amount, '.' );
		}
		return (string) preg_replace( '/[^0-9]/', '', $amount );
	}

	/** ساخت پیش‌نمایش دقیق پیامک فاکتور بدون ارسال واقعی */
	public function build_invoice_link_preview( string $phone, string $invoice_code, string $invoice_url, string $customer_name, $amount = '', $card_number = '' ): array {
		$phone         = SN_Helpers::normalize_mobile( $phone );
		$invoice_code  = trim( $invoice_code );
		$invoice_url   = trim( $invoice_url );
		$customer_name = trim( $customer_name );
		$amount        = trim( (string) $amount );
		$card_number   = trim( (string) $card_number );
		$normalized_amount = $this->normalize_invoice_sms_amount( $amount );
		if ( empty( $customer_name ) ) {
			$customer_name = 'مشتری گرامی';
		}
		if ( (string) get_option( 'sn_card_to_card_enabled', '1' ) !== '1' ) {
			$card_number = '';
		} elseif ( empty( $card_number ) ) {
			$card_number = get_option( 'sn_card_number', '' );
		}
		$base = [
			'preview_version' => defined( 'SN_VERSION' ) ? SN_VERSION : 'unknown',
			'sms_class_file'  => __FILE__,
			'provider'      => $this->provider,
			'to'            => $phone,
			'invoice_code'  => $invoice_code,
			'invoice_url'   => $invoice_url,
			'is_short_url'  => $this->is_short_invoice_url( $invoice_url ),
			'customer_name' => $customer_name,
			'amount'        => $amount,
			'normalized_amount' => $normalized_amount,
			'card_number'   => $card_number,
			'sender'        => $this->sender,
		];

		if ( $this->provider === 'kavenegar' && $this->event_pattern( 'invoice' ) !== '' ) {
			$pattern = $this->event_pattern( 'invoice' );
			$payload = $this->kavenegar_lookup_payload( $phone, $pattern, [ 'token'=>$invoice_code, 'token2'=>$invoice_url, 'token3'=>$normalized_amount, 'token10'=>$customer_name, 'token20'=>$card_number ] );
			return $base + [ 'mode'=>'kavenegar_pattern', 'endpoint'=>'https://api.kavenegar.com/v1/{API-KEY}/verify/lookup.json', 'pattern_code'=>$pattern, 'payload'=>$payload ?? [], 'validation_error'=>$payload === null ? $this->last_error : '' ];
		}

		if ( $this->provider === 'faraz' && ! empty( $this->invoice_pattern ) ) {
			$attributes = [
				'customer_name' => $customer_name,
				'invoice_code'  => $invoice_code,
				'invoice_url'   => $invoice_url,
				'amount'        => $normalized_amount,
				'card_number'   => $card_number,
			];
			return $base + [
				'mode'         => 'faraz_pattern',
				'endpoint'     => 'https://api.iranpayamak.com/ws/v1/sms/pattern',
				'pattern_code' => $this->invoice_pattern,
				'payload'      => [
					'code'          => $this->invoice_pattern,
					'recipient'     => $phone,
					'line_number'   => $this->sender,
					'attributes'    => $attributes,
					'number_format' => 'english',
				],
			];
		}

		if ( $this->provider === 'melipayamak' && ! empty( $this->meli_body_id_invoice ) ) {
			return $base + [
				'mode'    => 'melipayamak_pattern',
				'endpoint'=> 'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber',
				'body_id' => (int) $this->meli_body_id_invoice,
				'vars'    => [ $customer_name, $invoice_code, $invoice_url, $amount, $card_number ],
			];
		}

		$template = get_option( 'sn_sms_invoice_template', '' );
		if ( ! empty( $template ) ) {
			$message = str_replace(
				[ '{customer_name}', '{invoice_code}', '{invoice_url}', '{amount}', '{card_number}' ],
				[ $customer_name, $invoice_code, $invoice_url, $amount, $card_number ],
				$template
			);
		} else {
			$message  = "مشتری گرامی {$customer_name}\n";
			$message .= "فاکتور شما با کد {$invoice_code} آماده است.\n";
			if ( ! empty( $amount ) ) {
				$message .= "مبلغ: {$amount} تومان\n";
			}
			$message .= "برای مشاهده و پرداخت:\n{$invoice_url}";
			if ( ! empty( $card_number ) ) {
				$message .= "\n\nیا کارت به کارت به شماره:\n{$card_number}";
			}
		}

		return $base + [
			'mode'    => $this->provider === 'kavenegar' ? 'kavenegar_simple' : 'simple',
			'message' => $message,
		];
	}

	/** ارسال لینک فاکتور به مشتری */
	public function invoice_dispatch_state(): string { return $this->invoice_dispatch_state; }

	public function send_invoice_link( string $phone, string $invoice_code, string $invoice_url, string $customer_name, $amount = '', $card_number = '', int $invoice_id = 0, bool $only_previous_unsent = false ): bool {
		$this->last_error = '';
		$this->invoice_dispatch_state = '';
		$this->delivery_uncertain = false;
		if ( $invoice_id > 0 && class_exists( 'SN_Invoice_SMS_Policy' ) ) {
			$result = SN_Invoice_SMS_Policy::dispatch( $invoice_id,
				fn() => $this->send_invoice_link_payload( $phone, $invoice_code, $invoice_url, $customer_name, $amount, $card_number ),
				fn() => $this->delivery_uncertain(), $only_previous_unsent );
			$this->invoice_dispatch_state = $result['state'];
			if ( ! empty( $result['message'] ) ) { $this->last_error = $result['message']; }
			return $result['sent'];
		}
		return $this->send_invoice_link_payload( $phone, $invoice_code, $invoice_url, $customer_name, $amount, $card_number );
	}

	private function send_invoice_link_payload( string $phone, string $invoice_code, string $invoice_url, string $customer_name, $amount, $card_number ): bool {
		$this->last_error = '';
		$phone         = SN_Helpers::normalize_mobile( $phone );
		$invoice_code  = trim( $invoice_code );
		$invoice_url   = trim( $invoice_url );
		$customer_name = trim( $customer_name );
		$amount        = trim( (string) $amount );
		$card_number   = trim( (string) $card_number );

		if ( empty( $phone ) || empty( $invoice_url ) ) {
			$this->last_error = 'شماره مشتری یا لینک فاکتور خالی است.';
			return false;
		}

		if ( empty( $customer_name ) ) {
			$customer_name = 'مشتری گرامی';
		}
		
		// شماره کارت پیش‌فرض فقط وقتی قابلیت کارت‌به‌کارت روشن است وارد
		// پیامک شود؛ مقدار خالی در حالت خاموش نباید دوباره از تنظیمات پر شود.
		if ( (string) get_option( 'sn_card_to_card_enabled', '1' ) !== '1' ) {
			$card_number = '';
		} elseif ( empty( $card_number ) ) {
			$card_number = get_option( 'sn_card_number', '' );
		}

		$sn_invoice_sms_debug_payload = $this->build_invoice_link_preview( $phone, $invoice_code, $invoice_url, $customer_name, $amount, $card_number );
		update_option( 'sn_last_invoice_sms_debug', [
			'preview_only' => false,
			'created_at'   => current_time( 'mysql' ),
			'payload'      => $sn_invoice_sms_debug_payload,
		], false );

		// اگر سرویس‌دهنده فراز باشد و کد پترن تعریف شده باشد، با پترن بفرست
		if ( $this->provider === 'kavenegar' && $this->event_pattern( 'invoice' ) !== '' ) {
			return $this->send_event_pattern( $phone, 'invoice', [ 'customer_name'=>$customer_name, 'invoice_code'=>$invoice_code, 'invoice_url'=>$invoice_url, 'amount'=>$this->normalize_invoice_sms_amount( $amount ), 'card_number'=>$card_number ] );
		}
		if ( $this->provider === 'faraz' && ! empty( $this->invoice_pattern ) ) {
			return $this->send_faraz_pattern_once(
				$phone,
				$this->invoice_pattern,
				[
					'customer_name' => $customer_name,
					'invoice_code'  => $invoice_code,
					'invoice_url'   => $invoice_url,
					'amount'        => $this->normalize_invoice_sms_amount( $amount ),
					'card_number'   => $card_number,
				]
			);
		}

		// اگر سرویس‌دهنده ملی پیامک باشد و bodyId فاکتور تعریف شده باشد، با الگو بفرست
		if ( $this->provider === 'melipayamak' && ! empty( $this->meli_body_id_invoice ) ) {
			return $this->send_melipayamak_pattern(
				$phone,
				(int) $this->meli_body_id_invoice,
				[ $customer_name, $invoice_code, $invoice_url, $amount, $card_number ]
			);
		}

		// استفاده از template از تنظیمات
		$template = get_option( 'sn_sms_invoice_template', '' );
		
		// اگر template داریم از اون استفاده کن، وگرنه متن پیش‌فرض
		if ( ! empty( $template ) ) {
			$message = str_replace(
				[ '{customer_name}', '{invoice_code}', '{invoice_url}', '{amount}', '{card_number}' ],
				[ $customer_name, $invoice_code, $invoice_url, $amount, $card_number ],
				$template
			);
		} else {
			// متن پیش‌فرض
			$message  = "مشتری گرامی {$customer_name}\n";
			$message .= "فاکتور شما با کد {$invoice_code} آماده است.\n";
			
			if ( ! empty( $amount ) ) {
				$message .= "مبلغ: {$amount} تومان\n";
			}
			
			$message .= "برای مشاهده و پرداخت:\n{$invoice_url}";
			
			if ( ! empty( $card_number ) ) {
				$message .= "\n\nیا کارت به کارت به شماره:\n{$card_number}";
			}
		}

		return $this->send( $phone, $message );
	}

	// --- Kavenegar ---
	private function send_kavenegar( string $to, string $message ): bool {
		if ( trim( $message ) === '' ) { $this->last_error = 'متن پیامک خالی است.'; return false; }
		$payload = [ 'receptor'=>$to, 'message'=>$message ];
		if ( $this->sender !== '' ) { $payload['sender'] = $this->sender; }
		return $this->kavenegar_request( 'sms/send', $payload );
	}

	/** Provider-neutral boundary used by payment and reward notifications. */
	public function send_event_pattern( string $to, string $event, array $attributes ): bool {
		$this->last_error = '';
		$pattern = $this->event_pattern( $event );
		if ( $pattern === '' ) { $this->last_error = 'الگوی پیامک این رویداد برای درگاه فعال تنظیم نشده است.'; return false; }
		if ( $this->provider === 'faraz' ) { return $this->send_faraz_pattern_once( SN_Helpers::normalize_mobile( $to ), $pattern, $attributes ); }
		if ( $this->provider !== 'kavenegar' ) { $this->last_error = 'درگاه فعال از این نوع الگو پشتیبانی نمی‌کند.'; return false; }
		$maps = [
			'invoice'=>[ 'token'=>'invoice_code','token2'=>'invoice_url','token3'=>'amount','token10'=>'customer_name','token20'=>'card_number' ],
			'online_payment'=>[ 'token'=>'invoice_code','token2'=>'amount','token3'=>'payment_type','token10'=>'customer_name' ],
			'card_payment'=>[ 'token'=>'invoice_code','token2'=>'amount','token3'=>'payment_type','token10'=>'customer_name' ],
			'payment_reward'=>[ 'token'=>'invoice_code','token2'=>'invoice_url','token3'=>'reward_phone','token10'=>'customer_name','token20'=>'product_name' ],
			'customer_otp'=>[ 'token'=>'code' ],
		];
		$tokens = [];
		foreach ( $maps[ $event ] ?? [] as $token=>$field ) { $tokens[ $token ] = (string) ( $attributes[ $field ] ?? '' ); }
		if ( $event === 'card_payment' ) { $tokens['token3'] = 'card'; }
		return $this->send_kavenegar_pattern( $to, $pattern, $tokens );
	}

	/** Verify/Lookup uses Kavenegar templates, never Faraz pattern codes. */
	public function send_kavenegar_pattern( string $to, string $template, array $tokens ): bool {
		$this->last_error = '';
		if ( $this->provider !== 'kavenegar' ) { $this->last_error = 'درگاه فعال کاوه‌نگار نیست.'; return false; }
		$payload = $this->kavenegar_lookup_payload( SN_Helpers::normalize_mobile( $to ), $template, $tokens );
		return $payload !== null && $this->kavenegar_request( 'verify/lookup', $payload );
	}

	/** https://kavenegar.com/rest.html — token lengths and allowed spaces. */
	private function kavenegar_lookup_payload( string $to, string $template, array $tokens ): ?array {
		if ( ! SN_Helpers::is_valid_mobile( $to ) || ! preg_match( '/^[A-Za-z0-9-]{1,100}$/', $template ) ) {
			$this->last_error = 'شماره مقصد یا نام الگوی کاوه‌نگار معتبر نیست؛ الگو باید در پنل کاوه‌نگار ثبت و تأیید شده باشد.';
			return null;
		}
		$payload = [ 'receptor'=>$to, 'template'=>$template, 'type'=>'sms' ];
		foreach ( [ 'token'=>0,'token2'=>0,'token3'=>0,'token10'=>5,'token20'=>8 ] as $name=>$spaces ) {
			if ( isset( $tokens[ $name ] ) && ! is_scalar( $tokens[ $name ] ) ) { $this->last_error = 'مقدار متغیر الگوی کاوه‌نگار نامعتبر است.'; return null; }
			$value = trim( (string) ( $tokens[ $name ] ?? '' ) );
			if ( $value === '' && $name !== 'token' ) { continue; }
			$length = preg_match_all( '/./us', $value );
			if ( $value === '' || $length === false || $length > 100 || preg_match( '/[\r\n\t\x00-\x1F\x7F]/', $value ) || preg_match_all( '/\s/u', $value ) > $spaces ) {
				$this->last_error = 'مقدار ' . $name . ' با محدودیت طول یا فاصله الگوی کاوه‌نگار سازگار نیست؛ تنظیمات الگو را بررسی کنید.';
				return null;
			}
			$payload[ $name ] = $value;
		}
		return $payload;
	}

	private function kavenegar_request( string $method, array $payload ): bool {
		if ( $this->api_key === '' ) { $this->last_error = 'API Key کاوه‌نگار تنظیم نشده است.'; return false; }
		$url = 'https://api.kavenegar.com/v1/' . rawurlencode( $this->api_key ) . '/' . $method . '.json';
		$response = wp_remote_post( $url, [ 'timeout'=>15, 'redirection'=>0, 'sslverify'=>true, 'headers'=>[ 'Accept'=>'application/json' ], 'body'=>$payload ] );
		if ( is_wp_error( $response ) ) {
			$this->delivery_uncertain = true;
			$this->last_error = 'پاسخ کاوه‌نگار دریافت نشد (' . sanitize_key( $response->get_error_code() ) . ')؛ نتیجه ارسال نامشخص است. پیش از ارسال مجدد، پنل پیامک را بررسی کنید.';
			return false;
		}
		$http = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$status = is_array( $body ) ? (int) ( $body['return']['status'] ?? 0 ) : 0;
		$entry = is_array( $body['entries'] ?? null ) ? ( $body['entries'][0] ?? null ) : null;
		if ( $http === 200 && $status === 200 && is_array( $entry ) && (int) ( $entry['messageid'] ?? 0 ) > 0 && in_array( (int) ( $entry['status'] ?? 0 ), [ 1,2,4,5,10 ], true ) ) { return true; }
		$this->delivery_uncertain = $http < 100 || $http === 408 || $http >= 500
			|| ( $http >= 200 && $http < 300 && ( $status === 0 || $status === 200 ) );
		$detail = is_array( $body ) && is_scalar( $body['return']['message'] ?? null ) ? sanitize_text_field( (string) $body['return']['message'] ) : '';
		$detail = str_replace( [ $this->api_key, rawurlencode( $this->api_key ) ], '***', $detail );
		$detail = function_exists( 'mb_substr' ) ? mb_substr( $detail, 0, 300 ) : substr( $detail, 0, 300 );
		$this->last_error = 'کاوه‌نگار ارسال را تأیید نکرد (HTTP ' . $http . '، کد ' . $status . ').' . ( $detail !== '' ? ' ' . $detail : ' تنظیمات الگو، خط ارسال و اعتبار حساب را بررسی کنید.' );
		return false;
	}

	// --- Faraz SMS : Simple (IPPanel v1) ---
	private function send_faraz_simple( string $to, string $message ): bool {
		// داکیومنت: https://docs.iranpayamak.com/send-simple-sms-13909967e0.md
		$url = 'https://api.iranpayamak.com/ws/v1/sms/simple';

		// iranpayamak فرمت 09xx میخواد
		$originator = $this->sender;

		$payload = [
			'text'          => $message,
			'line_number'   => $originator,
			'recipients'    => [ $to ],
			'number_format' => 'english',
			'schedule'      => null,
		];

		$res = wp_remote_post( $url, [
			'timeout'   => 30,
			'redirection' => 0,
			'sslverify' => true,
			'sn_faraz_request' => true,
			'headers'   => [
				'Accept'        => 'application/json',
				'Content-Type'  => 'application/json',
				'Api-Key'      => $this->api_key,
			],
			'body' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
		] );

		return $this->faraz_response_ok( $res );
	}

	// --- Faraz SMS : Pattern ---
	// فراز SMS از IPPanel استفاده میکنه
	// اگر rest.ippanel.com بلاک بود (سرورهای ایران)، fallback به endpoint داخلی ippanel.com:8080
	public function send_faraz_pattern( string $to, string $pattern_code, array $attributes ): bool {
		$this->last_error = '';
		if ( $this->provider !== 'faraz' ) { $this->last_error = 'درگاه فعال فراز نیست.'; return false; }
		$recipient = SN_Helpers::normalize_mobile( $to );
		if ( ! SN_Helpers::is_valid_mobile( $recipient ) ) { $this->last_error = 'شماره مقصد پیامک معتبر نیست.'; return false; }
		// A timeout does not prove non-delivery. Never repeat a billable POST automatically.
		return $this->send_faraz_pattern_once( $recipient, trim( $pattern_code ), $attributes );
	}

	// --- Meli Payamak : Simple ---
	private function send_melipayamak( string $to, string $message ): bool {
		$url      = 'https://rest.payamak-panel.com/api/SendSMS/SendSMS';
		$username = $this->meli_username ?: explode( ':', $this->api_key . ':' )[0];
		$password = $this->meli_password ?: ( explode( ':', $this->api_key . ':' )[1] ?? '' );

		$res = wp_remote_post( $url, [
			'timeout' => 15,
			'body'    => [
				'username' => $username,
				'password' => $password,
				'to'       => $to,
				'from'     => $this->sender,
				'text'     => $message,
				'isFlash'  => 'false',
			],
		] );

		if ( is_wp_error( $res ) ) {
			$this->delivery_uncertain = true;
			error_log( 'SN MeliPayamak Error: ' . $res->get_error_message() );
			$this->last_error = 'پاسخ ملی‌پیامک دریافت نشد؛ وضعیت ارسال را در پنل پیامک بررسی کنید.';
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );
		$ok = isset( $body['RetStatus'] ) && (int) $body['RetStatus'] === 1;
		$http = (int) wp_remote_retrieve_response_code( $res );
		$this->delivery_uncertain = ! $ok && ( $http < 100 || $http === 408 || $http >= 500
			|| ( $http >= 200 && $http < 300 && ! isset( $body['RetStatus'] ) ) );
		if ( ! $ok ) { $this->last_error = 'ملی‌پیامک ارسال را تأیید نکرد؛ کد پاسخ: ' . (int) ( $body['RetStatus'] ?? wp_remote_retrieve_response_code( $res ) ); }
		return $ok;
	}

	// --- Meli Payamak : Pattern (الگوی اشتراکی) ---
	public function send_melipayamak_pattern( string $to, int $body_id, array $vars ): bool {
		if ( empty( $this->meli_username ) || empty( $this->meli_password ) || ! $body_id ) {
			$this->last_error = 'نام کاربری، رمز اتصال یا شناسه الگوی ملی‌پیامک تنظیم نشده است.';
			error_log( 'SN MeliPayamak Pattern: تنظیمات ناقص (username/password/bodyId)' );
			return false;
		}

		// متغیرها با ; جدا می‌شوند
		$text = implode( ';', $vars );

		$payload = [
			'username' => $this->meli_username,
			'password' => $this->meli_password,
			'text'     => $text,
			'to'       => $to,
			'bodyId'   => $body_id,
		];

		$res = wp_remote_post(
			'https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber',
			[
				'timeout' => 15,
				'headers' => [
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				],
				'body' => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
			]
		);

		if ( is_wp_error( $res ) ) {
			error_log( 'SN MeliPayamak Pattern Error: ' . $res->get_error_message() );
			$this->last_error = 'پاسخ ملی‌پیامک دریافت نشد؛ وضعیت ارسال را در پنل پیامک بررسی کنید.';
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $res ), true );

		// ارسال موفق: RetStatus=1 و Value بیشتر از ۱۵ رقم
		if (
			isset( $body['RetStatus'] ) &&
			(int) $body['RetStatus'] === 1 &&
			isset( $body['Value'] ) &&
			strlen( (string) $body['Value'] ) > 15
		) {
			return true;
		}

		$error_code = $body['Value'] ?? 'unknown';
		$this->last_error = 'ملی‌پیامک ارسال الگو را تأیید نکرد؛ کد پاسخ: ' . (int) ( $body['RetStatus'] ?? wp_remote_retrieve_response_code( $res ) ) . ( is_numeric( $error_code ) ? ' / ' . (string) $error_code : '' );
		error_log( 'SN MeliPayamak Pattern Failed — کد خطا: ' . $error_code . ' | پاسخ: ' . wp_json_encode( $body, JSON_UNESCAPED_UNICODE ) );
		return false;
	}
}
