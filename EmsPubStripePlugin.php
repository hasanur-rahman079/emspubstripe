<?php

/**
 * @file plugins/paymethod/emspubstripe/EmsPubStripePlugin.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2003-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class EmsPubStripePlugin
 *
 * @ingroup plugins_paymethod_emspubstripe
 *
 * @brief Stripe payment plugin class for EMS.pub
 */

namespace APP\plugins\paymethod\emspubstripe;

use APP\core\Application;
use APP\core\Request;
use APP\template\TemplateManager;
use Illuminate\Support\Collection;
use Omnipay\Omnipay;
use PKP\components\forms\context\PKPPaymentSettingsForm;
use PKP\config\Config;
use PKP\db\DAORegistry;
use PKP\plugins\Hook;
use PKP\plugins\PaymethodPlugin;

require_once(dirname(__FILE__) . '/vendor/autoload.php');

class EmsPubStripePlugin extends PaymethodPlugin
{
    /**
     * @see Plugin::getName
     */
    public function getName()
    {
        return 'EmsPubStripePayment';
    }

    /**
     * @see Plugin::getDisplayName
     */
    public function getDisplayName()
    {
        return __('plugins.paymethod.emspubstripe.displayName');
    }

    /**
     * @see Plugin::getDescription
     */
    public function getDescription()
    {
        return __('plugins.paymethod.emspubstripe.description');
    }

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }

        $this->addLocaleData();
        Hook::add('Form::config::before', $this->addSettings(...));
        return true;
    }

    /**
     * Add settings to the payments form
     *
     * @param string $hookName
     * @param \PKP\components\forms\FormComponent $form
     */
    public function addSettings($hookName, $form)
    {
        if ($form->id !== PKPPaymentSettingsForm::FORM_PAYMENT_SETTINGS) {
            return;
        }

        $context = Application::get()->getRequest()->getContext();
        if (!$context) {
            return;
        }

        $form->addGroup([
            'id' => 'emspubstripepayment',
            'label' => __('plugins.paymethod.emspubstripe.displayName'),
            'showWhen' => 'paymentsEnabled',
        ])
            ->addField(new \PKP\components\forms\FieldOptions('testMode', [
                'label' => __('plugins.paymethod.emspubstripe.settings.testMode'),
                'options' => [
                    ['value' => true, 'label' => __('common.enable')]
                ],
                'value' => (bool) $this->getSetting($context->getId(), 'testMode'),
                'groupId' => 'emspubstripepayment',
            ]))
            ->addField(new \PKP\components\forms\FieldText('accountName', [
                'label' => __('plugins.paymethod.emspubstripe.settings.accountName'),
                'value' => $this->getSetting($context->getId(), 'accountName'),
                'groupId' => 'emspubstripepayment',
            ]))
            ->addField(new \PKP\components\forms\FieldText('clientId', [
                'label' => __('plugins.paymethod.emspubstripe.settings.clientId'),
                'value' => $this->getSetting($context->getId(), 'clientId'),
                'groupId' => 'emspubstripepayment',
            ]))
            ->addField(new \PKP\components\forms\FieldText('secret', [
                'label' => __('plugins.paymethod.emspubstripe.settings.secret'),
                'value' => $this->getSetting($context->getId(), 'secret'),
                'groupId' => 'emspubstripepayment',
            ]));

        return;
    }

    /**
     * @copydoc PaymethodPlugin::saveSettings
     */
    public function saveSettings(string $hookname, array $args)
    {
        $illuminateRequest = $args[0]; /** @var \Illuminate\Http\Request $illuminateRequest */
        $request = $args[1]; /** @var Request $request */
        $updatedSettings = $args[3]; /** @var Collection $updatedSettings */

        $allParams = $illuminateRequest->input();
        $saveParams = [];
        foreach ($allParams as $param => $val) {
            switch ($param) {
                case 'accountName':
                case 'clientId':
                case 'secret':
                    $saveParams[$param] = (string) $val;
                    break;
                case 'testMode':
                    $saveParams[$param] = $val === 'true';
                    break;
            }
        }
        $contextId = $request->getContext()->getId();
        foreach ($saveParams as $param => $val) {
            $this->updateSetting($contextId, $param, $val);
            $updatedSettings->put($param, $val);
        }
    }

    /**
     * @copydoc PaymethodPlugin::getPaymentForm()
     */
    public function getPaymentForm($context, $queuedPayment)
    {
        return new EmsPubStripePaymentForm($this, $queuedPayment);
    }

    /**
     * @copydoc PaymethodPlugin::isConfigured
     */
    public function isConfigured($context)
    {
        if (!$context) {
            return false;
        }
        if ($this->getSetting($context->getId(), 'accountName') == '') {
            return false;
        }
        return true;
    }

    /**
     * Handle a handshake with the Stripe service
     */
    public function handle($args, $request)
    {
        // Application is set to sandbox mode and will not run the features of plugin
        if (Config::getVar('general', 'sandbox', false)) {
            error_log('Application is set to sandbox mode and no payment will be done via emspubstripe');
            return;
        }

        $journal = $request->getJournal();
        $queuedPaymentDao = DAORegistry::getDAO('QueuedPaymentDAO'); /** @var \PKP\payment\QueuedPaymentDAO $queuedPaymentDao */
        try {
            $queuedPayment = $queuedPaymentDao->getById($queuedPaymentId = $request->getUserVar('queuedPaymentId'));
            if (!$queuedPayment) {
                throw new \Exception("Invalid queued payment ID {$queuedPaymentId}!");
            }

            // Retrieve payment details for display
            $paymentManager = Application::get()->getPaymentManager($journal);
            $itemName = $paymentManager->getPaymentName($queuedPayment);
            $amount = $queuedPayment->getAmount();
            $currency = $queuedPayment->getCurrencyCode();
            
            // User requested redirect to dashboard
            $dashboardUrl = $request->url(null, 'dashboard');

            // Check for explicit cancel status from our form
            if ($request->getUserVar('status') === 'cancel') {
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->assign([
                    'backLink' => $dashboardUrl,
                    'itemName' => $itemName,
                    'amount' => $amount,
                    'currency' => $currency,
                ]);
                $templateMgr->display($this->getTemplateResource('paymentCancel.tpl'));
                return;
            }

            $sessionId = $request->getUserVar('session_id');
            if (!$sessionId) {
                // Try fallback parameter name if session_id is missing, though we set it explicitly
                // Stripe Checkout usually returns session_id if configured.
                // Log if missing
                 // Debug removed for production security
                 throw new \Exception("Missing session_id return parameter.");
            }

            // Initialize Gateway
            // 'Stripe_Checkout' maps to \Omnipay\Stripe\CheckoutGateway
            $gateway = \Omnipay\Omnipay::create('Stripe_Checkout');
            $gateway->initialize([
                'apiKey' => $this->getSetting($journal->getId(), 'secret'),
            ]);

            // Fetch the session
            $response = $gateway->fetchTransaction([
                'transactionReference' => $sessionId,
            ])->send();

            if ($response->isSuccessful()) {
                $data = $response->getData();
                // Log the successful fetch data
                 // Debug logging removed for production security
                
                if (isset($data['payment_status']) && $data['payment_status'] === 'paid') {
                    $paymentManager->fulfillQueuedPayment($request, $queuedPayment, $this->getName());
                    
                    // Show success template with link to My Invoices page
                    $pendingPaymentsUrl = $request->url(null, 'emspubcore', 'pendingPayments');
                    $templateMgr = TemplateManager::getManager($request);
                    $templateMgr->assign([
                        'backLink' => $pendingPaymentsUrl,
                        'itemName' => $itemName,
                        'amount' => $amount,
                        'currency' => $currency,
                    ]);
                    $templateMgr->display($this->getTemplateResource('paymentSuccess.tpl'));
                    return;
                } else {
                     throw new \Exception('Payment status is not paid. Status: ' . ($data['payment_status'] ?? 'unknown'));
                }
            } else {
                error_log('Stripe Fetch Failed: ' . $response->getMessage());
                throw new \Exception('Fetch transaction failed: ' . $response->getMessage());
            }
        } catch (\Exception $e) {

            error_log('Stripe transaction exception: ' . $e->getMessage());
            $templateMgr = TemplateManager::getManager($request);
            $templateMgr->assign('message', 'plugins.paymethod.emspubstripe.error');
            $templateMgr->display('frontend/pages/message.tpl');
        }
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\APP\plugins\paymethod\emspubstripe\EmsPubStripePlugin', '\EmsPubStripePlugin');
}

