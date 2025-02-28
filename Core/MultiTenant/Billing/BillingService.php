<?php
namespace Minds\Core\MultiTenant\Billing;

use DateTimeImmutable;
use Minds\Core\Config\Config;
use Minds\Core\Email\V2\Campaigns\Recurring\TenantTrial\TenantTrialEmailer;
use Minds\Core\EventStreams\Events\TenantBootstrapRequestEvent;
use Minds\Core\EventStreams\Topics\TenantBootstrapRequestsTopic;
use Minds\Core\Guid;
use Minds\Core\MultiTenant\AutoLogin\AutoLoginService;
use Minds\Core\MultiTenant\Billing\Types\TenantBillingType;
use Minds\Core\MultiTenant\Enums\TenantPlanEnum;
use Minds\Core\MultiTenant\Enums\TenantUserRoleEnum;
use Minds\Core\MultiTenant\Models\Tenant;
use Minds\Core\MultiTenant\Services\DomainService;
use Minds\Core\MultiTenant\Services\MultiTenantBootService;
use Minds\Core\Payments\Stripe\Checkout\Manager as StripeCheckoutManager;
use Minds\Core\Payments\Stripe\Checkout\Products\Services\ProductPriceService as StripeProductPriceService;
use Minds\Core\Payments\Stripe\Checkout\Products\Services\ProductService as StripeProductService;
use Minds\Core\Payments\Stripe\Checkout\Session\Services\SessionService as StripeCheckoutSessionService;
use Minds\Core\MultiTenant\Services\TenantsService;
use Minds\Core\MultiTenant\Services\TenantUsersService;
use Minds\Core\MultiTenant\Types\TenantUser;
use Minds\Core\Payments\Checkout\Enums\CheckoutTimePeriodEnum;
use Minds\Core\Payments\Stripe\Checkout\Enums\CheckoutModeEnum;
use Minds\Core\Payments\Stripe\Checkout\Enums\PaymentMethodCollectionEnum;
use Minds\Core\Payments\Stripe\Checkout\Models\CustomField;
use Minds\Core\Payments\Stripe\CustomerPortal\Services\CustomerPortalService;
use Minds\Core\Payments\Stripe\Subscriptions\Services\SubscriptionsService;
use Minds\Core\Router\Exceptions\ForbiddenException;
use Minds\Entities\User;
use Minds\Helpers\Url;
use Stripe\Price;

class BillingService
{
    /** @var string */
    const DEFAULT_ROOT_ACCOUNT_USERNAME = 'networkadmin';

    public function __construct(
        private readonly StripeCheckoutManager        $stripeCheckoutManager,
        private readonly StripeProductPriceService    $stripeProductPriceService,
        private readonly StripeProductService         $stripeProductService,
        private readonly StripeCheckoutSessionService $stripeCheckoutSessionService,
        private readonly DomainService                $domainService,
        private readonly TenantsService               $tenantsService,
        private readonly TenantUsersService           $usersService,
        private readonly TenantTrialEmailer           $emailService,
        private readonly TenantBootstrapRequestsTopic $tenantBootstrapRequestsTopic,
        private readonly SubscriptionsService         $stripeSubscriptionsService,
        private readonly AutoLoginService             $autoLoginService,
        private readonly CustomerPortalService        $customerPortalService,
        private readonly Config                       $config,
        private readonly MultiTenantBootService       $multiTenantBootService,
    ) {
    
    }

    /**
     * Returns a url to stripe checkout service, specifically for a customer
     * who is not on Minds.
     */
    public function createExternalCheckoutLink(
        TenantPlanEnum $plan,
        CheckoutTimePeriodEnum $timePeriod,
    ): string {
        // Build out the products and their add ons based on the input
        $product = $this->stripeProductService->getProductByKey('networks:' . strtolower($plan->name));
        $productPrices = $this->stripeProductPriceService->getPricesByProduct($product->id);
        $productPrice = array_filter(iterator_to_array($productPrices->getIterator()), fn (Price $price) => $price->lookup_key === 'networks:' . strtolower($plan->name) . ":" . strtolower($timePeriod->name));

        $lineItems = [
            [
                'price' => array_pop($productPrice)->id,
                'quantity' => 1,
            ]
        ];

        $checkoutSession = $this->stripeCheckoutManager->createSession(
            mode: CheckoutModeEnum::SUBSCRIPTION,
            successUrl: "api/v3/multi-tenant/billing/external-callback?session_id={CHECKOUT_SESSION_ID}",
            cancelUrl: "https://networks.minds.com/pricing",
            lineItems: $lineItems,
            paymentMethodTypes: [
                'card',
                'us_bank_account',
            ],
            submitMessage: $timePeriod === CheckoutTimePeriodEnum::YEARLY ? "You are agreeing to a 12 month subscription that will be billed monthly." : null,
            metadata: [
                'tenant_plan' => strtoupper($plan->name),
            ],
        );

        $checkoutLink = $checkoutSession->url;
        return $checkoutLink;
    }

    /**
     * Returns a url to stripe checkout service for a trial, specifically for a customer
     * who is not on Minds.
     * @param TenantPlanEnum $plan - The plan for the tenant.
     * @param CheckoutTimePeriodEnum $timePeriod - The billing period for the subscription.
     * @param string|null $customerUrl - The URL that we are creating a trial for.
     * @return string The URL for the Stripe checkout session.
     */
    public function createExternalTrialCheckoutLink(
        TenantPlanEnum $plan,
        CheckoutTimePeriodEnum $timePeriod,
        ?string $customerUrl = null,
    ): string {
        // Build out the products and their add ons based on the input
        $product = $this->stripeProductService->getProductByKey('networks:' . strtolower($plan->name));
        $productPrices = $this->stripeProductPriceService->getPricesByProduct($product->id);
        $productPrice = array_filter(iterator_to_array($productPrices->getIterator()), fn (Price $price) => $price->lookup_key === 'networks:' . strtolower($plan->name) . ":" . strtolower($timePeriod->name));

        if ($customerUrl) {
            $customerUrl = Url::prependScheme($customerUrl);
        }

        $checkoutSession = $this->stripeCheckoutManager->createSession(
            mode: CheckoutModeEnum::SUBSCRIPTION,
            successUrl: "https://www.minds.com/api/v3/multi-tenant/billing/external-trial-callback?session_id={CHECKOUT_SESSION_ID}",
            cancelUrl: "https://networks.minds.com/pricing",
            lineItems: [
                [
                    'price' => array_pop($productPrice)->id,
                    'quantity' => 1,
                ]
            ],
            paymentMethodTypes: [
                'card',
                'us_bank_account',
            ],
            submitMessage: $timePeriod === CheckoutTimePeriodEnum::YEARLY ? "You are agreeing to a 12 month subscription that will be billed monthly." : null,
            metadata: [
                'tenant_plan' => strtoupper($plan->name),
                'customer_url' => $customerUrl,
            ],
            phoneNumberCollection: true,
            subscriptionData: [
                'trial_settings' => ['end_behavior' => ['missing_payment_method' => 'pause']],
                'trial_period_days' => Tenant::TRIAL_LENGTH_IN_DAYS,
            ],
            paymentMethodCollection: PaymentMethodCollectionEnum::IF_REQUIRED,
            customFields: [
                new CustomField(
                    key: 'first_name',
                    label: 'First name',
                    type: 'text'
                ),
                new CustomField(
                    key: 'last_name',
                    label: 'Last name',
                    type: 'text'
                )
            ]
        );

        $checkoutLink = $checkoutSession->url;
        return $checkoutLink;
    }

    /**
     * Returns a stripe checkout link for a tenant admin who is trying to upgrade their network
     */
    public function createUpgradeCheckoutLink(
        TenantPlanEnum $plan,
        CheckoutTimePeriodEnum $timePeriod,
        User $loggedInUser,
    ): string {
        /** @var Tenant */
        $tenant = $this->config->get('tenant');

        if (!$tenant) {
            throw new ForbiddenException("Can only be run on an active tenant");
        }

        $this->runWithRootConfigs(function () use (&$checkoutLink, $timePeriod, $plan, $tenant, $loggedInUser) {
            // Does a subscription exist? If so, we can't do anything yet (todo), so redirect to the networks site contact form
            if ($tenant->stripeSubscription) {
                $checkoutLink = 'https://networks.minds.com/contact-upgrade?' . http_build_query([
                    'tenant_id' => $tenant->id,
                    'plan' => $plan->name,
                    'period' => $timePeriod->value,
                    'email' => $loggedInUser->getEmail(),
                ]);
                return; // This is the return of the callback, not the class function
            }

            // Build out the products and their add ons based on the input
            $product = $this->stripeProductService->getProductByKey('networks:' . strtolower($plan->name));
            $productPrices = $this->stripeProductPriceService->getPricesByProduct($product->id);
            $productPrice = array_filter(iterator_to_array($productPrices->getIterator()), fn (Price $price) => $price->lookup_key === 'networks:' . strtolower($plan->name) . ":" . strtolower($timePeriod->name));

            $lineItems = [
                [
                    'price' => array_pop($productPrice)->id,
                    'quantity' => 1,
                ]
            ];

            $navigatableDomain = $this->domainService->buildNavigatableDomain($tenant);

            $checkoutSession = $this->stripeCheckoutManager->createSession(
                mode: CheckoutModeEnum::SUBSCRIPTION,
                successUrl: "https://$navigatableDomain/api/v3/multi-tenant/billing/upgrade-callback?session_id={CHECKOUT_SESSION_ID}",
                cancelUrl: "https://networks.minds.com/pricing",
                lineItems: $lineItems,
                paymentMethodTypes: [
                    'card',
                    'us_bank_account',
                ],
                submitMessage: $timePeriod === CheckoutTimePeriodEnum::YEARLY ? "You are agreeing to a 12 month subscription that will be billed monthly." : null,
                metadata: [
                    'tenant_id' => $tenant->id,
                    'tenant_plan' => strtoupper($plan->name),
                ],
            );

            $checkoutLink = $checkoutSession->url;
        });

        return $checkoutLink;
    }

    /**
     * Execute when a checkout session has finished, so that we can create the tenant.
     * We will return an 'auto login' link for the customer too.
     */
    public function onSuccessfulCheckout(string $checkoutSessionId): string
    {
        // Get the checkout session
        $checkoutSession = $this->stripeCheckoutSessionService->retrieveCheckoutSession($checkoutSessionId);

        // Get the subscription
        $subscription = $this->stripeSubscriptionsService->retrieveSubscription($checkoutSession->subscription);

        if (isset($subscription->metadata->tenant_id)) {
            throw new ForbiddenException("The tenant has already been setup");
        }

        $plan = TenantPlanEnum::fromString($checkoutSession->metadata['tenant_plan']);

        $email = $checkoutSession->customer_details->email;

        // Create a temporary user, so that we can send them an email
        $user = $this->createEphemeralUser($email);

        // Create the tenant
        $tenant = $this->createTenant($plan, $user, $subscription->id);

        // Build an auto login url
        $user->guid = -1;
        $loginUrl = $this->autoLoginService->buildLoginUrlWithParamsFromTenant($tenant, $user);

        // We want to redirect back to the networks site, as we need to attach the identity
        $redirectUrl = 'https://networks.minds.com/complete-checkout?' . http_build_query([
            'email' => $email,
            'tenantId' => $tenant->id,
            'tenantDomain' => $this->buildDomain($tenant),
            'redirectUrl' => $loginUrl,
        ]);

        // Tell stripe billing about this tenant
        $this->stripeSubscriptionsService->updateSubscription(
            subscriptionId: $checkoutSession->subscription,
            metadata: [
                'tenant_id' => $tenant->id,
                'tenant_plan' => $tenant->plan->name,
            ]
        );

        return $redirectUrl;
    }

    /**
     * Execute when a checkout session has finished for a trial, so that we can create the tenant.
     * @param string $checkoutSessionId - the id of the checkout session.
     * @throws ForbiddenException - if the tenant has already been setup.
     * @return string - an auto-login link for the user.
     */
    public function onSuccessfulTrialCheckout(string $checkoutSessionId): string
    {
        // Get the checkout session
        $checkoutSession = $this->stripeCheckoutSessionService->retrieveCheckoutSession($checkoutSessionId);

        // Get the subscription
        $subscription = $this->stripeSubscriptionsService->retrieveSubscription($checkoutSession->subscription);

        if (isset($subscription->metadata['tenant_id'])) {
            throw new ForbiddenException("The tenant has already been setup");
        }

        $plan = TenantPlanEnum::fromString($checkoutSession->metadata['tenant_plan']);

        $email = $checkoutSession->customer_details->email;

        $customerUrl = $checkoutSession->metadata['customer_url'] ?? null;

        $firstNameField = array_values(array_filter($checkoutSession->custom_fields, fn ($field) => $field['key'] === 'first_name'));
        $firstName = count($firstNameField) ? $firstNameField[0]['text']['value'] : null;
        
        $lastNameField = array_values(array_filter($checkoutSession->custom_fields, fn ($field) => $field['key'] === 'last_name'));
        $lastName = count($lastNameField) ? $lastNameField[0]['text']['value'] : null;

        // Create a temporary user, so that we can send them an email.
        $user = $this->createEphemeralUser($email, $firstName);

        // Create the tenant.
        $tenant = $this->createTenant(
            plan: $plan,
            user: $user,
            stripeSubscription: $subscription->id,
            isTrial: true
        );

        if ($customerUrl) {
            $this->tenantBootstrapRequestsTopic->send(
                (new TenantBootstrapRequestEvent())
                    ->setTenantId($tenant->id)
                    ->setSiteUrl($customerUrl)
            );
        }

        // Build an auto login url.
        $user->guid = -1;
        $loginUrl = $this->autoLoginService->buildLoginUrlWithParamsFromTenant(
            tenant: $tenant,
            loggedInUser: $user,
            redirectPath: $customerUrl ? '/network/admin/bootstrap' : null
        );

        // Get input data from the form.
        $phoneNumber = $checkoutSession->customer_details?->phone ?? null;

        $redirectUrl = 'https://networks.minds.com/complete-trial-checkout?' . http_build_query([
            'email' => $email,
            'firstName' => $firstName,
            'lastName' => $lastName,
            'phone' => $phoneNumber,
            'tenantId' => $tenant->id,
            'tenantDomain' => $this->buildDomain($tenant),
            'redirectUrl' => $loginUrl,
        ]);

        // Tell stripe billing about this tenant.
        $this->stripeSubscriptionsService->updateSubscription(
            subscriptionId: $checkoutSession->subscription,
            metadata: [
                'tenant_id' => $tenant->id,
                'tenant_plan' => $tenant->plan->name,
            ]
        );

        return $redirectUrl;
    }

    /**
     * Execute when an upgrade checkout session has finished.
     */
    public function onSuccessfulUpgradeCheckout(string $checkoutSessionId, User $loggedInUser): string
    {
        /** Tenant */
        $tenant = $this->config->get('tenant');

        $this->runWithRootConfigs(function () use ($checkoutSessionId, $tenant, $loggedInUser) {
            // Get the checkout session
            $checkoutSession = $this->stripeCheckoutSessionService->retrieveCheckoutSession($checkoutSessionId);

            // Get the subscription
            $subscription = $this->stripeSubscriptionsService->retrieveSubscription($checkoutSession->subscription);

            $plan = TenantPlanEnum::fromString($checkoutSession->metadata['tenant_plan']);

            $this->tenantsService->upgradeTenant($tenant, $plan, $subscription->id, $loggedInUser);
        });

        /**
         * TODO: Consider using the navigatable domain here instead if we ever need the URL returned
         * At the time of writing, the return value is not in use, so there is no need to add an additional function call.
         */
        return $this->config->get('site_url') . 'network/admin/billing';
    }

    /**
     * Returns the tenant billing key info for the existing site (active tenant)
     */
    public function getTenantBillingOverview(): TenantBillingType
    {
        /** @var Tenant */
        $tenant = $this->config->get('tenant');

        if (!$tenant) {
            throw new ForbiddenException("Tenant not available");
        }

        // If the customer doesn't have a stripe subscription, there is no billing
        // setup yet.
        if (!$tenant->stripeSubscription) {
            return new TenantBillingType(
                plan: $tenant->plan,
                period: CheckoutTimePeriodEnum::MONTHLY,
                isActive: false,
            );
        }

        $this->runWithRootConfigs(function () use (&$subscription, &$manageUrl, $tenant) {
            $subscription = $this->stripeSubscriptionsService->retrieveSubscription($tenant->stripeSubscription);

            $navigatableDomain = $this->domainService->buildNavigatableDomain($tenant);

            $manageUrl = $this->customerPortalService->createCustomerPortalSession(
                stripeCustomerId: $subscription->customer,
                redirectUrl: "https://$navigatableDomain/network/admin/billing",
            );
        });

        $amountCents = array_sum(array_map(function ($item) {
            return $item->plan->amount;
        }, $subscription->items->data));

        return new TenantBillingType(
            plan: $tenant->plan,
            period: $subscription->plan->interval === 'month' ? CheckoutTimePeriodEnum::MONTHLY : CheckoutTimePeriodEnum::YEARLY,
            isActive: true,
            manageBillingUrl: $manageUrl,
            nextBillingAmountCents: $amountCents,
            nextBillingDate: (new DateTimeImmutable)->setTimestamp($subscription->current_period_end),
            previousBillingDate: (new DateTimeImmutable)->setTimestamp($subscription->current_period_start)
        );
    }

    /**
     * An ephemeral 'fake' account that is not automatically created.
     * @param string $email - The email address of the user.
     * @param string|null $username - The username of the user (defaults to networkadmin).
     * @return User
     */
    protected function createEphemeralUser(string $email, ?string $username = null): User
    {
        try {
            $username = $username && validate_username($username) ?
                strtolower(trim($username)) :
                self::DEFAULT_ROOT_ACCOUNT_USERNAME;
        } catch (\Exception $e) {
            $username = self::DEFAULT_ROOT_ACCOUNT_USERNAME;
        }

        $user = new User();
        $user->username = $username;
        $user->setEmail($email);

        return $user;
    }

    /**
     * Creates the tenant, the root user, and sends an email to the user
     * about their new site
     * @param TenantPlanEnum $plan - The plan for the new tenant.
     * @param User $user - The user associated with the new tenant.
     * @param string $stripeSubscription - The Stripe subscription ID.
     * @param bool $isTrial - Whether this is a trial tenant, defaults to false.
     * @return Tenant - The newly created tenant.
     */
    protected function createTenant(TenantPlanEnum $plan, User $user, string $stripeSubscription, bool $isTrial = false): Tenant
    {
        // should be able to specify that its a trial
        $tenant = $this->tenantsService->createNetwork(
            new Tenant(
                id: -1,
                ownerGuid: -1,
                plan: $plan,
                stripeSubscription: $stripeSubscription,
            ),
            $isTrial
        );

        // Generate a temorary password we will share with the customer
        $password = substr(hash('sha1', openssl_random_pseudo_bytes(256)), 0, 8);
        
        // Create the root user
        $this->usersService->createNetworkRootUser(
            networkUser: new TenantUser(
                guid: (int) Guid::build(),
                username: $user->username,
                tenantId: $tenant->id,
                role: TenantUserRoleEnum::OWNER,
                plainPassword: $password,
            ),
            sourceUser: $user
        );
 
        // Send an email with a the username and password to login to the tenant
        $this->emailService->setUser($user)
            ->setTenantId($tenant->id)
            ->setUsername($user->username)
            ->setPassword($password)
            ->setIsTrial(false)
            ->send();
 
        return $tenant;

    }

    /**
     * A helper function to ensure that code is run on the root configs and not on
     * the tenant configs.
     */
    private function runWithRootConfigs(callable $function): void
    {
        /** @var Tenant */
        $tenant = $this->config->get('tenant');

        // Rescope to root, as we need to use the Minds creds, not tenant
        $this->multiTenantBootService->resetRootConfigs();

        call_user_func($function);

        // Revert back to tenant configs
        $this->multiTenantBootService->bootFromTenantId($tenant->id);
    }

    /**
     * Quick function to build the initial domain. Should probably use the DomainService?
     */
    private function buildDomain(Tenant $tenant): string
    {
        return md5($tenant->id) . '.' . ($this->config->get('multi_tenant')['subdomain_suffix'] ?? 'minds.com');
    }
}
