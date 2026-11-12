<?php
if (!defined('ABSPATH')) { exit; }

trait SN_Sales_Branches
{
	private ?array $sn_manager_branch_cache = null;

	private function sn_sales_branch_options(): array
	{
		$branches = get_option(self::SN_BRANCH_OPTION, []);
		return is_array($branches) ? $branches : [];
	}

	private function sn_add_sales_branch(string $name): void
	{
		global $wpdb;
		$name = trim(sanitize_text_field($name));
		if ($name === '' || mb_strlen($name) > 191) {
			throw new RuntimeException('نام شعبه را حداکثر در ۱۹۱ نویسه وارد کنید.');
		}
		$lock = 'sn_branch_' . md5($wpdb->options);
		if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)', $lock)) !== 1) {
			throw new RuntimeException('ثبت شعبه در حال انجام است. دوباره تلاش کنید.');
		}
		try {
			// Read the committed catalog after obtaining the lock, even with an object cache.
			$stored = $wpdb->get_var($wpdb->prepare("SELECT option_value FROM {$wpdb->options} WHERE option_name=%s", self::SN_BRANCH_OPTION));
			if ($wpdb->last_error) { throw new RuntimeException('خواندن فهرست شعب انجام نشد.'); }
			$branches = maybe_unserialize($stored);
			$branches = is_array($branches) ? $branches : [];
			if (in_array($name, $branches, true)) { return; }
			$key = 'b_' . substr(hash('sha256', $name), 0, 20);
			$branches[$key] = $name;
			wp_cache_delete(self::SN_BRANCH_OPTION, 'options');
			wp_cache_delete('alloptions', 'options');
			wp_cache_delete('notoptions', 'options');
			if (!update_option(self::SN_BRANCH_OPTION, $branches, false)) {
				throw new RuntimeException('ذخیره شعبه انجام نشد.');
			}
		} finally {
			$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
		}
	}

	private function sn_sales_manager_branches(): array
	{
		if ($this->sn_manager_branch_cache !== null) { return $this->sn_manager_branch_cache; }
		$this->sn_manager_branch_cache = [];
		$branches = $this->sn_sales_branch_options();
		if (!$branches) { return []; }

		$ids = get_users(['meta_key'=>self::SN_MANAGER_BRANCH_META, 'fields'=>'ID']);
		if ($ids) { update_meta_cache('user', $ids); }
		foreach ($ids as $manager_id) {
			$manager_id = (int)$manager_id;
			$key = (string)get_user_meta($manager_id, self::SN_MANAGER_BRANCH_META, true);
			if (isset($branches[$key]) && $this->sn_hr_position_for_user($manager_id) === 'sales_manager') {
				$this->sn_manager_branch_cache[$manager_id] = ['key'=>$key, 'label'=>(string)$branches[$key], 'manager_id'=>$manager_id];
			}
		}
		return $this->sn_manager_branch_cache;
	}

	/** Effective branch follows the nearest sales manager in the current HR chain. */
	private function sn_branch_manager_for_chain(int $user_id, array $chain, array $managers): int
	{
		if (isset($managers[$user_id]) || ($chain['position_slug'] ?? '') === 'sales_manager') { return $user_id; }
		$hr_manager = (int)($chain['sales_manager_id'] ?? 0);
		foreach ($chain['ancestor_user_ids'] ?? [] as $ancestor) {
			if ($ancestor === $hr_manager || isset($managers[$ancestor])) { return $ancestor; }
		}
		return $hr_manager;
	}

	private function sn_sales_branch_map(array $ids, ?array $chains = null): array
	{
		$managers = $this->sn_sales_manager_branches();
		$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
		if (!$ids || !$managers) { return []; }
		$chains ??= $this->sn_admin_invoice_hr_chain_map($ids);

		$map = [];
		$without_parent = [];
		foreach ($ids as $id) {
			$chain = $chains[$id] ?? [];
			$manager_id = $this->sn_branch_manager_for_chain($id, $chain, $managers);
			if ($manager_id) {
				if (isset($managers[$manager_id])) { $map[$id] = $managers[$manager_id]; }
			} elseif (empty($chain['has_hr_parent'])) {
				$without_parent[] = $id;
			}
		}
		if (!$without_parent) { return $map; }

		// Consult legacy assignments only when HR has no current parent for this user.
		update_meta_cache('user', $without_parent);
		$legacy_parents = [];
		foreach ($without_parent as $id) {
			$parent = (int)get_user_meta($id, 'sn_supervisor_id', true);
			if ($parent > 0 && $parent !== $id) { $legacy_parents[$id] = $parent; }
		}
		$parent_chains = $this->sn_admin_invoice_hr_chain_map(array_values($legacy_parents));
		foreach ($without_parent as $id) {
			$parent = $legacy_parents[$id] ?? 0;
			$manager_id = $this->sn_branch_manager_for_chain($parent, $parent_chains[$parent] ?? [], $managers);
			if (!$manager_id && ($parent || (int)get_user_meta($id, 'sn_sales_manager_id', true) > 0)) {
				$manager_id = SN_Seller_Flow::instance()->sales_manager_for_user($id);
			}
			if (isset($managers[$manager_id])) { $map[$id] = $managers[$manager_id]; }
		}
		return $map;
	}

	private function sn_lead_branch_owner(array $lead): int
	{
		return (int)(($lead['seller_id'] ?? 0) ?: ($lead['supervisor_id'] ?? 0));
	}

	private function sn_sales_branch_csv_label(array $map, int $id): string
	{
		$label = (string)($map[$id]['label'] ?? '');
		return preg_match('/^[=+\-@\t\r\n]/u', $label) ? "'" . $label : $label;
	}

	private function sn_save_sales_manager_branch(int $manager, string $key): void
	{
		global $wpdb;
		$lock = 'sn_manager_branch_' . md5($wpdb->options . ':' . $manager);
		if ((int)$wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s,3)', $lock)) !== 1) {
			throw new RuntimeException('شعبه این مدیر در حال تغییر است. دوباره تلاش کنید.');
		}
		try {
			wp_cache_delete($manager, 'user_meta');
			if ((string)get_user_meta($manager, self::SN_MANAGER_BRANCH_META, true) === $key) { return; }
			$saved = $key === ''
				? delete_user_meta($manager, self::SN_MANAGER_BRANCH_META)
				: update_user_meta($manager, self::SN_MANAGER_BRANCH_META, $key);
			if (!$saved) { throw new RuntimeException('ذخیره شعبه مدیر انجام نشد.'); }
		} finally {
			$wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock));
		}
	}

	public function handle_sales_branches(): void
	{
		if (!current_user_can('manage_options')) { wp_die('دسترسی غیرمجاز', '', ['response'=>403]); }
		check_admin_referer('sn_sales_branches');
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { wp_die('روش درخواست معتبر نیست.', '', ['response'=>405]); }

		$operation = is_string($_POST['operation'] ?? null) ? sanitize_key($_POST['operation']) : '';
		try {
			if ($operation === 'create') {
				$this->sn_add_sales_branch(is_string($_POST['branch_name'] ?? null) ? wp_unslash($_POST['branch_name']) : '');
			} elseif ($operation === 'assign') {
				$manager = is_string($_POST['manager_id'] ?? null) ? absint($_POST['manager_id']) : 0;
				$key = is_string($_POST['branch_key'] ?? null) ? wp_unslash($_POST['branch_key']) : null;
				$branches = $this->sn_sales_branch_options();
				if (!$manager || !get_userdata($manager) || $this->sn_hr_position_for_user($manager) !== 'sales_manager') {
					throw new RuntimeException('مدیر فروش معتبر انتخاب کنید.');
				}
				if ($key === null || ($key !== '' && !isset($branches[$key]))) {
					throw new RuntimeException('شعبه انتخاب‌شده معتبر نیست.');
				}
				$this->sn_save_sales_manager_branch($manager, $key);
			} else {
				throw new RuntimeException('عملیات معتبر نیست.');
			}
			$notice = ['message'=>'تغییرات شعب ذخیره شد.'];
		} catch (RuntimeException $error) {
			$notice = ['message'=>$error->getMessage(), 'error'=>true];
		}

		$token = wp_generate_uuid4();
		set_transient('sn_branch_notice_' . get_current_user_id() . '_' . $token, $notice, 60);
		wp_safe_redirect(add_query_arg(['page'=>'sn-branches', 'sn_branch_notice'=>$token], admin_url('admin.php')));
		exit;
	}

	public function render_sales_branches(): void
	{
		if (!current_user_can('manage_options')) { wp_die('دسترسی غیرمجاز', '', ['response'=>403]); }
		$branches = $this->sn_sales_branch_options();
		$assigned = $this->sn_sales_manager_branches();
		$managers = $this->sn_admin_invoice_user_options(['sales_manager'], ['sn_sales_manager','sas_sales_manager']);
		$notice_key = is_string($_GET['sn_branch_notice'] ?? null) ? sanitize_key($_GET['sn_branch_notice']) : '';
		$notice_id = 'sn_branch_notice_' . get_current_user_id() . '_' . $notice_key;
		$notice = $notice_key ? get_transient($notice_id) : false;
		if ($notice) { delete_transient($notice_id); }
		?>
		<div class="wrap sn-admin sn-admin-modern" dir="rtl">
			<h1>شعب</h1>
			<p>برای هر مدیر فروش یک شعبه تعیین کنید. نیروها شعبه را از مدیر فروش بالادست خود می‌گیرند.</p>
			<?php if ($notice) : ?>
				<div class="notice inline <?php echo empty($notice['error']) ? 'notice-success' : 'notice-error'; ?>"><p><?php echo esc_html($notice['message']); ?></p></div>
			<?php endif; ?>

			<section class="sn-card">
				<h2>تعریف شعبه</h2>
				<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
					<input type="hidden" name="action" value="sn_sales_branches">
					<input type="hidden" name="operation" value="create">
					<?php wp_nonce_field('sn_sales_branches'); ?>
					<label for="sn-branch-name">نام شعبه</label>
					<input id="sn-branch-name" name="branch_name" class="regular-text" maxlength="191" required>
					<button class="button button-primary">افزودن شعبه</button>
				</form>
				<?php if ($branches) : ?>
					<p><strong>شعب ثبت‌شده</strong> <?php echo esc_html(implode('، ', $branches)); ?></p>
				<?php else : ?>
					<p>هنوز شعبه‌ای تعریف نشده است.</p>
				<?php endif; ?>
			</section>

			<h2>شعبه مدیران فروش</h2>
			<table class="widefat striped">
				<thead><tr><th scope="col">مدیر فروش</th><th scope="col">شعبه</th></tr></thead>
				<tbody>
				<?php $count = 0; foreach ($managers as $manager) :
					$id = (int)$manager->ID;
					if (!get_userdata($id) || $this->sn_hr_position_for_user($id) !== 'sales_manager') { continue; }
					$count++;
					?>
					<tr>
						<td><strong><?php echo esc_html($manager->display_name); ?></strong> <small>#<?php echo (int)$id; ?></small></td>
						<td>
							<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
								<input type="hidden" name="action" value="sn_sales_branches">
								<input type="hidden" name="operation" value="assign">
								<input type="hidden" name="manager_id" value="<?php echo (int)$id; ?>">
								<?php wp_nonce_field('sn_sales_branches', '_wpnonce', false); ?>
								<select name="branch_key" aria-label="<?php echo esc_attr('شعبه ' . $manager->display_name); ?>">
									<option value="">بدون شعبه</option>
									<?php foreach ($branches as $key => $name) : ?>
										<option value="<?php echo esc_attr($key); ?>" <?php selected($assigned[$id]['key'] ?? '', $key); ?>><?php echo esc_html($name); ?></option>
									<?php endforeach; ?>
								</select>
								<button class="button" <?php disabled(!$branches); ?>>ذخیره</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if (!$count) : ?><tr><td colspan="2">مدیر فروشی ثبت نشده است.</td></tr><?php endif; ?>
				</tbody>
			</table>
			<?php if ($branches) { $this->sn_render_sales_branch_members($branches); } ?>
		</div>
		<?php
	}

	private function sn_render_sales_branch_members(array $branches): void
	{
		$key = is_string($_GET['members_branch'] ?? null) ? wp_unslash($_GET['members_branch']) : '';
		?>
		<section class="sn-card">
			<h2>اعضای شعب</h2>
			<form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>">
				<input type="hidden" name="page" value="sn-branches">
				<label for="sn-members-branch">شعبه</label>
				<select id="sn-members-branch" name="members_branch" required>
					<option value="">انتخاب شعبه</option>
					<?php foreach ($branches as $branch_key => $label) : ?>
						<option value="<?php echo esc_attr($branch_key); ?>" <?php selected($key, $branch_key); ?>><?php echo esc_html($label); ?></option>
					<?php endforeach; ?>
				</select>
				<button class="button">نمایش اعضا</button>
			</form>
			<?php if (isset($branches[$key])) :
				$people = $this->sn_admin_invoice_user_options(
					['sales_manager','senior_supervisor','supervisor','seller','converter'],
					['sn_sales_manager','sas_sales_manager','sn_senior_supervisor','sas_senior_supervisor','sn_supervisor','sas_supervisor','sn_seller','sas_employee','sn_converter']
				);
				$membership = $this->sn_sales_branch_map(array_column($people, 'ID'));
				$members = array_values(array_filter($people, static fn($person) => ($membership[$person->ID]['key'] ?? '') === $key));
				$pages = max(1, (int)ceil(count($members) / 50));
				$page = min($pages, max(1, absint($_GET['branch_page'] ?? 1)));
				?>
				<p><?php echo esc_html(number_format_i18n(count($members))); ?> عضو در <?php echo esc_html($branches[$key]); ?></p>
				<?php if ($members) : ?>
					<table class="widefat striped">
						<thead><tr><th scope="col">کاربر</th><th scope="col">سمت</th><th scope="col">مدیر فروش</th></tr></thead>
						<tbody>
						<?php foreach (array_slice($members, ($page - 1) * 50, 50) as $person) : ?>
							<tr>
								<td><?php echo esc_html($person->display_name); ?> <small>#<?php echo (int)$person->ID; ?></small></td>
								<td><?php echo esc_html($this->sn_distribution_position_label($this->sn_hr_position_for_user((int)$person->ID))); ?></td>
								<td><?php echo esc_html($this->sn_user_label($membership[$person->ID]['manager_id'])); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endif; ?>
				<?php if ($pages > 1) :
					$url = add_query_arg(['page'=>'sn-branches', 'members_branch'=>$key, 'branch_page'=>999999999], admin_url('admin.php'));
					echo wp_kses_post(paginate_links(['base'=>str_replace('999999999', '%#%', $url), 'total'=>$pages, 'current'=>$page]));
				endif; ?>
			<?php endif; ?>
		</section>
		<?php
	}
}
