<?php

if (!defined('ABSPATH')) {

	die('This file cannot be accessed directly');
}

define('PD_RECORDS_PER_PAGE', '20');
define('PD_VERSION', 2.0);

wp_enqueue_script('jquery');

register_activation_hook((plugin_dir_path(__FILE__) . 'index.php'), array('payirpaiddownloads_class', 'install'));

class payirpaiddownloads_class
{
	var $options;
	var $error;
	var $info;
	var $currency_list;

	var $payir_currency_list = array('ریال', 'تومان');
	var $buynow_buttons_list = array('html', 'payir', 'css3', 'custom');

	function __construct()
	{
		if (function_exists('load_plugin_textdomain')) {

			load_plugin_textdomain('payirpaiddownloads', FALSE, dirname(plugin_basename(__FILE__)) . '/languages/');
		}

		$this->currency_list = array_unique(array_merge($this->payir_currency_list));

		sort($this->currency_list);

		$this->currency_list = array_unique(array_merge(array('تومان'), $this->currency_list));

		$failed_email_body = __('کاربر گرامی {name}،', 'payirpaiddownloads') . PHP_EOL . PHP_EOL . __('ضمن تشکر از شما جهت انتخاب محصول {product_title}،', 'payirpaiddownloads') . PHP_EOL . __('پرداخت شما با وضعیت {payment_status} در سیستم ثبت شده است.', 'payirpaiddownloads') . PHP_EOL . __('در صورتی که پس از بررسی پرداخت شما موفقیت آمیز بوده باشد جزئیات محصول اصلاح خواهد شد.', 'payirpaiddownloads') . PHP_EOL . PHP_EOL. __('با تشکر', 'payirpaiddownloads') . PHP_EOL . get_bloginfo('name');

		$success_email_body = __('کاربر گرامی {name}،', 'payirpaiddownloads') . PHP_EOL . PHP_EOL. __('ضمن تشکر از شما بابت خرید محصول {product_title}، جهت دانلود برروی لینک زیر کلیک نمایید :', 'payirpaiddownloads') . PHP_EOL . '{download_link}' . PHP_EOL . __('توجه داشته باشید لینک فوق تنها به مدت {download_link_lifetime} روز دارای اعتبار جهت دریافت می باشد .', 'payirpaiddownloads') . PHP_EOL . PHP_EOL . __('با تشکر', 'payirpaiddownloads') . PHP_EOL . get_bloginfo('name');

		$this->options = array (

			'exists'                => 1,
			'version'               => PD_VERSION,
            'enable_payir'          => 'on',
			'payir_api'             => NULL,
			'payir_currency'        => $payir_currency_list[1],
			'seller_email'          => 'alerts@' . str_replace('www.', NULL, $_SERVER['SERVER_NAME']),
			'from_name'             => get_bloginfo('name'),
			'from_email'            => 'noreply@' . str_replace('www.', NULL, $_SERVER['SERVER_NAME']),
			'success_email_subject' => __('جزئیات دانلود محصول', 'payirpaiddownloads'),
			'success_email_body'    => $success_email_body,
		    'failed_email_subject'  => __('عملیات ناموفق پرداخت', 'payirpaiddownloads'),
			'failed_email_body'     => $failed_email_body,
			'buynow_type'           => 'html',
			'buynow_image'          => NULL,
			'link_lifetime'         => 2,
			'terms'                 => NULL,
            'getphonenumber'        => 'off',
            'showdownloadlink'      => 'off'
		);

		if (!empty($_COOKIE['payirpaiddownloads_error'])) {

			$this->error = stripslashes($_COOKIE['payirpaiddownloads_error']);

			setcookie('payirpaiddownloads_error', NULL, time()+30, '/', '.' . str_replace('www.', NULL, $_SERVER['SERVER_NAME']));
		}

		if (!empty($_COOKIE['payirpaiddownloads_info'])) {

			$this->info = stripslashes($_COOKIE['payirpaiddownloads_info']);

			setcookie('payirpaiddownloads_info', NULL, time()+30, '/', '.' . str_replace('www.', NULL, $_SERVER['SERVER_NAME']));
		}

		$this->get_settings();
		
		if (is_admin()) {

			if ($this->check_settings() !== TRUE) {

				add_action('admin_notices', array(&$this, 'admin_warning'));
			}

			if (!file_exists(ABSPATH.'wp-content/uploads/paid-downloads/files')) {

				add_action('admin_notices', array(&$this, 'admin_warning_reactivate'));
			}

			add_action('admin_menu', array(&$this, 'admin_menu'));
			add_action('init', array(&$this, 'admin_request_handler'));
			add_action('admin_head', array(&$this, 'admin_header'), 15);

			if (isset($_GET['page']) && $_GET['page'] == 'paid-downloads-transactions') {

				//wp_enqueue_script('thickbox');
				//wp_enqueue_style('thickbox');
			}

		} else {

			add_action('init', array(&$this, 'front_init'));
			add_action('wp_head', array(&$this, 'front_header'));

			add_shortcode('paid-downloads', array(&$this, 'shortcode_handler'));
			add_shortcode('payirpaiddownloads', array(&$this, 'shortcode_handler'));
		}
	}

	function handle_versions()
	{
		global $wpdb;
	}

	function install ()
	{
		global $wpdb;

		$table_name = $wpdb->prefix . 'pd_orders';

		$sql = "CREATE TABLE " . $table_name . " (id int(11) NOT NULL auto_increment, file_id int(11) NOT NULL,
				payer_name varchar(255) collate utf8_unicode_ci NOT NULL, payer_phone varchar(255) collate utf8_unicode_ci NOT NULL,
				payer_email varchar(255) collate utf8_unicode_ci NOT NULL, completed int(11) NOT NULL default '0', UNIQUE KEY id (id));";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

		dbDelta($sql);

		$table_name = $wpdb->prefix . 'pd_files';

		$sql = "CREATE TABLE " . $table_name . " (id int(11) NOT NULL auto_increment, title varchar(255) collate utf8_unicode_ci NOT NULL,
				filename varchar(255) collate utf8_unicode_ci NOT NULL, uploaded int(11) NOT NULL default '1',
				filename_original varchar(255) collate utf8_unicode_ci NOT NULL, price float NOT NULL,
				currency varchar(7) collate utf8_unicode_ci NOT NULL, available_copies int(11) NOT NULL default '0',
				license_url varchar(255) NOT NULL default '', registered int(11) NOT NULL, deleted int(11) NOT NULL default '0', UNIQUE KEY id (id));";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

		dbDelta($sql);

		$table_name = $wpdb->prefix . 'pd_downloadlinks';

		$sql = "CREATE TABLE " . $table_name . " (id int(11) NOT NULL auto_increment, file_id int(11) NOT NULL,
				download_key varchar(255) collate utf8_unicode_ci NOT NULL, owner varchar(63) collate utf8_unicode_ci NOT NULL,
				source varchar(15) collate utf8_unicode_ci NOT NULL, created int(11) NOT NULL, deleted int(11) NOT NULL default '0', UNIQUE KEY id (id));";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

		dbDelta($sql);

		$table_name = $wpdb->prefix . 'pd_transactions';

		$sql = "CREATE TABLE " . $table_name . " (id int(11) NOT NULL auto_increment, file_id int(11) NOT NULL,
				payer_name varchar(255) collate utf8_unicode_ci NOT NULL, payer_phone varchar(255) collate utf8_unicode_ci NOT NULL,
				payer_email varchar(255) collate utf8_unicode_ci NOT NULL, gross float NOT NULL, currency varchar(15) collate utf8_unicode_ci NOT NULL,
				payment_status varchar(31) collate utf8_unicode_ci NOT NULL, transaction_type varchar(31) collate utf8_unicode_ci NOT NULL,
				details text collate utf8_unicode_ci NOT NULL, created int(11) NOT NULL, deleted int(11) NOT NULL default '0', UNIQUE KEY id (id));";

		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

		dbDelta($sql);

		if (!file_exists(ABSPATH . 'wp-content/uploads/paid-downloads')) {

			wp_mkdir_p(ABSPATH . 'wp-content/uploads/paid-downloads');

			if (!file_exists(ABSPATH . 'wp-content/uploads/paid-downloads/index.html')) {

				file_put_contents(ABSPATH . 'wp-content/uploads/paid-downloads/index.html', 'Silence is the gold!');
			}

			if (!file_exists(ABSPATH . 'wp-content/uploads/paid-downloads/files')) {

				wp_mkdir_p(ABSPATH . 'wp-content/uploads/paid-downloads/files');

				if (!file_exists(ABSPATH . 'wp-content/uploads/paid-downloads/files/.htaccess')) {

					file_put_contents(ABSPATH . 'wp-content/uploads/paid-downloads/files/.htaccess', 'deny from all');
				}
			}
		}
	}

	function get_settings()
	{
		$exists = get_option('payirpaiddownloads_version');

		if ($exists) {

			foreach ($this->options as $key => $value) {

				$this->options[$key] = get_option('payirpaiddownloads_' . $key);
			}
		}
	}

	function update_settings()
	{
		foreach ($this->options as $key => $value) {

			update_option('payirpaiddownloads_' . $key, $value);
		}
	}

	function populate_settings()
	{
		foreach ($this->options as $key => $value) {

			if (isset($_POST['payirpaiddownloads_' . $key])) {

				$this->options[$key] = stripslashes($_POST['payirpaiddownloads_' . $key]);
			}
		}
	}

	function check_settings()
	{
		$errors = array();

		if (strlen($this->options['payir_api']) < 3) {

			$errors[] = __('تعیین کلید API الزامی می باشد', 'payirpaiddownloads');
		}

		$preg_match = !preg_match("/^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,4})$/i", $this->options['seller_email']);

		if ($preg_match || strlen($this->options['seller_email']) == 0) {

			$errors[] = __('ایمیل وارد شده جهت دریافت اطلاع رسانی ها صحیح نمی باشد', 'payirpaiddownloads');
		}

		$preg_match = !preg_match("/^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,4})$/i", $this->options['from_email']);

		if ($preg_match || strlen($this->options['from_email']) == 0) {

			$errors[] = __('آدرس ایمیل وارد شده برای فروشگاه صحیح نمی باشد', 'payirpaiddownloads');
		}

		if (strlen($this->options['from_name']) < 3) {

			$errors[] = __('نام فروشگاه کوتاه می باشد', 'payirpaiddownloads');
		}

		if (strlen($this->options['success_email_subject']) < 3) {

			$errors[] = __('عنوان ایمیل خرید موفقیت آمیز می بایست حداقل دارای 3 حرف باشد', 'payirpaiddownloads');

		} else if (strlen($this->options['success_email_subject']) > 64) {

			$errors[] = __('عنوان ایمیل خرید موفقیت آمیز می بایست حداکثر دارای 64 حرف باشد', 'payirpaiddownloads');
		}

		if (strlen($this->options['success_email_body']) < 3) {

			$errors[] = __('متن ایمیل خرید موفقیت آمیز می بایست حداقل دارای 3 حرف باشد', 'payirpaiddownloads');
		}
		
		if (strlen($this->options['failed_email_subject']) < 3) {

			$errors[] = __('عنوان ایمیل خرید ناموفق می بایست حداقل دارای 3 حرف باشد', 'payirpaiddownloads');

		} else if (strlen($this->options['failed_email_subject']) > 64) {

			$errors[] = __('عنوان ایمیل خرید ناموفق می بایست حداکثر دارای 64 حرف باشد', 'payirpaiddownloads');
		}
		
		if (strlen($this->options['failed_email_body']) < 3) {

			$errors[] = __('متن ایمیل خرید ناموفق می بایست حداقل دارای 3 حرف باشد', 'payirpaiddownloads');
		}

		$lifetime = $this->options['link_lifetime'];

		if (intval($lifetime) != $lifetime || intval($lifetime) < 1 || intval($lifetime) > 365) {

			$errors[] = __('مدت اعتبار لینک را در بازه  [1...365] روز تعیین نمایید ', 'payirpaiddownloads');
		}

		if (empty($errors)) {

			return TRUE;

		} else {

			return $errors;
		}
	}

	function admin_menu()
	{
		if (get_bloginfo('version') >= 3.0) {

			define('PAID_DOWNLOADS_PERMISSION', 'add_users');

		} else {

			define('PAID_DOWNLOADS_PERMISSION', 'edit_themes');
		}

		add_menu_page(

			'Pay.ir', 'Pay.ir', PAID_DOWNLOADS_PERMISSION,
			'paid-downloads', array(&$this, 'admin_settings')
		);

		add_submenu_page(

			'paid-downloads', __('تنظیمات', 'payirpaiddownloads'), __('تنظیمات', 'payirpaiddownloads'), PAID_DOWNLOADS_PERMISSION,
			'paid-downloads', array(&$this, 'admin_settings')
		);

		add_submenu_page(

			'paid-downloads', __('فایل ها', 'payirpaiddownloads'), __('فایل ها', 'payirpaiddownloads'), PAID_DOWNLOADS_PERMISSION,
			'paid-downloads-files', array(&$this, 'admin_files')
		);

		add_submenu_page(

			'paid-downloads' , __('اضافه کردن فایل', 'payirpaiddownloads'), __('اضافه کردن فایل', 'payirpaiddownloads'), PAID_DOWNLOADS_PERMISSION,
			'paid-downloads-add', array(&$this, 'admin_add_file')
		);

		add_submenu_page(

			'paid-downloads' , __('لینک های دریافت', 'payirpaiddownloads'), __('لینک های دریافت', 'payirpaiddownloads'), PAID_DOWNLOADS_PERMISSION,
			'paid-downloads-links', array(&$this, 'admin_links')
		);

		add_submenu_page(

			'paid-downloads', __('اضافه کردن لینک', 'payirpaiddownloads'), __('اضافه کردن لینک', 'payirpaiddownloads'), PAID_DOWNLOADS_PERMISSION,
			'paid-downloads-add-link', array(&$this, 'admin_add_link')
		);

		add_submenu_page(

			'paid-downloads', __('پرداخت ها', 'payirpaiddownloads'), __('پرداخت ها', 'payirpaiddownloads'), PAID_DOWNLOADS_PERMISSION,
			'paid-downloads-transactions', array(&$this, 'admin_transactions')
		);
	}

	function admin_settings() {
		global $wpdb;
		$message = "";
		$errors = array();
		if (!empty($this->error)) $message = "<div class='error'><p>".$this->error."</p></div>";
		else {
			$errors = $this->check_settings();
			if (is_array($errors)) echo "<div class='error'><p>".__('خطا های موجود در فرم:', 'payirpaiddownloads')."<br />- ".implode("<br />- ", $errors)."</p></div>";
		}
		if ($_GET["updated"] == "true") {
			$message = '<div class="updated"><p>'.__('تنظیمات افزونه با  <strong>موفقیت</strong> بروزرسانی گردید .', 'payirpaiddownloads').'</p></div>';
		}
		if (!in_array($this->options['buynow_type'], $this->buynow_buttons_list)) $this->options['buynow_type'] = $this->buynow_buttons_list[0];
		if ($this->options['buynow_type'] == "custom")
		{
			if (empty($this->options['buynow_image'])) $this->options['buynow_type'] = $this->buynow_buttons_list[0];
		}
		print ('
		<div class="wrap admin_payirpaiddownloads_wrap">
			<div id="icon-options-general" class="icon32"><br /></div><h2>'.__('دانلود به ازای پرداخت Pay.ir - تنظیمات', 'payirpaiddownloads').'</h2><br />
			'.$message);

		print ('
			<form enctype="multipart/form-data" method="post" style="margin: 0px" action="'.get_bloginfo('wpurl').'/wp-admin/admin.php">

			<div class="postbox-container" style="width: 100%;">
				<div class="metabox-holder">
					<div class="meta-box-sortables ui-sortable">
						<div class="postbox">
							<!--<div class="handlediv" title="Click to toggle"><br /></div>-->
							<h3 class="hndle" style="cursor: default;"><span>'.__('تنظیمات اصلی', 'payirpaiddownloads').'</span></h3>
							<div class="inside">
								<table class="payirpaiddownloads_useroptions">
									<tr>
										<th>'.__('ایمیل اطلاع رسانی', 'payirpaiddownloads').':</th>
										<td><input type="text" id="payirpaiddownloads_seller_email" name="payirpaiddownloads_seller_email" value="'.htmlspecialchars($this->options['seller_email'], ENT_QUOTES).'" class="widefat"><br /><em>'.__('لطفا یک آدرس ایمیل جهت دریافت کلیه رویداد ها خرید/پرداخت وارد نمایید.', 'payirpaiddownloads').'</em></td>
									</tr>
									<tr>
										<th>'.__('نام فروشگاه', 'payirpaiddownloads').':</th>
										<td><input type="text" id="payirpaiddownloads_from_name" name="payirpaiddownloads_from_name" value="'.htmlspecialchars($this->options['from_name'], ENT_QUOTES).'" class="widefat"><br /><em>'.__('لطفا نام مورد نظر خود جهت پیام های ارسالی به خریدار را در این قسمت تعیین نمایید .', 'payirpaiddownloads').'</em></td>
									</tr>
									<tr>
										<th>'.__('ایمیل فروشگاه', 'payirpaiddownloads').':</th>
										<td><input type="text" id="payirpaiddownloads_from_email" name="payirpaiddownloads_from_email" value="'.htmlspecialchars($this->options['from_email'], ENT_QUOTES).'" class="widefat"><br /><em>'.__('تمامی ایمیل های ارسالی برای خریدار از طرف این ایمیل ارسال خواهد شد.', 'payirpaiddownloads').'</em></td>
									</tr>
									<tr>
										<th>'.__('عنوان ایمیل خرید موفق', 'payirpaiddownloads').':</th>
										<td><input type="text" id="payirpaiddownloads_success_email_subject" name="payirpaiddownloads_success_email_subject" value="'.htmlspecialchars($this->options['success_email_subject'], ENT_QUOTES).'" class="widefat"><br /><em>'.__('پس از پرداخت موفقیت آمیز پرداخت کننده یک ایمیل با این عنوان دریافت می نماید.', 'payirpaiddownloads').'</em></td>
									</tr>
									<tr>
										<th>'.__('متن ایمیل خرید موفق', 'payirpaiddownloads').':</th>
										<td><textarea id="payirpaiddownloads_success_email_body" name="payirpaiddownloads_success_email_body" class="widefat" style="height: 120px;">'.htmlspecialchars($this->options['success_email_body'], ENT_QUOTES).'</textarea><br /><em>'.__('پس از خرید موفقیت آمیز متن فوق برای کاربر ارسال می گردد، جهت جایگزینی در هنگام ارسال از فیلد های زیر استفاده نمایید: {name}, {payer_email}, {product_title}, {product_price}, {product_currency}, {download_link}, {download_link_lifetime}, {license_info}.', 'payirpaiddownloads').'</em></td>
									</tr>

									<tr>
										<th>'.__('عنوان ایمیل خرید ناموفق', 'payirpaiddownloads').':</th>
										<td><input type="text" id="payirpaiddownloads_failed_email_subject" name="payirpaiddownloads_failed_email_subject" value="'.htmlspecialchars($this->options['failed_email_subject'], ENT_QUOTES).'" class="widefat"><br /><em>'.__('پس از پرداخت ناموفق پرداخت کننده یک ایمیل با این عنوان دریافت می نماید.', 'payirpaiddownloads').'</em></td>
									</tr>
									<tr>
										<th>'.__('متن ایمیل خرید ناموفق', 'payirpaiddownloads').':</th>
										<td><textarea id="payirpaiddownloads_failed_email_body" name="payirpaiddownloads_failed_email_body" class="widefat" style="height: 120px;">'.htmlspecialchars($this->options['failed_email_body'], ENT_QUOTES).'</textarea><br /><em>'.__('پس از خرید ناموفق متن فوق برای کاربر ارسال می گردد، جهت جایگزینی در هنگام ارسال از فیلد های زیر استفاده نمایید : {name}, {payer_email}, {product_title}, {product_price}, {product_currency}, {payment_status}.', 'payirpaiddownloads').'</em></td>
									</tr>
									<tr>
										<th>'.__('مدت اعتبار لینک دانلود', 'payirpaiddownloads').':</th>
										<td><input type="text" id="payirpaiddownloads_link_lifetime" name="payirpaiddownloads_link_lifetime" value="'.htmlspecialchars($this->options['link_lifetime'], ENT_QUOTES).'" style="width: 60px; text-align: right;"> روز<br /><em>'.__('لطفا مدت زمان اعتبار لینک دانلود را تعیین نمایید.', 'payirpaiddownloads').'</em></td>
									</tr>
									<tr>
										<th>'.__('نوع کلید خرید', 'payirpaiddownloads').':</th>
										<td>
											<table style="border: 0px; padding: 0px;">
											<tr><td style="padding-top: 8px; width: 20px;"><input type="radio"  name="payirpaiddownloads_buynow_type" value="html"'.($this->options['buynow_type'] == "html" ? ' checked="checked"' : '').'></td><td>'.__('کلید استاندارد HTML', 'payirpaiddownloads').'<br /><button style="font-family:tahoma; padding:2px; width:100px" onclick="return false;">'.__('خرید', 'payirpaiddownloads').'</button></td></tr>
											<tr><td style="padding-top: 8px;"><input type="radio" name="payirpaiddownloads_buynow_type" value="payir"'.($this->options['buynow_type'] == "payir" ? ' checked="checked"' : '').'></td><td>'.__('کلید API', 'payirpaiddownloads').'<br /><img src="'.plugins_url('/images/btn_buynow.png', __FILE__).'" border="0"></td></tr>
											<tr><td style="padding-top: 8px;"><input type="radio" name="payirpaiddownloads_buynow_type" value="css3"'.($this->options['buynow_type'] == "css3" ? ' checked="checked"' : '').'></td><td>'.__('کلید با استفاده از CSS3', 'payirpaiddownloads').'<br />
											<a href="#" class="payirpaiddownloads-btn" onclick="return false;">
  												<span class="payirpaiddownloads-btn-icon-right"><span></span></span>
												<span class="payirpaiddownloads-btn-slide-text">1000 تومان</span>
                                                <span class="payirpaiddownloads-btn-text">'.__('خرید', 'payirpaiddownloads').'</span>
											</a>
											</td></tr>
											<tr><td style="padding-top: 8px;"><input type="radio" name="payirpaiddownloads_buynow_type" value="custom"'.($this->options['buynow_type'] == "custom" ? ' checked="checked"' : '').'></td><td>'.__('کلید سفارشی', 'payirpaiddownloads').(!empty($this->options['buynow_image']) ? '<br /><img src="'.get_bloginfo("wpurl").'/wp-content/uploads/paid-downloads/'.rawurlencode($this->options['buynow_image']).'" border="0">' : '').'<br /><input type="file" id="payirpaiddownloads_buynow_image" name="payirpaiddownloads_buynow_image" class="widefat"><br /><em>'.__('مجاز به انتخاب تصویر با ابعاد : 600px در  600px و پسوند : JPG, GIF, PNG.', 'payirpaiddownloads').'</em></td></tr>
											</table>
										</td>
									</tr>
                                    <tr>
										<th>'.__('گزینه ها', 'payirpaiddownloads').$this->options['getphonenumber'] .':</th>
										<td>
                                            <input type="checkbox" '.($this->options['getphonenumber'] == "on" ? ' checked="checked"' : '').' name="payirpaiddownloads_getphonenumber"  id="payirpaiddownloads_getphonenumber" /><label for="payirpaiddownloads_getphonenumber">دریافت شماره تلفن همراه کاربر</label>
                                            <br/>
                                            <input type="checkbox" '.($this->options['showdownloadlink'] == "on" ? ' checked="checked"' : '').' name="payirpaiddownloads_showdownloadlink"  id="payirpaiddownloads_showdownloadlink" /><label for="payirpaiddownloads_showdownloadlink">نمایش لینک دانلود پایان خرید</label>
                                        </td>
									</tr>
									<tr>
										<th>'.__('قوانین و مقررات', 'payirpaiddownloads').':</th>
										<td><textarea id="payirpaiddownloads_terms" name="payirpaiddownloads_terms" class="widefat" style="height: 120px;">'.htmlspecialchars($this->options['terms'], ENT_QUOTES).'</textarea><br /><em>'.__('در صورتی که نیازمند پذیرش قوانین و مقررات جهت خرید و دانلود فایل های سایت خود هستید از این قسمت استفاده نمایید، خالی گذاشتن فیلد فوق به معنی نداشتن قوانین و مقررات خواهد بود.', 'payirpaiddownloads').'</em></td>
									</tr>
								</table>
								<div class="alignright">
								<input type="hidden" name="ak_action" value="payirpaiddownloads_update_settings" />
								<input type="hidden" name="payirpaiddownloads_exists" value="1" />
								<input type="submit" class="paiddownoads_button button-primary" name="submit" value="'.__('ذخیره تنظیمات', 'payirpaiddownloads').' »">
								</div>
								<br class="clear">
							</div>
						</div>

						<div class="postbox">
							<!--<div class="handlediv" title="Click to toggle"><br /></div>-->
							<h3 class="hndle" style="cursor: default;"><span>'.__('تنظیمات درگاه پرداخت و کیف پول الکترونیک Pay.ir', 'payirpaiddownloads').'</span></h3>
							<div class="inside">
								<table class="payirpaiddownloads_useroptions">
									<tr><th colspan="2">'.
									
									(!in_array('curl', get_loaded_extensions()) ? __('جهت استفاده از درگاه Pay.ir cURL را بر روی سرور هاست خود فعال نمایید!', 'payirpaiddownloads') : NULL).'</th></tr>

									<tr>
										<th>'.__('کلید API', 'payirpaiddownloads').':</th>
										<td><input type="text" id="payirpaiddownloads_payir_api" name="payirpaiddownloads_payir_api" value="'.htmlspecialchars($this->options['payir_api'], ENT_QUOTES).'" class="widefat"'.(!in_array('curl', get_loaded_extensions()) ? ' disabled="disabled"' : '').'><br /><em>'.__('لطفا کلید API خود را این قسمت وارد نمایید.', 'payirpaiddownloads').'</em></td>
									</tr>
                                    <tr>
										<th>'.__('واحد پول', 'payirpaiddownloads').':</th>
										<td>
											<select name="payirpaiddownloads_payir_currency" id="payirpaiddownloads_payir_currency"'.(!in_array('curl', get_loaded_extensions()) ? ' disabled="disabled"' : '').'>');
		for ($i=0; $i<sizeof($this->payir_currency_list); $i++)
		{
			echo '
												<option value="'.$this->payir_currency_list[$i].'"'.($this->payir_currency_list[$i] == $this->options['payir_currency'] ? ' selected="selected"' : '').'>'.$this->payir_currency_list[$i].'</option>';
		}
		print('
											</select>
											<br /><em>'.__('نوع واحد پول مورد نظر خود را تعیین نمایید.', 'payirpaiddownloads').'</em>
										</td>
									</tr>
								</table>
								<div class="alignright">
									<input type="submit" class="paiddownoads_button button-primary" name="submit" value="'.__('ذخیره تنظیمات', 'payirpaiddownloads').' »">
								</div>
								<br class="clear">
							</div>
						</div>
					</div>
				</div>
			</div>
			</form>
		</div>');
	}

	function admin_files() {
		global $wpdb;

		if (isset($_GET["s"])) $search_query = trim(stripslashes($_GET["s"]));
		else $search_query = "";
		
		$tmp = $wpdb->get_row("SELECT COUNT(*) AS total FROM ".$wpdb->prefix."pd_files WHERE deleted = '0'  ".((strlen($search_query) > 0) ? "and filename_original LIKE '%".addslashes($search_query)."%' OR deleted = '0' and  title LIKE '%".addslashes($search_query)."%'" : ""), ARRAY_A);
		$total = $tmp["total"];
		$totalpages = ceil($total/PD_RECORDS_PER_PAGE);
		if ($totalpages == 0) $totalpages = 1;
		if (isset($_GET["p"])) $page = intval($_GET["p"]);
		else $page = 1;
		if ($page < 1 || $page > $totalpages) $page = 1;
		$switcher = $this->page_switcher(get_bloginfo("wpurl")."/wp-admin/admin.php?page=paid-downloads-files".((strlen($search_query) > 0) ? "&s=".rawurlencode($search_query) : ""), $page, $totalpages);

		$sql = "SELECT * FROM ".$wpdb->prefix."pd_files WHERE deleted = '0' ".((strlen($search_query) > 0) ? "and filename_original LIKE '%".addslashes($search_query)."%' OR deleted = '0' and  title LIKE '%".addslashes($search_query)."%'" : "")." ORDER BY registered DESC LIMIT ".(($page-1)*PD_RECORDS_PER_PAGE).", ".PD_RECORDS_PER_PAGE;
		$rows = $wpdb->get_results($sql, ARRAY_A);
		if (!empty($this->error)) $message = "<div class='error'><p>".$this->error."</p></div>";
		if (!empty($this->info)) $message = "<div class='updated'><p>".$this->info."</p></div>";

		print ('
			<div class="wrap admin_payirpaiddownloads_wrap">
				<div id="icon-upload" class="icon32"><br /></div><h2>'.__('دانلود به ازای پرداخت Pay.ir - فایل ها', 'payirpaiddownloads').'</h2><br />
				'.$message.'
				<form action="'.get_bloginfo("wpurl").'/wp-admin/admin.php" method="get" style="margin-bottom: 10px;">
				<input type="hidden" name="page" value="paid-downloads-files" />
				'.__('جستجو :', 'payirpaiddownloads').' <input type="text" name="s" value="'.htmlspecialchars($search_query, ENT_QUOTES).'">
				<input type="submit" class="button-secondary action" value="'.__('جستجو', 'payirpaiddownloads').'" />
				'.((strlen($search_query) > 0) ? '<input type="button" class="button-secondary action" value="'.__('برگشت به حالت لیست', 'payirpaiddownloads').'" onclick="window.location.href=\''.get_bloginfo("wpurl").'/wp-admin/admin.php?page=paid-downloads-files\';" />' : '').'
				</form>
				<div class="payirpaiddownloads_buttons"><a class="button" href="'.get_bloginfo("wpurl").'/wp-admin/admin.php?page=paid-downloads-add">'.__('بارگزاری فایل جدید', 'payirpaiddownloads').'</a></div>
				<div class="payirpaiddownloads_pageswitcher">'.$switcher.'</div>
				<table class="payirpaiddownloads_files">
				<tr>
					<th>'.__('فایل', 'payirpaiddownloads').'</th>
					<th style="width: 190px;">'.__('کد فایل', 'payirpaiddownloads').'</th>
					<th style="width: 90px;">'.__('مبلغ', 'payirpaiddownloads').'</th>
					<th style="width: 90px;">'.__('تعداد فروش', 'payirpaiddownloads').'</th>
					<th style="width: 130px;">'.__('عملیات', 'payirpaiddownloads').'</th>
				</tr>
		');
		if (sizeof($rows) > 0)
		{
			foreach ($rows as $row)
			{
				$sql = "SELECT COUNT(id) AS sales FROM ".$wpdb->prefix."pd_transactions WHERE file_id = '".$row["id"]."' AND (payment_status = '100')";
				$sales = $wpdb->get_row($sql, ARRAY_A);
				print ('
				<tr>
					<td><strong>'.$row['title'].'</strong><br /><em style="font-size: 12px; line-height: 14px;">'.htmlspecialchars($row['filename_original'], ENT_QUOTES).'</em></td>
					<td dir="ltr" style="direction:ltr; text-align:center">[payirpaiddownloads id="'.$row['id'].'"]</td>
					<td style="text-align: right;">'.number_format($row['price'],0).' '.$row['currency'].'</td>
					<td style="text-align: right;">'.intval($sales["sales"]).' / '.(($row['available_copies'] == 0) ? '&infin;' : $row['available_copies']).'</td>
					<td style="text-align: center;">
						<a href="'.get_bloginfo("wpurl").'/wp-admin/admin.php?page=paid-downloads-add&id='.$row['id'].'" title="'.__('ویرایش جزئیات', 'payirpaiddownloads').'"><img src="'.plugins_url('/images/edit.png', __FILE__).'" alt="'.__('ویرایش جزئیات', 'payirpaiddownloads').'" border="0"></a>
						<a href="'.get_bloginfo("wpurl").'/wp-admin/admin.php?page=paid-downloads-add-link&fid='.$row['id'].'" title="'.__('ایجاد لینک دانلود', 'payirpaiddownloads').'"><img src="'.plugins_url('/images/downloadlink.png', __FILE__).'" alt="'.__('ایجاد لینک دانلود', 'payirpaiddownloads').'" border="0"></a>
						<a href="'.get_bloginfo("wpurl").'/wp-admin/admin.php?page=paid-downloads-links&fid='.$row['id'].'" title="'.__('لینک های دانلود ایجاد شده', 'payirpaiddownloads').'"><img src="'.plugins_url('/images/linkhistory.png', __FILE__).'" alt="'.__('لینک های دانلود ایجاد شده', 'payirpaiddownloads').'" border="0"></a>
						<a href="'.get_bloginfo("wpurl").'/wp-admin/admin.php?page=paid-downloads-transactions&fid='.$row['id'].'" title="'.__('تراکنش های پرداختی', 'payirpaiddownloads').'"><img src="'.plugins_url('/images/transactions.png', __FILE__).'" alt="'.__('تراکنش های پرداختی', 'payirpaiddownloads').'" border="0"></a>
						<a href="'.get_bloginfo("wpurl").'/?payirpaiddownloads_id='.$row['id'].'" title="'.__('دانلود فایل', 'payirpaiddownloads').'"><img src="'.plugins_url('/images/download01.png', __FILE__).'" alt="'.__('دانلود فایل', 'payirpaiddownloads').'" border="0"></a>
						<a href="'.get_bloginfo("wpurl").'/wp-admin/admin.php?ak_action=payirpaiddownloads_delete&id='.$row['id'].'" title="'.__('حذف فایل', 'payirpaiddownloads').'" onclick="return payirpaiddownloads_submitOperation();"><img src="'.plugins_url('/images/delete.png', __FILE__).'" alt="'.__('حذف فایل', 'payirpaiddownloads').'" border="0"></a>
					</td>
				</tr>
				');
			}
		}
		else
		{
			print ('
				<tr><td colspan="5" style="padding: 20px; text-align: center;">'.((strlen($search_query) > 0) ? __('هیچ نتیجه ای یافت نشد', 'payirpaiddownloads').' "<strong>'.htmlspecialchars($search_query, ENT_QUOTES).'</strong>"' : __('هیچ فایلی یافت نشد.', 'payirpaiddownloads')).'</td></tr>
			');
		}
		print ('
				</table>
				<div class="payirpaiddownloads_buttons"><a class="button" href="'.get_bloginfo("wpurl").'/wp-admin/admin.php?page=paid-downloads-add">'.__('بارگزاری فایل جدید', 'payirpaiddownloads').'</a></div>
				<div class="payirpaiddownloads_pageswitcher">'.$switcher.'</div>
				<div class="payirpaiddownloads_legend">
				<strong>'.__('راهنما :', 'payirpaiddownloads').'</strong>
					<p><img src="'.plugins_url('/images/edit.png', __FILE__).'" alt="'.__('ویرایش جزئیات', 'payirpaiddownloads').'" border="0"> '.__('ویرایش جزئیات', 'payirpaiddownloads').'</p>
					<p><img src="'.plugins_url('/images/downloadlink.png', __FILE__).'" alt="'.__('ایجاد لینک دانلود', 'payirpaiddownloads').'" border="0"> '.__('ایجاد لینک دانلود', 'payirpaiddownloads').'</p>
					<p><img src="'.plugins_url('/images/linkhistory.png', __FILE__).'" alt="'.__('لینک های دانلود ایجاد شده', 'payirpaiddownloads').'" border="0"> '.__('لینک های دانلود ایجاد شده', 'payirpaiddownloads').'</p>
					<p><img src="'.plugins_url('/images/transactions.png', __FILE__).'" alt="'.__('تراکنش های پرداختی', 'payirpaiddownloads').'" border="0"> '.__('تراکنش های پرداختی', 'payirpaiddownloads').'</p>
					<p><img src="'.plugins_url('/images/download01.png', __FILE__).'" alt="'.__('دانلود فایل', 'payirpaiddownloads').'" border="0"> '.__('دانلود فایل', 'payirpaiddownloads').'</p>
					<p><img src="'.plugins_url('/images/delete.png', __FILE__).'" alt="'.__('حذف فایل', 'payirpaiddownloads').'" border="0"> '.__('حذف فایل', 'payirpaiddownloads').'</p>
				</div>
			</div>
		');
	}

	function admin_add_file() {
		global $wpdb;

		unset($id);
		$status = "";
		if (isset($_GET["id"]) && !empty($_GET["id"])) {
			$id = intval($_GET["id"]);
			$file_details = $wpdb->get_row("SELECT * FROM ".$wpdb->prefix."pd_files WHERE id = '".$id."' AND deleted = '0'", ARRAY_A);
			if (intval($file_details["id"]) == 0) unset($id);
		}
		$errors = true;
		if (!empty($this->error)) $message = "<div class='error'><p>".$this->error."</p></div>";
		else if (!empty($this->info)) $message = "<div class='updated'><p>".$this->info."</p></div>";

		$file = array();
		if (file_exists(ABSPATH.'wp-content/uploads/paid-downloads/files') && is_dir(ABSPATH.'wp-content/uploads/paid-downloads/files')) {
			$dircontent = scandir(ABSPATH.'wp-content/uploads/paid-downloads/files');
			for ($i=0; $i<sizeof($dircontent); $i++) {
				if ($dircontent[$i] != "." && $dircontent[$i] != ".." && $dircontent[$i] != "index.html" && $dircontent[$i] != ".htaccess") {
					if (is_file(ABSPATH.'wp-content/uploads/paid-downloads/files/'.$dircontent[$i])) {
						$files[] = $dircontent[$i];
					}
				}
			}
		}
		print ('
		<div class="wrap admin_payirpaiddownloads_wrap">
			<div id="icon-options-general" class="icon32"><br /></div><h2>'.(!empty($id) ? __('دانلود به ازای پرداخت Pay.ir - ویرایش فایل', 'payirpaiddownloads') : __('دانلود به ازای پرداخت Pay.ir - بارگزاری فایل جدید', 'payirpaiddownloads')).'</h2>
			'.$message.'
			<form enctype="multipart/form-data" method="post" style="margin: 0px" action="'.get_bloginfo('wpurl').'/wp-admin/admin.php">
			<div class="postbox-container" style="width: 100%;">
				<div class="metabox-holder">
					<div class="meta-box-sortables ui-sortable">
						<div class="postbox">
							<!--<div class="handlediv" title="Click to toggle"><br /></div>-->
							<h3 class="hndle" style="cursor: default;"><span>'.(!empty($id) ? __('رایش فایل', 'payirpaiddownloads') : __('بارگزاری فایل جدید', 'payirpaiddownloads')).'</span></h3>
							<div class="inside">
								<table class="payirpaiddownloads_useroptions">
									<tr>
										<th>'.__('عنوان', 'payirpaiddownloads').':</th>
										<td><input type="text" name="payirpaiddownloads_title" id="payirpaiddownloads_title" value="'.htmlspecialchars($file_details['title'], ENT_QUOTES).'" class="widefat"><br /><em>'.__('لطفا عنوان فایل خود را مشخص نمایید، در صورت خالی گذاشتن این فیلد نام فایل اصلی به انتخاب خواهد شد.', 'payirpaiddownloads').'</em></td>
									</tr>
									<tr>
										<th>'.__('فایل', 'payirpaiddownloads').':</th>
										<td>

                                        <div class="container-tab">

                                        	<ul class="tabs">
                                        		<li class="tab-link current" data-tab="tab-1" onclick="changeTab(this)">بارگزاری فایل</li>
                                        		<li class="tab-link" data-tab="tab-2" onclick="changeTab(this)">انتخاب از فایل ها موجود</li>
                                        		<li class="tab-link" data-tab="tab-3" onclick="changeTab(this)">لینک به فایل</li>
                                        	</ul>

                                        	<div id="tab-1" class="tab-content current">
    											<input type="file" name="payirpaiddownloads_file" id="payirpaiddownloads_file" class="widefat"><br /><em>'.__('انتخاب فایل برای بارگزاری', 'payirpaiddownloads').'</em>
                                        	</div>
                                        	<div id="tab-2" class="tab-content">
                                                <select name="payirpaiddownloads_fileselector" id="payirpaiddownloads_fileselector">
												<option value="">-- '.__('انتخاب از فایل های موجود', 'payirpaiddownloads').' --</option>');
                                        		for ($i=0; $i<sizeof($files); $i++)
                                        		{
                                        			echo '<option value="'.htmlspecialchars($files[$i], ENT_QUOTES).'"'.($files[$i] == $file_details['filename'] ? ' selected="selected"' : '').'>'.htmlspecialchars($files[$i], ENT_QUOTES).'</option>';
                                        		}
                                        		print('	</select><br /><em>'.__('شما می توانید یک فایل را از مسیر <strong>/wp-content/uploads/paid-downloads/files/</strong> انتخاب و یا نسبت به بارگزاری فایل اقدام نمایید.', 'payirpaiddownloads').'</em><br /><br />
                                        	</div>
                                        	<div id="tab-3" class="tab-content">
                                                 لینک دانلود فایل : <br /><br />
										         <input type="text" name="payirpaiddownloads_filelink" id="payirpaiddownloads_filelink" value="'.($file_details['uploaded'] == 2 ? $file_details['filename'] : "").'" class="widefat enput">
                                                 <br /><em>مسیر فایل را به صورت کامل جهت هدایت کاربر برای دانلود وارد نمایید ،&nbsp;مثال : http://www.mydownloadhost.com/files/filename.zip</em>
                                        	</div>

                                            <input name="payirpaiddownloads_filetype" id="payirpaiddownloads_filetype" type="hidden" value="'.(isset($_GET['ty']) ? $_GET['ty'] : ($file_details['uploaded'] == 2 ? 'tab-3' : (!empty($file_details['uploaded']) ? 'tab-2' : 'tab-1' ))).'" />
                                        </div>




										</td>
									</tr>
									<tr>
										<th>'.__('مبلغ', 'payirpaiddownloads').':</th>
										<td>
											<input type="text" name="payirpaiddownloads_price" id="payirpaiddownloads_price" value="'.(!empty($id) ? number_format($file_details['price'], 0, '.', '') : '0').'" style="width: 80px; text-align: right;">
											<select name="payirpaiddownloads_currency" style="vertical-align: inherit; height:26px;" id="payirpaiddownloads_currency" onchange="payirpaiddownloads_supportedmethods();">');
		foreach ($this->currency_list as $currency) {
			echo '
												<option value="'.$currency.'"'.($currency == $file_details['currency'] ? ' selected="selected"' : '').'>'.$currency.'</option>';
		}
		print('
											</select>
											<label id="payirpaiddownloads_supported" style="color: green; display:none"></label>
											<br /><em>'.__('مبلغ مورد نظر جهت خرید فایل را تعیین نمایید، ورود مبلغ 0 به معنی رایگان بودن فایل خواهد بود.', 'payirpaiddownloads').'</em>
										</td>
									</tr>
									<tr>
										<th>'.__('تعداد موجود', 'payirpaiddownloads').':</th>
										<td><input type="text" name="payirpaiddownloads_available_copies" id="payirpaiddownloads_available_copies" value="'.(!empty($id) ? intval($file_details['available_copies']) : '0').'" style="width: 80px; text-align: right;"><br /><em>'.__('تعداد موجود جهت فروش فایل را تعیین نمایید . پس از رسیدن فروش فایل به این سقف کلید خرید غیر فعال خواهد شد. خالی گذاشتن و یا مقدار 0 برای این فیلد به معنی تعداد نامحدود می باشد.در صورتی که محدودیتی در فروش ندارید این گزینه را خالی بگذارید.', 'payirpaiddownloads').'</em></td>
									</tr>
									<tr>
										<th>'.__('مسیر دریافت لایسنس', 'payirpaiddownloads').':</th>
										<td><input type="text" name="payirpaiddownloads_license_url" id="payirpaiddownloads_license_url" value="'.htmlspecialchars($file_details['license_url'], ENT_QUOTES).'" class="widefat enput"'.(!in_array('curl', get_loaded_extensions()) ? ' readonly="readonly"' : '').'><br /><em>'.__('در صورتی که استفاده از این فایل نیازمند دریافت لایسنس می باشد . پس از پرداخت موفقیت آمیز اطلاعات خرید به این مسیر ارسال می گردد. سپس محتوای برگشتی از این مسیر در  <strong>متن ایمیل خرید موفق</strong> جایگزین فیلد {license_info} خواهد شد. در صورتی که فایل شما بدون لایسنس می باشد از این فیلد صرف نظر نمایید. استفاده از این گزینه نیازمند فعال بودن CURL بر روی سرور هاست می باشد.', 'payirpaiddownloads').'</em></td>
									</tr>
								</table>
								<div class="alignright">
								<input type="hidden" name="ak_action" value="payirpaiddownloads_update_file" />
								'.(!empty($id) ? '<input type="hidden" name="payirpaiddownloads_id" value="'.$id.'" />' : '').'
								<input type="submit" class="paiddownoads_button button-primary" name="submit" value="'.__('ذخیره اطلاعات', 'payirpaiddownloads').' »">
								</div>
								<br class="clear">
							</div>
						</div>
					</div>
				</div>
			</div>
			</form>
			<script type="text/javascript">
				function payirpaiddownloads_supportedmethods() {
					var payir_currencies = new Array("'.implode('", "', $this->payir_currency_list).'");
					var currency = jQuery("#payirpaiddownloads_currency").val();
					var supported = "";
					if (jQuery.inArray(currency, payir_currencies) >= 0) supported = "payir, ";
					supported = supported + "InterKassa";
					jQuery("#payirpaiddownloads_supported").html("'.__('Supported payment methods:', 'payirpaiddownloads').' " + supported);
				}
				payirpaiddownloads_supportedmethods();

              	function changeTab(tab){

              		var tab_id = jQuery(tab).attr("data-tab");

              		jQuery("ul.tabs li").removeClass("current");
              		jQuery(".tab-content").removeClass("current");

              		jQuery(tab).addClass("current");
              		jQuery("#"+tab_id).addClass("current");
                    jQuery("#payirpaiddownloads_filetype").val(tab_id);

              	}
                changeTab(jQuery("li[data-tab=\'"+jQuery("#payirpaiddownloads_filetype").val()+"\'"));
			</script>
		</div>');
	}

	function admin_links() {
		global $wpdb;

		if (isset($_GET["fid"])) $file_id = intval(trim(stripslashes($_GET["fid"])));
		else $file_id = 0;

		$tmp = $wpdb->get_row("SELECT COUNT(*) AS total FROM ".$wpdb->prefix."pd_downloadlinks WHERE deleted = '0'".($file_id > 0 ? " AND file_id = '".$file_id."'" : ""), ARRAY_A);
		$total = $tmp["total"];
		$totalpages = ceil($total/PD_RECORDS_PER_PAGE);
		if ($totalpages == 0) $totalpages = 1;
		if (isset($_GET["p"])) $page = intval($_GET["p"]);
		else $page = 1;
		if ($page < 1 || $page > $totalpages) $page = 1;
		$switcher = $this->page_switcher(get_bloginfo("wpurl")."/wp-admin/admin.php?page=paid-downloads-links".($file_id > 0 ? '&fid='.$file_id : ''), $page, $totalpages);

		$sql = "SELECT t1.*, t2.title AS file_title FROM ".$wpdb->prefix."pd_downloadlinks t1 LEFT JOIN ".$wpdb->prefix."pd_files t2 ON t2.id = t1.file_id WHERE t1.deleted = '0'".($file_id > 0 ? " AND file_id = '".$file_id."'" : "")." ORDER BY t1.created DESC LIMIT ".(($page-1)*PD_RECORDS_PER_PAGE).", ".PD_RECORDS_PER_PAGE;
		$rows = $wpdb->get_results($sql, ARRAY_A);
		if (!empty($this->error)) $message = "<div class='error'><p>".$this->error."</p></div>";
		if (!empty($this->info)) $message = "<div class='updated'><p>".$this->info."</p></div>";

		print ('
			<div class="wrap admin_payirpaiddownloads_wrap">
				<div id="icon-upload" class="icon32"><br /></div><h2>'.__('دانلود به ازای پرداخت Pay.ir - لینک های دریافت', 'payirpaiddownloads').'</h2><br />
				'.$message.'
				<div class="payirpaiddownloads_buttons"><a class="button" href="'.get_bloginfo("wpurl").'/wp-admin/admin.php?page=paid-downloads-add-link'.($file_id > 0 ? '&fid='.$file_id : '').'">'.__('اضافه کردن لینک جدید', 'payirpaiddownloads').'</a></div>
				<div class="payirpaiddownloads_pageswitcher">'.$switcher.'</div>
				<table class="payirpaiddownloads_files">
				<tr>
					<th>'.__('لینک دانلود', 'payirpaiddownloads').'</th>
					<th style="width: 160px;">'.__('صاحب', 'payirpaiddownloads').'</th>
					<th style="width: 160px;">'.__('فایل', 'payirpaiddownloads').'</th>
					<th style="width: 80px;">'.__('منبع', 'payirpaiddownloads').'</th>
					<th style="width: 50px;">'.__('حذف', 'payirpaiddownloads').'</th>
				</tr>
		');
		if (sizeof($rows) > 0)
		{
			foreach ($rows as $row)
			{
				if (time() <= $row["created"] + 24*3600*$this->options['link_lifetime']) {
					$expired = "منقضی در ".$this->period_to_string($row["created"] + 24*3600*$this->options['link_lifetime'] - time())." دیگر";
					$bg_color = "#FFFFFF";
				} else {
					$expired = "";
					$bg_color = "#F0F0F0";
				}
				print ('
				<tr style="background-color: '.$bg_color .';">
					<td><input type="text" class="widefat" onclick="this.focus();this.select();" readonly="readonly" dir="ltr" value="'.get_bloginfo('wpurl').'/?payirpaiddownloads_key='.$row["download_key"].'">'.(!empty($expired) ? '<br /><em>'.$expired.'</em>' : '').'</td>
					<td>'.htmlspecialchars($row['owner'], ENT_QUOTES).'</td>
					<td>'.(!empty($row['file_title']) ? htmlspecialchars($row['file_title'], ENT_QUOTES) : '-').'</td>
					<td>'.htmlspecialchars($row['source'] == 'purchasing' ? 'فروش' : 'دستی', ENT_QUOTES).'</td>
					<td style="text-align: center;">
						<a href="'.get_bloginfo("wpurl").'/wp-admin/admin.php?ak_action=payirpaiddownloads_delete_link&id='.$row['id'].'" title="'.__('حذف لینک دریافت', 'payirpaiddownloads').'" onclick="return payirpaiddownloads_submitOperation();"><img src="'.plugins_url('/images/delete.png', __FILE__).'" alt="'.__('Delete download link', 'payirpaiddownloads').'" border="0"></a>
					</td>
				</tr>
				');
			}
		}
		else
		{
			print ('
				<tr><td colspan="5" style="padding: 20px; text-align: center;">'.__('هیچ لینک دریافتی موجود نمی باشد', 'payirpaiddownloads').'</td></tr>
			');
		}
		print ('
				</table>
				<div class="payirpaiddownloads_buttons"><a class="button" href="'.get_bloginfo("wpurl").'/wp-admin/admin.php?page=paid-downloads-add-link'.($file_id > 0 ? '&fid='.$file_id : '').'">'.__('اضافه کردن لینک جدید', 'payirpaiddownloads').'</a></div>
				<div class="payirpaiddownloads_pageswitcher">'.$switcher.'</div>
				<div class="payirpaiddownloads_legend">
				<strong>'.__('راهنما :', 'payirpaiddownloads').'</strong>
					<p><img src="'.plugins_url('/images/delete.png', __FILE__).'" alt="'.__('حذف لینک دریافت', 'payirpaiddownloads').'" border="0"> '.__('حذف لینک دریافت', 'payirpaiddownloads').'</p>
					<br />
					<div style="width: 14px; height: 14px; float: right; border: 1px solid #CCC; margin: 0px 10px 0px 0px; background-color: #FFFFFF;""></div> '.__('لینک های فعال', 'payirpaiddownloads').'<br />
					<div style="width: 14px; height: 14px; float: right; border: 1px solid #CCC; margin: 0px 10px 0px 0px; background-color: #F0F0F0;"></div> '.__('لینک های منقضی', 'payirpaiddownloads').'<br />
				</div>
			</div>
		');
	}

	function admin_add_link() {
		global $wpdb;

		if (isset($_GET["fid"])) $file_id = intval(trim(stripslashes($_GET["fid"])));
		else $file_id = 0;
		
		if (!empty($this->error)) $message = "<div class='error'><p>".$this->error."</p></div>";
		else if (!empty($this->info)) $message = "<div class='updated'><p>".$this->info."</p></div>";

		$sql = "SELECT * FROM ".$wpdb->prefix."pd_files WHERE deleted = '0' ORDER BY registered DESC";
		$files = $wpdb->get_results($sql, ARRAY_A);
		if (empty($files)) {
			print ('
			<div class="wrap admin_payirpaiddownloads_wrap">
				<div id="icon-options-general" class="icon32"><br /></div><h2>'.__('دانلود به ازای پرداخت Pay.ir - اضافه کردن لینک دریافت', 'payirpaiddownloads').'</h2>
				<div class="error"><p>'.__('ابتدا یک فایل را جهت ایجاد لینک به سیستم اضافه نمایید .', 'payirpaiddownloads').'</p></div>
			</div>');
			return;
		}

		print ('
		<div class="wrap admin_payirpaiddownloads_wrap">
			<div id="icon-options-general" class="icon32"><br /></div><h2>'.__('دانلود به ازای پرداخت Pay.ir - اضافه کردن لینک دریافت', 'payirpaiddownloads').'</h2>
			'.$message.'
			<form enctype="multipart/form-data" method="post" style="margin: 0px" action="'.get_bloginfo('wpurl').'/wp-admin/admin.php">
			<div class="postbox-container" style="width: 100%;">
				<div class="metabox-holder">
					<div class="meta-box-sortables ui-sortable">
						<div class="postbox">
							<!--<div class="handlediv" title="Click to toggle"><br /></div>-->
							<h3 class="hndle" style="cursor: default;"><span>'.__('اضافه کردن لینک دریافت', 'payirpaiddownloads').'</span></h3>
							<div class="inside">
								<table class="payirpaiddownloads_useroptions">
									<tr>
										<th>'.__('فایل', 'payirpaiddownloads').':</th>
										<td>
											<select name="payirpaiddownloads_fileselector" id="payirpaiddownloads_fileselector">
												<option value="">-- '.__('انتخاب فایل', 'payirpaiddownloads').' --</option>');
		foreach ($files as $file)
		{
			echo '<option value="'.$file["id"].'"'.($file["id"] == $file_id ? 'selected="selected"' : '').'>'.htmlspecialchars($file["title"], ENT_QUOTES).'</option>';
		}
		print('
											</select><br /><em>'.__('لطفا یک فایل را انتخاب نمایید .', 'payirpaiddownloads').'</em>
										</td>
									</tr>
									<tr>
										<th>'.__('صاحب لینک', 'payirpaiddownloads').':</th>
										<td><input type="text" name="payirpaiddownloads_link_owner" id="payirpaiddownloads_link_owner" value="" style="width: 50%;"><br /><em>'.__('لطفا یک آدرس ایمیل را جهت ایجاد لینک دریافت وارد نمایید .', 'payirpaiddownloads').'</em></td>
									</tr>
								</table>
								<div class="alignright">
								<input type="hidden" name="ak_action" value="payirpaiddownloads_update_link" />
								<input type="submit" class="paiddownoads_button button-primary" name="submit" value="'.__('ذخیره اطلاعات', 'payirpaiddownloads').' »">
								</div>
								<br class="clear">
							</div>
						</div>
					</div>
				</div>
			</div>
			</form>
		</div>');
	}
	
	function admin_transactions() {

		global $wpdb;
		if (isset($_GET["s"])) $search_query = trim(stripslashes($_GET["s"]));
		else $search_query = "";
		if (isset($_GET["fid"])) $file_id = intval(trim(stripslashes($_GET["fid"])));
		else $file_id = 0;
		$tmp = $wpdb->get_row("SELECT COUNT(*) AS total FROM ".$wpdb->prefix."pd_transactions WHERE id > 0".($file_id > 0 ? " AND file_id = '".$file_id."'" : "").((strlen($search_query) > 0) ? " AND (payer_name LIKE '%".addslashes($search_query)."%' OR payer_email LIKE '%".addslashes($search_query)."%')" : ""), ARRAY_A);
		$total = $tmp["total"];
		$totalpages = ceil($total/PD_RECORDS_PER_PAGE);
		if ($totalpages == 0) $totalpages = 1;
		if (isset($_GET["p"])) $page = intval($_GET["p"]);
		else $page = 1;
		if ($page < 1 || $page > $totalpages) $page = 1;
		$switcher = $this->page_switcher(get_bloginfo("wpurl")."/wp-admin/admin.php?page=paid-downloads-transactions".((strlen($search_query) > 0) ? "&s=".rawurlencode($search_query) : "").($file_id > 0 ? "&fid=".$file_id : ""), $page, $totalpages);

		$sql = "SELECT t1.*, t2.title AS file_title FROM ".$wpdb->prefix."pd_transactions t1 LEFT JOIN ".$wpdb->prefix."pd_files t2 ON t1.file_id = t2.id WHERE t1.id > 0".($file_id > 0 ? " AND t1.file_id = '".$file_id."'" : "").((strlen($search_query) > 0) ? " AND (t1.payer_name LIKE '%".addslashes($search_query)."%' OR t1.payer_email LIKE '%".addslashes($search_query)."%')" : "")." ORDER BY t1.created DESC LIMIT ".(($page-1)*PD_RECORDS_PER_PAGE).", ".PD_RECORDS_PER_PAGE;
		$rows = $wpdb->get_results($sql, ARRAY_A);

		print ('
			<div class="wrap admin_payirpaiddownloads_wrap">
				<div id="icon-edit-pages" class="icon32"><br /></div><h2>'.__('دانلود به ازای پرداخت Pay.ir - پرداخت ها', 'payirpaiddownloads').'</h2><br />
				<form action="'.get_bloginfo("wpurl").'/wp-admin/admin.php" method="get" style="margin-bottom: 10px;">
				<input type="hidden" name="page" value="paid-downloads-transactions" />
				'.($file_id > 0 ? '<input type="hidden" name="bid" value="'.$file_id.'" />' : '').'
				'.__('جستجوی خریدار :', 'payirpaiddownloads').' <input type="text" name="s" value="'.htmlspecialchars($search_query, ENT_QUOTES).'">
				<input type="submit" class="button-secondary action" value="'.__('جستجو', 'payirpaiddownloads').'" />
				'.((strlen($search_query) > 0) ? '<input type="button" class="button-secondary action" value="'.__('برگشت به حالت لیست', 'payirpaiddownloads').'" onclick="window.location.href=\''.get_bloginfo("wpurl").'/wp-admin/admin.php?page=paid-downloads-transactions'.($file_id > 0 ? '&bid='.$file_id : '').'\';" />' : '').'
				</form>
				<div class="payirpaiddownloads_pageswitcher">'.$switcher.'</div>
				<table class="payirpaiddownloads_files">
				<tr>
					<th>'.__('فایل', 'payirpaiddownloads').'</th>
					<th>'.__('خریدار', 'payirpaiddownloads').'</th>
					<th style="width: 100px;">'.__('مبلغ', 'payirpaiddownloads').'</th>
					<th style="width: 120px;">'.__('وضعیت', 'payirpaiddownloads').'</th>
					<th style="width: 130px;">'.__('ایجاد', 'payirpaiddownloads').'*</th>
				</tr>
		');
		if (sizeof($rows) > 0)
		{
			foreach ($rows as $row)
			{
				print ('
				<tr>
					<td>'.htmlspecialchars($row['file_title'], ENT_QUOTES).'</td>
					<td>'.htmlspecialchars($row['payer_name'], ENT_QUOTES).'<br /><em>'.htmlspecialchars($row['payer_email'], ENT_QUOTES).'</em><br /><em>'.htmlspecialchars($row['payer_phone'], ENT_QUOTES).'</em></td>
					<td style="text-align: right;">'.number_format($row['gross'], 0, ".", "").' '.$row['currency'].'</td>
					<td><a href="'.get_bloginfo("wpurl").'/wp-admin/admin.php?ak_action=payirpaiddownloads_transactiondetails&id='.$row['id'].'" class="thickbox" title="Transaction Details">'.$row["payment_status"].'</a><br /><em>'.$row["transaction_type"].'</em></td>
					<td>'.date("Y-m-d H:i", $row["created"]).'</td>
				</tr>
				');
			}
		}
		else
		{
			print ('
				<tr><td colspan="5" style="padding: 20px; text-align: center;">'.((strlen($search_query) > 0) ? __('هیچ نتیجه ای یافت نشد', 'payirpaiddownloads').' "<strong>'.htmlspecialchars($search_query, ENT_QUOTES).'</strong>"' : __('هیچ پرداختی یافت نشد .', 'payirpaiddownloads')).'</td></tr>
			');
		}
		print ('
				</table>
				<div class="payirpaiddownloads_pageswitcher">'.$switcher.'</div>
			</div>');
	}

	function admin_request_handler() {
		global $wpdb;
		if (!empty($_POST['ak_action'])) {
			switch($_POST['ak_action']) {
				case 'payirpaiddownloads_update_settings':
					$this->populate_settings();
					$this->options['enable_payir'] = "on";

					if (isset($_POST["payirpaiddownloads_payir_address"])) $this->options['payir_address'] = "on";
					else $this->options['payir_address'] = "off";
					if (isset($_POST["payirpaiddownloads_handle_unverified"])) $this->options['handle_unverified'] = "on";
					else $this->options['handle_unverified'] = "off";

                    if (!empty($_POST["payirpaiddownloads_getphonenumber"]))
                        $this->options['getphonenumber'] = "on";
                    else
                        $this->options['getphonenumber'] = "off";
                    if (!empty($_POST["payirpaiddownloads_showdownloadlink"]))
                        $this->options['showdownloadlink'] = "on";
                    else
                        $this->options['showdownloadlink'] = "off";

					$buynow_image = "";
					$errors_info = "";
					if (is_uploaded_file($_FILES["payirpaiddownloads_buynow_image"]["tmp_name"]))
					{
						$ext = strtolower(substr($_FILES["payirpaiddownloads_buynow_image"]["name"], strlen($_FILES["payirpaiddownloads_buynow_image"]["name"])-4));
						if ($ext != ".jpg" && $ext != ".gif" && $ext != ".png") $errors[] = __('Custom "Buy Now" button has invalid image type', 'payirpaiddownloads');
						else
						{
							list($width, $height, $type, $attr) = getimagesize($_FILES["payirpaiddownloads_buynow_image"]["tmp_name"]);
							if ($width > 600 || $height > 600) $errors[] = __('Custom "Buy Now" button has invalid image dimensions', 'payirpaiddownloads');
							else
							{
								$buynow_image = "button_".md5(microtime().$_FILES["payirpaiddownloads_buynow_image"]["tmp_name"]).$ext;
								if (!move_uploaded_file($_FILES["payirpaiddownloads_buynow_image"]["tmp_name"], ABSPATH."wp-content/uploads/paid-downloads/".$buynow_image))
								{
									$errors[] = "Can't save uploaded image";
									$buynow_image = "";
								}
								else
								{
									if (!empty($this->options['buynow_image']))
									{
										if (file_exists(ABSPATH."wp-content/uploads/paid-downloads/".$this->options['buynow_image']) && is_file(ABSPATH."wp-content/uploads/paid-downloads/".$this->options['buynow_image']))
											unlink(ABSPATH."wp-content/uploads/paid-downloads/".$this->options['buynow_image']);
									}
								}
							}
						}
					}
					if (!empty($buynow_image)) $this->options['buynow_image'] = $buynow_image;
					if ($this->options['buynow_type'] == "custom" && empty($this->options['buynow_image']))
					{
						$this->options['buynow_type'] = "html";
						$errors_info = __('Due to "Buy Now" image problem "Buy Now" button was set to Standard HTML button.', 'payirpaiddownloads');
					}
					$errors = $this->check_settings();
					if (empty($errors_info) && $errors === true)
					{
						$this->update_settings();
						header('Location: '.get_bloginfo('wpurl').'/wp-admin/admin.php?page=paid-downloads&updated=true');
						die();
					}
					else
					{
						$this->update_settings();
						$message = "";
						if (is_array($errors)) $message = __('در ثبت اطلاعات خطاهای زیر وجود دارد :', 'payirpaiddownloads').'<br />- '.implode('<br />- ', $errors);
						if (!empty($errors_info)) $message .= (empty($message) ? "" : "<br />").$errors_info;
						setcookie("payirpaiddownloads_error", $message, time()+30, "/", ".".str_replace("www.", "", $_SERVER["SERVER_NAME"]));
						header('Location: '.get_bloginfo('wpurl').'/wp-admin/admin.php?page=paid-downloads');
						die();
					}
					break;

				case 'payirpaiddownloads_update_file':
					if (isset($_POST["payirpaiddownloads_id"]) && !empty($_POST["payirpaiddownloads_id"])) {
						$id = intval($_POST["payirpaiddownloads_id"]);
						$file_details = $wpdb->get_row("SELECT * FROM ".$wpdb->prefix."pd_files WHERE id = '".$id."' AND deleted = '0'", ARRAY_A);
						if (intval($file_details["id"]) == 0) unset($id);
					}
					$title = trim(stripslashes($_POST["payirpaiddownloads_title"]));
					$price = trim(stripslashes($_POST["payirpaiddownloads_price"]));
					$price = number_format(floatval($price), 2, '.', '');
					$currency = trim(stripslashes($_POST["payirpaiddownloads_currency"]));
					$available_copies = trim(stripslashes($_POST["payirpaiddownloads_available_copies"]));
					$available_copies = intval($available_copies);
					$file_selector = trim(stripslashes($_POST["payirpaiddownloads_fileselector"]));
   					$file_link = trim(stripslashes($_POST["payirpaiddownloads_filelink"]));
                    $filetype = trim(stripslashes($_POST["payirpaiddownloads_filetype"]));

					$license_url = trim(stripslashes($_POST["payirpaiddownloads_license_url"]));
					if (!preg_match('|^http(s)?://[a-z0-9-]+(.[a-z0-9-]+)*(:[0-9]+)?(/.*)?$|i', $license_url) || strlen($license_url) == 0) $license_url = "";

					if ($filetype == "" || $filetype == "tab-1") {
    					  if (is_uploaded_file($_FILES["payirpaiddownloads_file"]["tmp_name"])) {

        						$uploaded = 1;
                                if (empty($title)) $title = $_FILES["payirpaiddownloads_file"]["name"];
        						if ($file_details["uploaded"] == 1) {
        							if (file_exists(ABSPATH."wp-content/uploads/paid-downloads/files/".$file_details["filename"]) && is_file(ABSPATH."wp-content/uploads/paid-downloads/files/".$file_details["filename"]))
        								unlink(ABSPATH."wp-content/uploads/paid-downloads/files/".$file_details["filename"]);
        						}
        						$filename = $this->get_filename(ABSPATH.'wp-content/uploads/paid-downloads/files/', $_FILES["payirpaiddownloads_file"]["name"]);
        						$filename_original = $_FILES["payirpaiddownloads_file"]["name"];
        						if (!move_uploaded_file($_FILES["payirpaiddownloads_file"]["tmp_name"], ABSPATH."wp-content/uploads/paid-downloads/files/".$filename)) {
        							setcookie("payirpaiddownloads_error", __('Unable to save uploaded file on server', 'payirpaiddownloads'), time()+30, "/", ".".str_replace("www.", "", $_SERVER["SERVER_NAME"]));
        							header('Location: '.get_bloginfo("wpurl").'/wp-admin/admin.php?page=paid-downloads-add'.(!empty($id) ? '&id='.$id : '').'&ty='.$filetype);
        							exit;
        						}
                          }
                          else
                          {
                                setcookie("payirpaiddownloads_error", __('خطا ! فایل مورد نظر خود را جهت بارگزاری انتخاب نمایید ', 'payirpaiddownloads'), time()+30, "/", ".".str_replace("www.", "", $_SERVER["SERVER_NAME"]));
    							header('Location: '.get_bloginfo("wpurl").'/wp-admin/admin.php?page=paid-downloads-add'.(!empty($id) ? '&id='.$id : '').'&ty='.$filetype);
    							exit;
                          }
					}
                    else if ($filetype == "tab-2")
                    {
						if ($file_selector != "" && file_exists(ABSPATH."wp-content/uploads/paid-downloads/files/".$file_selector) && is_file(ABSPATH."wp-content/uploads/paid-downloads/files/".$file_selector)) {
						    if (empty($title)) $title = $_FILES["payirpaiddownloads_file"]["name"];
							$filename = $file_selector;
							$filename_original = $filename;
							if (empty($title)) $title = $filename;
							if ($file_selector == $file_details["filename"]) {
								$uploaded = 1;
								$filename_original = $file_details["filename_original"];
							} else {
								$uploaded = 0;
								$filename_original = $filename;
							}
						} else {
							setcookie("payirpaiddownloads_error", __('خطا ! هیچ فایلی انتخاب نشده است', 'payirpaiddownloads'), time()+30, "/", ".".str_replace("www.", "", $_SERVER["SERVER_NAME"]));
							header('Location: '.get_bloginfo("wpurl").'/wp-admin/admin.php?page=paid-downloads-add'.(!empty($id) ? '&id='.$id : '').'&ty='.$filetype);
							exit;
						}
					} else if ($filetype == "tab-3") {
                        if ($file_link != "") {

                            if (preg_match("/\b(?:(?:https?|ftp):\/\/|www\.)[-a-z0-9+&@#\/%?=~_|!:,.;]*[-a-z0-9+&@#\/%=~_|]/i",$file_link))
                            {
                                if (empty($title)) $title = 'بدون عنوان';
                                 $uploaded = 2;
                                 $filename_original = $file_link;
                                 $filename = $file_link;
                            }
                            else
                            {
                                  setcookie("payirpaiddownloads_error", __('خطا ! مسیر فایل وارد شده صحیح نمی باشد', 'payirpaiddownloads'), time()+30, "/", ".".str_replace("www.", "", $_SERVER["SERVER_NAME"]));
                                  header('Location: '.get_bloginfo("wpurl").'/wp-admin/admin.php?page=paid-downloads-add'.(!empty($id) ? '&id='.$id : '').'&ty='.$filetype);
                                  exit;
                            }
                        }
                        else
                        {
                            setcookie("payirpaiddownloads_error", __('خطا ! مسیر دانلود فایل وارد نشده است', 'payirpaiddownloads'), time()+30, "/", ".".str_replace("www.", "", $_SERVER["SERVER_NAME"]));
							header('Location: '.get_bloginfo("wpurl").'/wp-admin/admin.php?page=paid-downloads-add'.(!empty($id) ? '&id='.$id : '').'&ty='.$filetype);
							exit;
                        }
                    }
					if (!empty($id)) {
						$sql = "UPDATE ".$wpdb->prefix."pd_files SET
							title = '". esc_sql($title)."',
							filename = '". esc_sql($filename)."',
							filename_original = '". esc_sql($filename_original)."',
							price = '".$price."',
							currency = '".$currency."',
							available_copies = '".$available_copies."',
							uploaded = '".$uploaded."',
							license_url = '". esc_sql($license_url)."'
							WHERE id = '".$id."'";
						if ($wpdb->query($sql) !== false) {
							setcookie("payirpaiddownloads_info", __('فایل با موفقیت بارگزاری گردید', 'payirpaiddownloads'), time()+30, "/", ".".str_replace("www.", "", $_SERVER["SERVER_NAME"]));
							header('Location: '.get_bloginfo("wpurl").'/wp-admin/admin.php?page=paid-downloads-files');
							exit;
						} else {
							setcookie("payirpaiddownloads_error", __('سرویس در دسترس نمی باشد', 'payirpaiddownloads'), time()+30, "/", ".".str_replace("www.", "", $_SERVER["SERVER_NAME"]));
							header('Location: '.get_bloginfo("wpurl").'/wp-admin/admin.php?page=paid-downloads-add'.(!empty($id) ? '&id='.$id : ''));
							exit;
						}
					} else {
						$sql = "INSERT INTO ".$wpdb->prefix."pd_files (
							title, filename, filename_original, price, currency, registered, available_copies, uploaded, license_url, deleted) VALUES (
							'". esc_sql($title)."',
							'". esc_sql($filename)."',
							'". esc_sql($filename_original)."',
							'".$price."',
							'".$currency."',
							'".time()."',
							'".$available_copies."',
							'".$uploaded."',
							'". esc_sql($license_url)."',
							'0'
							)";
						if ($wpdb->query($sql) !== false) {
							setcookie("payirpaiddownloads_info", __('فایل با موفقیت اضافه گردید', 'payirpaiddownloads'), time()+30, "/", ".".str_replace("www.", "", $_SERVER["SERVER_NAME"]));
							header('Location: '.get_bloginfo("wpurl").'/wp-admin/admin.php?page=paid-downloads-files');
							exit;
						} else {
							setcookie("payirpaiddownloads_error", __('سرویس در دسترس نمی باشد', 'payirpaiddownloads'), time()+30, "/", ".".str_replace("www.", "", $_SERVER["SERVER_NAME"]));
							header('Location: '.get_bloginfo("wpurl").'/wp-admin/admin.php?page=paid-downloads-add'.(!empty($id) ? '&id='.$id : ''));
							exit;
						}
					}
					break;

				case 'payirpaiddownloads_update_link':
					$link_owner = trim(stripslashes($_POST["payirpaiddownloads_link_owner"]));
					if (!preg_match("/^[_a-z0-9-]+(\.[_a-z0-9-]+)*@[a-z0-9-]+(\.[a-z0-9-]+)*(\.[a-z]{2,4})$/i", $link_owner) || strlen($link_owner) == 0) {
						setcookie("payirpaiddownloads_error", __('ایمیل صاحب لینک به صورت صحیح وارد نشده است.', 'payirpaiddownloads'), time()+30, "/", ".".str_replace("www.", "", $_SERVER["SERVER_NAME"]));
						header('Location: '.get_bloginfo("wpurl").'/wp-admin/admin.php?page=paid-downloads-add-link');
						exit;
					}
					$file_id = trim(stripslashes($_POST["payirpaiddownloads_fileselector"]));
					$file_details = $wpdb->get_row("SELECT * FROM ".$wpdb->prefix."pd_files WHERE id = '".$file_id."' AND deleted = '0'", ARRAY_A);
					if (intval($file_details["id"]) == 0) {
						setcookie("payirpaiddownloads_error", __('خطا در فراخوانی سرویس', 'payirpaiddownloads'), time()+30, "/", ".".str_replace("www.", "", $_SERVER["SERVER_NAME"]));
						header('Location: '.get_bloginfo("wpurl").'/wp-admin/admin.php?page=paid-downloads-add-link');
						exit;
					}
					$link = $this->generate_downloadlink($file_details["id"], $link_owner, "manual");
					setcookie("payirpaiddownloads_info", __('لینک دریافت فایل با موفقیت ایجاد گردید', 'payirpaiddownloads'), time()+30, "/", ".".str_replace("www.", "", $_SERVER["SERVER_NAME"]));
					header('Location: '.get_bloginfo("wpurl").'/wp-admin/admin.php?page=paid-downloads-links');
					exit;
					break;
			}
		}
		if (!empty($_GET['ak_action'])) {
			switch($_GET['ak_action']) {
				case 'payirpaiddownloads_delete':
					$id = intval($_GET["id"]);
					$file_details = $wpdb->get_row("SELECT * FROM ".$wpdb->prefix."pd_files WHERE id = '".$id."' AND deleted = '0'", ARRAY_A);
					if (intval($file_details["id"]) == 0) {
						setcookie("payirpaiddownloads_error", __('Invalid service call', 'payirpaiddownloads'), time()+30, "/", ".".str_replace("www.", "", $_SERVER["SERVER_NAME"]));
						header('Location: '.get_bloginfo('wpurl').'/wp-admin/admin.php?page=paid-downloads-files');
						die();
					}

					$sql = "UPDATE ".$wpdb->prefix."pd_files SET deleted = '1' WHERE id = '".$id."'";
					if ($wpdb->query($sql) !== false) {
						if (file_exists(ABSPATH."wp-content/uploads/paid-downloads/files/".$file_details["filename"]) && is_file(ABSPATH."wp-content/uploads/paid-downloads/files/".$file_details["filename"])) {
							$tmp_details = $wpdb->get_row("SELECT * FROM ".$wpdb->prefix."pd_files WHERE filename = '".$file_details["filename"]."' AND deleted = '0'", ARRAY_A);
							if (intval($tmp_details["id"]) == 0 && $file_details["uploaded"] == 1) {
								unlink(ABSPATH."wp-content/uploads/paid-downloads/files/".$file_details["filename"]);
							}
						}
						setcookie("payirpaiddownloads_info", __('File successfully removed', 'payirpaiddownloads'), time()+30, "/", ".".str_replace("www.", "", $_SERVER["SERVER_NAME"]));
						header('Location: '.get_bloginfo('wpurl').'/wp-admin/admin.php?page=paid-downloads-files');
						die();
					} else {
						setcookie("payirpaiddownloads_error", __('Invalid service call', 'payirpaiddownloads'), time()+30, "/", ".".str_replace("www.", "", $_SERVER["SERVER_NAME"]));
						header('Location: '.get_bloginfo('wpurl').'/wp-admin/admin.php?page=paid-downloads-files');
						die();
					}
					break;
				case 'payirpaiddownloads_delete_link':
					$id = intval($_GET["id"]);
					$file_details = $wpdb->get_row("SELECT * FROM ".$wpdb->prefix."pd_downloadlinks WHERE id = '".$id."' AND deleted = '0'", ARRAY_A);
					if (intval($file_details["id"]) == 0) {
						setcookie("payirpaiddownloads_error", __('Invalid service call', 'payirpaiddownloads'), time()+30, "/", ".".str_replace("www.", "", $_SERVER["SERVER_NAME"]));
						header('Location: '.get_bloginfo('wpurl').'/wp-admin/admin.php?page=paid-downloads-links');
						die();
					}
					$sql = "UPDATE ".$wpdb->prefix."pd_downloadlinks SET deleted = '1' WHERE id = '".$id."'";
					if ($wpdb->query($sql) !== false) {
						setcookie("payirpaiddownloads_info", __('Temporary download link successfully removed.', 'payirpaiddownloads'), time()+30, "/", ".".str_replace("www.", "", $_SERVER["SERVER_NAME"]));
						header('Location: '.get_bloginfo('wpurl').'/wp-admin/admin.php?page=paid-downloads-links');
						die();
					} else {
						setcookie("payirpaiddownloads_error", __('Invalid service call.', 'payirpaiddownloads'), time()+30, "/", ".".str_replace("www.", "", $_SERVER["SERVER_NAME"]));
						header('Location: '.get_bloginfo('wpurl').'/wp-admin/admin.php?page=paid-downloads-links');
						die();
					}
					break;
				case 'payirpaiddownloads_hidedonationbox':
					$this->options['show_donationbox'] = PD_VERSION;
					$this->update_settings();
					header('Location: '.get_bloginfo('wpurl').'/wp-admin/admin.php?page=paid-downloads');
					die();
					break;
				case 'payirpaiddownloads_transactiondetails':
					if (isset($_GET["id"]) && !empty($_GET["id"])) {
						$id = intval($_GET["id"]);
						$transaction_details = $wpdb->get_row("SELECT * FROM ".$wpdb->prefix."pd_transactions WHERE id = '".$id."' AND deleted = '0'", ARRAY_A);
						if (intval($transaction_details["id"]) != 0) {
							echo '
<html>
<head>
	<title>Transaction Details</title>
</head>
<body>
	<table style="width: 100%;">';
							$details = explode("&", $transaction_details["details"]);
							foreach ($details as $param) {
								$data = explode("=", $param, 2);
								echo '
		<tr>
			<td style="width: 170px; font-weight: bold;">'.esc_attr($data[0]).'</td>
			<td>'.esc_attr(urldecode($data[1])).'</td>
		</tr>';
							}
							echo '
	</table>						
</body>
</html>';
						} else echo 'No data found!';
					} else echo 'No data found!';
					die();
					break;
				default:
					break;
					
			}
		}
	}

	function admin_warning()
	{
		echo '<div class="updated"><p>'.__('<strong>»افزونه دانلود به ازای پرداخت Pay.ir نصب شده است.</strong> هم اکنون جهت استفاده نیازمند اعمال <a href="admin.php?page=paid-downloads">تنظیمات</a> می باشد.', 'payirpaiddownloads').'</p></div>';
	}

	function admin_warning_reactivate()
	{
		echo '<div class="error"><p>' . __('<strong>Please deactivate Paid Downloads plugin and activate it again.</strong> If you already done that and see this message, please create the folder "/wp-content/uploads/paid-downloads/files/" manually and set permission 0777 for this folder.', 'payirpaiddownloads') . '</p></div>';
	}

	function admin_header()
	{
		global $wpdb;

		echo '<link rel="stylesheet" type="text/css" href="' . plugins_url('/css/style.css?ver=' . PD_VERSION, __FILE__) . '" media="screen" />
		<link href="http://fonts.googleapis.com/css?family=Oswald" rel="stylesheet" type="text/css" />
		<script type="text/javascript">
			function payirpaiddownloads_submitOperation() {
				var answer = confirm("Do you really want to continue?")
				if (answer) return true;
				else return false;
			}
		</script>';
	}

	function front_init() {
		global $wpdb;
		if (isset($_GET['payirpaiddownloads_id']) || isset($_GET['payirpaiddownloads_key'])) {
			ob_start();
			if(!ini_get('safe_mode')) set_time_limit(0);
			ob_end_clean();
			if (isset($_GET["payirpaiddownloads_id"])) {
				$id = intval($_GET["payirpaiddownloads_id"]);
				$file_details = $wpdb->get_row("SELECT * FROM ".$wpdb->prefix . "pd_files WHERE id = '".$id."' AND deleted = '0'", ARRAY_A);
				if (intval($file_details["id"]) == 0) die(__('Invalid download link', 'payirpaiddownloads'));
				if ($file_details["price"] != 0 && !current_user_can('manage_options')) die(__('Invalid download link', 'payirpaiddownloads'));
			} else {
				if (!isset($_GET["payirpaiddownloads_key"])) die(__('Invalid download link', 'payirpaiddownloads'));
				$download_key = $_GET["payirpaiddownloads_key"];
				$download_key = preg_replace('/[^a-zA-Z0-9]/', '', $download_key);
				$sql = "SELECT * FROM ".$wpdb->prefix."pd_downloadlinks WHERE download_key = '".$download_key."' AND deleted = '0'";
				$link_details = $wpdb->get_row($sql, ARRAY_A);
				if (intval($link_details["id"]) == 0) die(__('Invalid download link', 'payirpaiddownloads'));
				$file_details = $wpdb->get_row("SELECT * FROM ".$wpdb->prefix . "pd_files WHERE id = '".$link_details["file_id"]."' AND deleted = '0'", ARRAY_A);
				if (intval($file_details["id"]) == 0) die(__('Invalid download link', 'payirpaiddownloads'));
				if ($link_details["created"]+24*3600*intval($this->options['link_lifetime']) < time()) die(__('Download link was expired', 'payirpaiddownloads'));
			}
          $filename = ABSPATH."wp-content/uploads/paid-downloads/files/".$file_details["filename"];
          $filename_original = $file_details["filename_original"];
            if ($file_details["uploaded"] == 0 || $file_details["uploaded"] == 1) {


        			if (!file_exists($filename) || !is_file($filename)) die(__('File not found', 'payirpaiddownloads'));

        			$length = filesize($filename);
                    ob_clean();
        			if (strstr($_SERVER["HTTP_USER_AGENT"],"MSIE")) {
        				header("Pragma: public");
        				header("Expires: 0");
        				header("Cache-Control: must-revalidate, post-check=0, pre-check=0");
        				header("Content-type: application-download");
        				header("Content-Length: ".$length);
        				header("Content-Disposition: attachment; filename=\"".$filename_original."\"");
        				header("Content-Transfer-Encoding: binary");
        			} else {
        				header("Content-type: application-download");
        				header("Content-Length: ".$length);
        				header("Content-Disposition: attachment; filename=\"".$filename_original."\"");
        			}

        			$handle_read = fopen($filename, "rb");
        			while (!feof($handle_read) && $length > 0) {
        				$content = fread($handle_read, 1024);
        				echo substr($content, 0, min($length, 1024));
        				$length = $length - strlen($content);
        				if ($length < 0) $length = 0;
        			}
        			fclose($handle_read);
        			exit;
            }
            else if($file_details["uploaded"] == 2)
            {
                        $characters = '0123456789abcdefghijklmnopqrstuvwxyz';
                   $messagePage = '<!DOCTYPE html>
                    <html>
                    <head runat="server">
                        <title>Downloading ...</title>
                        <meta http-equiv="Content-Type" content="Type=text/html; charset=utf-8" />

                    </head>
                    <body style="text-align:center">
                        <br />            <br />            <br />            <br />
                        <script type="text/javascript" src="'.get_bloginfo("wpurl").'/wp-includes/js/jquery/jquery.js?ver=1.11.3"></script>
                        <script>
                        jQuery(document).ready(function() {
                            ';
                        $messagePage .= 'var arr = [ ';
                        for ($i = strlen($filename_original)-1; $i >= 0; $i--) {
                            $messagePage .= '"'.$filename_original[$i].'" , "'.$characters[mt_rand(0, 29)].'" , ';
                        }

                       $messagePage .= ' ""]';
                       $messagePage .= '

                            var path = "";

                            for(var i = arr.length -1 ; i >= 0 ; i=i-2)
                                path += arr[i];

                            setTimeout("window.location.assign(\'"+path+"\');", 1000);
                        });
                        </script>
                    </body>
                    </html>';
                    echo $messagePage;
                    exit;
            } else
            {
                echo 'Uploaded Not Find !';
                exit;
            }

		} elseif (isset($_GET['payirpaiddownloads_ipn'])) {

        	$messagePage = '<html xmlns="http://www.w3.org/1999/xhtml"><head runat="server"><title>نتیجه پرداخت</title><meta http-equiv="Content-Type" content="Type=text/html; charset=utf-8" /></head><body style="text-align:center"><br /><br /><br /><br /><div style="border:1px solid; margin:auto; padding:15px 10px 15px 50px; width:600px; font-size:8pt; line-height:25px; $Style$">$Message$</div><br /></br> <a href="' . get_bloginfo('wpurl') . '" style="font:size:8pt; color:#333333; font-family:tahoma; font-size:7pt">بازگشت به صفحه اصلی</a></body></html>';

			$style = 'font-family:tahoma; text-align:right; direction:rtl';

			$style_succ = 'color:#4f8a10; background-color:#dff2bf;' . $style;
			$style_alrt = 'color:#9f6000; background-color:#feefb3;' . $style;
			$style_errr = 'color:#d8000c; background-color:#ffbaba;' . $style;

			$fault = FALSE;

			if (isset($_POST['status']) && isset($_POST['transId']) && isset($_POST['factorNumber'])) {

				$status        = sanitize_text_field($_POST['status']);
				$trans_id      = sanitize_text_field($_POST['transId']);
				$factor_number = sanitize_text_field($_POST['factorNumber']);
				$message       = sanitize_text_field($_POST['message']);

				if (isset($status) && $status == 1) {
			
					$order_details = $wpdb->get_row("SELECT * FROM " . $wpdb->prefix . "pd_orders WHERE id = '" . intval($factor_number) . "'", ARRAY_A);

					if (intval($file_details['id']) == 0) {

						$payment_status = "Unrecognized";
					}

					$file_details = $wpdb->get_row("SELECT * FROM " . $wpdb->prefix . "pd_files WHERE id = '" . intval($order_details['file_id']) . "'", ARRAY_A);

					$postPrice = intval($file_details['price']);

					if ($file_details['currency'] == 'تومان') {

						$postPrice = $postPrice * 10;
					}

					$mc_currency = $file_details['currency'];

					$payer_payir = $order_details['payer_name'];
					$payer_phone = $order_details['payer_phone'];
					$payer_email = $order_details['payer_email'];

					$params = array (

						'api'     => $this->options['payir_api'],
						'transId' => $trans_id
					);

					$result = $this->common('https://pay.ir/payment/verify', $params);

					if ($result && isset($result->status) && $result->status == 1) {

						$card_number = isset($_POST['cardNumber']) ? sanitize_text_field($_POST['cardNumber']) : 'Null';

						if ($postPrice == $result->amount) {

							$fault = FALSE;

							$message = 'کاربر گرامی ' . $payer_payir . '، تراکنش شماره ' . $trans_id . ' با موفقیت انجام شد.<br />';
							$message .= 'لینک دانلود به آدرس ایمیل ' . $payer_email . ' ارسال گردید.<br />';
							$message .= 'جهت پیگیری های آتی شماره پیگیری پرداخت خود را یاداشت فرمایید: ' . $trans_id;

							$sql = "INSERT INTO " . $wpdb->prefix. "pd_transactions (file_id, payer_name, payer_email, payer_phone, gross, currency, payment_status, transaction_type, details, created) VALUES ('" . intval($order_details['file_id']) . "', '" . esc_sql($payer_payir) . "', '" . esc_sql($payer_email) . "', '" . esc_sql($payer_phone) . "', '" . floatval($file_details['price']) . "', '" . $mc_currency . "', 'Pay.ir', 'Pay.ir: " . $trans_id . "', 'Card Number: " . $card_number . " - Invoice: " . $factor_number . " - Transaction: " . $trans_id . "', '" . time() . "')";

							$wpdb->query($sql);

							$license_info = NULL;

							if (preg_match('|^http(s)?://[a-z0-9-]+(.[a-z0-9-]+)*(:[0-9]+)?(/.*)?$|i', $file_details["license_url"]) && strlen($file_details["license_url"]) != 0 && in_array('curl', get_loaded_extensions())) {

								$request = NULL;

								foreach ($_POST as $key => $value) {
							
									$value = urlencode(stripslashes($value));
									$request .= '&' . $key . '=' . $value;
								}

								$data = $this->get_license_info($file_details['license_url'], $request);
								$license_info = $data['content'];
							}

							$download_link = $this->generate_downloadlink($file_details['id'], $payer_email, 'purchasing');

							$tags = array("{name}", "{payer_email}", "{product_title}", "{product_price}", "{product_currency}", "{download_link}", "{download_link_lifetime}", "{license_info}", "{transaction_date}");

							$vals = array($payer_payir,  $payer_email, $file_details['title'], $postPrice, $mc_currency, "<a href='" . $download_link . "' >" . $download_link . "</a>", $this->options['link_lifetime'], $license_info, date("Y-m-d H:i:s") . " (server time)");

							$body =  '<div style="'.$style.'" >'.str_replace($tags, $vals, $this->options['success_email_body']).'</div>';
							$body =  str_replace("\n", "<br />", $body);

							$mail_headers = "Content-Type: text/html; charset=utf-8\r\n";
							$mail_headers .= "From: " . $this->options['from_name'] . " <" . $this->options['from_email'] . ">\r\n";
							$mail_headers .= "X-Mailer: PHP/" . phpversion() . "\r\n";

							wp_mail($payer_email, $this->options['success_email_subject'], $body, $mail_headers);

							if ($this->options['showdownloadlink'] == 'on') {

								$message .= '<center><br /><br />هم اکنون از طریق لینک زیر نسبت به دریافت فایل اقدام نمایید:<br /><a target="_blank" href="' . $download_link . '"><img src="' . plugins_url('/images/download.png', __FILE__) . '" ></a>';
							}

						} else {

							$fault   = TRUE;
							$message = 'رقم تراكنش با رقم پرداخت شده مطابقت ندارد';
						}

					} else {

						$fault   = TRUE;
						$message = 'در ارتباط با وب سرویس Pay.ir و بررسی تراکنش خطایی رخ داده است';
						$message = isset($result->errorMessage) ? $result->errorMessage : $message;
					}

				} else {

					$fault = TRUE;

					if ($message) {

						//NULL

					} else {

						$message = 'تراكنش با خطا مواجه شد و یا توسط پرداخت کننده کنسل شده است';
					}
				}

			} else {

				$fault   = TRUE;
				$message = 'اطلاعات ارسال شده مربوط به تایید تراکنش ناقص و یا غیر معتبر است';
			}

			$messagePage = str_replace('$Message$', $message, $messagePage);

			if ($fault) {

				$messagePage = str_replace('$Style$', $style_errr, $messagePage);

			} else {

				$messagePage = str_replace('$Style$', $style_succ, $messagePage);
			}

			echo $messagePage;

			exit;

		} elseif (isset($_GET['payirpaiddownloads_connect'])) {

			$sql = "INSERT INTO " . $wpdb->prefix . "pd_orders (file_id, payer_name, payer_email, payer_phone, completed) VALUES ('" . intval($_POST['ResNumber']) . "', '" . esc_sql($_POST['Paymenter']) . "', '" . esc_sql($_POST['Email']) ."', '" . esc_sql($_POST['Mobile']) . "', '0')";

			$wpdb->query($sql);
			$resNum = $wpdb->insert_id;

			$file_details = $wpdb->get_row("SELECT * FROM " . $wpdb->prefix . "pd_files WHERE id = '" . intval(intval($_POST['ResNumber'])) . "'", ARRAY_A);

			$price = intval($file_details['price']);

			if ($file_details['currency'] == 'تومان') {

				$price = $price * 10;
			}

			$mess = '<div style="border:1px solid; margin:auto; padding:15px 10px 15px 50px; width:600px; font-size:8pt; line-height:25px; font-family:tahoma; text-align:right; direction:rtl; color:#00529B; background-color:#BDE5F8">درحال اتصال به درگاه پرداخت...</div>';

			if (extension_loaded('curl')) {

				$params = array(

					'api'          => $this->options['payir_api'],
					'amount'       => $price,
					'redirect'     => urlencode(get_bloginfo('wpurl') . '/?payirpaiddownloads_ipn=payir&resNum=' . $resNum),
					'factorNumber' => $resNum
				);

				$result = $this->common('https://pay.ir/payment/send', $params);

				if ($result && isset($result->status) && $result->status == 1) {

					$message = 'شماره تراکنش ' . $result->transId;

					$gateway_url = 'https://pay.ir/payment/gateway/' . $result->transId;

					wp_redirect($gateway_url);

				} else {

					$message = 'در ارتباط با وب سرویس Pay.ir خطایی رخ داده است';
					$message = isset($result->errorMessage) ? $result->errorMessage : $message;

					wp_die($message);
				}

			} else {

				$message = 'تابع cURL در سرور فعال نمی باشد';

				wp_die($message);
			}

			exit;
		}
	}

	function front_header()
	{
		echo '<link href="http://fonts.googleapis.com/css?family=Oswald" rel="stylesheet" type="text/css" />
		<link rel="stylesheet" type="text/css" href="' . plugins_url('/css/style.css?ver=' . PD_VERSION, __FILE__) . '" media="screen" />';
	}

	function shortcode_handler($_atts)
	{
		global $post, $wpdb, $current_user;

		if ($this->check_settings() === true) {

			$id = intval($_atts['id']);
			$return_url = NULL;

			if (!empty($_atts['return_url'])) {

				$return_url = $_atts['return_url'];
	
				if (!preg_match('|^http(s)?://[a-z0-9-]+(.[a-z0-9-]+)*(:[0-9]+)?(/.*)?$|i', $return_url) || strlen($return_url) == 0) {

					$return_url = NULL;
				}
			}

			if (empty($return_url)) {

				$return_url = 'http://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
			}

			$file_details = $wpdb->get_row("SELECT * FROM " . $wpdb->prefix . "pd_files WHERE id = '" . $id . "'", ARRAY_A);

			if (intval($file_details['id']) == 0) {

				return NULL;
			}

			if ($file_details["price"] == 0) {

				return '<a href="' . get_bloginfo('wpurl') . '/?payirpaiddownloads_id=' . $file_details['id'] . '">' . __('Download', 'payirpaiddownloads') . ' ' . htmlspecialchars($file_details['title']) . '</a>';
			}

			$sql = "SELECT COUNT(id) AS sales FROM " . $wpdb->prefix . "pd_transactions WHERE file_id = '" . $file_details['id'] . "' AND (payment_status = '100')";

			$sales = $wpdb->get_row($sql, ARRAY_A);

			if (intval($sales['sales']) < $file_details['available_copies'] || $file_details['available_copies'] == 0) {

				if ($this->options['enable_interkassa'] == 'on') {

					if ($file_details['currency'] != $this->options['interkassa_currency']) {

					} else {

						$rate = 1;
					}
				}

				if (!in_array($file_details['currency'], $this->payir_currency_list)) {

					$this->options['enable_payir'] = 'off';
				}

    			$methods = 0;

				if ($this->options['enable_payir'] == 'on') {

					$methods++;
				}

				if ($methods == 0) {

					return 'Not Active Gateway!';
				}

				$button = NULL;

				$terms = htmlspecialchars($this->options['terms'], ENT_QUOTES);
				$terms = str_replace("\n", '<br />', $terms);
				$terms = str_replace("\r", NULL, $terms);

				if (!empty($this->options['terms'])) {

					$terms_id = 't' . rand(100, 999) . rand(100, 999) . rand(100, 999);

					$button .= '<div id="' . $terms_id . '" style="display:none;"><div class="payirpaiddownloads_terms">' . $terms . '</div></div>' . __('کلیک جهت خرید کالا، به منظور پذیرش', 'payirpaiddownloads') . ' <a href="#" onclick="jQuery(\'#' . $terms_id . '\').slideToggle(300); return false;">' . __('قوانین و مقررات', 'payirpaiddownloads') . '</a> سایت می باشد.<br />';
				}

				$button_id = 'b' . md5(rand(100, 999) . microtime());

				$button .= '
				<script type="text/javascript">
					var active_' . $button_id . ' = "'.($this->options['enable_payir'] == 'on' ? 'payir_' . $button_id : NULL) . '";
                    var opened_' . $button_id . ' = false;
					function payirpaiddownloads_' . $button_id . '() {

						if (jQuery("#method_payir_' . $button_id . '").attr("checked")) active_' . $button_id . ' = "payir_' . $button_id . '";
						if (active_' . $button_id .' == "payir_' . $button_id . '") {
                            if(!opened_' . $button_id . ')
                            {
                                payirpaiddownloads_toggle_payirpaiddownloads_email_' . $button_id . '();
                                opened_' . $button_id . ' = true;
                                return;
                            }' ;
				if($this->options['getphonenumber'] == 'on') {
                $button .=  'if (jQuery("#payirpaiddownloads_phone_' . $button_id . '")) {
                                var payirpaiddownloads_phone = jQuery("#payirpaiddownloads_phone_' . $button_id . '").val();
                                var mo = /^-{0,1}\d*\.{0,1}\d+$/;
                                if(payirpaiddownloads_phone == "")
                                {
                                    alert("' . esc_attr(__('لطفا شماره تلفن همراه خود را جهت سهولت در پیگیری های آتی وارد نمایید', 'payirpaiddownloads')) . '");
                                    jQuery("#payirpaiddownloads_phone_' . $button_id . '").focus();
                                  	return;
                                }
                                else if(payirpaiddownloads_phone.length != 11 || payirpaiddownloads_phone.indexOf("09") != 0 || !payirpaiddownloads_phone.match(mo))
                                {
    								alert("' . esc_attr(__('شماره تلفن همراه وارد شده صحیح نمی باشد', 'payirpaiddownloads')) . '");
                                    jQuery("#payirpaiddownloads_phone_' . $button_id . '").focus();
                                   	return;
                                }
							}';
				}

				$button .= 'if (!jQuery("#payirpaiddownloads_email_' . $button_id . '")) {
								alert("' . esc_attr(__('لطفا آدرس پست الکترونیکی خود را به صورت صحیح وارد نمایید، لینک دانلود به این آدرس ارسال خواهد شد', 'payirpaiddownloads')) . '");
                                jQuery("#payirpaiddownloads_email_' . $button_id . '").focus();
								return;
							}
							var payirpaiddownloads_email = jQuery("#payirpaiddownloads_email_' . $button_id . '").val();
							var re = /^[\w-]+(\.[\w-]+)*@([\w-]+\.)+[a-zA-Z]{2,7}$/;
							if (!payirpaiddownloads_email.match(re)) {
								alert("' . esc_attr(__('لطفا آدرس پست الکترونیکی خود را به صورت صحیح وارد نمایید، لینک دانلود به این آدرس ارسال خواهد شد', 'payirpaiddownloads')) . '");
                                jQuery("#payirpaiddownloads_email_' . $button_id . '").focus();
								return;
							}

							jQuery("#payir_email_'.$button_id.'").val(payirpaiddownloads_email);
   							jQuery("#payir_payer_'.$button_id.'").val(jQuery("#payirpaiddownloads_payer_'.$button_id.'").val());';
                            if($this->options['getphonenumber'] == 'on') {

                                $button .=  'jQuery("#payir_phone_'.$button_id.'").val(jQuery("#payirpaiddownloads_phone_'.$button_id.'").val());';
                            }
				   $button .= '}
						jQuery("#" + active_'.$button_id.').click();
						return;
					}
					function payirpaiddownloads_toggle_payirpaiddownloads_email_'.$button_id.'() {
						if (jQuery("#payirpaiddownloads_email_container_'.$button_id.'")) {
							jQuery("#payirpaiddownloads_email_container_'.$button_id.'").slideDown(100);
						}
					}
				</script>';

				$checked = ' checked="checked"';
				$price   = $rate*$file_details['price'];

				if ($this->options['enable_payir'] == 'on') {

					$style = 'font-family:tahoma; font-size:14px; line-height:14px; margin:5px 0px; padding:3px 5px; background:#fff;';
					$style .= 'border:1px solid #888; width:200px; -webkit-border-radius:3px; border-radius:3px; color:#666; min-height:28px';

					$value = esc_attr(__('نام و نام خانوادگی', 'payirpaiddownloads'));

					$button .= '<div id="payirpaiddownloads_email_container_' . $button_id . '" style="display:none; font-size:8pt">';
					$button .= 'جهت سفارش لطفا اطلاعات زیر را تکمیل نمایید:<br />';
					$button .= '<input type="text" id="payirpaiddownloads_payer_' . $button_id . '" style="' . $style . '" value="' . $value . '" onfocus="if (this.value == \'' . $value. '\'){this.value = \'\';}" onblur="if (this.value == \'\') {this.value = \'' . $value . '\';}" /><br />';

					if ($this->options['getphonenumber'] == 'on') {

						$style = 'font-family:tahoma; font-size:14px; line-height:14px; margin:5px 0px; padding:3px 5px; background:#fff;';
						$style .= 'border:1px solid #888; width:200px; -webkit-border-radius:3px; border-radius:3px; color:#666; min-height:28px';

						$value = esc_attr(__('شماره تلفن همراه', 'payirpaiddownloads'));

						$button .= '<input type="text" id="payirpaiddownloads_phone_' . $button_id . '" maxlength="11" style="' . $style . '" value="' . $value . '" onfocus="if (this.value == \'' . $value . '\') {this.value = \'\'; this.style.textAlign= \'left\'; this.style.direction= \'ltr\'}" onblur="if (this.value == \'\') {this.value = \'' . $value . '\'; this.style.textAlign= \'right\'; this.style.direction= \'rtl\'}" /><br />';
					}

					$style = 'font-family:tahoma; font-size:14px; line-height:14px; margin:5px 0px; padding:3px 5px; background:#fff;';
					$style .= 'border:1px solid #888; width:200px; -webkit-border-radius:3px; border-radius:3px; color:#666; min-height:28px';

					$value = esc_attr(__('آدرس پست الکترونیکی', 'payirpaiddownloads'));

					$button .= '<input type="text" id="payirpaiddownloads_email_' . $button_id . '" style="' . $style . '" value="' . $value . '" onfocus="if (this.value == \'' . $value . '\'){this.value = \'\'; this.style.textAlign= \'left\'; this.style.direction= \'ltr\' }" onblur="if (this.value == \'\') {this.value = \'' . $value . '\';  this.style.textAlign= \'right\'; this.style.direction= \'rtl\'}" /></div>';

					$button .= '<form action="'.get_bloginfo('wpurl').'/?payirpaiddownloads_connect=payir" method="post" style="display:none;">';
					$button .= '<input type="hidden" name="Description" value="سفارش ' . htmlspecialchars($file_details['title'], ENT_QUOTES) . '">';
					$button .= '<input type="hidden" name="ResNumber" value="' . $file_details['id'] . '">';
					$button .= '<input type="hidden" name="Price" value="' . $file_details['price'] . '">';
					$button .= '<input type="hidden" id="payir_payer_' . $button_id . '" name="Paymenter" value="">';
					$button .= '<input type="hidden" id="payir_email_' . $button_id . '" name="Email" value="">';
					$button .= '<input type="hidden" id="payir_phone_' . $button_id . '" name="Mobile" value="">';
					$button .= '<input type="hidden" name="ReturnPath" value="' . get_bloginfo('wpurl') . '/?payirpaiddownloads_ipn=payir">';
					$button .= '<input id="payir_' . $button_id . '" type="submit" value="Buy Now" style="margin: 0px; padding: 0px;">';
					$button .= '</form>';
				}

				if ($this->options['buynow_type'] == 'custom') {

					$img   = get_bloginfo('wpurl') . '/wp-content/uploads/paid-downloads/' . rawurlencode($this->options['buynow_image']);
					$alt   = htmlspecialchars($file_details['title'], ENT_QUOTES);
					$style = 'margin:5px 0px; padding:0px; border:0px;';

					$button .= '<input type="image" src="' . $img . '" name="submit" alt="' . $alt . '" style="' . $style . '" onclick="payirpaiddownloads_' . $button_id . '(); return false;">';

				} elseif ($this->options['buynow_type'] == 'payir') {

					$img   = plugins_url('/images/btn_buynow.png', __FILE__);
					$alt   = htmlspecialchars($file_details['title'], ENT_QUOTES);
					$style = 'margin:5px 0px; padding:0px; border:0px;';

					$button .= '<input type="image" src="' . $img . '" name="submit" alt="' . $alt . '" style="' . $style . '" onclick="payirpaiddownloads_' . $button_id . '(); return false;">';

				} elseif ($this->options['buynow_type'] == 'css3') {

					$text = number_format($file_details['price'], 0, '.', NULL) . ' ' . $file_details['currency'];
					$style = 'border:0px; margin:5px 0px; padding:0px; height:100%; overflow:hidden;';

					$button .= '<div style="' . $style . '">';
					$button .= '<a href="#" class="payirpaiddownloads-btn" onclick="payirpaiddownloads_' . $button_id . '(); return false;">';
					$button .= '<span class="payirpaiddownloads-btn-icon-right"><span></span></span>';
					$button .= '<span class="payirpaiddownloads-btn-slide-text">' . $text . '</span>';
					$button .= '<span class="payirpaiddownloads-btn-text">' . __('خرید', 'payirpaiddownloads') . '</span>';
					$button .= '</a>';
					$button .= '</div>';

				} else {

					$text  = __('خرید', 'payirpaiddownloads');
					$style = 'margin:5px 0px; padding:2px; width:100px; font-family:tahoma;';

					$button .= '<input type="button" value="' . $text . '" style="' . $style . '" onclick="payirpaiddownloads_' . $button_id . '(); return false;">';
				}

			} else {

				$button = '-';
			}

			return $button;
		}

		return NULL;
	}

	function generate_downloadlink($_fileid, $_owner, $_source)
	{
		global $wpdb;

		$file_details = $wpdb->get_row("SELECT * FROM " . $wpdb->prefix . "pd_files WHERE id = '" . intval($_fileid) . "'", ARRAY_A);

		if (intval($file_details['id']) == 0) {

			return FALSE;
		}

		$download_key = md5(microtime() . rand(1, 10000)) . md5(microtime() . $file_details['title']);

		$sql = "INSERT INTO " . $wpdb->prefix . "pd_downloadlinks (file_id, download_key, owner, source, created) VALUES ('" . $_fileid . "', '" . $download_key . "', '" . esc_sql($_owner) . "', '" . $_source . "', '" . time() . "')";

		$wpdb->query($sql);

		return get_bloginfo('wpurl') . '/?payirpaiddownloads_key=' . $download_key;
	}

	function page_switcher ($_urlbase, $_currentpage, $_totalpages)
	{
		$pageswitcher = NULL;

		if ($_totalpages > 1) {

			$pageswitcher = '<div class="tablenav bottom"><div class="tablenav-pages">' . __('Pages:', 'payirpaiddownloads') . ' <span class="pagiation-links">';

			if (strpos($_urlbase, '?') !== FALSE) {

				$_urlbase .= '&amp;';

			} else {

				$_urlbase .= '?';
			}

			if ($_currentpage == 1) {

				$pageswitcher .= ' <a class="page disabled">1</a> ';

			} else {

				$pageswitcher .= ' <a class="page" href="' . $_urlbase . 'p=1">1</a> ';
			}

			$start = max($_currentpage - 3, 2);
			$end   = min(max($_currentpage + 3, $start + 6), $_totalpages - 1);
			$start = max(min($start, $end - 6), 2);

			if ($start > 2) {

				$pageswitcher .= ' <b>...</b> ';
			}

			for ($i = $start; $i <= $end; $i++) {

				if ($_currentpage == $i) {

					$pageswitcher .= ' <a class="page disabled">' . $i . '</a> ';

				} else {

					$pageswitcher .= ' <a class="page" href="' . $_urlbase . 'p=' . $i . '">' . $i . '</a> ';
				}
			}

			if ($end < $_totalpages - 1) {

				$pageswitcher .= ' <b>...</b> ';
			}

			if ($_currentpage == $_totalpages) {

				$pageswitcher .= ' <a class="page disabled">' . $_totalpages . '</a> ';

			} else {

				$pageswitcher .= ' <a class="page" href="' . $_urlbase . 'p=' . $_totalpages . '">' . $_totalpages . '</a> ';
			}

			$pageswitcher .= '</span></div></div>';
		}

		return $pageswitcher;
	}

	function get_filename($_path, $_filename)
	{
		$filename = preg_replace('/[^a-zA-Z0-9\s\-\.\_]/', ' ', $_filename);
		$filename = preg_replace('/(\s\s)+/', ' ', $filename);
		$filename = trim($filename);
		$filename = preg_replace('/\s+/', '-', $filename);
		$filename = preg_replace('/\-+/', '-', $filename);

		if (strlen($filename) == 0) {

			$filename = 'file';

		} elseif ($filename[0] == '.') {

			$filename = 'file' . $filename;
		}

		while (file_exists($_path.$filename)) {

			$pos = strrpos($filename, '.');

			if ($pos !== FALSE) {

				$ext      = substr($filename, $pos);
				$filename = substr($filename, 0, $pos);

			} else {

				$ext = NULL;
			}

			$pos = strrpos($filename, '-');

			if ($pos !== FALSE) {

				$suffix = substr($filename, $pos+1);

				if (is_numeric($suffix)) {

					$suffix++;
					$filename = substr($filename, 0, $pos) . '-' . $suffix . $ext;

				} else {

					$filename = $filename . '-1' . $ext;
				}

			} else {

				$filename = $filename . '-1' . $ext;
			}
		}

		return $filename;
	}

	function period_to_string($period)
	{
		$period_str = NULL;

		$days    = floor($period / (24 * 3600));
		$period -= $days * 24 * 3600;
		$hours   = floor($period / 3600);
		$period -= $hours * 3600;
		$minutes = floor($period / 60);

		if ($days > 1) {

			$period_str = $days . ' ' . __('روز', 'payirpaiddownloads') . ' و ';

		} elseif ($days == 1) {

			$period_str = $days . ' ' . __('روز', 'payirpaiddownloads') . ' و ';
		}

		if ($hours > 1) {

			$period_str .= $hours . ' ' . __('ساعت', 'payirpaiddownloads') . ' و ';

		} elseif ($hours == 1) {

			$period_str .= $hours . ' ' . __('ساعت', 'payirpaiddownloads') . ' و ';

		} elseif (!empty($period_str)) {

			$period_str .= '0 ' . __('ساعت', 'payirpaiddownloads') . ' و ';
		}

		if ($minutes > 1) {

			$period_str .= $minutes . ' ' . __('دقیقه', 'payirpaiddownloads');

		} elseif ($minutes == 1) {

			$period_str .= $minutes . ' ' . __('دقیقه', 'payirpaiddownloads');

		} else {

			$period_str .= '0 ' . __('دقیقه', 'payirpaiddownloads');
		}

		return $period_str;
	}

	function get_license_info($_url, $_postdata)
	{
		$uagent = 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1; .NET CLR 1.1.4322)';

		$ch = curl_init($_url);

		curl_setopt($ch, CURLOPT_URL, $_url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_HEADER, FALSE);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
		curl_setopt($ch, CURLOPT_ENCODING, NULL);
		curl_setopt($ch, CURLOPT_USERAGENT, $uagent);
		curl_setopt($ch, CURLOPT_TIMEOUT, 120);
		curl_setopt($ch, CURLOPT_FAILONERROR, TRUE);
		curl_setopt($ch, CURLOPT_AUTOREFERER, TRUE);
		curl_setopt($ch, CURLOPT_POST, TRUE);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $_postdata);

		$content = curl_exec($ch);
		$err     = curl_errno($ch);
		$errmsg  = curl_error($ch);
		$header  = curl_getinfo($ch);

		curl_close( $ch );

		$header['errno']   = $err;
		$header['errmsg']  = $errmsg;
		$header['content'] = $content;

		return $header;
	}

	function get_currency_rate($_from, $_to, $_postdata = NULL)
	{
		$url = 'http://www.google.com/ig/calculator?hl=en&q=1' . $_from . '=?' . $_to;

		$ch= curl_init($url);

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_HEADER, FALSE);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, TRUE);
		curl_setopt($ch, CURLOPT_ENCODING, NULL);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/4.0 (compatible; MSIE 6.0; Windows NT 5.1; SV1; .NET CLR 1.1.4322)');
		curl_setopt($ch, CURLOPT_TIMEOUT, 30);
		curl_setopt($ch, CURLOPT_FAILONERROR, TRUE);
		curl_setopt($ch, CURLOPT_AUTOREFERER, TRUE);
		curl_setopt($ch, CURLOPT_POST, TRUE);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $_postdata);

		$data  = curl_exec($ch);

		curl_close( $ch );

		preg_match("!rhs: \"(.*?)\s!si", $data, $rate);

		$rate = floatval($rate[1]);

		if ($rate <= 0) {

			return FALSE;

		} else {

			return $rate;
		}
	}

	private static function common($url, $params)
	{
		$ch = curl_init();

		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_POST, TRUE);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, FALSE);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));

		$response = curl_exec($ch);
		$error    = curl_errno($ch);

		curl_close($ch);

		$output = $error ? FALSE : json_decode($response);

		return $output;
	}
}

if (class_exists('payirpaiddownloadspro_class')) {

	add_action('admin_notices', 'payirpaiddownloads_warning');

} else {

	$payirpaiddownloads = new payirpaiddownloads_class();
}

function payirpaiddownloads_warning() {

	echo '<div class="updated"><p>' . __('لطفا افزونه <strong>Paid Downloads Pro</strong> را غیر فعال نمایید', 'payirpaiddownloads') . '</p></div>';
}
