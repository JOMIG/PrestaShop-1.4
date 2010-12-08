<?php
/*
* Copyright (C) 2007-2010 PrestaShop 
*
* NOTICE OF LICENSE
*
* This source file is subject to the Open Software License (OSL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/osl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author Prestashop SA <contact@prestashop.com>
*  @copyright  Copyright (c) 2007-2010 Prestashop SA : 6 rue lacepede, 75005 PARIS
*  @version  Release: $Revision: 1.4 $
*  @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
*  International Registred Trademark & Property of PrestaShop SA
*/

include(dirname(__FILE__).'/../../config/config.inc.php');
include(dirname(__FILE__).'/../../init.php');
include(dirname(__FILE__).'/tm4b.php');
include(dirname(__FILE__).'/classes/Tm4bSms.php');

if (!Configuration::get('TM4B_DAILY_REPORT_ACTIVE'))
	die ('Daily report not active');
if (Configuration::get('TM4B_LAST_REPORT') == date('Y-m-d'))
	die ('Report already sent');
Configuration::updateValue('TM4B_LAST_REPORT', date('Y-m-d'));
$module = new Tm4b();
$sms = new Tm4bSms(Configuration::get('TM4B_USER'), Configuration::get('TM4B_PASSWORD'), Configuration::get('TM4B_ROUTE'));
$sms->msg = $module->getStatsBody();
$numbers = explode(',', Configuration::get('TM4B_NEW_ORDER_NUMBERS'));
foreach ($numbers as $number)
	if ($number != '')
		$sms->addRecipient($number);
$sms->Send(Configuration::get('TM4B_SIM'));
die ('OK');

