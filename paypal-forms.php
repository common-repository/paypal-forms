<?php
/*
Plugin Name: PayPal Forms
Version: 1.0.3
Plugin URI: http://smye.co/
Author: Smyeco
Author URI: http://www.smye.co/
Description: This plugin allows you to create order forms with integrated PayPal payments. Simple and straightforward to use.
*/

function pf_is_number($string){
	$string = preg_match('/^[0-9]+$/i',$string);
	return $string;
}
function pf_is_decimal($string){
	$string = preg_match('/^[0-9\.]+$/i',$string);
	return $string;
}

function pf_is_email($string){
	$string = preg_match('/[[:alnum:]_.-]+[@][[:alnum:]_.-]{2,}.[[:alnum:]_.-]{2,}/',$string);
	return $string;
}
function pf_is_url($string){
	$string = preg_match('/^http[s]?\\:\\/\\/[a-z0-9\-]+\.([a-z0-9\-]+\.)?[a-z0-9]+/i',$string);
	return $string;
}

add_action('init', 'pf_init');
function pf_init(){
	global $pf_errors;
	$pf_errors = array();

	if ($_SERVER['REQUEST_METHOD'] != 'POST') return false;
	if (!isset($_POST['paypal-form'])) return false;
	if ($_POST['paypal-form'] != 'yes') return false;
	if (!isset($_POST['form'])) return false;

	$form_id = $_POST['form'];
	$forms = get_option('_paypal_forms');
	if (!$forms) $forms = array();
	if (!isset($forms[$form_id])) return false;

	$form = $forms[$form_id];
	unset($forms);
	$fields = $form['fields'];

	foreach ($fields as $field_id=>$field){
		if ($field['mandatory'] == 'yes' && (!isset($_POST['paypal_form_'.$field_id]) || !strlen($_POST['paypal_form_'.$field_id]))) $pf_errors[] = $field_id;
		elseif ($field['type'] == 'text' && isset($_POST['paypal_form_'.$field_id]) && strlen($_POST['paypal_form_'.$field_id])){
			if ($field['validation'] == 'email'){
				if (!pf_is_email($_POST['paypal_form_'.$field_id])) $pf_errors[] = $field_id;
			}elseif ($field['validation'] == 'url'){
				if (!pf_is_url($_POST['paypal_form_'.$field_id])) $pf_errors[] = $field_id;
			}elseif ($field['validation'] == 'number'){
				if (!pf_is_number($_POST['paypal_form_'.$field_id])) $pf_errors[] = $field_id;
			}
		}
	}
	if (strlen($form['coupon'])){
		if (strlen($_POST['paypal_form_coupon']) && $_POST['paypal_form_coupon'] != $form['coupon']) $pf_errors[] = 'coupon';
	}

	if (!count($pf_errors)){
		$item_name = $form['item'];
		$price = $form['price'];
		$paid_options = array();
		foreach ($fields as $field_id=>$field){
			if ($field['type'] == 'select' || $field['type'] == 'radio'){
				foreach ($field['options'] as $option){
					$value = (isset($option['value']))? $option['value'] : $option['label'];
					if ($_POST['paypal_form_'.$field_id] == $value){
						if (isset($option['price'])){
							$price += $option['price'];
							$paid_options[] = $option['label'];
						}
						break;
					}
				}
			}
		}
		if (strlen($form['coupon'])){
			if ($_POST['paypal_form_coupon'] == $form['coupon']) $price -= $price*$form['coupon_discount']/100;
		}

		//email
		$lines = array();
		foreach ($fields as $field_id=>$field){
			if (!isset($_POST['paypal_form_'.$field_id])) continue;
			if (!strlen($_POST['paypal_form_'.$field_id])) continue;
			$lines[] = $field['name'].': '.$_POST['paypal_form_'.$field_id];
		}
		if (strlen($form['coupon'])){
			if ($_POST['paypal_form_coupon'] == $form['coupon']) $lines[] = 'Coupon code: '.$form['coupon'].' ('.$form['currency'].' '.$form['coupon_discount'].')';
		}
		wp_mail($form['email'],$form['name'],implode("\n",$lines));

		// paypal
		if ($price > 0){
			if (count($paid_options)) $item_name .= ' ('.implode(' / ',$paid_options).')';
			if ($form['period'] == 'one-time'){
				$url = 'https://www.paypal.com/cgi-bin/webscr?cmd=_xclick';
				$url .= '&amount='.number_format($price,2);
			}else{
				$url = 'https://www.paypal.com/cgi-bin/webscr?cmd=_xclick-subscriptions';
				$url .= '&src=1&sra=1&a3='.number_format($price,2);
				$url .= '&p3=1';
				if ($form['period'] == 'monthly'){
					$url .= '&t3=M';
				}elseif ($form['period'] == 'yearly'){
					$url .= '&t3=Y';
				}
			}
			$url .= '&business='.urlencode($form['paypal']);
			$url .= '&item_name='.urlencode($item_name);
			$url .= '&item_number='.urlencode($item_name);
			$url .= '&currency_code='.$form['currency'];
			$url .= '&return='.urlencode($form['return']);

			wp_redirect($url,301);
			die;
		}
	}
}

add_shortcode('paypal-form', 'pf_shortcode');

function pf_shortcode($args = array()){
    ob_start();
    global $pf_errors;
	if (!isset($pf_errors)) $pf_errors = array();

	if (!isset($args['id'])){
		echo '<p>Form ID not specified</p>';
		return false;
	}
	$form_id = $args['id'];
	$forms = get_option('_paypal_forms');
	if (!$forms) $forms = array();
	if (!isset($forms[$form_id])){
		echo '<p>Form not found</p>';
		return false;
	}
	$form = $forms[$form_id];
	unset($forms);
	$fields = $form['fields'];

	if ($_SERVER['REQUEST_METHOD'] != 'POST'){
		foreach ($fields as $field_id=>$field){
			$_POST['paypal_form_'.$field_id] = '';
			if ($field['type'] == 'select' || $field['type'] == 'radio'){
				foreach ($field['options'] as $option){
					if (isset($option['default'])){
						$value = (isset($option['value']))? $option['value'] : $option['label'];
						$_POST['paypal_form_'.$field_id] = $value;
						break;
					}
				}
			}
		}
		if (strlen($form['coupon'])) $_POST['paypal_form_coupon'] = '';
	}

	if ($_SERVER['REQUEST_METHOD'] == 'POST' && !count($pf_errors)){
		echo '<p><b>Form sent successfully.</b></p>';
	}else{
		echo '<form action="" method="post" id="paypal-form-'.esc_html($form_id).'" class="paypal-form">';
		foreach ($fields as $field_id=>$field){
			$color = (in_array($field_id,$pf_errors))? 'red' : 'inherit';
			$mandatory = ($field['mandatory'] == 'yes')? ' *' : '';
			echo '<p>';
			if ($field['type'] == 'text'){
				echo '<span style="color:'.$color.'">'.esc_html($field['name']).$mandatory.'</span><br/>';
				echo '<input type="text" name="paypal_form_'.$field_id.'" size="60" value="'.esc_html($_POST['paypal_form_'.$field_id]).'" />';
			}
			if ($field['type'] == 'textarea'){
				echo '<span style="color:'.$color.'">'.esc_html($field['name']).$mandatory.'</span><br/>';
				echo '<textarea name="paypal_form_'.$field_id.'" cols="60" rows="4">'.esc_html($_POST['paypal_form_'.$field_id]).'</textarea>';
			}
			if ($field['type'] == 'select'){
				echo '<span style="color:'.$color.'">'.esc_html($field['name']).$mandatory.'</span><br/>';
				echo '<select name="paypal_form_'.$field_id.'">';
				echo '<option>Select...</option>';
				foreach ($field['options'] as $option){
					$value = (isset($option['value']))? $option['value'] : $option['label'];
					echo '<option value="'.esc_html($value).'"'.(($value == $_POST['paypal_form_'.$field_id])? ' selected="selected"' : '').'>'.esc_html($option['label']).'</option>';
				}
				echo '</select>';
			}
			if ($field['type'] == 'radio'){
				echo '<span style="color:'.$color.'">'.esc_html($field['name']).$mandatory.'</span>';
				foreach ($field['options'] as $option){
					$value = (isset($option['value']))? $option['value'] : $option['label'];
					echo '<br/><label><input type="radio" name="paypal_form_'.$field_id.'" value="'.esc_html($value).'"'.(($value == $_POST['paypal_form_'.$field_id])? ' checked="checked"' : '').' /> '.esc_html($option['label']).'</label>';
				}
			}
			if ($field['type'] == 'checkbox'){
				echo '<label><input type="checkbox" name="paypal_form_'.$field_id.'" value="yes"'.((isset($_POST['paypal_form_'.$field_id]) && 'yes' == $_POST['paypal_form_'.$field_id])? ' checked="checked"' : '').' /> <span style="color:'.$color.'">'.esc_html($field['name']).$mandatory.'</span></label>';
			}
			echo '</p>';
		}
		if (strlen($form['coupon'])){
			$color = (in_array('coupon',$pf_errors))? 'red' : 'inherit';
			echo '<p>';
			echo '<span style="color:'.$color.'">Coupon code</span><br/>';
			echo '<input type="text" name="paypal_form_coupon" size="40" value="'.esc_html($_POST['paypal_form_coupon']).'" />';
			echo '</p>';
		}
		echo '<p>';
		echo '<input type="submit" value="Submit" />';
		echo '</p>';
		echo '<input type="hidden" name="paypal-form" value="yes" />';
		echo '<input type="hidden" name="form" value="'.esc_html($form_id).'" />';
		echo '</form>';
                $paypal_fields = ob_get_clean();
                return $paypal_fields;
	}
}

add_action('admin_menu', 'pf_admin_menu');

function pf_admin_menu(){
	add_menu_page('PayPal Forms', 'PayPal Forms', 'administrator', 'paypal-forms', 'pf_page');
}

function pf_page(){
	$form_id = (isset($_GET['form']))? $_GET['form'] : false;
	$field_id = (isset($_GET['field']))? $_GET['field'] : false;
	$action = (isset($_GET['action']))? $_GET['action'] : false;
	$field_type = (isset($_GET['type']))? $_GET['type'] : false;

	$display = '';
	$forms = get_option('_paypal_forms');
	if (!$forms) $forms = array();
	$types = array('text','textarea','select','radio','checkbox');
	$currencies = array('USD','EUR','GBP');
	$periods = array('one-time','monthly','yearly');
	$validations = array('none','email','url','number');

	$errors = array();
	$message = '';

	if ($form_id*1 && $field_id*1  && $action == 'remove'){
		// remove field
		if (!isset($forms[$form_id])){
			echo '<script>location.replace("admin.php?page=paypal-forms")</script>';
			return false;
		}
		if (isset($forms[$form_id]['fields'][$field_id])){
			unset($forms[$form_id]['fields'][$field_id]);
			$forms[$form_id]['fields'] = array_values($forms[$form_id]['fields']);
			array_unshift($forms[$form_id]['fields'],null);
			unset($forms[$form_id]['fields'][0]);
			add_option('_paypal_forms',$forms) or update_option('_paypal_forms',$forms);
		}
		echo '<script>location.replace("admin.php?page=paypal-forms&form='.urlencode($form_id).'")</script>';
		return false;

	}elseif ($form_id*1 && $field_id*1 && $action == 'edit'){
		// edit field
		$display = 'edit-field';
		if (!isset($forms[$form_id])){
			echo '<script>location.replace("admin.php?page=paypal-forms")</script>';
			return false;
		}
		if (!isset($forms[$form_id]['fields'][$field_id])){
		echo '<script>location.replace("admin.php?page=paypal-forms&form='.urlencode($form_id).'")</script>';
			return false;
		}
		$form = $forms[$form_id];
		$field = $form['fields'][$field_id];
		$field_type = $field['type'];
		if ($_SERVER['REQUEST_METHOD'] == 'POST'){
			if (!strlen($_POST['name'])) $errors[] = 'You must enter a name';
			if (!strlen($_POST['position'])) $errors[] = 'You must select a position';
			elseif (!pf_is_number($_POST['position'])) $errors[] = 'Position is invalid';
			if (!strlen($_POST['mandatory'])) $errors[] = 'You must select mandatory';
			elseif (!in_array($_POST['mandatory'],array('yes','no'))) $errors[] = 'Mandatory is invalid';
			if ($field_type == 'text'){
				if (!strlen($_POST['validation'])) $errors[] = 'You must select validation';
				elseif (!in_array($_POST['validation'],$validations)) $errors[] = 'Validation is invalid';
			}
			if ($field_type == 'select' || $field_type == 'radio'){
				$last_option = 0;
				for ($x = 1; $x <= 50; $x++){
					if (strlen($_POST['option_'.$x.'_value']) || strlen($_POST['option_'.$x.'_label']) || strlen($_POST['option_'.$x.'_price'])) $last_option = $x;
				}
				if (!$last_option){
					$errors[] = 'You must enter at least one option';
				}else{
					if (!strlen($_POST['default_option'])) $errors[] = 'You must select a default option';
					for ($x = 1; $x <= $last_option; $x++){
						if (!strlen($_POST['option_'.$x.'_label'])) $errors[] = 'You must enter a label for option '.$x;
						if (strlen($_POST['option_'.$x.'_price'])){
							if (!pf_is_decimal($_POST['option_'.$x.'_price'])) $errors[] = 'Price for option '.$x.' is invalid';
						}
					}
				}
			}

			if (!count($errors)){
				$position = $_POST['position']*1;
				if ($position > count($form['fields'])) $position = count($form['fields']);

				$new_field = array(array());
				$new_field[0]['type'] = $field_type;
				$new_field[0]['name'] = $_POST['name'];
				$new_field[0]['mandatory'] = $_POST['mandatory'];
				if ($field_type == 'text') $new_field[0]['validation'] = $_POST['validation'];

				if ($field_type == 'select' || $field_type == 'radio'){
					$new_field[0]['options'] = array();
					$default_option = $_POST['default_option']*1;
					if (!$default_option || $default_option > $last_option) $default_option = 1;
					for ($x = 1; $x <= $last_option; $x++){
						$new_option = array();
						if (strlen($_POST['option_'.$x.'_value'])) $new_option['value'] = $_POST['option_'.$x.'_value'];
						$new_option['label'] = $_POST['option_'.$x.'_label'];
						if (strlen($_POST['option_'.$x.'_price'])) $new_option['price'] = $_POST['option_'.$x.'_price'];
						if ($x == $default_option) $new_option['default'] = 'yes';

						$new_field[0]['options'][] = $new_option;
					}
					array_unshift($new_field[0]['options'],null);
					unset($new_field[0]['options'][0]);
				}

				unset($forms[$form_id]['fields'][$field_id]);
				$forms[$form_id]['fields'] = array_slice($forms[$form_id]['fields'],0,$position,true) + $new_field + array_slice($forms[$form_id]['fields'],$position,count($forms[$form_id]['fields'])-$position,true);
				array_unshift($forms[$form_id]['fields'],null);
				unset($forms[$form_id]['fields'][0]);

				add_option('_paypal_forms',$forms) or update_option('_paypal_forms',$forms);

				echo '<script>location.replace("admin.php?page=paypal-forms&form='.urlencode($form_id).'")</script>';
				return false;
			}
		}else{
			$_POST = $field;
			$_POST['position'] = $field_id;
			unset($_POST['fields']);
			if ($field_type == 'select' || $field_type == 'radio'){
				$_POST['default_option'] = 1;
				for ($x = 1; $x <= 50; $x++){
					if (isset($field['options'][$x])){
						$_POST['option_'.$x.'_value'] = (isset($field['options'][$x]['value']))? $field['options'][$x]['value'] : '';
						$_POST['option_'.$x.'_label'] = $field['options'][$x]['label'];
						$_POST['option_'.$x.'_price'] = (isset($field['options'][$x]['value']))? $field['options'][$x]['price'] : '';
						if (isset($field['options'][$x]['default'])) $_POST['default_option'] = $x;
					}else{
						$_POST['option_'.$x.'_value'] = '';
						$_POST['option_'.$x.'_label'] = '';
						$_POST['option_'.$x.'_price'] = '';
					}
				}
			}
		}


	}elseif ($form_id*1 && $action == 'new' && $field_type != false){
		// add field
		$display = 'add-field';
		if (!isset($forms[$form_id])){
			echo '<script>location.replace("admin.php?page=paypal-forms")</script>';
			return false;
		}
		$form = $forms[$form_id];
		if ($_SERVER['REQUEST_METHOD'] == 'POST'){
			if (!strlen($_POST['name'])) $errors[] = 'You must enter a name';
			if (!strlen($_POST['position'])) $errors[] = 'You must select a position';
			elseif (!pf_is_number($_POST['position'])) $errors[] = 'Position is invalid';
			if (!strlen($_POST['mandatory'])) $errors[] = 'You must select mandatory';
			elseif (!in_array($_POST['mandatory'],array('yes','no'))) $errors[] = 'Mandatory is invalid';
			if ($field_type == 'text'){
				if (!strlen($_POST['validation'])) $errors[] = 'You must select validation';
				elseif (!in_array($_POST['validation'],$validations)) $errors[] = 'Validation is invalid';
			}
			if ($field_type == 'select' || $field_type == 'radio'){
				$last_option = 0;
				for ($x = 1; $x <= 50; $x++){
					if (strlen($_POST['option_'.$x.'_value']) || strlen($_POST['option_'.$x.'_label']) || strlen($_POST['option_'.$x.'_price'])) $last_option = $x;
				}
				if (!$last_option){
					$errors[] = 'You must enter at least one option';
				}else{
					if (!strlen($_POST['default_option'])) $errors[] = 'You must select a default option';
					for ($x = 1; $x <= $last_option; $x++){
						if (!strlen($_POST['option_'.$x.'_label'])) $errors[] = 'You must enter a label for option '.$x;
						if (strlen($_POST['option_'.$x.'_price'])){
							if (!pf_is_decimal($_POST['option_'.$x.'_price'])) $errors[] = 'Price for option '.$x.' is invalid';
						}
					}
				}
			}

			if (!count($errors)){
				$position = $_POST['position']*1;
				if ($position > count($form['fields'])) $position = count($form['fields']);

				$new_field = array(array());
				$new_field[0]['type'] = $field_type;
				$new_field[0]['name'] = $_POST['name'];
				$new_field[0]['mandatory'] = $_POST['mandatory'];
				if ($field_type == 'text') $new_field[0]['validation'] = $_POST['validation'];

				if ($field_type == 'select' || $field_type == 'radio'){
					$new_field[0]['options'] = array();
					$default_option = $_POST['default_option']*1;
					if (!$default_option || $default_option > $last_option) $default_option = 1;
					for ($x = 1; $x <= $last_option; $x++){
						$new_option = array();
						if (strlen($_POST['option_'.$x.'_value'])) $new_option['value'] = $_POST['option_'.$x.'_value'];
						$new_option['label'] = $_POST['option_'.$x.'_label'];
						if (strlen($_POST['option_'.$x.'_price'])) $new_option['price'] = $_POST['option_'.$x.'_price'];
						if ($x == $default_option) $new_option['default'] = 'yes';

						$new_field[0]['options'][] = $new_option;
					}
					array_unshift($new_field[0]['options'],null);
					unset($new_field[0]['options'][0]);
				}


				$forms[$form_id]['fields'] = array_slice($forms[$form_id]['fields'],0,$position,true) + $new_field + array_slice($forms[$form_id]['fields'],$position,count($forms[$form_id]['fields'])-$position,true);
				array_unshift($forms[$form_id]['fields'],null);
				unset($forms[$form_id]['fields'][0]);

				add_option('_paypal_forms',$forms) or update_option('_paypal_forms',$forms);

				echo '<script>location.replace("admin.php?page=paypal-forms&form='.urlencode($form_id).'")</script>';
				return false;
			}
		}else{
			$_POST['name'] = '';
			$_POST['position'] = (count($form['fields']))? count($form['fields']) : 0;
			$_POST['mandatory'] = 'no';
			$_POST['validation'] = 'none';
			if ($field_type == 'select' || $field_type == 'radio'){
				for ($x = 1; $x <= 50; $x++){
					$_POST['option_'.$x.'_value'] = '';
					$_POST['option_'.$x.'_label'] = '';
					$_POST['option_'.$x.'_price'] = '';
				}
				$_POST['default_option'] = '1';
			}
		}
	}elseif ($form_id*1 && $action == 'remove'){
		// remove form

		if (isset($forms[$form_id])){
			unset($forms[$form_id]);
			add_option('_paypal_forms',$forms) or update_option('_paypal_forms',$forms);
		}

		echo '<script>location.replace("admin.php?page=paypal-forms")</script>';
		return false;

	}elseif ($form_id*1 && $action == 'duplicate'){
		// duplicate form
		if (!isset($forms[$form_id])){
			echo '<script>location.replace("admin.php?page=paypal-forms")</script>';
			return false;
		}

		$new_form_id = array_keys($forms);
		$new_form_id = ($new_form_id)? max($new_form_id)+1 : 1;

		$forms[$new_form_id] = $forms[$form_id];
		$forms[$new_form_id]['name'] .= ' Copy';

		add_option('_paypal_forms',$forms) or update_option('_paypal_forms',$forms);

		echo '<script>location.replace("admin.php?page=paypal-forms&form='.urlencode($new_form_id).'")</script>';
		return false;

	}elseif ($form_id*1 && $action == 'edit'){
		// edit form
		$display = 'edit-form';
		if (!isset($forms[$form_id])){
			echo '<script>location.replace("admin.php?page=paypal-forms")</script>';
			return false;
		}
		$form = $forms[$form_id];
		if ($_SERVER['REQUEST_METHOD'] == 'POST'){
			if (!strlen($_POST['name'])) $errors[] = 'You must enter a name';
			if (!strlen($_POST['paypal'])) $errors[] = 'You must enter a Paypal address';
			elseif (!pf_is_email($_POST['paypal'])) $errors[] = 'Paypal address is invalid';
			if (!strlen($_POST['email'])) $errors[] = 'You must enter a notification email';
			elseif (!pf_is_email($_POST['email'])) $errors[] = 'Notification email is invalid';
			if (!strlen($_POST['item'])) $errors[] = 'You must enter a Paypal item name';
			if (!strlen($_POST['currency'])) $errors[] = 'You must select a currency';
			elseif (!in_array($_POST['currency'],$currencies)) $errors[] = 'Currency is invalid';
			if (!strlen($_POST['price'])) $errors[] = 'You must enter a base price';
			elseif (!pf_is_decimal($_POST['price'])) $errors[] = 'Base price is invalid';
			if (!strlen($_POST['period'])) $errors[] = 'You must select a recurring period';
			elseif (!in_array($_POST['period'],$periods)) $errors[] = 'Recurring period is invalid';
			if (!strlen($_POST['return'])) $errors[] = 'You must enter a return url';
			elseif (!pf_is_url($_POST['return'])) $errors[] = 'Return url is invalid';
			if (strlen($_POST['coupon'])){
				if (!strlen($_POST['coupon_discount'])) $errors[] = 'You must enter a coupon discount';
				elseif (!pf_is_decimal($_POST['coupon_discount'])) $errors[] = 'Cupon discount is invalid';
			}

			if (!count($errors)){
				$forms[$form_id]['name'] = $_POST['name'];
				$forms[$form_id]['paypal'] = $_POST['paypal'];
				$forms[$form_id]['email'] = $_POST['email'];
				$forms[$form_id]['item'] = $_POST['item'];
				$forms[$form_id]['currency'] = $_POST['currency'];
				$forms[$form_id]['price'] = $_POST['price'];
				$forms[$form_id]['period'] = $_POST['period'];
				$forms[$form_id]['return'] = $_POST['return'];
				$forms[$form_id]['coupon'] = $_POST['coupon'];
				$forms[$form_id]['coupon_discount'] = $_POST['coupon_discount'];

				add_option('_paypal_forms',$forms) or update_option('_paypal_forms',$forms);

				echo '<script>location.replace("admin.php?page=paypal-forms&form='.urlencode($form_id).'")</script>';
				return false;
			}
		}else{
			$_POST = $form;
		}

	}elseif ($form_id*1){
		// display form
		$display = 'display-form';
		if (!isset($forms[$form_id])){
			echo '<script>location.replace("admin.php?page=paypal-forms")</script>';
			return false;
		}
		$form = $forms[$form_id];
	}elseif ($action == 'new'){
		// new form
		$display = 'add-form';
		if ($_SERVER['REQUEST_METHOD'] == 'POST'){
			if (!strlen($_POST['name'])) $errors[] = 'You must enter a name';
			if (!strlen($_POST['paypal'])) $errors[] = 'You must enter a Paypal address';
			elseif (!pf_is_email($_POST['paypal'])) $errors[] = 'Paypal address is invalid';
			if (!strlen($_POST['email'])) $errors[] = 'You must enter a notification email';
			elseif (!pf_is_email($_POST['email'])) $errors[] = 'Notification email is invalid';
			if (!strlen($_POST['item'])) $errors[] = 'You must enter a Paypal item name';
			if (!strlen($_POST['currency'])) $errors[] = 'You must select a currency';
			elseif (!in_array($_POST['currency'],$currencies)) $errors[] = 'Currency is invalid';
			if (!strlen($_POST['price'])) $errors[] = 'You must enter a base price';
			elseif (!pf_is_decimal($_POST['price'])) $errors[] = 'Base price is invalid';
			if (!strlen($_POST['period'])) $errors[] = 'You must select a recurring period';
			elseif (!in_array($_POST['period'],$periods)) $errors[] = 'Recurring period is invalid';
			if (!strlen($_POST['return'])) $errors[] = 'You must enter a return url';
			elseif (!pf_is_url($_POST['return'])) $errors[] = 'Return url is invalid';
			if (strlen($_POST['coupon'])){
				if (!strlen($_POST['coupon_discount'])) $errors[] = 'You must enter a coupon discount';
				elseif (!pf_is_decimal($_POST['coupon_discount'])) $errors[] = 'Cupon discount is invalid';
			}

			if (!count($errors)){
				$form_id = array_keys($forms);
				$form_id = ($form_id)? max($form_id)+1 : 1;

				$forms[$form_id] = array();
				$forms[$form_id]['name'] = $_POST['name'];
				$forms[$form_id]['paypal'] = $_POST['paypal'];
				$forms[$form_id]['email'] = $_POST['email'];
				$forms[$form_id]['item'] = $_POST['item'];
				$forms[$form_id]['currency'] = $_POST['currency'];
				$forms[$form_id]['price'] = $_POST['price'];
				$forms[$form_id]['period'] = $_POST['period'];
				$forms[$form_id]['return'] = $_POST['return'];
				$forms[$form_id]['coupon'] = $_POST['coupon'];
				$forms[$form_id]['coupon_discount'] = $_POST['coupon_discount'];
				$forms[$form_id]['fields'] = array();

				add_option('_paypal_forms',$forms) or update_option('_paypal_forms',$forms);

				echo '<script>location.replace("admin.php?page=paypal-forms&form='.urlencode($form_id).'")</script>';
				return false;
			}
		}else{
			$display = 'add-form';
			$_POST['name'] = '';
			$_POST['email'] = get_option('admin_email');
			$_POST['paypal'] = get_option('admin_email');
			$_POST['item'] = '';
			$_POST['currency'] = 'USD';
			$_POST['price'] = '0';
			$_POST['period'] = 'one-time';
			$_POST['return'] = home_url('/');
			$_POST['coupon'] = '';
			$_POST['coupon_discount'] = '';
		}
	}else{
		// forms
		$display = 'forms';
	}

?>
<div class="wrap">
	<div id="icon-edit-pages" class="icon32"><br></div>
	<h2>PayPal Forms<a href="admin.php?page=paypal-forms&amp;action=new" class="add-new-h2">Add New</a></h2>

<?php if (strlen($message)): ?>
	<div id="message" class="updated below-h2"><p><?php echo $message; ?></p></div>
<?php elseif (count($errors)): ?>
	<div id="message" class="error below-h2"><ul>
<?php	foreach ($errors as $error): ?>
		<li><b><?php echo $error; ?></b></li>
<?php	endforeach; ?>
	</ul></div>
<?php endif; ?>

<?php if ($display == 'forms'): ?>



	<h3>Forms</h3>
<?php 	if (count($forms)): ?>
	<table border="0" cellpadding="0" cellspacing="0" class="wp-list-table widefat fixed posts">
		<thead>
		<tr>
			<th>Name</th>
			<th>Shortcode</th>
			<th>Paypal</th>
			<th>Price</th>
			<th>Actions</th>
		</tr>
		</thead>
<?php			$x = 0; foreach ($forms as $form_id=>$form): $x++; ?>
		<tr<?php if ($x%2) echo ' class="alternate"'; ?>>
			<td><a href="admin.php?page=paypal-forms&amp;form=<?php echo urlencode($form_id); ?>"><b><?php echo esc_html($form['name']); ?></b></a></td>
			<td>[paypal-form id="<?php echo esc_html($form_id); ?>"]</td>
			<td><?php echo esc_html($form['paypal']); ?></td>
			<td><?php echo esc_html($form['currency']); ?> <?php echo number_format($form['price']); ?></td>
			<td>
				<a href="admin.php?page=paypal-forms&amp;form=<?php echo urlencode($form_id); ?>&amp;action=edit">Edit</a> |
				<a href="admin.php?page=paypal-forms&amp;form=<?php echo urlencode($form_id); ?>&amp;action=duplicate">Duplicate</a> |
				<a href="admin.php?page=paypal-forms&amp;form=<?php echo urlencode($form_id); ?>&amp;action=remove" onclick="return confirm('Remove?')">Remove</a>
			</td>
		</tr>
<?php			endforeach; ?>
	</table>
<?php 	else: ?>
	<p>No forms found.</p>
<?php		endif; ?>
	<form action="admin.php">
		<input type="hidden" name="page" value="paypal-forms" />
		<input type="hidden" name="action" value="new" />
		<p><span class="button-border"><input type="submit" class="button-primary" value="Add Form" /></span></p>
	</form>

<a href="http://www.smye.co">PayPal Forms is brought to you by Smyeco</a>

<?php elseif ($display == 'add-form'): ?>



	<h3>Add Form</h3>
	<form method="post" action="">
		<table border="0" cellpadding="0" cellspacing="0" class="form-table">
		<tr>
			<td>Name</td>
			<td><input type="text" name="name" size="80" maxlength="100" class="text" value="<?php echo esc_html($_POST['name']); ?>" /></td>
		</tr>
		<tr>
			<td>Paypal address</td>
			<td><input type="text" name="paypal" size="80" maxlength="100" class="text" value="<?php echo esc_html($_POST['paypal']); ?>" /></td>
		</tr>
		<tr>
			<td>Notification email</td>
			<td><input type="text" name="email" size="80" maxlength="100" class="text" value="<?php echo esc_html($_POST['email']); ?>" /></td>
		</tr>
		<tr>
			<td>Paypal item name</td>
			<td><input type="text" name="item" size="80" maxlength="100" class="text" value="<?php echo esc_html($_POST['item']); ?>" /></td>
		</tr>
		<tr>
			<td>Currency</td>
			<td><select name="currency">
				<option value="">Select...</option>
	<?php foreach ($currencies as $currency): ?>
				<option<?php if ($currency == $_POST['currency']) echo ' selected="selected"'; ?>><?php echo esc_html($currency); ?></option>
	<?php endforeach; ?>
			</select></td>
		</tr>
		<tr>
			<td>Base price</td>
			<td><input type="text" name="price" size="6" maxlength="8" class="text" value="<?php echo esc_html($_POST['price']); ?>" /></td>
		</tr>
		<tr>
			<td>Recurring period</td>
			<td><select name="period">
				<option value="">Select...</option>
	<?php foreach ($periods as $period): ?>
				<option<?php if ($period == $_POST['period']) echo ' selected="selected"'; ?>><?php echo esc_html($period); ?></option>
	<?php endforeach; ?>
			</select></td>
		</tr>
		<tr>
			<td>Return url</td>
			<td><input type="text" name="return" size="80" maxlength="100" class="text" value="<?php echo esc_html($_POST['return']); ?>" /></td>
		</tr>
		<tr>
			<td>Coupon code</td>
			<td><input type="text" name="coupon" size="40" maxlength="50" class="text" value="<?php echo esc_html($_POST['coupon']); ?>" /></td>
		</tr>
		<tr>
			<td>Coupon discount</td>
			<td><input type="text" name="coupon_discount" size="2" maxlength="3" class="text" value="<?php echo esc_html($_POST['coupon_discount']); ?>" />%</td>
		</tr>
		<tr>
			<td>&nbsp;</td>
			<td><span class="button-border"><input type="submit" class="button-primary" value="Submit" /></span></td>
		</tr>
		</table>
	</form>


<?php elseif ($display == 'display-form'): ?>


	<h3><?php echo esc_html($form['name']); ?></h3>
		<table border="0" cellpadding="0" cellspacing="0" class="links-table">
		<tr>
			<td>Name</td>
			<td><b><?php echo esc_html($form['name']); ?></b></td>
		</tr>
		<tr>
			<td>Paypal address</td>
			<td><b><?php echo esc_html($form['paypal']); ?></b></td>
		</tr>
		<tr>
			<td>Notification email</td>
			<td><b><?php echo esc_html($form['email']); ?></b></td>
		</tr>
		<tr>
			<td>Paypal item name</td>
			<td><b><?php echo esc_html($form['item']); ?></b></td>
		</tr>
		<tr>
			<td>Currency</td>
			<td><b><?php echo esc_html($form['currency']); ?></b></td>
		</tr>
		<tr>
			<td>Base price</td>
			<td><b><?php echo esc_html($form['currency']); ?> <?php echo number_format($form['price'],2); ?></b></td>
		</tr>
		<tr>
			<td>Recurring period</td>
			<td><b><?php echo esc_html($form['period']); ?></b></td>
		</tr>
		<tr>
			<td>Return url</td>
			<td><a href="<?php echo esc_html($form['return']); ?>" target="_blank"><b><?php echo esc_html($form['return']); ?></a></b></td>
		</tr>
		<tr>
			<td>Coupon code</td>
			<td><b><?php echo esc_html($form['coupon']); ?></b></td>
		</tr>
		<tr>
			<td>Coupon discount</td>
			<td><b><?php echo number_format($form['coupon_discount']*1); ?>%</b></td>
		</tr>
		<tr>
			<td>&nbsp;</td>
			<td>
				<form action="admin.php" style="display: inline">
					<input type="hidden" name="page" value="paypal-forms" />
					<input type="hidden" name="form" value="<?php echo esc_html($form_id); ?>" />
					<input type="hidden" name="action" value="edit" />
					<span class="button-border"><input type="submit" class="button" value="Edit" /></span>
				</form>
				<form action="admin.php" style="display: inline">
					<input type="hidden" name="page" value="paypal-forms" />
					<input type="hidden" name="form" value="<?php echo esc_html($form_id); ?>" />
					<input type="hidden" name="action" value="duplicate" />
					<span class="button-border"><input type="submit" class="button" value="Duplicate" /></span>
				</form>
				<form action="admin.php" style="display: inline">
					<input type="hidden" name="page" value="paypal-forms" />
					<input type="hidden" name="form" value="<?php echo esc_html($form_id); ?>" />
					<input type="hidden" name="action" value="remove" />
					<span class="button-border"><input type="submit" class="button" value="Remove" onclick="return confirm('Remove?')" /></span>
				</form>
			</td>
		</tr>
		</table>


	<h3>Fields</h3>
<?php 	if (count($form['fields'])): ?>
	<table border="0" cellpadding="0" cellspacing="0" class="wp-list-table widefat fixed posts">
		<thead>
		<tr>
			<th>Name</th>
			<th>Type</th>
			<th>Actions</th>
		</tr>
		</thead>
<?php			$x = 0; foreach ($form['fields'] as $field_id=>$field): $x++; ?>
		<tr<?php if ($x%2) echo ' class="alternate"'; ?>>
			<td><a href="admin.php?page=paypal-forms&amp;form=<?php echo urlencode($form_id); ?>&amp;field=<?php echo urlencode($field_id); ?>&amp;action=edit"><b><?php echo esc_html($field['name']); ?></b></a></td>
			<td><?php echo esc_html($field['type']); ?></td>
			<td>
				<a href="admin.php?page=paypal-forms&amp;form=<?php echo urlencode($form_id); ?>&amp;field=<?php echo urlencode($field_id); ?>&amp;action=edit">Edit</a> |
				<a href="admin.php?page=paypal-forms&amp;form=<?php echo urlencode($form_id); ?>&amp;field=<?php echo urlencode($field_id); ?>&amp;action=remove" onclick="return confirm('Remove?')">Remove</a>
			</td>
		</tr>
<?php			endforeach; ?>
	</table>
<?php 	else: ?>
	<p>No fields found.</p>
<?php		endif; ?>
	<form action="admin.php">
		<input type="hidden" name="page" value="paypal-forms" />
		<input type="hidden" name="form" value="<?php echo esc_html($form_id); ?>" />
		<input type="hidden" name="action" value="new" />
		<p>
			<select name="type">
<?php			foreach ($types as $type): ?>
				<option><?php echo esc_html($type); ?></option>
<?php			endforeach; ?>
			</select>
			<span class="button-border"><input type="submit" class="button-primary" value="Add Field" /></span>
		</p>
	</form>


<?php elseif ($display == 'edit-form'): ?>



	<h3><?php echo esc_html($form['name']); ?></h3>
	<form method="post" action="">
	<table border="0" cellpadding="0" cellspacing="0" class="form-table">
	<tr>
		<td>Name</td>
		<td><input type="text" name="name" size="80" maxlength="100" class="text" value="<?php echo esc_html($_POST['name']); ?>" /></td>
	</tr>
	<tr>
		<td>Paypal address</td>
		<td><input type="text" name="paypal" size="80" maxlength="100" class="text" value="<?php echo esc_html($_POST['paypal']); ?>" /></td>
	</tr>
		<tr>
			<td>Notification email</td>
			<td><input type="text" name="email" size="80" maxlength="100" class="text" value="<?php echo esc_html($_POST['email']); ?>" /></td>
		</tr>
	<tr>
		<td>Paypal item name</td>
		<td><input type="text" name="item" size="80" maxlength="100" class="text" value="<?php echo esc_html($_POST['item']); ?>" /></td>
	</tr>
	<tr>
		<td>Currency</td>
		<td><select name="currency">
			<option value="">Select...</option>
<?php foreach ($currencies as $currency): ?>
			<option<?php if ($currency == $_POST['currency']) echo ' selected="selected"'; ?>><?php echo esc_html($currency); ?></option>
<?php endforeach; ?>
		</select></td>
	</tr>
	<tr>
		<td>Base price</td>
		<td><input type="text" name="price" size="6" maxlength="8" class="text" value="<?php echo esc_html($_POST['price']); ?>" /></td>
	</tr>
	<tr>
		<td>Recurring period</td>
		<td><select name="period">
			<option value="">Select...</option>
<?php foreach ($periods as $period): ?>
			<option<?php if ($period == $_POST['period']) echo ' selected="selected"'; ?>><?php echo esc_html($period); ?></option>
<?php endforeach; ?>
		</select></td>
	</tr>
	<tr>
		<td>Return url</td>
		<td><input type="text" name="return" size="80" maxlength="100" class="text" value="<?php echo esc_html($_POST['return']); ?>" /></td>
	</tr>
	<tr>
		<td>Coupon code</td>
		<td><input type="text" name="coupon" size="40" maxlength="50" class="text" value="<?php echo esc_html($_POST['coupon']); ?>" /></td>
	</tr>
	<tr>
		<td>Coupon discount</td>
		<td><input type="text" name="coupon_discount" size="2" maxlength="3" class="text" value="<?php echo esc_html($_POST['coupon_discount']); ?>" />%</td>
	</tr>
	<tr>
		<td>&nbsp;</td>
		<td><span class="button-border"><input type="submit" class="button-primary" value="Submit" /></span></td>
	</tr>
	</table>
	</form>


<?php elseif ($display == 'add-field'): ?>



	<h3>Add Field</h3>
	<form method="post" action="">
	<table border="0" cellpadding="0" cellspacing="0" class="form-table">
	<tr>
		<td>Type</td>
		<td><input type="text" disabled="disabled" size="80" maxlength="100" class="text" value="<?php echo esc_html($field_type); ?>" /></td>
	</tr>
	<tr>
		<td>Name</td>
		<td><input type="text" name="name" size="80" maxlength="100" class="text" value="<?php echo esc_html($_POST['name']); ?>" /></td>
	</tr>
	<tr>
		<td>Position</td>
		<td><select name="position">
			<option value="">Select...</option>
<?php	if (count($form['fields'])): $position = count($form['fields'])+1; ?>
			<option value="<?php echo esc_html($position-1); ?>" <?php if ($position-1 == $_POST['position']) echo ' selected="selected"'; ?>>At the end</option>
<?php 	foreach ($form['fields'] as $x=>$field): $position = $x + 1; ?>
			<option value="<?php echo esc_html($position-1); ?>"<?php if ($position-1 == $_POST['position'] && $position != count($form['fields'])+1) echo ' selected="selected"'; ?>>After <?php echo esc_html($field['name']); ?></option>
<?php 	endforeach; ?>
<?php endif; ?>
			<option value="0" <?php if (0 == $_POST['position']) echo ' selected="selected"'; ?>>At the beginning</option>
		</select></td>
	</tr>
	<tr>
		<td>Mandatory</td>
		<td>
			<label><input type="radio" name="mandatory" value="yes" <?php if ($_POST['mandatory'] == 'yes') echo ' checked="checked"'; ?>/> yes</label> &nbsp;
			<label><input type="radio" name="mandatory" value="no" <?php if ($_POST['mandatory'] == 'no') echo ' checked="checked"'; ?>/> no</label>
		</td>
	</tr>
<?php	if ($field_type == 'text'): ?>
	<tr>
		<td>Validation</td>
		<td><select name="validation">
			<option value="">Select...</option>
<?php foreach ($validations as $validation): ?>
			<option<?php if ($validation == $_POST['validation']) echo ' selected="selected"'; ?>><?php echo esc_html($validation); ?></option>
<?php endforeach; ?>
		</select></td>
	</tr>
<?php endif; ?>

	<tr>
		<td>&nbsp;</td>
		<td><span class="button-border"><input type="submit" class="button-primary" value="Submit" /></span></td>
	</tr>
	</table>

<?php	if ($field_type == 'select' || $field_type == 'radio'): ?>
	<h3>Options</h3>
	<table border="0" cellpadding="0" cellspacing="0">
		<tr>
			<td>&nbsp;</td>
			<td>&nbsp;&nbsp;</td>
			<td>Value (optional)</td>
			<td>&nbsp;</td>
			<td>Label</td>
			<td>&nbsp;</td>
			<td>Price (optional)</td>
			<td>&nbsp;</td>
			<td>Default option</td>
		</tr>
<?php		for ($x = 1; $x <= 50; $x++): ?>
		<tr>
			<td><?php echo esc_html($x); ?></td>
			<td>&nbsp;</td>
			<td><input type="text" name="option_<?php echo esc_html($x); ?>_value" size="20" maxlength="100" class="text" value="<?php echo esc_html($_POST['option_'.$x.'_value']); ?>" /></td>
			<td>&nbsp;</td>
			<td><input type="text" name="option_<?php echo esc_html($x); ?>_label" size="76" maxlength="100" class="text" value="<?php echo esc_html($_POST['option_'.$x.'_label']); ?>" /></td>
			<td>&nbsp;</td>
			<td><input type="text" name="option_<?php echo esc_html($x); ?>_price" size="6" maxlength="8" class="text" value="<?php echo esc_html($_POST['option_'.$x.'_price']); ?>" /></td>
			<td>&nbsp;</td>
			<td><input type="radio" name="default_option" value="<?php echo esc_html($x); ?>"<?php if ($x == $_POST['default_option']) echo ' checked="checked"'; ?> /></td>
		</tr>
<?php		endfor; ?>
	</table>

<?php endif; ?>

	</form>



<?php elseif ($display == 'edit-field'): ?>



	<h3>Add Field</h3>
	<form method="post" action="">
	<table border="0" cellpadding="0" cellspacing="0" class="form-table">
	<tr>
		<td>Type</td>
		<td><input type="text" disabled="disabled" size="80" maxlength="100" class="text" value="<?php echo esc_html($field_type); ?>" /></td>
	</tr>
	<tr>
		<td>Name</td>
		<td><input type="text" name="name" size="80" maxlength="100" class="text" value="<?php echo esc_html($_POST['name']); ?>" /></td>
	</tr>
	<tr>
		<td>Position</td>
		<td><select name="position">
			<option value="">Select...</option>
<?php	if (count($form['fields'])): $position = count($form['fields'])+1; ?>
			<option value="<?php echo esc_html($position-1); ?>" <?php if ($position-1 == $_POST['position']) echo ' selected="selected"'; ?>>At the end</option>
<?php 	foreach ($form['fields'] as $x=>$field): $position = $x + 1; ?>
<?php			if ($x == $field_id): ?>
			<option value="<?php echo esc_html($position-1); ?>"<?php if ($position-1 == $_POST['position']) echo ' selected="selected"'; ?>>No change</option>
<?php			else: ?>
			<option value="<?php echo esc_html($position-1); ?>"<?php if ($position-1 == $_POST['position']) echo ' selected="selected"'; ?>>After <?php echo esc_html($field['name']); ?></option>
<?php			endif; ?>
<?php 	endforeach; ?>
<?php endif; ?>
			<option value="0" <?php if (0 == $_POST['position']) echo ' selected="selected"'; ?>>At the beginning</option>
		</select></td>
	</tr>
	<tr>
		<td>Mandatory</td>
		<td>
			<label><input type="radio" name="mandatory" value="yes" <?php if ($_POST['mandatory'] == 'yes') echo ' checked="checked"'; ?>/> yes</label> &nbsp;
			<label><input type="radio" name="mandatory" value="no" <?php if ($_POST['mandatory'] == 'no') echo ' checked="checked"'; ?>/> no</label>
		</td>
	</tr>
<?php	if ($field_type == 'text'): ?>
	<tr>
		<td>Validation</td>
		<td><select name="validation">
			<option value="">Select...</option>
<?php foreach ($validations as $validation): ?>
			<option<?php if ($validation == $_POST['validation']) echo ' selected="selected"'; ?>><?php echo esc_html($validation); ?></option>
<?php endforeach; ?>
		</select></td>
	</tr>
<?php endif; ?>

	<tr>
		<td>&nbsp;</td>
		<td><span class="button-border"><input type="submit" class="button-primary" value="Submit" /></span></td>
	</tr>
	</table>

<?php	if ($field_type == 'select' || $field_type == 'radio'): ?>
	<h3>Options</h3>
	<table border="0" cellpadding="0" cellspacing="0">
		<tr>
			<td>&nbsp;</td>
			<td>&nbsp;&nbsp;</td>
			<td>Value (optional)</td>
			<td>&nbsp;</td>
			<td>Label</td>
			<td>&nbsp;</td>
			<td>Price (optional)</td>
			<td>&nbsp;</td>
			<td>Default option</td>
		</tr>
<?php		for ($x = 1; $x <= 50; $x++): ?>
		<tr>
			<td><?php echo esc_html($x); ?></td>
			<td>&nbsp;</td>
			<td><input type="text" name="option_<?php echo esc_html($x); ?>_value" size="20" maxlength="100" class="text" value="<?php echo esc_html($_POST['option_'.$x.'_value']); ?>" /></td>
			<td>&nbsp;</td>
			<td><input type="text" name="option_<?php echo esc_html($x); ?>_label" size="76" maxlength="100" class="text" value="<?php echo esc_html($_POST['option_'.$x.'_label']); ?>" /></td>
			<td>&nbsp;</td>
			<td><input type="text" name="option_<?php echo esc_html($x); ?>_price" size="6" maxlength="8" class="text" value="<?php echo esc_html($_POST['option_'.$x.'_price']); ?>" /></td>
			<td>&nbsp;</td>
			<td><input type="radio" name="default_option" value="<?php echo esc_html($x); ?>"<?php if ($x == $_POST['default_option']) echo ' checked="checked"'; ?> /></td>
		</tr>
<?php		endfor; ?>
	</table>

<?php endif; ?>

	</form>


<?php endif; ?>

	

</div>
<?php
}