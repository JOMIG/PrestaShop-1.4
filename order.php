<?php

/* SSL Management */
$useSSL = true;

require_once(dirname(__FILE__).'/config/config.inc.php');
/* Step number is needed on some modules */
$step = intval(Tools::getValue('step'));
require_once(dirname(__FILE__).'/init.php');

if (Configuration::get('PS_ORDER_PROCESS_TYPE') == 1)
	Tools::redirect('order-opc.php');

/* Disable some cache related bugs on the cart/order */
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');

$errors = array();

/* Class FreeOrder to use PaymentModule (abstract class, cannot be instancied) */
class	FreeOrder extends PaymentModule {}

global $currency;

/* If some products have disappear */
if (!$cart->checkQuantities())
{
	$step = 0;
	$errors[] = Tools::displayError('An item in your cart is no longer available, you cannot proceed with your order');
}

/* Check minimal account */
$orderTotal = $cart->getOrderTotal();

$orderTotalDefaultCurrency = Tools::convertPrice($cart->getOrderTotal(true, 1), Currency::getCurrency(intval(Configuration::get('PS_CURRENCY_DEFAULT'))));
$minimalPurchase = floatval(Configuration::get('PS_PURCHASE_MINIMUM'));
if ($orderTotalDefaultCurrency < $minimalPurchase)
{
	$step = 0;
	$errors[] = Tools::displayError('A minimum purchase total of').' '.Tools::displayPrice($minimalPurchase, Currency::getCurrency(intval($cart->id_currency))).
	' '.Tools::displayError('is required in order to validate your order');
}

if (!$cookie->isLogged() AND in_array($step, array(1, 2, 3)))
	Tools::redirect('authentication.php?back=order.php?step='.$step);

$smarty->assign('back', Tools::safeOutput(Tools::getValue('back')));

if ($cart->nbProducts())
{
	/* Manage discounts */
	if ((Tools::isSubmit('submitDiscount') OR Tools::isSubmit('submitDiscount')) AND Tools::getValue('discount_name'))
	{
		$discountName = Tools::getValue('discount_name');
		if (!Validate::isDiscountName($discountName))
			$errors[] = Tools::displayError('voucher name not valid');
		else
		{
			$discount = new Discount(intval(Discount::getIdByName($discountName)));
			if (Validate::isLoadedObject($discount))
			{
				if ($tmpError = $cart->checkDiscountValidity($discount, $cart->getDiscounts(), $cart->getOrderTotal(), $cart->getProducts(), true))
					$errors[] = $tmpError;
			}
			else
				$errors[] = Tools::displayError('voucher name not valid');
			if (!sizeof($errors))
			{
				$cart->addDiscount(intval($discount->id));
				Tools::redirect('order.php');
			}
		}
		$smarty->assign(array(
			'errors' => $errors,
			'discount_name' => Tools::safeOutput($discountName)
		));
	}
	elseif (isset($_GET['deleteDiscount']) AND Validate::isUnsignedId($_GET['deleteDiscount']))
	{
		$cart->deleteDiscount(intval($_GET['deleteDiscount']));
		Tools::redirect('order.php');
	}

	/* Is there only virtual product in cart */
	if ($isVirtualCart = $cart->isVirtualCart())
		setNoCarrier();
	$smarty->assign('virtual_cart', $isVirtualCart);

	/* 4 steps to the order */
	switch (intval($step))
	{
		case 1:
			displayAddress();
			break;
		case 2:
			if(Tools::isSubmit('processAddress'))
				processAddress();
			autoStep(2);
			displayCarrier();
			break;
		case 3:
			if(Tools::isSubmit('processCarrier'))
				processCarrier();
			autoStep(3);
			checkFreeOrder();
			displayPayment();
			break;
		default:
			$smarty->assign('errors', $errors);
			if (file_exists(_PS_SHIP_IMG_DIR_.intval($cart->id_carrier).'.jpg'))
				$smarty->assign('carrierPicture', 1);
			$summary = $cart->getSummaryDetails();
			$customizedDatas = Product::getAllCustomizedDatas(intval($cart->id));
			Product::addCustomizationPrice($summary['products'], $customizedDatas);

			if ($free_ship = Tools::convertPrice(floatval(Configuration::get('PS_SHIPPING_FREE_PRICE')), new Currency(intval($cart->id_currency))))
			{
				$discounts = $cart->getDiscounts();
				$total_free_ship =  $free_ship - ($summary['total_products_wt'] + $summary['total_discounts']);
				foreach ($discounts as $discount)
					if ($discount['id_discount_type'] == 3)
					{
						$total_free_ship = 0;
						break;
					}
				$smarty->assign('free_ship', $total_free_ship);
			}
			// for compatibility with 1.2 themes
			foreach($summary['products'] AS $key => $product)
				$summary['products'][$key]['quantity'] = $product['cart_quantity'];
			$smarty->assign($summary);
			$token = Tools::getToken(false);
			$smarty->assign(array(
				'token_cart' => $token,
				'isVirtualCart' => $cart->isVirtualCart(),
				'productNumber' => $cart->nbProducts(),
				'voucherAllowed' => Configuration::get('PS_VOUCHERS'),
				'HOOK_SHOPPING_CART' => Module::hookExec('shoppingCart', $summary),
				'HOOK_SHOPPING_CART_EXTRA' => Module::hookExec('shoppingCartExtra', $summary),
				'shippingCost' => $cart->getOrderTotal(true, 5),
				'shippingCostTaxExc' => $cart->getOrderTotal(false, 5),
				'customizedDatas' => $customizedDatas,
				'CUSTOMIZE_FILE' => _CUSTOMIZE_FILE_,
				'CUSTOMIZE_TEXTFIELD' => _CUSTOMIZE_TEXTFIELD_,
				'lastProductAdded' => $cart->getLastProduct(),
				'displayVouchers' => Discount::getVouchersToCartDisplay(intval($cookie->id_lang)),
				'currencySign' => $currency->sign,
				'currencyRate' => $currency->conversion_rate,
				'currencyFormat' => $currency->format,
				'currencyBlank' => $currency->blank
				));
			Tools::safePostVars();
			Tools::addCSS(_THEME_CSS_DIR_.'addresses.css');
			Tools::addJS(_THEME_JS_DIR_.'tools.js');
			Tools::addJS(_THEME_JS_DIR_.'cart-summary.js');
			require_once(dirname(__FILE__).'/header.php');
			$smarty->display(_PS_THEME_DIR_.'shopping-cart.tpl');
			break;
	}
}
else
{
	/* Default page */
	Tools::addCSS(_THEME_CSS_DIR_.'addresses.css');
	$smarty->assign('empty', 1);
	Tools::safePostVars();
	require_once(dirname(__FILE__).'/header.php');
	$smarty->display(_PS_THEME_DIR_.'shopping-cart.tpl');
}

include(dirname(__FILE__).'/footer.php');

/* Order process controller */
function autoStep($step)
{
	global $cart, $isVirtualCart;

	if ($step >= 2 AND (!$cart->id_address_delivery OR !$cart->id_address_invoice))
		Tools::redirect('order.php?step=1');
	$delivery = new Address(intval($cart->id_address_delivery));
	$invoice = new Address(intval($cart->id_address_invoice));
	if ($delivery->deleted OR $invoice->deleted)
	{
		if ($delivery->deleted)
			unset($cart->id_address_delivery);
		if ($invoice->deleted)
			unset($cart->id_address_invoice);
		Tools::redirect('order.php?step=1');
	}
	elseif ($step >= 3 AND !$cart->id_carrier AND !$isVirtualCart)
		Tools::redirect('order.php?step=2');
}

/* Bypass payment step if total is 0 */
function checkFreeOrder()
{
	global $cart;

	if ($cart->getOrderTotal() <= 0)
	{
		$order = new FreeOrder();
		$order->validateOrder(intval($cart->id), _PS_OS_PAYMENT_, 0, Tools::displayError('Free order', false));
		Tools::redirect('history.php');
	}
}

/**
 * Set id_carrier to 0 (no shipping price)
 *
 */
function setNoCarrier()
{
	global $cart;
	$cart->id_carrier = 0;
	$cart->update();
}

/*
 * Manage address
 */
function processAddress()
{
	global $cart, $smarty, $css_files, $js_files;
	$errors = array();

	if (!isset($_POST['id_address_delivery']) OR !Address::isCountryActiveById(intval($_POST['id_address_delivery'])))
		$errors[] = Tools::displayError('this address is not in a valid area');
	else
	{
		$cart->id_address_delivery = intval(Tools::getValue('id_address_delivery'));
		$cart->id_address_invoice = Tools::isSubmit('same') ? $cart->id_address_delivery : intval(Tools::getValue('id_address_invoice'));
		if (!$cart->update())
			$errors[] = Tools::displayError('an error occured while updating your cart');

		if (Tools::isSubmit('message') AND !empty($_POST['message']))
		{
			if (!Validate::isMessage($_POST['message']))
				$errors[] = Tools::displayError('invalid message');
			elseif ($oldMessage = Message::getMessageByCartId(intval($cart->id)))
			{
				$message = new Message(intval($oldMessage['id_message']));
				$message->message = htmlentities($_POST['message'], ENT_COMPAT, 'UTF-8');
				$message->update();
			}
			else
			{
				$message = new Message();
				$message->message = htmlentities($_POST['message'], ENT_COMPAT, 'UTF-8');
				$message->id_cart = intval($cart->id);
				$message->id_customer = intval($cart->id_customer);
				$message->add();
			}
		}
	}
	if (sizeof($errors))
	{
		if (Tools::getValue('ajax'))
			die('{\'hasError\' : true, errors : [\''.implode('\',\'', $errors).'\']}');
		$smarty->assign('errors', $errors);
		displayAddress();
		require_once(dirname(__FILE__).'/footer.php');
		exit;
	}
	if (Tools::getValue('ajax'))
		die(true);
}

/* Carrier step */
function processCarrier()
{
	global $cart, $smarty, $isVirtualCart, $orderTotal, $css_files, $js_files;

	$errors = array();

	$cart->recyclable = (isset($_POST['recyclable']) AND !empty($_POST['recyclable'])) ? 1 : 0;

	if (isset($_POST['gift']) AND !empty($_POST['gift']))
	{
	 	if (!Validate::isMessage($_POST['gift_message']))
			$errors[] = Tools::displayError('invalid gift message');
		else
		{
			$cart->gift = 1;
			$cart->gift_message = strip_tags($_POST['gift_message']);
		}
	}
	else
		$cart->gift = 0;

	$address = new Address(intval($cart->id_address_delivery));
	if (!Validate::isLoadedObject($address))
		die(Tools::displayError());
	if (!$id_zone = Address::getZoneById($address->id))
		$errors[] = Tools::displayError('no zone match with your address');
	if (isset($_POST['id_carrier']) AND Validate::isInt($_POST['id_carrier']) AND sizeof(Carrier::checkCarrierZone(intval($_POST['id_carrier']), intval($id_zone))))
		$cart->id_carrier = intval($_POST['id_carrier']);
	elseif (!$isVirtualCart)
		$errors[] = Tools::displayError('invalid carrier or no carrier selected');

	Module::hookExec('ProcessCarrier', array('cart' => $cart));

	$cart->update();

	if (sizeof($errors))
	{
		$smarty->assign('errors', $errors);
		displayCarrier();
		include(dirname(__FILE__).'/footer.php');
		exit;
	}
	$orderTotal = $cart->getOrderTotal();
}

/* Address step */
function displayAddress()
{
	global $smarty, $cookie, $cart, $css_files, $js_files;
	Tools::addJS(_THEME_JS_DIR_.'order-address.js');
	Tools::addCSS(_THEME_CSS_DIR_.'addresses.css');

	if (!Customer::getAddressesTotalById(intval($cookie->id_customer)))
		Tools::redirect('address.php?back=order.php?step=1');
	$customer = new Customer(intval($cookie->id_customer));
	if (Validate::isLoadedObject($customer))
	{
		/* Getting customer addresses */
		$customerAddresses = $customer->getAddresses(intval($cookie->id_lang));
		$smarty->assign('addresses', $customerAddresses);

		/* Setting default addresses for cart */
		if ((!isset($cart->id_address_delivery) OR empty($cart->id_address_delivery)) AND sizeof($customerAddresses))
		{
			$cart->id_address_delivery = intval($customerAddresses[0]['id_address']);
			$update = 1;
		}
		if ((!isset($cart->id_address_invoice) OR empty($cart->id_address_invoice)) AND sizeof($customerAddresses))
		{
			$cart->id_address_invoice = intval($customerAddresses[0]['id_address']);
			$update = 1;
		}
		/* Update cart addresses only if needed */
		if (isset($update) AND $update)
			$cart->update();

		/* If delivery address is valid in cart, assign it to Smarty */
		if (isset($cart->id_address_delivery))
		{
			$deliveryAddress = new Address(intval($cart->id_address_delivery));
			if (Validate::isLoadedObject($deliveryAddress) AND ($deliveryAddress->id_customer == $customer->id))
				$smarty->assign('delivery', $deliveryAddress);
		}

		/* If invoice address is valid in cart, assign it to Smarty */
		if (isset($cart->id_address_invoice))
		{
			$invoiceAddress = new Address(intval($cart->id_address_invoice));
			if (Validate::isLoadedObject($invoiceAddress) AND ($invoiceAddress->id_customer == $customer->id))
				$smarty->assign('invoice', $invoiceAddress);
		}
	}
	if ($oldMessage = Message::getMessageByCartId(intval($cart->id)))
		$smarty->assign('oldMessage', $oldMessage['message']);
	$smarty->assign('cart', $cart);

	Tools::safePostVars();
	require_once(dirname(__FILE__).'/header.php');
	$smarty->display(_PS_THEME_DIR_.'order-address.tpl');
}

/* Carrier step */
function displayCarrier()
{
	global $smarty, $cart, $cookie, $defaultCountry, $link, $css_files, $js_files;

	$address = new Address(intval($cart->id_address_delivery));
	$id_zone = Address::getZoneById(intval($address->id));
	if (isset($cookie->id_customer))
		$customer = new Customer(intval($cookie->id_customer));
	else
		die(Tools::displayError('Fatal error: No customer'));
	$result = Carrier::getCarriers(intval($cookie->id_lang), true, false, intval($id_zone), $customer->getGroups());
	if (!$result)
		$result = Carrier::getCarriers(intval($cookie->id_lang), true, false, intval($id_zone));
	$resultsArray = array();
	foreach ($result AS $k => $row)
	{
		$carrier = new Carrier(intval($row['id_carrier']));

		// Get only carriers that are compliant with shipping method
		if (($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_WEIGHT AND $carrier->getMaxDeliveryPriceByWeight($id_zone) === false)
		OR ($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_PRICE AND $carrier->getMaxDeliveryPriceByPrice($id_zone) === false))
		{
			unset($result[$k]);
			continue ;
		}
		
		// If out-of-range behavior carrier is set on "Desactivate carrier"
		if ($row['range_behavior'])
		{
			// Get id zone
	        if (isset($cart->id_address_delivery) AND $cart->id_address_delivery)
				$id_zone = Address::getZoneById(intval($cart->id_address_delivery));
			else
				$id_zone = intval($defaultCountry->id_zone);

			// Get only carriers that have a range compatible with cart
			if (($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_WEIGHT AND (!Carrier::checkDeliveryPriceByWeight($row['id_carrier'], $cart->getTotalWeight(), $id_zone)))
			OR ($carrier->getShippingMethod() == Carrier::SHIPPING_METHOD_PRICE AND (!Carrier::checkDeliveryPriceByPrice($row['id_carrier'], $cart->getOrderTotal(true, 4), $id_zone))))
				{
					unset($result[$k]);
					continue ;
				}
		}
		$row['name'] = (strval($row['name']) != '0' ? $row['name'] : Configuration::get('PS_SHOP_NAME'));
		$row['price'] = $cart->getOrderShippingCost(intval($row['id_carrier']));
		$row['price_tax_exc'] = $cart->getOrderShippingCost(intval($row['id_carrier']), false);
		$row['img'] = file_exists(_PS_SHIP_IMG_DIR_.intval($row['id_carrier']).'.jpg') ? _THEME_SHIP_DIR_.intval($row['id_carrier']).'.jpg' : '';
		$resultsArray[] = $row;
	}

	// Wrapping fees
	$wrapping_fees = floatval(Configuration::get('PS_GIFT_WRAPPING_PRICE'));
	$wrapping_fees_tax = new Tax(intval(Configuration::get('PS_GIFT_WRAPPING_TAX')));
	$wrapping_fees_tax_inc = $wrapping_fees * (1 + ((floatval($wrapping_fees_tax->rate) / 100)));

	if (Validate::isUnsignedInt($cart->id_carrier) AND $cart->id_carrier)
	{
		$carrier = new Carrier(intval($cart->id_carrier));
		if ($carrier->active AND !$carrier->deleted)
			$checked = intval($cart->id_carrier);
	}
	$cms = new CMS(intval(Configuration::get('PS_CONDITIONS_CMS_ID')), intval($cookie->id_lang));
	$link_conditions = $link->getCMSLink($cms, $cms->link_rewrite, true);
	if (!strpos($link_conditions, '?'))
		$link_conditions .= '?content_only=1&TB_iframe=true&width=450&height=500&thickbox=true';
	else
		$link_conditions .= '&content_only=1&TB_iframe=true&width=450&height=500&thickbox=true';
	if (!isset($checked) OR intval($checked) == 0)
		$checked = intval(Configuration::get('PS_CARRIER_DEFAULT'));
	$smarty->assign(array(
		'checkedTOS' => intval($cookie->checkedTOS),
		'recyclablePackAllowed' => intval(Configuration::get('PS_RECYCLABLE_PACK')),
		'giftAllowed' => intval(Configuration::get('PS_GIFT_WRAPPING')),
		'cms_id' => intval(Configuration::get('PS_CONDITIONS_CMS_ID')),
		'conditions' => intval(Configuration::get('PS_CONDITIONS')),
		'link_conditions' => $link_conditions,
		'recyclable' => intval($cart->recyclable),
		'gift_wrapping_price' => floatval(Configuration::get('PS_GIFT_WRAPPING_PRICE')),
		'carriers' => $resultsArray,
		'default_carrier' => intval(Configuration::get('PS_CARRIER_DEFAULT')),
		'HOOK_EXTRACARRIER' => Module::hookExec('extraCarrier', array('address' => $address)),
		'HOOK_BEFORECARRIER' => Module::hookExec('beforeCarrier', array('carriers' => $resultsArray)),
		'checked' => intval($checked),
		'total_wrapping' => Tools::convertPrice($wrapping_fees_tax_inc, new Currency(intval($cookie->id_currency))),
		'total_wrapping_tax_exc' => Tools::convertPrice($wrapping_fees, new Currency(intval($cookie->id_currency)))));
	Tools::safePostVars();
	Tools::addCSS(_PS_CSS_DIR_.'thickbox.css', 'all');
	Tools::addJS(_PS_JS_DIR_.'jquery/thickbox-modified.js');
	require_once(dirname(__FILE__).'/header.php');
	$smarty->display(_PS_THEME_DIR_.'order-carrier.tpl');
}

/* Payment step */
function displayPayment()
{
	global $smarty, $cart, $currency, $cookie, $orderTotal, $css_files, $js_files;

	// Redirect instead of displaying payment modules if any module are grefted on
	Hook::backBeforePayment(strval(Tools::getValue('back')));

	/* We may need to display an order summary */
	$smarty->assign($cart->getSummaryDetails());

	$cookie->checkedTOS = '1';
	$smarty->assign(array(
		'HOOK_PAYMENT' => Module::hookExecPayment(), 
		'total_price' => floatval($orderTotal),
		'taxes_enabled' => intval(Configuration::get('PS_TAX'))
	));

	Tools::safePostVars();
	require_once(dirname(__FILE__).'/header.php');
	$smarty->display(_PS_THEME_DIR_.'order-payment.tpl');
}

?>
