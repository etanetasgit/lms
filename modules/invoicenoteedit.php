<?php

/*
 * LMS version 1.11-git
 *
 *  (C) Copyright 2001-2019 LMS Developers
 *
 *  Please, see the doc/AUTHORS for more information about authors!
 *
 *  This program is free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License Version 2 as
 *  published by the Free Software Foundation.
 *
 *  This program is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with this program; if not, write to the Free Software
 *  Foundation, Inc., 59 Temple Place - Suite 330, Boston, MA 02111-1307,
 *  USA.
 *
 *  $Id$
 */

include(MODULES_DIR . DIRECTORY_SEPARATOR . 'invoiceajax.inc.php');

$taxeslist = $LMS->GetTaxes();
$action = isset($_GET['action']) ? $_GET['action'] : '';

if (isset($_GET['id']) && $action == 'edit') {
    if ($LMS->isDocumentPublished($_GET['id']) && !ConfigHelper::checkPrivilege('published_document_modification')) {
        return;
    }

    if ($LMS->isDocumentReferenced($_GET['id'])) {
        return;
    }

    if ($LMS->isArchiveDocument($_GET['id'])) {
        return;
    }

    $cnote = $LMS->GetInvoiceContent($_GET['id']);

    if (!empty($cnote['cancelled'])) {
        return;
    }

    $invoice = array();
    foreach ($cnote['invoice']['content'] as $item) {
        $invoice[$item['itemid']] = $item;
    }
    $cnote['invoice']['content'] = $invoice;

    $SESSION->remove('cnotecontents');
    $SESSION->remove('cnote');
    $SESSION->remove('cnoteediterror');

    $cnotecontents = array();
    foreach ($cnote['content'] as $item) {
        $deleted = $item['value'] == 0;
        $nitem['deleted'] = $deleted;
        $nitem['tariffid']  = $item['tariffid'];
        $nitem['name']      = $item['description'];
        $nitem['prodid']    = $item['prodid'];
        if ($deleted) {
            $iitem = $invoice[$item['itemid']];
            $nitem['count'] = $iitem['count'];
            $nitem['discount']  = $iitem['discount'];
            $nitem['pdiscount'] = $iitem['pdiscount'];
            $nitem['vdiscount'] = $iitem['vdiscount'];
            $nitem['content']       = $iitem['content'];
            $nitem['valuenetto']    = $iitem['basevalue'];
            $nitem['valuebrutto']   = $iitem['value'];
            $nitem['s_valuenetto']  = $iitem['totalbase'];
            $nitem['s_valuebrutto'] = $iitem['total'];
        } else {
            $nitem['count']     = str_replace(',', '.', $item['count']);
            $nitem['discount']  = str_replace(',', '.', $item['pdiscount']);
            $nitem['pdiscount'] = str_replace(',', '.', $item['pdiscount']);
            $nitem['vdiscount'] = str_replace(',', '.', $item['vdiscount']);
            $nitem['content']       = str_replace(',', '.', $item['content']);
            $nitem['valuenetto']    = str_replace(',', '.', $item['basevalue']);
            $nitem['valuebrutto']   = str_replace(',', '.', $item['value']);
            $nitem['s_valuenetto']  = str_replace(',', '.', $item['totalbase']);
            $nitem['s_valuebrutto'] = str_replace(',', '.', $item['total']);
        }
        $nitem['tax']       = isset($taxeslist[$item['taxid']]) ? $taxeslist[$item['taxid']]['label'] : '';
        $nitem['taxid']     = $item['taxid'];
        $cnotecontents[$item['itemid']] = $nitem;
    }
    $SESSION->save('cnotecontents', $cnotecontents);

    $cnote['oldcdate'] = $cnote['cdate'];
    $cnote['oldsdate'] = $cnote['sdate'];
    $cnote['olddeadline'] = $cnote['deadline'] = $cnote['cdate'] + $cnote['paytime'] * 86400;
    $cnote['oldnumber'] = $cnote['number'];
    $cnote['oldnumberplanid'] = $cnote['numberplanid'];
    $cnote['oldcustomerid'] = $cnote['customerid'];
    $cnote['oldcurrency'] = $cnote['currency'];

    $hook_data = array(
        'contents' => $cnotecontents,
        'cnote' => $cnote,
    );
    $hook_data = $LMS->ExecuteHook('invoicenoteedit_init', $hook_data);
    $cnotecontents = $hook_data['contents'];
    $cnote = $hook_data['cnote'];

    $SESSION->save('cnote', $cnote);
    $SESSION->save('cnoteid', $cnote['id']);
}

$SESSION->restore('cnotecontents', $contents);
$SESSION->restore('cnote', $cnote);
$SESSION->restore('cnoteediterror', $error);
$itemdata = r_trim($_POST);

$ntempl = docnumber(array(
    'number' => $cnote['number'],
    'template' => $cnote['template'],
    'cdate' => $cnote['cdate'],
    'customerid' => $cnote['customerid'],
));
$layout['pagetitle'] = trans('Credit Note for Invoice Edit: $a', $ntempl);

switch ($action) {
    case 'deletepos':
        if ($cnote['closed']) {
            break;
        }
        $contents[$_GET['itemid']]['deleted'] = true;
        break;

    case 'recoverpos':
        if ($cnote['closed']) {
            break;
        }
        $contents[$_GET['itemid']]['deleted'] = false;
        break;

    case 'setheader':
        $oldcdate = $cnote['oldcdate'];
        $oldsdate = $cnote['oldsdate'];
        $oldnumber = $cnote['oldnumber'];
        $oldnumberplanid = $cnote['oldnumberplanid'];
        $oldcustomerid = $cnote['oldcustomerid'];
        $oldcurrency = $cnote['oldcurrency'];

        $oldcnote = $cnote;
        $cnote = null;
        $error = null;

        if ($cnote = $_POST['cnote']) {
            foreach ($cnote as $key => $val) {
                $cnote[$key] = $val;
            }
        }

        if (!isset($cnote['splitpayment'])) {
            $cnote['splitpayment'] = 0;
        }

        $cnote['oldcdate'] = $oldcdate;
        $cnote['oldsdate'] = $oldsdate;
        $cnote['oldnumber'] = $oldnumber;
        $cnote['oldnumberplanid'] = $oldnumberplanid;
        $cnote['oldcustomerid'] = $oldcustomerid;
        $cnote['oldcurrency'] = $oldcurrency;

        $invoice = $oldcnote['invoice'];

        $SESSION->restore('cnoteid', $cnote['id']);

        $currtime = time();

        if (ConfigHelper::checkPrivilege('invoice_consent_date')) {
            if ($cnote['cdate']) {
                list ($year, $month, $day) = explode('/', $cnote['cdate']);
                if (checkdate($month, $day, $year)) {
                    $cnote['cdate'] = mktime(date('G', $currtime), date('i', $currtime), date('s', $currtime), $month, $day, $year);
                    if ($cnote['cdate'] < $invoice['cdate']) {
                        $error['cdate'] = trans('Credit note date cannot be earlier than invoice date!');
                    }
                } else {
                    $error['cdate'] = trans('Incorrect date format! Using current date.');
                    $cnote['cdate'] = $currtime;
                }
            } else {
                $cnote['cdate'] = $currtime;
            }
        } else {
            $cnote['cdate'] = $currtime;
        }

        if (ConfigHelper::checkPrivilege('invoice_sale_date')) {
            if ($cnote['sdate']) {
                list ($syear, $smonth, $sday) = explode('/', $cnote['sdate']);
                if (checkdate($smonth, $sday, $syear)) {
                    $sdate = mktime(23, 59, 59, $smonth, $sday, $syear);
                    $cnote['sdate'] = mktime(date('G', $currtime), date('i', $currtime), date('s', $currtime), $smonth, $sday, $syear);
                    if ($sdate < $invoice['sdate']) {
                        $error['sdate'] = trans('Credit note sale date cannot be earlier than invoice sale date!');
                    }
                } else {
                    $error['sdate'] = trans('Incorrect date format! Using current date.');
                    $cnote['sdate'] = $currtime;
                }
            } else {
                $cnote['sdate'] = $currtime;
            }
        } else {
            $cnote['sdate'] = $cnote['oldsdate'];
        }

        if ($cnote['deadline']) {
            list ($dyear, $dmonth, $dday) = explode('/', $cnote['deadline']);
            if (checkdate($dmonth, $dday, $dyear)) {
                $cnote['deadline'] = mktime(date('G', $currtime), date('i', $currtime), date('s', $currtime), $dmonth, $dday, $dyear);
            } else {
                $error['deadline'] = trans('Incorrect date format!');
                $cnote['deadline'] = $currtime;
                break;
            }
        } else {
            $cnote['deadline'] = $currtime;
        }

        if ($cnote['deadline'] < $cnote['cdate']) {
            $error['deadline'] = trans('Deadline date should be later than consent date!');
        }

        if ($cnote['number']) {
            if (!preg_match('/^[0-9]+$/', $cnote['number'])) {
                $error['number'] = trans('Credit note number must be integer!');
            } elseif (($cnote['oldcdate'] != $cnote['cdate'] || $cnote['oldnumber'] != $cnote['number']
                    || ($cnote['oldnumber'] == $cnote['number'] && $cnote['oldcustomerid'] != $cnote['customerid'])
                    || $cnote['oldnumberplanid'] != $cnote['numberplanid']) && ($docid = $LMS->DocumentExists(array(
                    'number' => $cnote['number'],
                    'doctype' => DOC_CNOTE,
                    'planid' => $cnote['numberplanid'],
                    'cdate' => $cnote['cdate'],
                    'customerid' => $cnote['customerid'],
                    ))) > 0 && $docid != $cnote['id']) {
                $error['number'] = trans('Credit note number $a already exists!', $cnote['number']);
            }
        }

        $cnote = array_merge($oldcnote, $cnote);
        break;

    case 'save':
        if (empty($contents)) {
            break;
        }

        $error = array();

        $SESSION->restore('cnoteid', $cnote['id']);
        $cnote['type'] = DOC_CNOTE;

        $currtime = time();

        if (ConfigHelper::checkPrivilege('invoice_consent_date')) {
            $cdate = $cnote['cdate'] ? $cnote['cdate'] : $currtime;
        } else {
            $cdate = $cnote['oldcdate'];
        }

        if (ConfigHelper::checkPrivilege('invoice_sale_date')) {
            $sdate = $cnote['sdate'] ? $cnote['sdate'] : $currtime;
        } else {
            $sdate = $cnote['oldsdate'];
        }

        $cnote['currency'] = $cnote['oldcurrency'];

        $deadline = $cnote['deadline'] ? $cnote['deadline'] : $currtime;
        $paytime = $cnote['paytime'] = round(($cnote['deadline'] - $cnote['cdate']) / 86400);
        $iid   = $cnote['id'];

        $invoicecontents = $cnote['invoice']['content'];
        $cnotecontents = $cnote['content'];
        $newcontents = r_trim($_POST);

        foreach ($contents as $idx => $item) {
            $contents[$idx]['taxid'] = isset($newcontents['taxid'][$idx]) ? $newcontents['taxid'][$idx] : $item['taxid'];
            $contents[$idx]['prodid'] = isset($newcontents['prodid'][$idx]) ? $newcontents['prodid'][$idx] : $item['prodid'];
            $contents[$idx]['content'] = isset($newcontents['content'][$idx]) ? $newcontents['content'][$idx] : $item['content'];
            $contents[$idx]['count'] = isset($newcontents['count'][$idx]) ? $newcontents['count'][$idx] : $item['count'];

            $contents[$idx]['discount'] = str_replace(',', '.', isset($newcontents['discount'][$idx]) ? $newcontents['discount'][$idx] : $item['discount']);
            $contents[$idx]['pdiscount'] = 0;
            $contents[$idx]['vdiscount'] = 0;
            $contents[$idx]['discount_type'] = isset($newcontents['discount_type'][$idx]) ? $newcontents['discount_type'][$idx] : $item['discount_type'];
            if (preg_match('/^[0-9]+(\.[0-9]+)*$/', $contents[$idx]['discount'])) {
                $contents[$idx]['pdiscount'] = ($contents[$idx]['discount_type'] == DISCOUNT_PERCENTAGE ? floatval($contents[$idx]['discount']) : 0);
                $contents[$idx]['vdiscount'] = ($contents[$idx]['discount_type'] == DISCOUNT_AMOUNT ? floatval($contents[$idx]['discount']) : 0);
            }
            if ($contents[$idx]['pdiscount'] < 0 || $contents[$idx]['pdiscount'] > 99.99 || $contents[$idx]['vdiscount'] < 0) {
                $error['discount[' . $idx . ']'] = trans('Wrong discount value!');
            }

            $contents[$idx]['name'] = isset($newcontents['name'][$idx]) ? $newcontents['name'][$idx] : $item['name'];
            $contents[$idx]['tariffid'] = isset($newcontents['tariffid'][$idx]) ? $newcontents['tariffid'][$idx] : $item['tariffid'];
            $contents[$idx]['valuebrutto'] = $newcontents['valuebrutto'][$idx] != '' ? $newcontents['valuebrutto'][$idx] : $item['valuebrutto'];
            $contents[$idx]['valuenetto'] = $newcontents['valuenetto'][$idx] != '' ? $newcontents['valuenetto'][$idx] : $item['valuenetto'];
            $contents[$idx]['valuebrutto'] = f_round($contents[$idx]['valuebrutto']);
            $contents[$idx]['valuenetto'] = f_round($contents[$idx]['valuenetto']);
            $contents[$idx]['count'] = f_round($contents[$idx]['count'], 3);
            $contents[$idx]['pdiscount'] = f_round($contents[$idx]['pdiscount']);
            $contents[$idx]['vdiscount'] = f_round($contents[$idx]['vdiscount']);
            $taxvalue = $taxeslist[$contents[$idx]['taxid']]['value'];

            $contents[$idx]['old_discount_type'] = $cnotecontents[$idx]['pdiscount'] != 0 ? DISCOUNT_PERCENTAGE : DISCOUNT_AMOUNT;
            $discount_method = ConfigHelper::getConfig('invoices.credit_note_relation_to_invoice', 'first');
            //if discount was changed
            if (!(isset($item['deleted']) && $item['deleted'])
                && $contents[$idx]['valuenetto'] == $item['valuenetto'] && $contents[$idx]['valuebrutto'] == $item['valuebrutto'] && $contents[$idx]['count'] == $item['count']
                && ($newcontents['discount'][$idx] != $item['pdiscount'] || $newcontents['discount'][$idx] != $item['vdiscount'] || $contents[$idx]['discount_type'] != $contents[$idx]['old_discount_type'])) {
                if ($contents[$idx]['pdiscount'] == 0 && $contents[$idx]['vdiscount'] == 0) {
                    //when discount is removed or zeroed restore last document value
                    if ($contents[$idx]['old_discount_type'] == DISCOUNT_PERCENTAGE) {
                        $old_valuebrutto = $invoicecontents[$idx]['value'] / (1 - $invoicecontents[$idx]['pdiscount'] / 100);
                        $contents[$idx]['valuebrutto'] = f_round($old_valuebrutto - $invoicecontents[$idx]['value']);
                    } else {
                        $contents[$idx]['valuebrutto'] = f_round($invoicecontents[$idx]['vdiscount']);
                    }
                } else {
                    //when discount is changed, not removed or zeroed
                    //if discount type was changed (discount value could be changed too)
                    if ($contents[$idx]['discount_type'] != $contents[$idx]['old_discount_type']) {
                        // if document type was changed
                        if ($contents[$idx]['old_discount_type'] == DISCOUNT_PERCENTAGE) {
                            $contents[$idx]['diff_pdiscount'] = 0;
                            //change pdiscount to vdiscount
                            if ($discount_method == 'first') {
                                //calculate vdiscount as difference between current value and first document value
                                $orig_valuebrutto = isset($cnote['invoice']['invoice']['content'][$idx]['value']) ? $cnote['invoice']['invoice']['content'][$idx]['value'] : $cnote['invoice']['content'][$idx]['value'];
                                $old_valuebrutto = $invoicecontents[$idx]['value'];
                                $new_valuebrutto = f_round($orig_valuebrutto - $contents[$idx]['vdiscount']);
                                $contents[$idx]['valuebrutto'] = f_round($new_valuebrutto - $old_valuebrutto);
                            } else {
                                //calculate vdiscount as difference between current value and last document value
                                $old_valuebrutto = $invoicecontents[$idx]['valuebrutto'];
                                $contents[$idx]['diff_vdiscount'] = floatval($contents[$idx]['vdiscount']);
                                $contents[$idx]['valuebrutto'] = f_round($old_valuebrutto - $contents[$idx]['diff_vdiscount'] - $old_valuebrutto);
                            }
                        } else {
                            $contents[$idx]['diff_vdiscount'] = 0;
                            //change vdiscount to pdiscount
                            if ($discount_method == 'first') {
                                //calculate pdiscount as difference between current value and first document value
                                $old_valuebrutto = $invoicecontents[$idx]['value'] + $invoicecontents[$idx]['vdiscount'];
                                $vdiscount_to_pdiscount = ($invoicecontents[$idx]['vdiscount'] / $old_valuebrutto) * 100;
                            } else {
                                //calculate pdiscount as difference between current value and last document value
                                $old_valuebrutto = $invoicecontents[$idx]['value'];
                                $vdiscount_to_pdiscount = ($contents[$idx]['vdiscount'] / $old_valuebrutto) * 100;
                            }
                            $contents[$idx]['diff_pdiscount'] = floatval($contents[$idx]['pdiscount'] - $vdiscount_to_pdiscount);
                            $contents[$idx]['valuebrutto'] = f_round((($old_valuebrutto - $old_valuebrutto * $contents[$idx]['diff_pdiscount'] / 100) - $contents[$idx]['diff_vdiscount']) - $old_valuebrutto);
                        }
                    } else {
                        //only discount value was changed and document type was not changed
                        if ($discount_method == 'first') {
                            if ($contents[$idx]['discount_type'] == DISCOUNT_PERCENTAGE) {
                                $orig_valuebrutto = isset($cnote['invoice']['invoice']['content'][$idx]['value']) ? $cnote['invoice']['invoice']['content'][$idx]['value'] : $cnote['invoice']['content'][$idx]['value'];
                                $old_valuebrutto = $invoicecontents[$idx]['value'];
                                $new_valuebrutto = f_round($orig_valuebrutto - $orig_valuebrutto * $contents[$idx]['pdiscount'] / 100);
                                $contents[$idx]['valuebrutto'] = f_round($new_valuebrutto - $old_valuebrutto);
                            } else {
                                $contents[$idx]['diff_vdiscount'] = !empty($invoicecontents[$idx]['vdiscount']) ? floatval($contents[$idx]['vdiscount'] - $invoicecontents[$idx]['vdiscount']) : 0;
                                $contents[$idx]['valuebrutto'] = f_round($contents[$idx]['valuebrutto'] - $contents[$idx]['diff_vdiscount'] - $cnotecontents[$idx]['value']);
                            }
                        } else {
                            //difference between current value and last document value
                            $contents[$idx]['diff_pdiscount'] = !empty($invoicecontents[$idx]['pdiscount']) ? floatval($contents[$idx]['pdiscount']) : 0;
                            $contents[$idx]['diff_vdiscount'] = !empty($invoicecontents[$idx]['vdiscount']) ? floatval($contents[$idx]['vdiscount']) : 0;
                            $contents[$idx]['valuebrutto'] = f_round((($invoicecontents[$idx]['value'] - $invoicecontents[$idx]['value'] * $contents[$idx]['diff_pdiscount'] / 100) - $contents[$idx]['diff_vdiscount']) - $invoicecontents[$idx]['value']);
                        }
                    }
                }
                if (!empty($invoicecontents[$idx]['count']) && !empty($contents[$idx]['count'])) {
                    //cash value for recovered/restored invoice position
                    $contents[$idx]['cash'] = f_round(-1 * $contents[$idx]['valuebrutto'] * $contents[$idx]['count'], 2);
                } else {
                    $contents[$idx]['cash'] = 0;
                }

                $contents[$idx]['count'] = 0;
            } else { // if discount type or discount value dosen't change
                //zeroing discounts
                $contents[$idx]['pdiscount'] = 0;
                $contents[$idx]['vdiscount'] = 0;

                if ($contents[$idx]['valuenetto'] != $item['valuenetto']) {
                    $contents[$idx]['valuebrutto'] = $contents[$idx]['valuenetto'] * ($taxvalue / 100 + 1);
                } elseif (f_round($contents[$idx]['valuebrutto']) == f_round($item['valuebrutto'])) {
                    $contents[$idx]['valuebrutto'] = $item['valuebrutto'];
                }

                if ((isset($item['deleted']) && $item['deleted']) || empty($contents[$idx]['count'])) {
                    $contents[$idx]['valuebrutto'] = f_round(-1 * $invoicecontents[$idx]['value'] * $invoicecontents[$idx]['count']);
                    $contents[$idx]['cash'] = f_round($invoicecontents[$idx]['value'] * $invoicecontents[$idx]['count'], 2);
                    $contents[$idx]['count'] = f_round(-1 * $invoicecontents[$idx]['count'], 3);
                } elseif ($contents[$idx]['count'] != $item['count']
                    || $contents[$idx]['valuebrutto'] != $item['valuebrutto']) {
                    $contents[$idx]['valuebrutto'] = f_round($contents[$idx]['valuebrutto'] - $invoicecontents[$idx]['value']);
                    $contents[$idx]['count'] = f_round($contents[$idx]['count'] - $invoicecontents[$idx]['count'], 3);
                    if (empty($contents[$idx]['count'])) {
                        $contents[$idx]['cash'] = f_round(-1 * $contents[$idx]['valuebrutto'] * $invoicecontents[$idx]['count'], 2);
                    } elseif (empty($contents[$idx]['valuebrutto'])) {
                        $contents[$idx]['cash'] = f_round(-1 * $invoicecontents[$idx]['value'] * $contents[$idx]['count'], 2);
                    } else {
                        $contents[$idx]['cash'] = f_round(-1 * $invoicecontents[$idx]['value'] * $invoicecontents[$idx]['count'], 2);
                    }
                } else {
                    $contents[$idx]['cash'] = 0;
                    $contents[$idx]['valuebrutto'] = 0;
                    $contents[$idx]['count'] = 0;
                }
            }
            $contents[$idx]['cash'] = str_replace(',', '.', $contents[$idx]['cash']);
            $contents[$idx]['valuebrutto'] = str_replace(',', '.', $contents[$idx]['valuebrutto']);
            $contents[$idx]['count'] = str_replace(',', '.', $contents[$idx]['count']);
        }

        $hook_data = array(
            'contents' => $contents,
            'cnote' => $cnote,
        );
        $hook_data = $LMS->ExecuteHook('invoicenoteedit_save_validation', $hook_data);
        if (isset($hook_data['error']) && is_array($hook_data['error'])) {
            $error = array_merge($error, $hook_data['error']);
        }

        if (!empty($error)) {
            foreach ($contents as $idx => $item) {
                $contents[$idx]['taxid'] = $newcontents['taxid'][$idx];
                $contents[$idx]['prodid'] = $newcontents['prodid'][$idx];
                $contents[$idx]['content'] = $newcontents['content'][$idx];
                $contents[$idx]['count'] = $newcontents['count'][$idx];
                $contents[$idx]['discount'] = $newcontents['discount'][$idx];
                $contents[$idx]['discount_type'] = $newcontents['discount_type'][$idx];
                $contents[$idx]['name'] = $newcontents['name'][$idx];
                $contents[$idx]['tariffid'] = $newcontents['tariffid'][$idx];
                $contents[$idx]['valuebrutto'] = $newcontents['valuebrutto'][$idx];
                $contents[$idx]['valuenetto'] = $newcontents['valuenetto'][$idx];
            }
            break;
        }

        $cnote['currencyvalue'] = $LMS->getCurrencyValue($cnote['currency'], $sdate);
        if (!isset($cnote['currencyvalue'])) {
            die('Fatal error: couldn\'t get quote for ' . $cnote['currency'] . ' currency!<br>');
        }

        $DB->BeginTrans();

        $use_current_customer_data = isset($cnote['use_current_customer_data']);
        if ($use_current_customer_data) {
            $customer = $LMS->GetCustomer($cnote['customerid'], true);
        }

        $division = $LMS->GetDivision($use_current_customer_data ? $customer['divisionid'] : $cnote['divisionid']);

        if (!$cnote['number']) {
            $cnote['number'] = $LMS->GetNewDocumentNumber(array(
                'doctype' => DOC_CNOTE,
                'planid' => $cnote['numberplanid'],
                'cdate' => $cnote['cdate'],
                'customerid' => $cnote['customerid'],
            ));
        } else {
            if (!preg_match('/^[0-9]+$/', $cnote['number'])) {
                $error['number'] = trans('Credit note number must be integer!');
            } elseif (($cnote['cdate'] != $cnote['oldcdate'] || $cnote['number'] != $cnote['oldnumber']
                || ($cnote['oldnumber'] == $cnote['number'] && $cnote['oldcustomerid'] != $cnote['customerid'])
                || $cnote['numberplanid'] != $cnote['oldnumberplanid']) && ($docid = $LMS->DocumentExists(array(
                    'number' => $cnote['number'],
                    'doctype' => DOC_CNOTE,
                    'planid' => $cnote['numberplanid'],
                    'cdate' => $cnote['cdate'],
                    'customerid' => $cnote['customerid'],
                ))) > 0 && $docid != $iid) {
                $error['number'] = trans('Credit note number $a already exists!', $cnote['number']);
            }

            if ($error) {
                $cnote['number'] = $LMS->GetNewDocumentNumber(array(
                    'doctype' => DOC_CNOTE,
                    'planid' => $cnote['numberplanid'],
                    'cdate' => $cnote['cdate'],
                    'customerid' => $cnote['customerid'],
                ));
                $error = null;
            }
        }

        $args = array(
            'cdate' => $cdate,
            'sdate' => $sdate,
            'paytime' => $paytime,
            'paytype' => $cnote['paytype'],
            'splitpayment' => $cnote['splitpayment'],
            SYSLOG::RES_CUST => $cnote['customerid'],
            'name' => $use_current_customer_data ? $customer['customername'] : $cnote['name'],
            'address' => $use_current_customer_data ? (($customer['postoffice'] && $customer['postoffice'] != $customer['city'] && $customer['street']
                ? $customer['postoffice'] . ', ' : '') . $customer['address']) : $cnote['address'],
            'ten' => $use_current_customer_data ? $customer['ten'] : $cnote['ten'],
            'ssn' => $use_current_customer_data ? $customer['ssn'] : $cnote['ssn'],
            'zip' => $use_current_customer_data ? $customer['zip'] : $cnote['zip'],
            'city' => $use_current_customer_data ? ($customer['postoffice'] ? $customer['postoffice'] : $customer['city'])
                : $cnote['city'],
            SYSLOG::RES_COUNTRY => $use_current_customer_data ? (empty($customer['countryid']) ? null : $customer['countryid'])
                : (empty($cnote['countryid']) ? null : $cnote['countryid']),
            'reason' => $cnote['reason'],
            SYSLOG::RES_DIV => $use_current_customer_data ? $customer['divisionid'] : $cnote['divisionid'],
            'div_name' => ($division['name'] ? $division['name'] : ''),
            'div_shortname' => ($division['shortname'] ? $division['shortname'] : ''),
            'div_address' => ($division['address'] ? $division['address'] : ''),
            'div_city' => ($division['city'] ? $division['city'] : ''),
            'div_zip' => ($division['zip'] ? $division['zip'] : ''),
            'div_' . SYSLOG::getResourceKey(SYSLOG::RES_COUNTRY) => ($division['countryid'] ? $division['countryid'] : 0),
            'div_ten'=> ($division['ten'] ? $division['ten'] : ''),
            'div_regon' => ($division['regon'] ? $division['regon'] : ''),
            'div_bank' => $division['bank'] ?: null,
            'div_account' => ($division['account'] ? $division['account'] : ''),
            'div_inv_header' => ($division['inv_header'] ? $division['inv_header'] : ''),
            'div_inv_footer' => ($division['inv_footer'] ? $division['inv_footer'] : ''),
            'div_inv_author' => ($division['inv_author'] ? $division['inv_author'] : ''),
            'div_inv_cplace' => ($division['inv_cplace'] ? $division['inv_cplace'] : ''),
            'currency' => $cnote['currency'],
            'currencyvalue' => $cnote['currencyvalue'],
        );
        $args['number'] = $cnote['number'];
        if ($cnote['numberplanid']) {
            $args['fullnumber'] = docnumber(array(
                'number' => $cnote['number'],
                'template' => $DB->GetOne('SELECT template FROM numberplans WHERE id = ?', array($cnote['numberplanid'])),
                'cdate' => $cnote['cdate'],
                'customerid' => $cnote['customerid'],
            ));
        } else {
            $args['fullnumber'] = null;
        }
        $args[SYSLOG::RES_NUMPLAN] = !empty($cnote['numberplanid']) ? $cnote['numberplanid'] : null;
        $args[SYSLOG::RES_DOC] = $iid;

        $DB->Execute('UPDATE documents SET cdate = ?, sdate = ?, paytime = ?, paytype = ?, splitpayment = ?, customerid = ?,
				name = ?, address = ?, ten = ?, ssn = ?, zip = ?, city = ?, countryid = ?, reason = ?, divisionid = ?,
				div_name = ?, div_shortname = ?, div_address = ?, div_city = ?, div_zip = ?, div_countryid = ?,
				div_ten = ?, div_regon = ?, div_bank = ?, div_account = ?, div_inv_header = ?, div_inv_footer = ?,
				div_inv_author = ?, div_inv_cplace = ?, currency = ?, currencyvalue = ?,
				number = ?, fullnumber = ?, numberplanid = ?
				WHERE id = ?', array_values($args));
        if ($SYSLOG) {
            $SYSLOG->AddMessage(
                SYSLOG::RES_DOC,
                SYSLOG::OPER_UPDATE,
                $args,
                array('div_' . SYSLOG::getResourceKey(SYSLOG::RES_COUNTRY))
            );
        }

        if (!$cnote['closed']) {
            if ($SYSLOG) {
                $cashids = $DB->GetCol('SELECT id FROM cash WHERE docid = ?', array($iid));
                foreach ($cashids as $cashid) {
                    $args = array(
                        SYSLOG::RES_CASH => $cashid,
                        SYSLOG::RES_DOC => $iid,
                        SYSLOG::RES_CUST => $cnote['customerid'],
                    );
                    $SYSLOG->AddMessage(SYSLOG::RES_CASH, SYSLOG::OPER_DELETE, $args);
                }
                $itemids = $DB->GetCol('SELECT itemid FROM invoicecontents WHERE docid = ?', array($iid));
                foreach ($itemids as $itemid) {
                    $args = array(
                        SYSLOG::RES_DOC => $iid,
                        SYSLOG::RES_CUST => $cnote['customerid'],
                        'itemid' => $itemid,
                    );
                    $SYSLOG->AddMessage(SYSLOG::RES_INVOICECONT, SYSLOG::OPER_DELETE, $args);
                }
            }
            $DB->Execute('DELETE FROM invoicecontents WHERE docid = ?', array($iid));
            $DB->Execute('DELETE FROM cash WHERE docid = ?', array($iid));

            $itemid=0;
            foreach ($contents as $idx => $item) {
                $itemid++;

                $args = array(
                    SYSLOG::RES_DOC => $iid,
                    'itemid' => $itemid,
                    'value' => str_replace(',', '.', $item['valuebrutto']),
                    SYSLOG::RES_TAX => $item['taxid'],
                    'prodid' => $item['prodid'],
                    'content' => $item['content'],
                    'count' => $item['count'],
                    'pdiscount' => str_replace(',', '.', $item['pdiscount']),
                    'vdiscount' => str_replace(',', '.', $item['vdiscount']),
                    'name' => $item['name'],
                    SYSLOG::RES_TARIFF => empty($item['tariffid']) ? null : $item['tariffid'],
                );
                $DB->Execute('INSERT INTO invoicecontents (docid, itemid, value,
					taxid, prodid, content, count, pdiscount, vdiscount, description, tariffid)
					VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)', array_values($args));
                if ($SYSLOG) {
                    $args[SYSLOG::RES_CUST] = $cnote['customerid'];
                    $SYSLOG->AddMessage(SYSLOG::RES_INVOICECONT, SYSLOG::OPER_ADD, $args);
                }

                $LMS->AddBalance(array(
                    'time' => $cdate,
                    'value' => $item['cash'],
                    'currency' => $cnote['currency'],
                    'currencyvalue' => $cnote['currencyvalue'],
                    'taxid' => $item['taxid'],
                    'customerid' => $cnote['customerid'],
                    'comment' => $item['name'],
                    'docid' => $iid,
                    'itemid' => $itemid
                    ));
            }
        } else {
            if ($SYSLOG) {
                $cashids = $DB->GetCol('SELECT id FROM cash WHERE docid = ?', array($iid));
                foreach ($cashids as $cashid) {
                    $args = array(
                        SYSLOG::RES_CASH => $cashid,
                        SYSLOG::RES_DOC => $iid,
                        SYSLOG::RES_CUST => $cnote['customerid'],
                    );
                    $SYSLOG->AddMessage(SYSLOG::RES_CASH, SYSLOG::OPER_UPDATE, $args);
                }
            }
            $DB->Execute(
                'UPDATE cash SET customerid = ? WHERE docid = ?',
                array($cnote['customerid'], $iid)
            );
        }

        $DB->CommitTrans();

        if (isset($_GET['print'])) {
            $which = isset($_GET['which']) ? $_GET['which'] : 0;

            $SESSION->save('invoiceprint', array('invoice' => $iid, 'which' => $which));
        }

        $SESSION->redirect('?m=invoicelist');
        break;
}

$SESSION->save('cnote', $cnote);
$SESSION->save('cnotecontents', $contents);
$SESSION->save('cnoteediterror', $error);

if ($action != '') {
    // redirect needed because we don't want to destroy contents of invoice in order of page refresh
    $SESSION->redirect('?m=invoicenoteedit');
}

$hook_data = array(
    'contents' => $contents,
    'cnote' => $cnote,
);
$hook_data = $LMS->ExecuteHook('invoicenoteedit_before_display', $hook_data);
$contents = $hook_data['contents'];
$cnote = $hook_data['cnote'];

$SMARTY->assign('error', $error);
$SMARTY->assign('contents', $contents);
$SMARTY->assign('cnote', $cnote);
$SMARTY->assign('refdoc', $cnote);
$SMARTY->assign('taxeslist', $taxeslist);

$args = array(
    'doctype' => DOC_CNOTE,
    'cdate' => date('Y/m', $cnote['cdate']),
    'customerid' => $cnote['customerid'],
    'division' => $DB->GetOne('SELECT divisionid FROM customers WHERE id = ?', array($cnote['customerid'])),
);
$SMARTY->assign('numberplanlist', $LMS->GetNumberPlans($args));
$SMARTY->assign('messagetemplates', $LMS->GetMessageTemplates(TMPL_CNOTE_REASON));

$total_value = 0;
if (!empty($contents)) {
    foreach ($contents as $item) {
        $total_value += $item['s_valuebrutto'];
    }
}

$SMARTY->assign('is_split_payment_suggested', $LMS->isSplitPaymentSuggested(
    $cnote['customerid'],
    date('Y/m/d', $cnote['cdate']),
    $total_value
));

$SMARTY->display('invoice/invoicenotemodify.html');
