<?php

/**
 * @file EmsPubStripePaymentForm.php
 *
 * Copyright (c) 2014-2022 Simon Fraser University
 * Copyright (c) 2003-2022 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class EmsPubStripePaymentForm
 *
 * Form for Stripe-based payments for EMS.pub.
 *
 */

namespace APP\plugins\paymethod\emspubstripe;

use APP\core\Application;
use APP\core\Request;
use APP\template\TemplateManager;
// use Omnipay\PayPal\Message\RestAuthorizeResponse; // Need Stripe equivalent or generic
use PKP\config\Config;
use PKP\form\Form;
use PKP\payment\QueuedPayment;

class EmsPubStripePaymentForm extends Form
{
    /** @var EmsPubStripePlugin */
    public $_emsPubStripePlugin;

    /** @var QueuedPayment */
    public $_queuedPayment;

    /**
     * @param EmsPubStripePlugin $emsPubStripePlugin
     * @param QueuedPayment $queuedPayment
     */
    public function __construct($emsPubStripePlugin, $queuedPayment)
    {
        $this->_emsPubStripePlugin = $emsPubStripePlugin;
        $this->_queuedPayment = $queuedPayment;
        parent::__construct(null);
    }

    /**
     * @copydoc Form::display()
     *
     * @param null|Request $request
     * @param null|mixed $template
     */
    public function display($request = null, $template = null)
    {
        // Application is set to sandbox mode and will not run the features of plugin
        if (Config::getVar('general', 'sandbox', false)) {
            error_log('Application is set to sandbox mode and no payment will be done via emspubstripe');
            TemplateManager::getManager($request)
                ->assign('message', 'common.sandbox')
                ->display('frontend/pages/message.tpl');
            return;
        }

        try {
            $journal = $request->getJournal();
            $paymentManager = Application::get()->getPaymentManager($journal);
            
            // Use Stripe PaymentIntents for modern 3DS support
            $gateway = \Omnipay\Omnipay::create('Stripe\PaymentIntents');
            $gateway->initialize([
                'apiKey' => $this->_emsPubStripePlugin->getSetting($journal->getId(), 'secret'),
            ]);
            
            $transaction = $gateway->purchase([
                'amount' => number_format($this->_queuedPayment->getAmount(), 2, '.', ''),
                'currency' => $this->_queuedPayment->getCurrencyCode(),
                'description' => $paymentManager->getPaymentName($this->_queuedPayment),
                'returnUrl' => $request->url(null, 'payment', 'plugin', [$this->_emsPubStripePlugin->getName(), 'return'], ['queuedPaymentId' => $this->_queuedPayment->getId()]),
                'cancelUrl' => $request->url(null, 'index'),
                'confirm' => true, // Confirm the payment intent immediately to get next action if needed
                'returnUrl' => $request->url(null, 'payment', 'plugin', [$this->_emsPubStripePlugin->getName(), 'return'], ['queuedPaymentId' => $this->_queuedPayment->getId()]),
                'paymentMethodTypes' => ['card'],
            ]);
            
            $response = $transaction->send();
            
            // For PaymentIntents, we might get a RedirectResponse if 3DS is needed, 
            // or we might need to handle the response differently. 
            // Omnipay Stripe PaymentIntents typically redirects for 3DS or returns reference.
            
            if ($response->isRedirect()) {
                $request->redirectUrl($response->getRedirectUrl());
                return;
            }
            
            // If satisfied/successful without redirect (e.g. no 3DS required and auto-confirm? Unlikely without payment method ID)
            // Wait, for creating a session (Checkout), we use Stripe\Checkout\Session.
            // Let's try to use Checkout Session if available as it creates a hosted page like PayPal.
            // PaymentIntents usually require a frontend element (Elements) to collect card details first.
            // Since this Form display is server-side, we want a Hosted Page (Checkout).
             
        } catch (\Exception $e) {
             error_log('Stripe initialization exception: ' . $e->getMessage());
             // Fallback or retry with Checkout Session if above fails (see below)
        }
        
        // Let's use Checkout Session approach which matches PayPal flow better
        try {
             $journal = $request->getJournal();
             // 'Stripe_Checkout' should map to \Omnipay\Stripe\CheckoutGateway
             $gateway = \Omnipay\Omnipay::create('Stripe_Checkout'); 
             $gateway->initialize([
                'apiKey' => $this->_emsPubStripePlugin->getSetting($journal->getId(), 'secret'),
             ]);

             $transaction = $gateway->purchase([
                'line_items' => [[
                    'price_data' => [
                        'currency' => $this->_queuedPayment->getCurrencyCode(),
                        'product_data' => [
                            'name' => $paymentManager->getPaymentName($this->_queuedPayment),
                        ],
                        'unit_amount' => (int)($this->_queuedPayment->getAmount() * 100), // Stripe expects cents
                    ],
                    'quantity' => 1,
                ]],
                'mode' => 'payment',
                'success_url' => $request->url(null, 'payment', 'plugin', [$this->_emsPubStripePlugin->getName(), 'return'], ['queuedPaymentId' => $this->_queuedPayment->getId()]) . '&session_id={CHECKOUT_SESSION_ID}',
                'cancel_url' => $request->url(null, 'payment', 'plugin', [$this->_emsPubStripePlugin->getName(), 'return'], ['queuedPaymentId' => $this->_queuedPayment->getId(), 'status' => 'cancel']),
                'payment_method_types' => ['card'],
             ]);
             
             $response = $transaction->send();

             if ($response->isRedirect()) {
                 $request->redirectUrl($response->getRedirectUrl());
                 return;
             } elseif ($response->isSuccessful() && isset($response->getData()['url'])) {
                 $request->redirectUrl($response->getData()['url']);
                 return;
             }
             
             // If not redirecting, something failed
             file_put_contents(dirname(__FILE__) . '/debug_log.txt', "Stripe Response Data: " . var_export($response->getData(), true) . "\n", FILE_APPEND);
             throw new \Exception($response->getMessage() ?? 'Stripe Session creation failed');

        } catch (\Exception $e) {
            file_put_contents(dirname(__FILE__) . '/debug_log.txt', 'Stripe transaction exception: ' . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n", FILE_APPEND);
            error_log('Stripe transaction exception: ' . $e->getMessage());
            $templateMgr = TemplateManager::getManager($request);
            $templateMgr->assign('message', 'plugins.paymethod.emspubstripe.error');
            $templateMgr->display('frontend/pages/message.tpl');
        }
    }
}
